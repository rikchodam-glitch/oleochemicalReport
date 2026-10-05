<?php

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\AksesMiddleware;
use App\Http\Middleware\HanyaBacaMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin'      => AdminMiddleware::class,
            'akses'      => AksesMiddleware::class,
            'hanya_baca' => HanyaBacaMiddleware::class,
        ]);

        // Percayai proxy lokal saja (cloudflared berjalan di komputer ini dan
        // meneruskan permintaan dari 127.0.0.1). Dengan begitu header
        // X-Forwarded-Proto/Host dari tunnel ikut dikenali, sehingga aplikasi
        // tahu alamat publiknya https://<domain> — mencegah halaman tampil
        // tanpa CSS (mixed content) dan mencegah redirect loop.
        $middleware->trustProxies(
            at: ['127.0.0.1', '::1'],
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PREFIX
        );

        $middleware->validateCsrfTokens(except: [
            'telegram/webhook',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*')
                // Endpoint AJAX pada halaman laporan (tambah/hapus kolaborator,
                // alat tambahan, unggah foto) dipanggil dengan fetch dan butuh
                // balasan JSON termasuk saat VALIDASI gagal. Tanpa pengecualian
                // ini, galat validasi memutar halaman (302 + HTML) sehingga
                // JavaScript-nya bingung membaca jawaban server.
                || ($request->expectsJson() && $request->is('reports/*')),
        );
    })->create();
