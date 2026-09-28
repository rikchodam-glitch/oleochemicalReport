<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gerbang masuk panel admin.
 *
 * Memastikan pengguna sudah login dan akunnya punya role yang dikenali
 * (admin, supervisor, atau kode Bagian). Pembatasan halaman per Bagian
 * dilakukan AksesMiddleware, jadi middleware ini hanya menjaga autentikasi.
 */
class AdminMiddleware
{
    /**
     * Periksa autentikasi dan kelengkapan role akun.
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

        // Akun tanpa role tidak diberi akses apa pun.
        if (trim((string) $user->role) === '') {
            abort(403, 'Akun Anda belum memiliki Bagian. Hubungi administrator.');
        }

        return $next($request);
    }
}
