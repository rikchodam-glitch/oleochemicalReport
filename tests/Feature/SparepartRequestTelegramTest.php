<?php

use App\Console\Commands\PollTelegramUpdates;
use App\Models\SpRequest;
use App\Models\Technician;
use App\Services\Telegram\ReportWizardService;
use App\Services\Telegram\SparepartRequestWizardService;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    Storage::fake('telegram_photos');

    config([
        'services.telegram.bot_token' => 'token-uji-sparepart',
        'telegram.photo_disk'          => 'telegram_photos',
    ]);

    $this->pemohon = Technician::create([
        'name'        => 'Teknisi Pemohon',
        'nik'         => 'REQ001',
        'group'       => 'reguler',
        'section'     => 'mekanik',
        'status'      => 'active',
        'telegram_id' => '91001',
    ]);

    $this->staff = Technician::create([
        'name'        => 'Staff CM PM',
        'nik'         => 'REQ002',
        'group'       => 'reguler_staff',
        'section'     => 'cm_pm',
        'status'      => 'active',
        'telegram_id' => '91002',
    ]);

    $this->wizard = app(SparepartRequestWizardService::class);

    Http::preventStrayRequests();
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []], 200),
    ]);
});

/**
 * Jalankan satu pesan teks pada command polling seperti update Telegram.
 *
 * @param  string $chatId
 * @param  string $teks
 * @return void
 */
function kirimTeksRequestKeBot(string $chatId, string $teks): void
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
 * Isi wizard request sampai langkah konfirmasi dengan foto.
 *
 * @param  SparepartRequestWizardService $wizard
 * @param  string                        $chatId
 * @param  string|null                   $fotoPath
 * @return void
 */
function isiWizardSampaiKonfirmasi(
    SparepartRequestWizardService $wizard,
    string $chatId,
    ?string $fotoPath = null,
    string $ukuran = '25 mm',
    string $satuan = 'pcs'
): void {
    $wizard->mulai($chatId, Technician::where('telegram_id', $chatId)->firstOrFail(), 'Seal pompa bocor');
    $wizard->handleText($chatId, $ukuran);
    $wizard->handleText($chatId, '2');
    $wizard->handleText($chatId, $satuan);

    if ($fotoPath !== null) {
        $wizard->handleFoto($chatId, $fotoPath);
    } else {
        $wizard->handleText($chatId, '-');
    }
}

test('/req menyimpan request lengkap dan mengirim notifikasi ke pemohon serta Staff CM/PM', function () {
    $fotoPath = 'sparepart-requests/2026/10/24/91001/foto.jpg';
    Storage::disk('telegram_photos')->put($fotoPath, 'foto');

    isiWizardSampaiKonfirmasi($this->wizard, '91001', $fotoPath);

    $respons = $this->wizard->handleCallback('91001', 'spr:kirim');

    expect($respons['message'])->toContain('berhasil dikirim')
        ->and($this->wizard->hasActive('91001'))->toBeFalse();

    $request = SpRequest::query()->firstOrFail();

    expect($request->keterangan)->toBe('Seal pompa bocor')
        ->and($request->deskripsi_ukuran)->toBe('25 mm')
        ->and($request->jumlah)->toBe(2.0)
        ->and($request->satuan)->toBe('pcs')
        ->and($request->foto_path)->toBe($fotoPath)
        ->and($request->status)->toBe(SpRequest::STATUS_MENUNGGU)
        ->and($request->kode)->toMatch('/^REQ-\d{8}-\d{4}$/');

    Http::assertSent(fn ($http) => str_contains((string) $http->url(), '/sendMessage')
        && (string) ($http['chat_id'] ?? '') === '91001'
        && str_contains((string) ($http['text'] ?? ''), 'sudah diterima'));

    Http::assertSent(fn ($http) => str_contains((string) $http->url(), '/sendMessage')
        && (string) ($http['chat_id'] ?? '') === '91002'
        && str_contains((string) ($http['text'] ?? ''), 'Teknisi Pemohon')
        && str_contains((string) ($http['text'] ?? ''), $request->kode));
});

test('deskripsi ukuran, satuan, dan foto boleh dilewati', function () {
    isiWizardSampaiKonfirmasi($this->wizard, '91001', null, '-', '-');

    $this->wizard->handleCallback('91001', 'spr:kirim');

    $request = SpRequest::query()->firstOrFail();

    expect($request->deskripsi_ukuran)->toBeNull()
        ->and($request->satuan)->toBeNull()
        ->and($request->foto_path)->toBeNull();
});

test('jumlah wajib berupa angka positif', function () {
    $this->wizard->mulai('91001', $this->pemohon, 'Seal pompa bocor');
    $this->wizard->handleText('91001', '-');

    $respons = $this->wizard->handleText('91001', 'bukan angka');

    expect($respons['message'])->toContain('angka')
        ->and($this->wizard->getState('91001')['step'])->toBe(SparepartRequestWizardService::STEP_JUMLAH)
        ->and(SpRequest::query()->count())->toBe(0);
});

test('batal menghapus sesi dan foto yang belum dipakai', function () {
    $fotoPath = 'sparepart-requests/2026/10/24/91001/batal.jpg';
    Storage::disk('telegram_photos')->put($fotoPath, 'foto');

    isiWizardSampaiKonfirmasi($this->wizard, '91001', $fotoPath);
    $respons = $this->wizard->handleCallback('91001', 'spr:batal');

    expect($respons['message'])->toContain('dibatalkan')
        ->and($this->wizard->hasActive('91001'))->toBeFalse()
        ->and(SpRequest::query()->count())->toBe(0)
        ->and(Storage::disk('telegram_photos')->exists($fotoPath))->toBeFalse();
});

test('/req memulai wizard request dari perintah teks', function () {
    kirimTeksRequestKeBot('91001', '/req Seal pompa bocor');

    $state = $this->wizard->getState('91001');

    expect($this->wizard->hasActive('91001'))->toBeTrue()
        ->and($state['keterangan'])->toBe('Seal pompa bocor')
        ->and($state['step'])->toBe(SparepartRequestWizardService::STEP_UKURAN);

    Http::assertSent(fn ($http) => str_contains((string) ($http['text'] ?? ''), 'Deskripsi/ukuran'));
});

test('/req tanpa keterangan meminta keterangan lebih dulu', function () {
    kirimTeksRequestKeBot('91001', '/req');

    expect($this->wizard->hasActive('91001'))->toBeTrue()
        ->and($this->wizard->getState('91001')['step'])->toBe(SparepartRequestWizardService::STEP_KETERANGAN);

    Http::assertSent(fn ($http) => str_contains((string) ($http['text'] ?? ''), 'Tulis keterangan request'));
});

test('/reset membatalkan sesi request spare part', function () {
    kirimTeksRequestKeBot('91001', '/req Seal pompa bocor');

    expect($this->wizard->hasActive('91001'))->toBeTrue();

    kirimTeksRequestKeBot('91001', '/reset');

    expect($this->wizard->hasActive('91001'))->toBeFalse();

    Http::assertSent(fn ($http) => str_contains((string) ($http['text'] ?? ''), 'Sesi request spare part dibatalkan'));
});

test('/req ditolak saat wizard laporan sedang aktif', function () {
    $reportWizard = app(ReportWizardService::class);
    $reportWizard->saveState('91001', [
        'chat_id'          => '91001',
        'text'             => 'Laporan sedang berjalan',
        'step'             => ReportWizardService::STEP_WORK_CLASSIFICATION,
        'step_history'     => [],
        'created_at'       => now()->toIso8601String(),
        'last_activity_at' => now()->toIso8601String(),
    ]);

    kirimTeksRequestKeBot('91001', '/req Seal pompa bocor');

    expect($this->wizard->hasActive('91001'))->toBeFalse();

    Http::assertSent(fn ($http) => str_contains((string) ($http['text'] ?? ''), 'Selesaikan atau batalkan laporan'));
});
