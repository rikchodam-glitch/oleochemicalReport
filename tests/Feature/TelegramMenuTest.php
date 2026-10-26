<?php

use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// ============================================================
// MENU PERINTAH BOT TELEGRAM
// ============================================================

test('service mendaftarkan command dan menu button default', function () {
    config(['services.telegram.bot_token' => '123:menu-uji']);
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true], 200),
    ]);

    $hasil = app(TelegramService::class)->sinkronkanMenuBot();

    expect($hasil)->toBe(['commands' => true, 'menu' => true]);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/setMyCommands')) {
            return false;
        }

        $daftar = collect($request['commands']);

        return $daftar->contains(fn ($command) => $command['command'] === 'laporan')
            && $daftar->contains(fn ($command) => $command['command'] === 'req')
            && ! $daftar->contains(fn ($command) => $command['command'] === 'start')
            && ! $daftar->contains(fn ($command) => $command['command'] === 'cuti');
    });

    Http::assertSent(fn ($request) => str_contains($request->url(), '/setChatMenuButton')
        && ($request['menu_button']['type'] ?? null) === 'default');
});

test('admin bisa sinkronkan menu dari halaman bot', function () {
    $this->seed();
    config(['services.telegram.bot_token' => '123:menu-uji']);
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true], 200),
    ]);

    $admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->actingAs($admin)
        ->post(route('bot.menu.sync'))
        ->assertRedirect()
        ->assertSessionHas('success');
});

test('tanpa token bot, sinkronisasi tidak memanggil Telegram API', function () {
    config(['services.telegram.bot_token' => '']);
    Http::fake();

    $hasil = app(TelegramService::class)->sinkronkanMenuBot();

    expect($hasil)->toBe(['commands' => false, 'menu' => false]);
    Http::assertNothingSent();
});
