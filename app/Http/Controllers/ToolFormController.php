<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Technician;
use App\Models\Tool;
use App\Models\ToolLoan;
use App\Models\ToolLocation;
use App\Models\ToolRack;
use App\Models\ToolToken;
use App\Services\LocationHierarchyService;
use App\Services\ToolLendingService;
use App\Services\ToolTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Form publik berbasis token untuk keeper dan teknisi.
 *
 * Identitas selalu diambil dari token, bukan dari isian form. Token keeper
 * boleh dipakai beberapa transaksi sampai kedaluwarsa; token teknisi sekali
 * pakai dan ditandai terpakai setelah transaksi berhasil.
 */
class ToolFormController extends Controller
{
    public function __construct(
        private ToolTokenService $token,
        private ToolLendingService $peminjaman
    ) {
    }

    /**
     * Tampilkan form sesuai purpose token.
     */
    public function show(string $token): View|Response
    {
        $model = $this->token->tokenValid($token);

        if ($model === null) {
            return response()->view('tools.form-kadaluarsa', ['pesan' => 'Tautan tidak valid atau sudah kedaluwarsa.'], 410);
        }

        $pemilik = $this->token->pemilikSah($model);

        if ($pemilik === null) {
            return response()->view('tools.form-kadaluarsa', [
                'pesan' => 'Akun pemilik tautan tidak aktif atau bukan tool keeper lagi.',
            ], 410);
        }

        $this->token->tandaiDibuka($model);

        return view('tools.form', $this->dataForm($model, $pemilik, $token));
    }

    /**
     * Simpan peminjaman dari form token.
     */
    public function pinjam(Request $request, string $token): RedirectResponse
    {
        $model = $this->token->tokenValid($token);

        if ($model === null || ! in_array($model->purpose, [
            ToolToken::PURPOSE_KEEPER_PINJAM,
            ToolToken::PURPOSE_TEKNISI_PINJAM,
        ], true)) {
            return redirect()->route('tools.form', ['token' => $token])
                ->withErrors(['token' => 'Tautan tidak valid atau sudah kedaluwarsa.']);
        }

        $pemilik = $this->token->pemilikSah($model);

        if ($pemilik === null) {
            return redirect()->route('tools.form', ['token' => $token])
                ->withErrors(['token' => 'Akun pemilik tautan tidak lagi berhak memakai form ini.']);
        }

        $aturan = [
            'tool_id' => ['required', 'exists:tools,id'],
        ];

        if ($model->purpose === ToolToken::PURPOSE_KEEPER_PINJAM) {
            $aturan = array_merge($aturan, [
                'borrower_type'          => ['required', 'in:teknisi,non_teknisi'],
                'technician_id'          => [
                    'required_if:borrower_type,teknisi',
                    'nullable',
                    Rule::exists('technicians', 'id')->where('status', 'active'),
                ],
                'borrower_name'          => ['required_if:borrower_type,non_teknisi', 'nullable', 'string', 'max:150'],
                'borrower_department_id' => ['nullable', 'exists:departments,id'],
            ]);
        }

        $data = $request->validate($aturan);
        $alat  = Tool::query()->with('rack.location')->findOrFail($data['tool_id']);

        if ($model->purpose === ToolToken::PURPOSE_KEEPER_PINJAM) {
            if ($data['borrower_type'] === ToolLoan::BORROWER_TEKNISI) {
                $teknisi  = Technician::query()->active()->with('department')->findOrFail($data['technician_id']);
                $peminjam = $this->peminjaman->dataPeminjamTeknisi($teknisi);
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

            $this->peminjaman->pinjamkan($alat, $peminjam, ToolLoan::HANDLED_KEEPER, $pemilik);

            return redirect()->route('tools.form', ['token' => $token])
                ->with('success', 'Peminjaman ' . $alat->name . ' (' . $alat->code . ') berhasil dicatat.');
        }

        $this->peminjaman->pinjamkan(
            $alat,
            $this->peminjaman->dataPeminjamTeknisi($pemilik),
            ToolLoan::HANDLED_SELF,
            $pemilik
        );

        $this->token->tandaiTerpakai($model);

        return redirect()->route('tools.form.selesai')->with('hasil', [
            'judul'  => 'Peminjaman berhasil',
            'pesan'  => 'Alat ' . $alat->name . ' (' . $alat->code . ') tercatat dipinjam.',
            'kode'   => null,
        ]);
    }

    /**
     * Kembalikan satu pinjaman dari form token.
     */
    public function kembalikan(Request $request, string $token, ToolLoan $toolLoan): RedirectResponse
    {
        $model = $this->token->tokenValid($token);

        if ($model === null || ! in_array($model->purpose, [
            ToolToken::PURPOSE_KEEPER_KEMBALI,
            ToolToken::PURPOSE_TEKNISI_KEMBALI,
        ], true)) {
            return redirect()->route('tools.form', ['token' => $token])
                ->withErrors(['token' => 'Tautan tidak valid atau sudah kedaluwarsa.']);
        }

        $pemilik = $this->token->pemilikSah($model);

        if ($pemilik === null) {
            return redirect()->route('tools.form', ['token' => $token])
                ->withErrors(['token' => 'Akun pemilik tautan tidak lagi berhak memakai form ini.']);
        }

        if ($model->purpose === ToolToken::PURPOSE_TEKNISI_KEMBALI
            && (int) $toolLoan->technician_id !== (int) $pemilik->id) {
            abort(403, 'Pinjaman ini bukan milik Anda.');
        }

        if ($toolLoan->returned_at !== null) {
            return redirect()->route('tools.form', ['token' => $token])
                ->with('success', 'Pinjaman ini sudah dikembalikan sebelumnya.');
        }

        $jenis = $model->purpose === ToolToken::PURPOSE_KEEPER_KEMBALI
            ? ToolLoan::HANDLED_KEEPER
            : ToolLoan::HANDLED_SELF;

        $this->peminjaman->kembalikan($toolLoan, $jenis, $pemilik);

        if ($model->purpose === ToolToken::PURPOSE_TEKNISI_KEMBALI) {
            $this->token->tandaiTerpakai($model);

            return redirect()->route('tools.form.selesai')->with('hasil', [
                'judul' => 'Pengembalian berhasil',
                'pesan' => 'Alat ' . $toolLoan->tool_name . ' (' . $toolLoan->tool_code . ') sudah dikembalikan.',
                'kode'  => $toolLoan->kode,
            ]);
        }

        return redirect()->route('tools.form', ['token' => $token])
            ->with('success', 'Alat ' . $toolLoan->tool_name . ' (' . $toolLoan->tool_code . ') sudah dikembalikan.');
    }

    /**
     * Kembalikan semua pinjaman teknisi (khusus token teknisi).
     */
    public function kembalikanSemua(Request $request, string $token): RedirectResponse
    {
        $model = $this->token->tokenValid($token, ToolToken::PURPOSE_TEKNISI_KEMBALI);

        if ($model === null) {
            return redirect()->route('tools.form', ['token' => $token])
                ->withErrors(['token' => 'Tautan tidak valid atau sudah kedaluwarsa.']);
        }

        $pemilik = $this->token->pemilikSah($model);

        if ($pemilik === null) {
            return redirect()->route('tools.form', ['token' => $token])
                ->withErrors(['token' => 'Akun pemilik tautan tidak lagi berhak memakai form ini.']);
        }

        $jumlah = $this->peminjaman->kembalikanSemua($pemilik, ToolLoan::HANDLED_SELF);
        $this->token->tandaiTerpakai($model);

        return redirect()->route('tools.form.selesai')->with('hasil', [
            'judul' => 'Pengembalian berhasil',
            'pesan' => $jumlah . ' pinjaman alat sudah dikembalikan.',
            'kode'  => null,
        ]);
    }

    /**
     * Halaman selesai setelah transaksi token sekali pakai.
     */
    public function selesai(): View
    {
        return view('tools.form-selesai', [
            'hasil' => session('hasil', []),
        ]);
    }

    /**
     * Susun data yang dibutuhkan view form.
     *
     * @return array<string,mixed>
     */
    private function dataForm(ToolToken $model, Technician $pemilik, string $asli): array
    {
        $data = [
            'token'      => $model,
            'asli'       => $asli,
            'pemilik'    => $pemilik,
            'purpose'    => $model->purpose,
            'modeKeeper' => $model->milikKeeper(),
            'kadaluarsa' => $model->expires_at,
        ];

        if (in_array($model->purpose, [
            ToolToken::PURPOSE_KEEPER_PINJAM,
            ToolToken::PURPOSE_TEKNISI_PINJAM,
        ], true)) {
            $data['grup']              = $this->grupAlat();
            $data['prefillToolId']     = $model->prefill_tool_id;
            $data['daftarTeknisi']     = $model->purpose === ToolToken::PURPOSE_KEEPER_PINJAM
                ? $this->teknisiPerBagian()
                : [];
            $data['daftarDepartemen']  = app(LocationHierarchyService::class)->semuaDepartemen();

            return $data;
        }

        $daftar = ToolLoan::query()
            ->with(['tool.rack.location', 'technician', 'borrowerDepartment'])
            ->when($model->purpose === ToolToken::PURPOSE_TEKNISI_KEMBALI, function ($query) use ($pemilik): void {
                $query->where('technician_id', $pemilik->id);
            })
            ->aktif()
            ->orderByDesc('borrowed_at')
            ->get();

        $data['daftarPinjaman'] = $daftar;

        return $data;
    }

    /**
     * Data card alat dikelompokkan per Lokasi > Rak untuk form pinjam.
     *
     * @return array<int,array<string,mixed>>
     */
    private function grupAlat(): array
    {
        return ToolLocation::query()
            ->with([
                'racks.tools.pinjamanAktif.technician',
                'racks.tools.pinjamanAktif.borrowerDepartment',
                'racks.tools.pinjamanTerakhir.technician',
                'racks.tools.pinjamanTerakhir.borrowerDepartment',
            ])
            ->orderBy('name')
            ->get()
            ->map(function (ToolLocation $lokasi): array {
                return [
                    'id'   => $lokasi->id,
                    'name' => $lokasi->name,
                    'rak'  => $lokasi->racks->map(function (ToolRack $rak): array {
                        return [
                            'id'    => $rak->id,
                            'name'  => $rak->name,
                            'tools' => $rak->tools->map(function (Tool $alat): array {
                                $aktif    = $alat->pinjamanAktif;
                                $terakhir = $alat->pinjamanTerakhir;

                                return [
                                    'id'                => $alat->id,
                                    'name'              => $alat->name,
                                    'code'              => $alat->code,
                                    'status'            => $alat->status,
                                    'status_label'      => $alat->statusLabel(),
                                    'foto_url'          => $alat->foto_url,
                                    'note'              => $alat->note,
                                    'peminjam'          => $aktif?->peminjamLabel(),
                                    'peminjam_terakhir' => $aktif === null && $terakhir !== null ? $terakhir->peminjamLabel() : null,
                                    'bisa_dipinjam'     => $alat->status === Tool::STATUS_TERSEDIA,
                                ];
                            })->values()->all(),
                        ];
                    })->values()->all(),
                ];
            })
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
                        'department' => $teknisi->department?->name,
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();
    }
}
