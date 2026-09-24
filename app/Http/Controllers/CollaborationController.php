<?php

namespace App\Http\Controllers;

use App\Models\CollaborationGroup;
use App\Models\Technician;
use App\Services\CollaborationService;
use Illuminate\Http\Request;

/**
 * CollaborationController
 *
 * Halaman pengaturan kolaborasi: mengatur kelompok (mis. Grub A sampai D) dan
 * anggotanya. Ketika salah satu anggota kelompok membuat laporan di bot, anggota
 * lain pada kelompok yang sama otomatis ikut andil (lihat CollaborationService).
 */
class CollaborationController extends Controller
{
    /**
     * @param  CollaborationService  $kolaborasi
     */
    public function __construct(protected CollaborationService $kolaborasi)
    {
    }

    /**
     * Tampilkan daftar kelompok kolaborasi beserta anggotanya.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $groups = $this->kolaborasi->groups();

        // Teknisi yang belum jadi anggota kelompok ini (untuk dropdown tambah anggota).
        $semuaTeknisi = Technician::orderBy('name')->get(['id', 'name', 'nik', 'group']);

        return view('collaboration.index', compact('groups', 'semuaTeknisi'));
    }

    /**
     * Simpan kelompok kolaborasi baru.
     *
     * @param  Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'             => 'required|string|max:100',
            'code'             => 'required|string|max:50|alpha_dash|unique:collaboration_groups,code',
            'description'      => 'nullable|string|max:255',
            'sort_order'       => 'nullable|integer|min:0',
            'is_active'        => 'nullable|boolean',
            'auto_collaborate' => 'nullable|boolean',
        ], [
            'code.unique'  => 'Kode kelompok sudah dipakai, gunakan kode lain.',
            'code.alpha_dash' => 'Kode hanya boleh berisi huruf, angka, garis bawah, atau tanda hubung.',
        ]);

        $this->kolaborasi->buatGrup($validated);

        return redirect()->route('collaboration.index')
            ->with('success', 'Kelompok kolaborasi "' . $validated['name'] . '" berhasil dibuat.');
    }

    /**
     * Perbarui data kelompok kolaborasi.
     *
     * @param  Request            $request
     * @param  CollaborationGroup $group
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request, CollaborationGroup $group)
    {
        $validated = $request->validate([
            'name'             => 'required|string|max:100',
            'description'      => 'nullable|string|max:255',
            'sort_order'       => 'nullable|integer|min:0',
            'is_active'        => 'nullable|boolean',
            'auto_collaborate' => 'nullable|boolean',
        ]);

        $this->kolaborasi->perbaruiGrup($group, $validated);

        return redirect()->route('collaboration.index')
            ->with('success', 'Kelompok "' . $group->name . '" berhasil diperbarui.');
    }

    /**
     * Hapus kelompok kolaborasi.
     *
     * Laporan yang sudah dibuat tidak terpengaruh; hanya pengaturan otomasinya
     * yang hilang.
     *
     * @param  CollaborationGroup  $group
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(CollaborationGroup $group)
    {
        $nama = $group->name;

        $group->delete();

        return redirect()->route('collaboration.index')
            ->with('success', 'Kelompok "' . $nama . '" berhasil dihapus.');
    }

    /**
     * Tambah satu teknisi sebagai anggota kelompok.
     *
     * @param  Request            $request
     * @param  CollaborationGroup $group
     * @return \Illuminate\Http\RedirectResponse
     */
    public function addMember(Request $request, CollaborationGroup $group)
    {
        $validated = $request->validate([
            'technician_id' => 'required|exists:technicians,id',
        ]);

        $ditambah = $this->kolaborasi->tambahAnggota($group, (int) $validated['technician_id']);

        return redirect()->route('collaboration.index')
            ->with(
                $ditambah ? 'success' : 'error',
                $ditambah
                    ? 'Anggota berhasil ditambahkan ke kelompok "' . $group->name . '".'
                    : 'Teknisi tersebut sudah menjadi anggota kelompok ini.'
            );
    }

    /**
     * Hapus satu teknisi dari kelompok.
     *
     * @param  CollaborationGroup  $group
     * @param  Technician          $technician
     * @return \Illuminate\Http\RedirectResponse
     */
    public function removeMember(CollaborationGroup $group, Technician $technician)
    {
        $this->kolaborasi->hapusAnggota($group, (int) $technician->id);

        return redirect()->route('collaboration.index')
            ->with('success', $technician->name . ' dihapus dari kelompok "' . $group->name . '".');
    }

    /**
     * Isi anggota kelompok dari penanda grup teknisi (technicians.group).
     *
     * @param  CollaborationGroup  $group
     * @return \Illuminate\Http\RedirectResponse
     */
    public function syncMembers(CollaborationGroup $group)
    {
        $ditambah = $this->kolaborasi->selaraskanDariGrupTeknisi($group);

        return redirect()->route('collaboration.index')
            ->with('success', $ditambah > 0
                ? $ditambah . ' anggota baru ditambahkan dari grup teknisi ' . $group->code . '.'
                : 'Semua teknisi pada grup ' . $group->code . ' sudah menjadi anggota.');
    }
}
