<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Technician;
use App\Models\Tool;
use App\Models\ToolGroup;
use App\Models\ToolLoan;
use App\Models\ToolLocation;
use App\Models\ToolRack;
use App\Models\ToolType;
use App\Services\LocationHierarchyService;
use App\Services\PencarianToolService;
use App\Services\ToolLendingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Halaman peminjaman alat untuk akun teknisi dan tool keeper.
 *
 * Berada di luar middleware modul/hanya-baca karena dipakai semua teknisi yang
 * punya akun. Hak aksi diperiksa di controller:
 * - meminjam untuk diri sendiri: semua teknisi aktif;
 * - meminjamkan: administrator atau teknisi yang masih tool keeper;
 * - mengembalikan: pemilik pinjaman (mengajukan), keeper/admin (menerima).
 */
class AlatSayaController extends Controller
{
    public function __construct(
        private ToolLendingService $peminjaman,
        private PencarianToolService $pencarian
    ) {
    }

    /**
     * Dashboard alat untuk teknisi/keeper.
     */
    public function index(Request $request): View
    {
        $teknisi          = $this->teknisiAktif();
        $bolehMeminjamkan = $this->bolehMeminjamkan($teknisi);
        $admin            = (bool) auth()->user()?->isAdmin();
        $kataKunci        = trim((string) $request->query('search', $request->query('q', '')));
        $filter           = $this->filterDariRequest($request);

        $query = Tool::query()->with([
            'group.type',
            'rack.location',
            'pinjamanAktif.technician',
            'pinjamanAktif.borrowerDepartment',
            'pinjamanTerakhir.technician',
            'pinjamanTerakhir.borrowerDepartment',
        ]);

        $this->pencarian->terapkan($query, $kataKunci);
        $this->terapkanFilter($query, $filter);

        if ($kataKunci !== '') {
            $this->pencarian->terapkanUrutan($query, $kataKunci);
        } else {
            $query->orderBy('name')->orderBy('code');
        }

        $daftar = $query->paginate(36)->withQueryString();

        return view('alat.index', [
            'teknisi'          => $teknisi,
            'grup'             => $this->grupDariKoleksi($daftar->getCollection(), $teknisi, $bolehMeminjamkan, $admin),
            'daftar'           => $daftar,
            'kataKunci'        => $kataKunci,
            'filter'           => $filter,
            'pinjamanSaya'     => $teknisi !== null ? $this->pinjamanAktifTeknisi($teknisi->id) : collect(),
            'pinjamanAktif'    => $bolehMeminjamkan ? $this->semuaPinjamanAktif() : collect(),
            'jumlahKembali'    => $bolehMeminjamkan ? ToolLoan::query()->menungguKembali()->count() : 0,
            'bolehMeminjamkan' => $bolehMeminjamkan,
            'daftarTeknisi'    => $bolehMeminjamkan ? $this->teknisiPerBagian() : [],
            'daftarDepartemen' => $bolehMeminjamkan ? app(LocationHierarchyService::class)->semuaDepartemen() : collect(),
            'daftarLokasi'     => ToolLocation::query()->orderBy('name')->get(['id', 'name']),
            'daftarRak'        => ToolRack::query()->with('location')->orderBy('name')->get(),
            'daftarJenis'      => ToolType::query()->orderBy('name')->get(['id', 'name']),
            'daftarKelompok'   => ToolGroup::query()->with('type')->orderBy('name')->get(),
        ]);
    }

    /**
     * Halaman detail satu alat beserta log peminjamannya.
     */
    public function show(Request $request, Tool $tool): View
    {
        $aturanFilter = [
            'dari'   => ['nullable', 'date'],
            'sampai' => ['nullable', 'date'],
        ];

        if ($request->filled('dari')) {
            $aturanFilter['sampai'][] = 'after_or_equal:dari';
        }

        $filter = $request->validate($aturanFilter);

        $tool->load(['rack.location', 'group.type']);

        $dari   = $filter['dari'] ?? null;
        $sampai = $filter['sampai'] ?? null;

        $log = ToolLoan::query()
            ->with(['technician', 'borrowerDepartment', 'handlerTechnician', 'handlerUser', 'returnerTechnician', 'returnerUser'])
            ->where('tool_id', $tool->id)
            ->when($dari !== null, fn ($query) => $query->whereDate('borrowed_at', '>=', $dari))
            ->when($sampai !== null, fn ($query) => $query->whereDate('borrowed_at', '<=', $sampai))
            ->orderByDesc('borrowed_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $teknisi          = $this->teknisiAktif();
        $bolehMeminjamkan = $this->bolehMeminjamkan($teknisi);
        $admin            = (bool) auth()->user()?->isAdmin();
        $aktif            = $tool->pinjamanAktif()
            ->with(['technician', 'borrowerDepartment'])
            ->first();

        $milikSendiri = $aktif !== null
            && $teknisi !== null
            && (int) $aktif->technician_id === (int) $teknisi->id;

        return view('alat.show', [
            'alat'              => $tool,
            'log'               => $log,
            'dari'              => $dari,
            'sampai'            => $sampai,
            'aktif'             => $aktif,
            'teknisi'           => $teknisi,
            'bolehTulis'        => (bool) auth()->user()?->bolehMenulis(),
            'bolehMeminjamkan'  => $bolehMeminjamkan,
            'bolehPinjam'       => $teknisi !== null && $aktif === null && $tool->status === Tool::STATUS_TERSEDIA,
            'bolehKembalikan'   => $aktif !== null
                && ! $aktif->menungguPenerimaan()
                && ($admin || $bolehMeminjamkan || $milikSendiri),
            'menungguKembali'   => $aktif?->menungguPenerimaan() ?? false,
            'daftarTeknisi'     => $bolehMeminjamkan ? $this->teknisiPerBagian() : [],
            'daftarDepartemen'  => $bolehMeminjamkan ? app(LocationHierarchyService::class)->semuaDepartemen() : collect(),
            'daftarKelompok'    => ToolGroup::query()->with('type')->orderBy('name')->get(),
            'grupRak'           => $this->pilihanRak(),
        ]);
    }

    /**
     * Halaman Barang Kembali untuk keeper/administrator.
     */
    public function returns(Request $request): View
    {
        $teknisi          = $this->teknisiAktif();
        $bolehMeminjamkan = $this->bolehMeminjamkan($teknisi);

        if (! $bolehMeminjamkan) {
            abort(403, 'Hanya tool keeper atau administrator yang boleh menerima pengembalian.');
        }

        $kataKunci = trim((string) $request->query('search', ''));
        $filter    = $this->filterDariRequest($request);

        $query = ToolLoan::query()
            ->menungguKembali()
            ->with(['tool.group.type', 'tool.rack.location', 'technician', 'borrowerDepartment', 'returnRequesterTechnician']);

        if ($kataKunci !== '') {
            $pola = '%' . $kataKunci . '%';
            $query->where(function ($sub) use ($pola): void {
                $sub->where('tool_name', 'like', $pola)
                    ->orWhere('tool_code', 'like', $pola)
                    ->orWhere('borrower_name', 'like', $pola);
            });
        }

        $this->terapkanFilterKembali($query, $filter);

        $daftar = $query->orderByDesc('return_requested_at')->paginate(36)->withQueryString();

        return view('tools.returns', [
            'daftar'         => $daftar,
            'grup'           => $this->grupKembaliDariKoleksi($daftar->getCollection()),
            'kataKunci'      => $kataKunci,
            'filter'         => $filter,
            'bolehTulis'     => $bolehMeminjamkan,
            'tampilTabs'     => false,
            'namaRuteTerima' => 'alat.returns.terima',
            'daftarLokasi'   => ToolLocation::query()->orderBy('name')->get(['id', 'name']),
            'daftarJenis'    => ToolType::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Terima pengembalian (keeper/administrator).
     */
    public function terimaKembali(Request $request, ToolLoan $toolLoan): RedirectResponse
    {
        $teknisi          = $this->teknisiAktif();
        $bolehMeminjamkan = $this->bolehMeminjamkan($teknisi);

        if (! $bolehMeminjamkan) {
            abort(403, 'Hanya tool keeper atau administrator yang boleh menerima pengembalian.');
        }

        $admin = (bool) $request->user()?->isAdmin();

        $this->peminjaman->terimaKembali(
            $toolLoan,
            $admin ? ToolLoan::HANDLED_ADMIN : ToolLoan::HANDLED_KEEPER,
            $teknisi,
            $admin ? $request->user() : null
        );

        return back()->with('success', 'Alat ' . $toolLoan->tool_name . ' (' . $toolLoan->tool_code . ') diterima kembali.');
    }

    /**
     * Pinjam alat untuk diri sendiri lewat halaman web.
     */
    public function pinjam(Tool $tool): RedirectResponse
    {
        $teknisi = $this->teknisiAktif();

        if ($teknisi === null) {
            return back()->withErrors(['alat' => 'Akun ini belum terhubung ke teknisi aktif.']);
        }

        $this->peminjaman->pinjamkan(
            $tool,
            $this->peminjaman->dataPeminjamTeknisi($teknisi),
            ToolLoan::HANDLED_SELF,
            $teknisi
        );

        return back()->with('success', 'Alat ' . $tool->name . ' (' . $tool->code . ') berhasil dipinjam.');
    }

    /**
     * Pinjamkan alat ke teknisi/non-teknisi (administrator atau keeper).
     */
    public function pinjamkan(Request $request): RedirectResponse
    {
        $teknisi = $this->teknisiAktif();

        if (! $this->bolehMeminjamkan($teknisi)) {
            abort(403, 'Hanya tool keeper atau administrator yang boleh meminjamkan alat.');
        }

        $data = $request->validate([
            'tool_id'                => ['required', 'exists:tools,id'],
            'borrower_type'          => ['required', Rule::in([ToolLoan::BORROWER_TEKNISI, ToolLoan::BORROWER_NON_TEKNISI])],
            'technician_id'          => [
                'required_if:borrower_type,teknisi',
                'nullable',
                Rule::exists('technicians', 'id')->where('status', 'active'),
            ],
            'borrower_name'          => ['required_if:borrower_type,non_teknisi', 'nullable', 'string', 'max:150'],
            'borrower_department_id' => ['nullable', 'exists:departments,id'],
            'catatan'                => ['nullable', 'string', 'max:1000'],
        ]);

        $alat = Tool::query()->with('rack.location')->findOrFail($data['tool_id']);

        if ($data['borrower_type'] === ToolLoan::BORROWER_TEKNISI) {
            $peminjam = $this->peminjaman->dataPeminjamTeknisi(
                Technician::query()->active()->with('department')->findOrFail($data['technician_id'])
            );
        } else {
            $departemen = isset($data['borrower_department_id'])
                ? Department::query()->find($data['borrower_department_id'])
                : null;

            $peminjam = $this->peminjaman->dataPeminjamNonTeknisi(
                trim($data['borrower_name']),
                $departemen?->id,
                $departemen?->name
            );
        }

        $admin = (bool) $request->user()?->isAdmin();

        $this->peminjaman->pinjamkan(
            $alat,
            $peminjam,
            $admin ? ToolLoan::HANDLED_ADMIN : ToolLoan::HANDLED_KEEPER,
            $teknisi,
            $admin ? $request->user() : null,
            $data['catatan'] ?? null
        );

        return back()->with('success', 'Peminjaman ' . $alat->name . ' (' . $alat->code . ') berhasil dicatat.');
    }

    /**
     * Kembalikan satu pinjaman lewat halaman web.
     *
     * Teknisi pemilik mengajukan pengembalian; keeper/admin langsung menerima.
     */
    public function kembalikan(Request $request, ToolLoan $toolLoan): RedirectResponse
    {
        $teknisi  = $this->teknisiAktif();
        $admin    = (bool) $request->user()?->isAdmin();
        $keeper   = $this->bolehMeminjamkan($teknisi);
        $pemilik  = $teknisi !== null && (int) $toolLoan->technician_id === (int) $teknisi->id;

        if (! $admin && ! $keeper && ! $pemilik) {
            abort(403, 'Pinjaman ini bukan milik Anda.');
        }

        if ($admin || $keeper) {
            $this->peminjaman->terimaKembali(
                $toolLoan,
                $admin ? ToolLoan::HANDLED_ADMIN : ToolLoan::HANDLED_KEEPER,
                $teknisi,
                $admin ? $request->user() : null
            );

            return back()->with('success', 'Alat ' . $toolLoan->tool_name . ' (' . $toolLoan->tool_code . ') diterima kembali.');
        }

        if ($toolLoan->menungguPenerimaan()) {
            return back()->with('success', 'Pengembalian alat ini sudah menunggu diterima tool keeper.');
        }

        $this->peminjaman->mintaKembali($toolLoan, ToolLoan::HANDLED_SELF, $teknisi);

        return back()->with('success', 'Pengajuan pengembalian dikirim ke tool keeper untuk diperiksa.');
    }

    /**
     * Teknisi aktif milik akun yang sedang login.
     */
    private function teknisiAktif(): ?Technician
    {
        $pengguna = auth()->user();
        $teknisi  = $pengguna?->technician;

        return $teknisi !== null && $teknisi->status === 'active' ? $teknisi : null;
    }

    /**
     * Apakah akun berhak meminjamkan/menerima alat (admin atau keeper aktif).
     */
    private function bolehMeminjamkan(?Technician $teknisi): bool
    {
        return (bool) auth()->user()?->isAdmin() || (bool) $teknisi?->toolKeeperAktif();
    }

    /**
     * @return array{lokasi:int|null,rak:int|null,jenis:int|null,grup:int|null,status:string|null}
     */
    private function filterDariRequest(Request $request): array
    {
        $status = (string) $request->query('status', '');

        return [
            'lokasi' => (int) $request->query('lokasi', 0) ?: null,
            'rak'    => (int) $request->query('rak', 0) ?: null,
            'jenis'  => (int) $request->query('jenis', 0) ?: null,
            'grup'   => (int) $request->query('grup', 0) ?: null,
            'status' => in_array($status, ['tersedia', 'dipakai', 'rusak'], true) ? $status : null,
        ];
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Tool> $query
     * @param  array<string,mixed> $filter
     */
    private function terapkanFilter($query, array $filter): void
    {
        $query->when($filter['lokasi'] !== null, fn ($q) => $q->whereHas('rack', fn ($rak) => $rak->where('tool_location_id', $filter['lokasi'])))
            ->when($filter['rak'] !== null, fn ($q) => $q->where('tool_rack_id', $filter['rak']))
            ->when($filter['jenis'] !== null, fn ($q) => $q->whereHas('group', fn ($grup) => $grup->where('tool_type_id', $filter['jenis'])))
            ->when($filter['grup'] !== null, fn ($q) => $q->where('tool_group_id', $filter['grup']))
            ->when($filter['status'] !== null, fn ($q) => $q->where('status', $filter['status']));
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<ToolLoan> $query
     * @param  array<string,mixed> $filter
     */
    private function terapkanFilterKembali($query, array $filter): void
    {
        $query->when($filter['lokasi'] !== null, fn ($q) => $q->whereHas('tool.rack', fn ($rak) => $rak->where('tool_location_id', $filter['lokasi'])))
            ->when($filter['rak'] !== null, fn ($q) => $q->whereHas('tool', fn ($alat) => $alat->where('tool_rack_id', $filter['rak'])))
            ->when($filter['jenis'] !== null, fn ($q) => $q->whereHas('tool.group', fn ($grup) => $grup->where('tool_type_id', $filter['jenis'])))
            ->when($filter['grup'] !== null, fn ($q) => $q->whereHas('tool', fn ($alat) => $alat->where('tool_group_id', $filter['grup'])));
    }

    /**
     * Bentuk grup dari koleksi alat.
     *
     * @param  Collection<int,Tool> $alat
     * @return array<int,array<string,mixed>>
     */
    private function grupDariKoleksi(Collection $alat, ?Technician $teknisi, bool $bolehMeminjamkan, bool $admin): array
    {
        $hasil = [];

        foreach ($alat as $unit) {
            $rak    = $unit->rack;
            $lokasi = $rak?->location;
            $idLokasi = $lokasi?->id ?? 0;
            $idRak    = $rak?->id ?? 0;

            $aktif     = $unit->pinjamanAktif;
            $milikSaya = $aktif !== null
                && $teknisi !== null
                && (int) $aktif->technician_id === (int) $teknisi->id;
            $menunggu = $aktif?->menungguPenerimaan() ?? false;

            $hasil[$idLokasi] ??= ['id' => $idLokasi, 'name' => $lokasi?->name ?? 'Tanpa Lokasi', 'rak' => []];
            $hasil[$idLokasi]['rak'][$idRak] ??= ['id' => $idRak, 'name' => $rak?->name ?? 'Tanpa Rak', 'tools' => []];
            $hasil[$idLokasi]['rak'][$idRak]['tools'][] = [
                'id'                => $unit->id,
                'name'              => $unit->name,
                'group_name'        => $unit->group?->name ?? $unit->name,
                'type_name'         => $unit->group?->type?->name,
                'code'              => $unit->code,
                'status'            => $unit->status,
                'status_label'      => $menunggu ? 'Menunggu diterima' : $unit->statusLabel(),
                'menunggu_kembali'  => $menunggu,
                'foto_url'          => $unit->foto_url,
                'note'              => $unit->note,
                'peminjam'          => $aktif?->peminjamLabel(),
                'peminjam_terakhir' => $aktif === null && $unit->pinjamanTerakhir !== null
                    ? $unit->pinjamanTerakhir->peminjamLabel()
                    : null,
                'pinjaman_aktif_id' => $aktif?->id,
                'boleh_pinjam'      => $teknisi !== null && $aktif === null && $unit->status === Tool::STATUS_TERSEDIA,
                'boleh_pinjamkan'   => $bolehMeminjamkan && $aktif === null && $unit->status === Tool::STATUS_TERSEDIA,
                'boleh_kembalikan'  => $aktif !== null && ! $menunggu && ($admin || $bolehMeminjamkan || $milikSaya),
            ];
        }

        return array_values(array_map(function (array $lokasi): array {
            $lokasi['rak'] = array_values($lokasi['rak']);

            return $lokasi;
        }, $hasil));
    }

    /**
     * @param  Collection<int,ToolLoan> $daftar
     * @return array<int,array<string,mixed>>
     */
    private function grupKembaliDariKoleksi(Collection $daftar): array
    {
        $hasil = [];

        foreach ($daftar as $pinjaman) {
            $rak    = $pinjaman->tool?->rack;
            $lokasi = $rak?->location;
            $idLokasi = $lokasi?->id ?? 0;
            $idRak    = $rak?->id ?? 0;

            $hasil[$idLokasi] ??= ['id' => $idLokasi, 'name' => $lokasi?->name ?? 'Tanpa Lokasi', 'rak' => []];
            $hasil[$idLokasi]['rak'][$idRak] ??= ['id' => $idRak, 'name' => $rak?->name ?? 'Tanpa Rak', 'loans' => []];
            $hasil[$idLokasi]['rak'][$idRak]['loans'][] = [
                'id'       => $pinjaman->id,
                'tool_id'  => $pinjaman->tool_id,
                'tool'     => $pinjaman->tool_name,
                'code'     => $pinjaman->tool_code,
                'group'    => $pinjaman->tool?->group?->name ?? $pinjaman->tool_name,
                'type'     => $pinjaman->tool?->group?->type?->name,
                'foto_url' => $pinjaman->tool?->foto_url,
                'peminjam' => $pinjaman->peminjamLabel(),
                'diminta'  => $pinjaman->return_requested_at?->format('d/m/Y H:i'),
                'durasi'   => $pinjaman->durasiTeks(),
                'pengaju'  => $pinjaman->returnRequesterTechnician?->name
                    ?? $pinjaman->returnRequesterUser?->name
                    ?? $pinjaman->peminjamLabel(),
            ];
        }

        return array_values(array_map(function (array $lokasi): array {
            $lokasi['rak'] = array_values($lokasi['rak']);

            return $lokasi;
        }, $hasil));
    }

    /**
     * Pinjaman aktif satu teknisi.
     *
     * @return \Illuminate\Support\Collection<int,ToolLoan>
     */
    private function pinjamanAktifTeknisi(int $teknisiId)
    {
        return ToolLoan::query()
            ->with(['tool.rack.location'])
            ->where('technician_id', $teknisiId)
            ->aktif()
            ->orderByDesc('borrowed_at')
            ->get();
    }

    /**
     * Semua pinjaman aktif (untuk keeper/administrator).
     *
     * @return \Illuminate\Support\Collection<int,ToolLoan>
     */
    private function semuaPinjamanAktif()
    {
        return ToolLoan::query()
            ->with(['tool.rack.location', 'technician', 'borrowerDepartment'])
            ->aktif()
            ->orderByDesc('borrowed_at')
            ->limit(100)
            ->get();
    }

    /**
     * Pilihan lokasi/rak untuk modal edit alat.
     *
     * @return array<int,array<string,mixed>>
     */
    private function pilihanRak(): array
    {
        return ToolLocation::query()
            ->with(['racks' => fn ($query) => $query->orderBy('name')])
            ->orderBy('name')
            ->get()
            ->map(fn (ToolLocation $lokasi): array => [
                'id'   => $lokasi->id,
                'name' => $lokasi->name,
                'rak'  => $lokasi->racks->map(fn (ToolRack $rak): array => [
                    'id'   => $rak->id,
                    'name' => $rak->name,
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Daftar teknisi aktif dikelompokkan per Bagian.
     *
     * @return array<int,array<string,mixed>>
     */
    private function teknisiPerBagian(): array
    {
        return Technician::query()
            ->active()
            ->with('department')
            ->orderBy('name')
            ->get()
            ->groupBy(fn (Technician $teknisi): string => $teknisi->section ?: 'lainnya')
            ->map(function ($daftar, string $kode): array {
                return [
                    'kode'  => $kode,
                    'label' => Technician::SECTIONS[$kode] ?? ucfirst($kode),
                    'items' => $daftar->map(fn (Technician $teknisi): array => [
                        'id'         => $teknisi->id,
                        'name'       => $teknisi->name,
                        'nik'        => $teknisi->nik,
                        'section'    => $teknisi->section,
                        'group'      => $teknisi->group,
                        'department' => $teknisi->department?->name,
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();
    }
}
