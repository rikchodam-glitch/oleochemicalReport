<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Membatasi akun non-administrator menjadi hanya baca (read only).
 *
 * Semua permintaan yang mengubah data (POST/PUT/PATCH/DELETE) ditolak, kecuali:
 * - route yang memang bebas (mis. logout) atau route yang tidak mengubah data
 *   (mis. menandai notifikasi sudah dibaca) sesuai config('akses.route_hanya_baca_bebas');
 * - route yang termasuk kemampuan tulis sebagian group akun (mis. Staff Grub
 *   boleh mengelola laporan + import PO Spare Part + request Spare Part +
 *   sync data CM) sesuai config('akses.tulis_grup') dan
 *   config('akses.tulis_kemampuan').
 * Halaman yang boleh DIBUKA tetap diatur per Bagian lewat AksesMiddleware, dan
 * batas per-baris (hanya laporan grub yang dinaungi) ditegakkan di controller.
 */
class HanyaBacaMiddleware
{
    /**
     * Metode HTTP yang tidak mengubah data.
     */
    private const METODE_BACA = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * Periksa apakah akun ini boleh memakai metode tulis.
     *
     * @param  Request  $request Request yang masuk
     * @param  Closure  $next    Rantai middleware berikutnya
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user === null) {
            return redirect('/login');
        }

        // Akun administrator (dan role penulis lain) bebas.
        if ($user->bolehMenulis()) {
            return $next($request);
        }

        if (in_array($request->method(), self::METODE_BACA, true)) {
            return $next($request);
        }

        $namaRoute = (string) $request->route()?->getName();
        $bebas     = (array) config('akses.route_hanya_baca_bebas', []);

        if ($namaRoute !== '' && in_array($namaRoute, $bebas, true)) {
            return $next($request);
        }

        // Izin tulis sebagian (mis. Staff Grub hanya boleh mengelola laporan
        // grubnya + import PO Spare Part + sync data CM).
        if ($user->bolehMenulisRoute($namaRoute)) {
            return $next($request);
        }

        $pesan = $user->kemampuanTulis() === []
            ? (string) config('akses.pesan_hanya_baca', 'Akun Anda hanya bisa melihat data.')
            : (string) config('akses.pesan_tulis_sebagian', config('akses.pesan_hanya_baca'));

        abort(403, $pesan);
    }
}
