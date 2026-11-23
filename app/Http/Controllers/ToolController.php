<?php

namespace App\Http\Controllers;

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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Halaman Management Tools untuk administrator.
 *
 * Berisi dashboard card kompak per Lokasi > Rak, master Lokasi/Rak,
 * Jenis/Kelompok, pengajuan pengembalian, dan data pendukung form Pinjamkan.
 */
class ToolController extends Controller
{
    public function __construct(
        private PencarianToolService $pencarian,
        private ToolLendingService $peminjaman
    ) {
    }

    /**
     * Dashboard alat: card kompak dikelompokkan per lokasi dan rak.
     */
    public function index(Request $request): View
    {
        $kataKunci = trim((string) $request->query('search', $request->query('q', '')));
        $filter    = $this->filterDariRequest($request);

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
        $grup   = $this->grupDariKoleksi($daftar->getCollection());

        return view('tools.index', [
            'grup'             => $grup,
            'daftar'           => $daftar,
            'kataKunci'        => $kataKunci,
            'filter'           => $filter,
            'ringkasan'        => $this->ringkasan(),
            'jumlahKembali'    => ToolLoan::query()->menungguKembali()->count(),
            'daftarLokasi'     => ToolLocation::query()->orderBy('name')->get(['id', 'name']),
            'daftarRak'        => ToolRack::query()->with('location')->orderBy('name')->get(),
            'daftarJenis'      => ToolType::query()->withCount('groups')->orderBy('name')->get(),
            'daftarKelompok'   => ToolGroup::query()->with('type')->withCount('tools')->orderBy('name')->get(),
            'daftarTeknisi'    => $this->teknisiPerBagian(),
            'daftarDepartemen' => app(LocationHierarchyService::class)->semuaDepartemen(),
            'bolehTulis'       => (bool) auth()->user()?->bolehMenulis(),
        ]);
    }

    /**
     * Halaman master lokasi dan rak.
     */
    public function lokasiRak(Request $request): View
    {
        $daftarLokasi = ToolLocation::query()
            ->withCount('racks')
            ->orderBy('name')
            ->get();

        $terpilih = null;

        if ($daftarLokasi->isNotEmpty()) {
            $id = (int) $request->query('lokasi', $daftarLokasi->first()->id);
            $terpilih = $daftarLokasi->firstWhere('id', $id) ?? $daftarLokasi->first();
        }

        if ($terpilih !== null) {
            $terpilih->load(['racks' => fn ($query) => $query->withCount('tools')]);
        }

        return view('tools.lokasi-rak', [
            'daftarLokasi' => $daftarLokasi,
            'terpilih'     => $terpilih,
            'bolehTulis'   => (bool) auth()->user()?->bolehMenulis(),
        ]);
    }

    /**
     * Halaman master jenis dan kelompok alat.
     */
    public function katalog(): View
    {
        return view('tools.katalog', [
            'daftarJenis'    => ToolType::query()->withCount('groups')->orderBy('name')->get(),
            'daftarKelompok' => ToolGroup::query()->with('type')->withCount('tools')->orderBy('name')->get(),
            'bolehTulis'     => (bool) auth()->user()?->bolehMenulis(),
        ]);
    }

    /**
     * Halaman Barang Kembali: pengajuan yang belum diterima keeper.
     */
    public function returns(Request $request): View
    {
        $kataKunci = trim((string) $request->query('search', ''));
        $filter    = $this->filterDariRequest($request);

        $query = ToolLoan::query()
            ->menungguKembali()
            ->with([
                'tool.group.type',
                'tool.rack.location',
                'technician',
                'borrowerDepartment',
                'returnRequesterTechnician',
            ]);

        if ($kataKunci !== '') {
            $query->where(function ($sub) use ($kataKunci): void {
                $pola = '%' . $kataKunci . '%';
                $sub->where('tool_name', 'like', $pola)
                    ->orWhere('tool_code', 'like', $pola)
                    ->orWhere('borrower_name', 'like', $pola)
                    ->orWhereHas('technician', fn ($t) => $t->where('name', 'like', $pola)->orWhere('nik', 'like', $pola));
            });
        }

        if ($filter['lokasi'] !== null) {
            $query->whereHas('tool.rack', fn ($rak) => $rak->where('tool_location_id', $filter['lokasi']));
        }

        if ($filter['rak'] !== null) {
            $query->whereHas('tool', fn ($alat) => $alat->where('tool_rack_id', $filter['rak']));
        }

        if ($filter['jenis'] !== null) {
            $query->whereHas('tool.group', fn ($grup) => $grup->where('tool_type_id', $filter['jenis']));
        }

        if ($filter['grup'] !== null) {
            $query->whereHas('tool', fn ($alat) => $alat->where('tool_group_id', $filter['grup']));
        }

        $daftar = $query->orderByDesc('return_requested_at')->paginate(36)->withQueryString();

        return view('tools.returns', [
            'daftar'        => $daftar,
            'grup'          => $this->grupKembaliDariKoleksi($daftar->getCollection()),
            'kataKunci'     => $kataKunci,
            'filter'        => $filter,
            'bolehTulis'    => (bool) auth()->user()?->bolehMenulis(),
            'tampilTabs'    => true,
            'namaRuteTerima'=> 'tools.returns.terima',
            'daftarLokasi'  => ToolLocation::query()->orderBy('name')->get(['id', 'name']),
            'daftarJenis'   => ToolType::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Terima pengembalian dari halaman admin.
     */
    public function terimaKembali(Request $request, ToolLoan $toolLoan): RedirectResponse
    {
        $this->peminjaman->terimaKembali($toolLoan, ToolLoan::HANDLED_ADMIN, null, $request->user());

        return back()->with('success', 'Alat ' . $toolLoan->tool_name . ' (' . $toolLoan->tool_code . ') diterima kembali.');
    }

    /**
     * Simpan lokasi baru.
     */
    public function simpanLokasi(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:150', 'unique:tool_locations,name'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active'   => ['nullable', 'boolean'],
        ]);

        ToolLocation::create([
            'name'        => trim($data['name']),
            'description' => $data['description'] ?? null,
            'is_active'   => (bool) ($data['is_active'] ?? true),
        ]);

        return back()->with('success', 'Lokasi alat berhasil ditambahkan.');
    }

    /**
     * Ubah lokasi.
     */
    public function ubahLokasi(Request $request, ToolLocation $toolLocation): RedirectResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:150', Rule::unique('tool_locations', 'name')->ignore($toolLocation->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active'   => ['nullable', 'boolean'],
        ]);

        $toolLocation->update([
            'name'        => trim($data['name']),
            'description' => $data['description'] ?? null,
            'is_active'   => (bool) ($data['is_active'] ?? false),
        ]);

        return back()->with('success', 'Lokasi alat berhasil diperbarui.');
    }

    /**
     * Hapus lokasi bila belum punya rak.
     */
    public function hapusLokasi(ToolLocation $toolLocation): RedirectResponse
    {
        if ($toolLocation->racks()->exists()) {
            return back()->withErrors(['lokasi' => 'Lokasi masih punya rak. Pindahkan atau hapus raknya lebih dulu.']);
        }

        $toolLocation->delete();

        return back()->with('success', 'Lokasi alat berhasil dihapus.');
    }

    /**
     * Simpan rak baru pada satu lokasi.
     */
    public function simpanRak(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tool_location_id' => ['required', 'exists:tool_locations,id'],
            'name'             => ['required', 'string', 'max:150'],
            'description'      => ['nullable', 'string', 'max:2000'],
        ]);

        $sudahAda = ToolRack::query()
            ->where('tool_location_id', $data['tool_location_id'])
            ->where('name', trim($data['name']))
            ->exists();

        if ($sudahAda) {
            return back()->withErrors(['name' => 'Nama rak sudah dipakai di lokasi ini.']);
        }

        ToolRack::create([
            'tool_location_id' => $data['tool_location_id'],
            'name'             => trim($data['name']),
            'description'      => $data['description'] ?? null,
        ]);

        return redirect()
            ->route('tools.locations.index', ['lokasi' => $data['tool_location_id']])
            ->with('success', 'Rak berhasil ditambahkan.');
    }

    /**
     * Ubah rak.
     */
    public function ubahRak(Request $request, ToolRack $toolRack): RedirectResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $sudahAda = ToolRack::query()
            ->where('tool_location_id', $toolRack->tool_location_id)
            ->where('name', trim($data['name']))
            ->whereKeyNot($toolRack->id)
            ->exists();

        if ($sudahAda) {
            return back()->withErrors(['name' => 'Nama rak sudah dipakai di lokasi ini.']);
        }

        $toolRack->update([
            'name'        => trim($data['name']),
            'description' => $data['description'] ?? null,
        ]);

        return back()->with('success', 'Rak berhasil diperbarui.');
    }

    /**
     * Hapus rak bila belum berisi alat.
     */
    public function hapusRak(ToolRack $toolRack): RedirectResponse
    {
        if ($toolRack->tools()->exists()) {
            return back()->withErrors(['rak' => 'Rak masih berisi alat. Pindahkan atau hapus alatnya lebih dulu.']);
        }

        $lokasiId = $toolRack->tool_location_id;
        $toolRack->delete();

        return redirect()
            ->route('tools.locations.index', ['lokasi' => $lokasiId])
            ->with('success', 'Rak berhasil dihapus.');
    }

    /**
     * Simpan jenis alat baru.
     */
    public function simpanJenis(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:100', 'unique:tool_types,name'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active'   => ['nullable', 'boolean'],
        ]);

        ToolType::create([
            'name'        => trim($data['name']),
            'description' => $data['description'] ?? null,
            'is_active'   => (bool) ($data['is_active'] ?? true),
        ]);

        return back()->with('success', 'Jenis alat berhasil ditambahkan.');
    }

    /**
     * Ubah jenis alat.
     */
    public function ubahJenis(Request $request, ToolType $toolType): RedirectResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:100', Rule::unique('tool_types', 'name')->ignore($toolType->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active'   => ['nullable', 'boolean'],
        ]);

        $toolType->update([
            'name'        => trim($data['name']),
            'description' => $data['description'] ?? null,
            'is_active'   => (bool) ($data['is_active'] ?? false),
        ]);

        return back()->with('success', 'Jenis alat berhasil diperbarui.');
    }

    /**
     * Hapus jenis bila belum dipakai kelompok.
     */
    public function hapusJenis(ToolType $toolType): RedirectResponse
    {
        if ($toolType->groups()->exists()) {
            return back()->withErrors(['jenis' => 'Jenis masih dipakai kelompok. Pindahkan kelompoknya lebih dulu.']);
        }

        $toolType->delete();

        return back()->with('success', 'Jenis alat berhasil dihapus.');
    }

    /**
     * Simpan kelompok alat baru.
     */
    public function simpanKelompok(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tool_type_id' => ['nullable', 'exists:tool_types,id'],
            'name'         => ['required', 'string', 'max:150', 'unique:tool_groups,name'],
            'description'  => ['nullable', 'string', 'max:2000'],
        ]);

        ToolGroup::create([
            'tool_type_id' => $data['tool_type_id'] ?? null,
            'name'         => trim($data['name']),
            'description'  => $data['description'] ?? null,
        ]);

        return back()->with('success', 'Kelompok alat berhasil ditambahkan.');
    }

    /**
     * Ubah kelompok alat dan samakan nama unit agar pencarian tetap konsisten.
     */
    public function ubahKelompok(Request $request, ToolGroup $toolGroup): RedirectResponse
    {
        $data = $request->validate([
            'tool_type_id' => ['nullable', 'exists:tool_types,id'],
            'name'         => ['required', 'string', 'max:150', Rule::unique('tool_groups', 'name')->ignore($toolGroup->id)],
            'description'  => ['nullable', 'string', 'max:2000'],
        ]);

        $toolGroup->update([
            'tool_type_id' => $data['tool_type_id'] ?? null,
            'name'         => trim($data['name']),
            'description'  => $data['description'] ?? null,
        ]);

        $toolGroup->tools()->update(['name' => trim($data['name'])]);

        return back()->with('success', 'Kelompok alat berhasil diperbarui.');
    }

    /**
     * Hapus kelompok bila belum berisi unit.
     */
    public function hapusKelompok(ToolGroup $toolGroup): RedirectResponse
    {
        if ($toolGroup->tools()->exists()) {
            return back()->withErrors(['kelompok' => 'Kelompok masih berisi unit alat. Pindahkan atau hapus unitnya lebih dulu.']);
        }

        $toolGroup->delete();

        return back()->with('success', 'Kelompok alat berhasil dihapus.');
    }

    /**
     * Simpan unit alat baru beserta fotonya.
     */
    public function simpanTool(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tool_rack_id'  => ['required', 'exists:tool_racks,id'],
            'tool_group_id' => ['required', 'exists:tool_groups,id'],
            'code'          => ['required', 'string', 'max:50', 'unique:tools,code'],
            'status'        => ['nullable', Rule::in([Tool::STATUS_TERSEDIA, Tool::STATUS_RUSAK])],
            'note'          => ['nullable', 'string', 'max:2000'],
            'photo'         => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ]);

        $grup = ToolGroup::query()->findOrFail($data['tool_group_id']);
        $foto = $request->file('photo');
        $path = $foto instanceof UploadedFile ? $foto->store('tools/photos', 'public') : null;

        Tool::create([
            'tool_rack_id'  => $data['tool_rack_id'],
            'tool_group_id' => $grup->id,
            'name'          => $grup->name,
            'code'          => strtoupper(trim($data['code'])),
            'photo_path'    => $path,
            'status'        => $data['status'] ?? Tool::STATUS_TERSEDIA,
            'note'          => $data['note'] ?? null,
        ]);

        return back()->with('success', 'Alat berhasil ditambahkan.');
    }

    /**
     * Ubah unit alat, termasuk ganti/hapus foto dan kelompok.
     */
    public function ubahTool(Request $request, Tool $tool): RedirectResponse
    {
        $data = $request->validate([
            'tool_rack_id'  => ['required', 'exists:tool_racks,id'],
            'tool_group_id' => ['required', 'exists:tool_groups,id'],
            'code'          => ['required', 'string', 'max:50', Rule::unique('tools', 'code')->ignore($tool->id)],
            'status'        => ['nullable', Rule::in([Tool::STATUS_TERSEDIA, Tool::STATUS_RUSAK])],
            'note'          => ['nullable', 'string', 'max:2000'],
            'photo'         => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'hapus_foto'    => ['nullable', 'boolean'],
        ]);

        $grup           = ToolGroup::query()->findOrFail($data['tool_group_id']);
        $sedangDipinjam = $tool->loans()->whereNull('returned_at')->exists();
        $statusBaru     = $data['status'] ?? Tool::STATUS_TERSEDIA;

        if ($sedangDipinjam && $statusBaru === Tool::STATUS_RUSAK) {
            return back()->withErrors(['status' => 'Alat sedang dipinjam; tandai rusak setelah dikembalikan.']);
        }

        $path = $tool->photo_path;

        if ($request->boolean('hapus_foto') && $path !== null) {
            Storage::disk('public')->delete($path);
            $path = null;
        }

        $foto = $request->file('photo');

        if ($foto instanceof UploadedFile) {
            if ($path !== null) {
                Storage::disk('public')->delete($path);
            }

            $path = $foto->store('tools/photos', 'public');
        }

        $tool->update([
            'tool_rack_id'  => $data['tool_rack_id'],
            'tool_group_id' => $grup->id,
            'name'          => $grup->name,
            'code'          => strtoupper(trim($data['code'])),
            'photo_path'    => $path,
            'status'        => $sedangDipinjam ? Tool::STATUS_DIPAKAI : $statusBaru,
            'note'          => $data['note'] ?? null,
        ]);

        return back()->with('success', 'Alat berhasil diperbarui.');
    }

    /**
     * Hapus unit alat yang tidak sedang dipinjam.
     */
    public function hapusTool(Tool $tool): RedirectResponse
    {
        if ($tool->loans()->whereNull('returned_at')->exists()) {
            return back()->withErrors(['alat' => 'Alat sedang dipinjam dan belum bisa dihapus.']);
        }

        $tool->delete();

        return redirect()->route('tools.index')->with('success', 'Alat berhasil dihapus.');
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
     * Terapkan filter lokasi/rak/jenis/kelompok/status ke query alat.
     *
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
     * @return array<string,int>
     */
    private function ringkasan(): array
    {
        return [
            'total'    => Tool::query()->count(),
            'tersedia' => Tool::query()->where('status', Tool::STATUS_TERSEDIA)->count(),
            'dipakai'  => Tool::query()->where('status', Tool::STATUS_DIPAKAI)->count(),
            'rusak'    => Tool::query()->where('status', Tool::STATUS_RUSAK)->count(),
            'kembali'  => ToolLoan::query()->menungguKembali()->count(),
        ];
    }

    /**
     * Bentuk grup dari koleksi alat (halaman/paginator).
     *
     * @param  Collection<int,Tool> $alat
     * @return array<int,array<string,mixed>>
     */
    private function grupDariKoleksi(Collection $alat): array
    {
        $hasil = [];

        foreach ($alat as $unit) {
            $rak    = $unit->rack;
            $lokasi = $rak?->location;
            $idLokasi = $lokasi?->id ?? 0;
            $idRak    = $rak?->id ?? 0;

            $hasil[$idLokasi] ??= ['id' => $idLokasi, 'name' => $lokasi?->name ?? 'Tanpa Lokasi', 'rak' => []];
            $hasil[$idLokasi]['rak'][$idRak] ??= ['id' => $idRak, 'name' => $rak?->name ?? 'Tanpa Rak', 'tools' => []];
            $hasil[$idLokasi]['rak'][$idRak]['tools'][] = $this->dataKartu($unit);
        }

        return array_values(array_map(function (array $lokasi): array {
            $lokasi['rak'] = array_values($lokasi['rak']);

            return $lokasi;
        }, $hasil));
    }

    /**
     * Bentuk grup pengajuan pengembalian per lokasi/rak.
     *
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
            $hasil[$idLokasi]['rak'][$idRak]['loans'][] = $this->dataKembali($pinjaman);
        }

        return array_values(array_map(function (array $lokasi): array {
            $lokasi['rak'] = array_values($lokasi['rak']);

            return $lokasi;
        }, $hasil));
    }

    /**
     * @return array<string,mixed>
     */
    private function dataKembali(ToolLoan $pinjaman): array
    {
        return [
            'id'          => $pinjaman->id,
            'tool_id'     => $pinjaman->tool_id,
            'tool'        => $pinjaman->tool_name,
            'code'        => $pinjaman->tool_code,
            'group'       => $pinjaman->tool?->group?->name ?? $pinjaman->tool_name,
            'type'        => $pinjaman->tool?->group?->type?->name,
            'foto_url'    => $pinjaman->tool?->foto_url,
            'peminjam'    => $pinjaman->peminjamLabel(),
            'diminta'     => $pinjaman->return_requested_at?->format('d/m/Y H:i'),
            'durasi'      => $pinjaman->durasiTeks(),
            'pengaju'     => $pinjaman->returnRequesterTechnician?->name
                ?? $pinjaman->returnRequesterUser?->name
                ?? $pinjaman->peminjamLabel(),
        ];
    }

    /**
     * Bentuk data satu kartu alat untuk Blade.
     *
     * @return array<string,mixed>
     */
    private function dataKartu(Tool $alat): array
    {
        $aktif    = $alat->pinjamanAktif;
        $terakhir = $alat->pinjamanTerakhir;
        $menunggu = $aktif?->menungguPenerimaan() ?? false;

        return [
            'id'                => $alat->id,
            'name'              => $alat->name,
            'group_name'        => $alat->group?->name ?? $alat->name,
            'type_name'         => $alat->group?->type?->name,
            'group_id'          => $alat->tool_group_id,
            'jenis_id'          => $alat->group?->tool_type_id,
            'code'              => $alat->code,
            'status'            => $alat->status,
            'status_label'      => $menunggu ? 'Menunggu diterima' : $alat->statusLabel(),
            'menunggu_kembali'  => $menunggu,
            'foto_url'          => $alat->foto_url,
            'note'              => $alat->note,
            'peminjam'          => $aktif?->peminjamLabel(),
            'peminjam_terakhir' => $aktif === null && $terakhir !== null ? $terakhir->peminjamLabel() : null,
            'pinjaman_aktif_id' => $aktif?->id,
            'bisa_dihapus'      => $aktif === null,
        ];
    }

    /**
     * Daftar teknisi aktif dikelompokkan per Bagian untuk pemilih peminjam.
     *
     * @return array<int,array{kode:string,label:string,items:array<int,array<string,mixed>>}>
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
