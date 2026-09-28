<?php

namespace App\Http\Middleware;

use App\Support\AksesModul;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menegakkan hak akses halaman per Bagian.
 *
 * Setiap nama route dipetakan ke satu modul pada config/akses.php. Akun dengan
 * role admin/supervisor selalu boleh lewat; akun Bagian hanya boleh lewat bila
 * modul tersebut dicentang pada halaman "Hak Akses Bagian". Route yang tidak
 * termasuk modul mana pun (mis. logout) selalu boleh dilewati karena sudah
 * dijaga AdminMiddleware.
 */
class AksesMiddleware
{
    /**
     * Periksa izin akses halaman yang sedang diminta.
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

        $kunci = AksesModul::kunciUntukRoute($request->route()?->getName());

        // Route tanpa modul (mis. halaman login/logout) tidak dibatasi di sini.
        if ($kunci === null) {
            return $next($request);
        }

        if (AksesModul::khususAdmin($kunci) && ! $user->bolehKelolaUser()) {
            abort(403, 'Halaman ini hanya bisa diakses administrator.');
        }

        if (! $user->punyaAkses($kunci)) {
            abort(403, (string) config('akses.pesan_tolak', 'Akses ditolak.'));
        }

        return $next($request);
    }
}
