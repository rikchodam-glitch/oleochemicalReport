<?php

use App\Console\Commands\PollTelegramUpdates;
use App\Models\Technician;
use App\Models\Tool;
use App\Models\ToolLoan;
use App\Models\ToolLocation;
use App\Models\ToolRack;
use App\Models\ToolToken;
use App\Services\ToolLendingService;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

uses(RefreshDatabase::class);

/**
 * Perintah /pinjam, /pakai, dan /selesai pada bot Telegram.
 */
beforeEach(function () {
    $this->seed();

    Storage::fake('public');

    $this->lokasi = ToolLocation::create(['name' => 'Workshop']);
    $this->rak    = ToolRack::create(['tool_location_id' => $this->lokasi->id, 'name' => 'Rak A']);

    Storage::disk('public')->put('tools/photos/bor.jpg', 'foto');

    $this->alat = Tool::create([
        'tool_rack_id' => $this->rak->id,
        'name'         => 'Bor Batrai',
        'code'         => 'BRBT001',
        'status'       => Tool::STATUS_TERSEDIA,
        'photo_path'   => 'tools/photos/bor.jpg',
    ]);

    $this->alatKedua = Tool::create([
        'tool_rack_id' => $this->rak->id,
        'name'         => 'Bor Batrai',
        'code'         => 'BRBT002',
        'status'       => Tool::STATUS_TERSEDIA,
    ]);

    $this->teknisi = Technician::create([
        'name'        => 'Teknisi Bot',
        'nik'         => 'BOT001',
        'group'       => 'reguler',
        'section'     => 'mekanik',
        'status'      => 'active',
        'telegram_id' => '55001',
    ]);

    $this->keeper = Technician::create([
        'name'           => 'Keeper Bot',
        'nik'            => 'BOT002',
        'group'          => 'reguler',
        'section'        => 'mekanik',
        'status'         => 'active',
        'is_tool_keeper' => true,
        'telegram_id'    => '55002',
    ]);

    config(['services.telegram.bot_token' => 'token-uji-bot-tools']);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
    ]);
});

/**
 * Kirim satu pesan teks ke poller seperti update Telegram.
 */
function kirimPesanAlat(string $chatId, string $teks): void
{
    $command = app(PollTelegramUpdates::class);
    $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput()));

    $metode = new ReflectionMethod($command, 'processUpdate');
    $metode->setAccessible(true);

    $metode->invoke($command, [
        'update_id' => random_int(100000, 999999),
        'message'   => [
            'message_id' => 1,
            'chat'       => ['id' => (int) $chatId],
            'from'       => ['id' => (int) $chatId, 'first_name' => 'Teknisi Uji'],
            'text'       => $teks,
        ],
    ]);
}

/**
 * Kirim satu callback inline ke poller.
 */
function kirimCallbackAlat(string $chatId, string $data, string $callbackId = ''): void
{
    $command = app(PollTelegramUpdates::class);
    $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput()));

    $metode = new ReflectionMethod($command, 'processCallbackQuery');
    $metode->setAccessible(true);

    $metode->invoke($command, [
        'update_id' => random_int(100000, 999999),
        'callback_query' => [
            'id'   => $callbackId !== '' ? $callbackId : 'cb-' . uniqid(),
            'from' => ['id' => (int) $chatId, 'first_name' => 'Teknisi Uji'],
            'message' => [
                'message_id' => 99,
                'chat'       => ['id' => (int) $chatId],
            ],
            'data' => $data,
        ],
    ]);
}

test('/pinjam hanya untuk keeper dan mengirim tautan form', function () {
    kirimPesanAlat('55002', '/pinjam');

    expect(ToolToken::query()->where('purpose', ToolToken::PURPOSE_KEEPER_PINJAM)->count())->toBe(1);

    Http::assertSent(fn ($http) => str_contains((string) $http->url(), '/sendMessage')
        && (string) ($http['chat_id'] ?? '') === '55002'
        && str_contains((string) ($http['text'] ?? ''), 'Form Peminjaman Alat')
        && str_contains((string) ($http['text'] ?? ''), '/tools/form/'));

    kirimPesanAlat('55001', '/pinjam');

    Http::assertSent(fn ($http) => str_contains((string) $http->url(), '/sendMessage')
        && (string) ($http['chat_id'] ?? '') === '55001'
        && str_contains((string) ($http['text'] ?? ''), 'hanya untuk tool keeper'));
});

test('/pakai menampilkan foto, status unit, dan tautan dashboard', function () {
    $layanan = app(ToolLendingService::class);

    // Pinjaman lama yang sudah kembali memberi info "Peminjam terakhir".
    $lama = $layanan->pinjamkan($this->alat, $layanan->dataPeminjamTeknisi($this->teknisi), ToolLoan::HANDLED_SELF, $this->teknisi);
    $layanan->kembalikan($lama, ToolLoan::HANDLED_SELF, $this->teknisi);

    $layanan->pinjamkan($this->alatKedua, $layanan->dataPeminjamTeknisi($this->keeper), ToolLoan::HANDLED_SELF, $this->keeper);

    kirimPesanAlat('55001', '/pakai bor batrai');

    expect(ToolToken::query()->where('purpose', ToolToken::PURPOSE_TEKNISI_PINJAM)->count())->toBe(1);

    Http::assertSent(fn ($http) => str_contains((string) $http->url(), '/sendPhoto')
        && (string) ($http['chat_id'] ?? '') === '55001'
        && str_contains((string) ($http['caption'] ?? ''), 'BRBT001')
        && str_contains((string) ($http['caption'] ?? ''), 'BRBT002')
        && str_contains((string) ($http['caption'] ?? ''), 'Dipakai oleh')
        && str_contains((string) ($http['caption'] ?? ''), 'Peminjam terakhir'));

    Http::assertSent(fn ($http) => str_contains((string) $http->url(), '/sendPhoto')
        && (string) (($http['reply_markup']['inline_keyboard'][0][0]['url'] ?? '')) !== '');
});

test('/pakai tanpa hasil menawarkan hierarki lokasi', function () {
    kirimPesanAlat('55001', '/pakai alat yang tidak ada');

    Http::assertSent(function ($http) {
        return str_contains((string) $http->url(), '/sendMessage')
            && (string) ($http['chat_id'] ?? '') === '55001'
            && str_contains((string) ($http['text'] ?? ''), 'tidak ditemukan')
            && isset($http['reply_markup']['inline_keyboard'][0][0]['callback_data'])
            && str_starts_with((string) $http['reply_markup']['inline_keyboard'][0][0]['callback_data'], 'alat:l:');
    });
});

test('hierarki callback membuka rak dan alat lalu mengirim tautan', function () {
    kirimPesanAlat('55001', '/pakai');

    Http::assertSent(fn ($http) => str_contains((string) $http->url(), '/sendMessage')
        && str_contains((string) ($http['text'] ?? ''), 'Pilih lokasi'));

    kirimCallbackAlat('55001', 'alat:l:' . $this->lokasi->id);

    Http::assertSent(fn ($http) => str_contains((string) $http->url(), '/editMessageText')
        && str_contains((string) ($http['text'] ?? ''), 'Pilih rak'));

    kirimCallbackAlat('55001', 'alat:r:' . $this->rak->id . ':1');

    Http::assertSent(fn ($http) => str_contains((string) $http->url(), '/editMessageText')
        && str_contains((string) ($http['text'] ?? ''), 'Pilih alat'));

    kirimCallbackAlat('55001', 'alat:g:' . $this->alat->id);

    $token = ToolToken::query()
        ->where('purpose', ToolToken::PURPOSE_TEKNISI_PINJAM)
        ->firstOrFail();

    expect($token->prefill_tool_id)->toBe($this->alat->id);

    Http::assertSent(fn ($http) => str_contains((string) $http->url(), '/sendPhoto')
        && str_contains((string) ($http['caption'] ?? ''), 'Bor Batrai'));
});

test('/selesai teknisi menampilkan daftar pinjaman dan tombol kembalikan', function () {
    $layanan = app(ToolLendingService::class);
    $pinjaman = $layanan->pinjamkan($this->alat, $layanan->dataPeminjamTeknisi($this->teknisi), ToolLoan::HANDLED_SELF, $this->teknisi);

    kirimPesanAlat('55001', '/selesai');

    Http::assertSent(function ($http) use ($pinjaman) {
        return str_contains((string) $http->url(), '/sendMessage')
            && (string) ($http['chat_id'] ?? '') === '55001'
            && str_contains((string) ($http['text'] ?? ''), 'BRBT001')
            && (($http['reply_markup']['inline_keyboard'][0][0]['callback_data'] ?? '') === 'alat:ret:' . $pinjaman->id);
    });

    kirimCallbackAlat('55001', 'alat:ret:' . $pinjaman->id);

    expect($pinjaman->fresh()->return_requested_at)->not->toBeNull()
        ->and($pinjaman->fresh()->returned_at)->toBeNull()
        ->and($this->alat->fresh()->status)->toBe(Tool::STATUS_DIPAKAI);

    Http::assertSent(fn ($http) => str_contains((string) $http->url(), '/editMessageText')
        && str_contains((string) ($http['text'] ?? ''), 'Pengajuan pengembalian'));
});

test('/selesai keeper mengirim tautan form pengembalian', function () {
    $layanan = app(ToolLendingService::class);
    $layanan->pinjamkan($this->alat, $layanan->dataPeminjamTeknisi($this->teknisi), ToolLoan::HANDLED_SELF, $this->teknisi);

    kirimPesanAlat('55002', '/selesai');

    expect(ToolToken::query()->where('purpose', ToolToken::PURPOSE_KEEPER_KEMBALI)->count())->toBe(1);

    Http::assertSent(fn ($http) => str_contains((string) $http->url(), '/sendMessage')
        && (string) ($http['chat_id'] ?? '') === '55002'
        && str_contains((string) ($http['text'] ?? ''), 'Barang Kembali')
        && str_contains((string) ($http['text'] ?? ''), '/tools/form/'));
});

test('callback mengembalikan semua hanya untuk pinjaman milik sendiri', function () {
    $layanan = app(ToolLendingService::class);
    $layanan->pinjamkan($this->alat, $layanan->dataPeminjamTeknisi($this->teknisi), ToolLoan::HANDLED_SELF, $this->teknisi);
    $layanan->pinjamkan($this->alatKedua, $layanan->dataPeminjamTeknisi($this->teknisi), ToolLoan::HANDLED_SELF, $this->teknisi);

    kirimCallbackAlat('55001', 'alat:retall');
    kirimCallbackAlat('55001', 'alat:retall:ya');

    expect(ToolLoan::query()->menungguKembali()->count())->toBe(2)
        ->and($this->alat->fresh()->status)->toBe(Tool::STATUS_DIPAKAI)
        ->and($this->alatKedua->fresh()->status)->toBe(Tool::STATUS_DIPAKAI);
});

test('keeper menerima pengembalian lewat callback Terima', function () {
    $layanan = app(ToolLendingService::class);
    $pinjaman = $layanan->pinjamkan($this->alat, $layanan->dataPeminjamTeknisi($this->teknisi), ToolLoan::HANDLED_SELF, $this->teknisi);
    $layanan->mintaKembali($pinjaman, ToolLoan::HANDLED_SELF, $this->teknisi);

    kirimCallbackAlat('55002', 'alat:terima:' . $pinjaman->id);

    expect($pinjaman->fresh()->returned_at)->not->toBeNull()
        ->and($this->alat->fresh()->status)->toBe(Tool::STATUS_TERSEDIA);

    Http::assertSent(fn ($http) => str_contains((string) $http->url(), '/editMessageText')
        && str_contains((string) ($http['text'] ?? ''), 'diterima kembali'));
});
