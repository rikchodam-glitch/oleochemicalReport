<?php

namespace App\Http\Controllers;

use App\Models\CutiTeknisi;
use App\Models\Technician;
use Illuminate\View\View;

/**
 * Halaman cuti milik teknisi yang sudah login (hanya melihat).
 *
 * Pencatatan/edit/hapus cuti dilakukan Admin Reguler dari halaman teknisi.
 */
class CutiSayaController extends Controller
{
    /**
     * Daftar cuti milik pengguna yang sedang login.
     *
     * @return View
     */
    public function index(): View
    {
        $teknisi = $this->teknisi();

        $daftar = CutiTeknisi::query()
            ->where('technician_id', $teknisi->id)
            ->orderByDesc('tanggal_mulai')
            ->paginate(15);

        return view('cuti.saya-index', [
            'teknisi' => $teknisi,
            'daftar'  => $daftar,
        ]);
    }

    /**
     * Teknisi pemilik akun yang sedang login.
     *
     * @return Technician
     */
    private function teknisi(): Technician
    {
        $teknisi = auth()->user()?->technician;

        if ($teknisi === null) {
            abort(403, 'Akun ini belum terhubung ke data teknisi. Hubungi administrator.');
        }

        return $teknisi;
    }
}
