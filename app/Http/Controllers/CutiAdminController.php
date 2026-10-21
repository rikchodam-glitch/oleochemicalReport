<?php

namespace App\Http\Controllers;

use App\Models\CutiTeknisi;
use App\Models\Technician;
use App\Services\CutiService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Pencatatan cuti oleh Admin Reguler dari halaman teknisi.
 *
 * Admin Reguler hanya boleh mengelola cuti; bukan administrator penuh dan
 * bukan pengelola data teknisi. Teknisi sendiri hanya melihat cuti (Cuti Saya).
 */
class CutiAdminController extends Controller
{
    public function __construct(private readonly CutiService $cutiService)
    {
    }

    /**
     * Halaman kelola cuti satu teknisi.
     *
     * @param  Technician $technician Teknisi yang dipilih
     * @return \Illuminate\View\View
     */
    public function index(Technician $technician)
    {
        $this->pastikanBolehKelola();

        $daftar = CutiTeknisi::query()
            ->where('technician_id', $technician->id)
            ->orderByDesc('tanggal_mulai')
            ->paginate(20);

        return view('cuti.kelola', [
            'teknisi'   => $technician,
            'daftar'    => $daftar,
            'jenisCuti' => CutiTeknisi::JENIS,
        ]);
    }

    /**
     * Mencatat cuti baru untuk teknisi.
     *
     * @param  Request    $request    Request form
     * @param  Technician $technician Teknisi pemilik cuti
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request, Technician $technician)
    {
        $this->pastikanBolehKelola();

        $data = $request->validate([
            'tanggal_mulai'   => ['required', 'date'],
            'tanggal_selesai' => ['required', 'date', 'after_or_equal:tanggal_mulai'],
            'jenis'           => ['required', Rule::in(array_keys(CutiTeknisi::JENIS))],
            'alasan'          => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->cutiService->catatOlehAdmin($technician, $data);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()
            ->route('cuti.kelola.index', $technician)
            ->with('status', 'Cuti ' . $technician->name . ' berhasil dicatat.');
    }

    /**
     * Form edit cuti.
     *
     * @param  CutiTeknisi $cuti Cuti yang diubah
     * @return \Illuminate\View\View
     */
    public function edit(CutiTeknisi $cuti)
    {
        $this->pastikanBolehKelola();

        return view('cuti.kelola-edit', [
            'cuti'      => $cuti,
            'jenisCuti' => CutiTeknisi::JENIS,
        ]);
    }

    /**
     * Menyimpan perubahan cuti.
     *
     * @param  Request     $request Request form
     * @param  CutiTeknisi $cuti    Cuti yang diubah
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request, CutiTeknisi $cuti)
    {
        $this->pastikanBolehKelola();

        $data = $request->validate([
            'tanggal_mulai'   => ['required', 'date'],
            'tanggal_selesai' => ['required', 'date', 'after_or_equal:tanggal_mulai'],
            'jenis'           => ['required', Rule::in(array_keys(CutiTeknisi::JENIS))],
            'alasan'          => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->cutiService->perbarui($cuti, $data);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()
            ->route('cuti.kelola.index', $cuti->technician_id)
            ->with('status', 'Cuti berhasil diperbarui.');
    }

    /**
     * Menghapus cuti.
     *
     * @param  CutiTeknisi $cuti Cuti yang dihapus
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(CutiTeknisi $cuti)
    {
        $this->pastikanBolehKelola();

        $technicianId = $cuti->technician_id;
        $cuti->delete();

        return redirect()
            ->route('cuti.kelola.index', $technicianId)
            ->with('status', 'Cuti berhasil dihapus. Target man hour mengikuti perubahan ini.');
    }

    /**
     * Pastikan akun boleh mengelola cuti.
     *
     * @return void
     */
    private function pastikanBolehKelola(): void
    {
        if (! auth()->user()?->bolehKelolaCuti()) {
            abort(403, 'Halaman ini hanya untuk Admin Reguler atau Administrator.');
        }
    }
}
