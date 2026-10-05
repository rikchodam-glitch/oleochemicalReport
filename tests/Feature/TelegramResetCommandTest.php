<?php

use App\Console\Commands\PollTelegramUpdates;
use App\Models\Technician;
use App\Services\Telegram\ReportWizardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Perintah teks /reset pada bot Telegram.
 *
 * Tombol "Batalkan Laporan" hanya menempel pada pesan wizard yang sedang aktif.
 * Bila pesan itu sudah tergulung jauh di riwayat chat (atau layarnya sudah
 * digambar ulang sehingga keyboard lama hilang), teknisi tidak punya jalan
 * keluar selain membiarkan sesi itu menggantung. Karena itu disediakan
 * perintah teks /reset: mengetik /reset membuang state wizard chat itu
 * sehingga pesan berikutnya diproses sebagai laporan BARU, bukan jawaban
 * langkah lama.
 *
 * Yang dijaga di sini:
 *   1. /reset membuang sesi wizard yang sedang berjalan + memberi balasan.
 *   2. /reset saat tidak ada sesi tetap membalas tanpa membuat sesi baru.
 *   3. /reset@namabot (bentuk perintah di grup) ikut dikenali.
 *   4. Sesi wizard chat LAIN tidak ikut terhapus.
 */

beforeEach(function () {
    $this->seed();

    $this->teknisi = Technician::create([
        'name'        => 'Teknisi Reset',
        'nik'         => 'RST001',
        'group'       => 'reguler',
        'status'      => 'active',
        'telegram_id' => '57201',
    ]);

    $this->chatId   = '57201';
    $this->chatLain = '57202';
    $this->layanan  = app(ReportWizardService::class);

    config(['services.telegram.bot_token' => 'token-uji-reset']);

    Http::preventStrayRequests();
    Http::fake([
        '*api.telegram.org/bot*/sendMessage*' => Http::response(['ok' => true, 'result' => []], 200),
    ]);
});

/**
 * Simpan state wizard yang masih segar (bukan sesi basi) untuk satu chat.
 *
 * @param  ReportWizardService $layanan Instance layanan wizard
 * @param  string              $chatId  Chat ID Telegram
 * @param  string              $step    Step wizard yang sedang berjalan
 * @return void
 */
function simpanStateSegar(ReportWizardService $layanan, string $chatId, string $step): void
{
    $layanan->saveState($chatId, [
        'chat_id'          => $chatId,
        'text'             => 'Modifikasi scruw pitch box RG2',
        'step'             => $step,
        'step_history'     => ['work_classification'],
        'created_at'       => now()->toIso8601String(),
        'last_activity_at' => now()->toIso8601String(),
    ]);
}

/**
 * Kirim satu pesan teks ke handler bot seperti update Telegram sungguhan.
 *
 * @param  string $chatId     Chat ID tujuan
 * @param  string $teks       Isi pesan
 * @param  int    $telegramId ID Telegram pengirim
 * @return void
 */
function kirimTeksKeBotReset(string $chatId, string $teks, int $telegramId): void
{
    $command = app(PollTelegramUpdates::class);

    // Command ini dijalankan langsung (bukan lewat Artisan), sehingga objek
    // output harus dipasang sendiri supaya $this->line() di processUpdate()
    // tidak memanggil method pada null.
    $command->setOutput(new Illuminate\Console\OutputStyle(
        new Symfony\Component\Console\Input\ArrayInput([]),
        new Symfony\Component\Console\Output\NullOutput()
    ));

    $metode  = new ReflectionMethod($command, 'processUpdate');
    $metode->setAccessible(true);

    $metode->invoke($command, [
        'update_id' => 700001,
        'message'   => [
            'message_id' => 1,
            'chat'       => ['id' => (int) $chatId],
            'from'       => ['id' => $telegramId, 'first_name' => 'Teknisi Reset'],
            'text'       => $teks,
        ],
    ]);
}

test('/reset membatalkan sesi wizard yang sedang berjalan', function () {
    simpanStateSegar($this->layanan, $this->chatId, ReportWizardService::STEP_WORK_CLASSIFICATION);

    expect($this->layanan->hasActiveWizard($this->chatId))->toBeTrue();

    kirimTeksKeBotReset($this->chatId, '/reset', 57201);

    expect($this->layanan->hasActiveWizard($this->chatId))->toBeFalse()
        ->and($this->layanan->getState($this->chatId))->toBeNull()
        ->and(Cache::has(ReportWizardService::CACHE_PREFIX . $this->chatId))->toBeFalse();

    Http::assertSent(fn ($request) => str_contains((string) $request->url(), '/sendMessage')
        && str_contains((string) ($request['text'] ?? ''), 'Sesi laporan dibatalkan'));
});

test('/reset saat tidak ada sesi aktif tetap membalas tanpa membuat sesi baru', function () {
    kirimTeksKeBotReset($this->chatId, '/reset', 57201);

    expect($this->layanan->hasActiveWizard($this->chatId))->toBeFalse()
        ->and($this->layanan->getState($this->chatId))->toBeNull();

    Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'Tidak ada sesi laporan yang aktif'));
});

test('/reset dengan nama bot ikut dikenali', function () {
    simpanStateSegar($this->layanan, $this->chatId, ReportWizardService::STEP_COLLABORATOR);

    kirimTeksKeBotReset($this->chatId, '/reset@oleo_report_bot', 57201);

    expect($this->layanan->hasActiveWizard($this->chatId))->toBeFalse();
});

test('/reset hanya membuang sesi chat pengirim', function () {
    simpanStateSegar($this->layanan, $this->chatId, ReportWizardService::STEP_WORK_CLASSIFICATION);
    simpanStateSegar($this->layanan, $this->chatLain, ReportWizardService::STEP_WORK_CLASSIFICATION);

    kirimTeksKeBotReset($this->chatId, '/reset', 57201);

    expect($this->layanan->hasActiveWizard($this->chatId))->toBeFalse()
        ->and($this->layanan->hasActiveWizard($this->chatLain))->toBeTrue();
});
