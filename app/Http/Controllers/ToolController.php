<?php

namespace App\Http\Controllers;

use App\Models\Technician;
use App\Models\Tool;
use App\Models\ToolLocation;
use App\Models\ToolRack;
use App\Services\LocationHierarchyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Halaman Management Tools untuk administrator.
 *
 * Berisi dashboard card per Lokasi > Rak, master Lokasi/Rak, dan data
 * pendukung form Pinjamkan. Transaksi peminjaman ditangani ToolLoanController.
 */
class ToolController extends Controller
{
    /**
     * Dashboard alat: card 1:1 dikelompokkan per lokasi dan rak.
     */
    public function index(): View
    {
        $lokasi = ToolLocation::query()
            ->with([
                'racks.tools.pinjamanAktif.technician',
                'racks.tools.pinjamanAktif.borrowerDepartment',
                'racks.tools.pinjamanTerakhir.technician',
                'racks.tools.pinjamanTerakhir.borrowerDepartment',
            ])
            ->orderBy('name')
            ->get();

        $grup = $lokasi->map(function (ToolLocation $tempat): array {
            return [
                'id'   => $tempat->id,
                'name' => $tempat->name,
                'rak'  => $tempat->racks->map(function (ToolRack $rak): array {
                    return [
                        'id'    => $rak->id,
                        'name'  => $rak->name,
                        'tools' => $rak->tools->map(fn (Tool $alat): array => $this->dataKartu($alat))->values()->all(),
                    ];
                })->values()->all(),
            ];
        })->values()->all();

        $ringkasan = [
            'total'    => Tool::query()->count(),
            'tersedia' => Tool::query()->where('status', Tool::STATUS_TERSEDIA)->count(),
            'dipakai'  => Tool::query()->where('status', Tool::STATUS_DIPAKAI)->count(),
            'rusak'    => Tool::query()->where('status', Tool::STATUS_RUSAK)->count(),
        ];

        return view('tools.index', [
            'grup'          => $grup,
            'ringkasan'     => $ringkasan,
            'daftarLokasi'  => ToolLocation::query()->orderBy('name')->get(['id', 'name']),
            'daftarRak'     => ToolRack::query()->orderBy('name')->get(['id', 'tool_location_id', 'name']),
            'daftarTeknisi' => $this->teknisiPerBagian(),
            'daftarDepartemen' => app(LocationHierarchyService::class)->semuaDepartemen(),
            'bolehTulis'    => (bool) auth()->user()?->bolehMenulis(),
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
     * Simpan unit alat baru beserta fotonya.
     */
    public function simpanTool(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tool_rack_id' => ['required', 'exists:tool_racks,id'],
            'name'         => ['required', 'string', 'max:150'],
            'code'         => ['required', 'string', 'max:50', 'unique:tools,code'],
            'status'       => ['nullable', Rule::in([Tool::STATUS_TERSEDIA, Tool::STATUS_RUSAK])],
            'note'         => ['nullable', 'string', 'max:2000'],
            'photo'        => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ]);

        $foto = $request->file('photo');
        $path = $foto instanceof UploadedFile ? $foto->store('tools/photos', 'public') : null;

        Tool::create([
            'tool_rack_id' => $data['tool_rack_id'],
            'name'         => trim($data['name']),
            'code'         => strtoupper(trim($data['code'])),
            'photo_path'   => $path,
            'status'       => $data['status'] ?? Tool::STATUS_TERSEDIA,
            'note'         => $data['note'] ?? null,
        ]);

        return back()->with('success', 'Alat berhasil ditambahkan.');
    }

    /**
     * Ubah unit alat, termasuk ganti/hapus foto.
     */
    public function ubahTool(Request $request, Tool $tool): RedirectResponse
    {
        $data = $request->validate([
            'tool_rack_id' => ['required', 'exists:tool_racks,id'],
            'name'         => ['required', 'string', 'max:150'],
            'code'         => ['required', 'string', 'max:50', Rule::unique('tools', 'code')->ignore($tool->id)],
            'status'       => ['nullable', Rule::in([Tool::STATUS_TERSEDIA, Tool::STATUS_RUSAK])],
            'note'         => ['nullable', 'string', 'max:2000'],
            'photo'        => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'hapus_foto'   => ['nullable', 'boolean'],
        ]);

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
            'tool_rack_id' => $data['tool_rack_id'],
            'name'         => trim($data['name']),
            'code'         => strtoupper(trim($data['code'])),
            'photo_path'   => $path,
            'status'       => $sedangDipinjam ? Tool::STATUS_DIPAKAI : $statusBaru,
            'note'         => $data['note'] ?? null,
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

        return back()->with('success', 'Alat berhasil dihapus.');
    }

    /**
     * Bentuk data satu kartu alat untuk Blade.
     *
     * @return array<string,mixed>
     */
    private function dataKartu(Tool $alat): array
    {
        $aktif       = $alat->pinjamanAktif;
        $terakhir    = $alat->pinjamanTerakhir;

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
