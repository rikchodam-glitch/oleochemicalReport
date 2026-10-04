<?php

use App\Models\Technician;
use App\Services\Telegram\ReportWizardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/**
 * Batas diam (idle) sesi wizard laporan Telegram.
 *
 * Sesi wizard disimpan di cache dengan batas keras 2 jam (CACHE_TTL). Bila
 * teknisi meninggalkan alur di tengah jalan, sesi lama itu tetap hidup sehingga
 * teks BARU dibaca sebagai jawaban langkah lama — inilah gejala teknisi
 * "tersangkut di sesi/tahap tertentu". Aturan yang dijaga di sini:
 *
 *   1. Teks baru setelah menganggur melewati batas (config
 *      cm.wizard.idle_timeout_menit, default 10 menit) membatalkan sesi lama,
 *      sehingga teks itu diproses sebagai laporan BARU.
 *   2. Foto dan tombol TIDAK membatalkan sesi (progres teknisi yang kembali
 *      dari lapangan tidak boleh hilang) — hasActiveWizard() tetap true.
 *   3. Setiap saveState() memperbarui penanda aktivitas terakhir.
 */

beforeEach(function () {
    $this->seed();

    Technician::create([
        'name'        => 'Teknisi Idle',
        'nik'         => 'IDL001',
        'group'       => 'reguler',
        'status'      => 'active',
        'telegram_id' => '56101',
    ]);

    $this->chatId  = '56101';
    $this->layanan = app(ReportWizardService::class);
});

/**
 * Simpan state wizard dengan penanda aktivitas terakhir beberapa menit lalu.
 *
 * @param  ReportWizardService $layanan    Instance layanan wizard
 * @param  string              $chatId     Chat ID Telegram
 * @param  string              $step       Step wizard yang sedang berjalan
 * @param  int                 $menitLalu  Umur aktivitas terakhir (menit)
 * @return array               State yang disimpan
 */
function simpanStateIdle(ReportWizardService $layanan, string $chatId, string $step, int $menitLalu): array
{
    $state = [
        'chat_id'                 => $chatId,
        'text'                    => 'Modifikasi scruw pitch box RG2',
        'step'                    => $step,
        'step_history'            => ['work_classification'],
        'work_classification'     => 'zpm5',
        'jenis_pekerjaan'         => 'Fabrikasi',
        'root_cause'              => 'Kebocoran material dari scruw pitch box',
        'photo_documentation'     => [],
        'photo_hygiene_clearance' => [],
        'created_at'              => now()->subMinutes($menitLalu + 5)->toIso8601String(),
        'last_activity_at'        => now()->subMinutes($menitLalu)->toIso8601String(),
    ];

    Cache::put(ReportWizardService::CACHE_PREFIX . $chatId, $state, ReportWizardService::CACHE_TTL);

    return $state;
}

test('teks baru setelah menganggur melewati batas membatalkan sesi lama', function () {
    simpanStateIdle($this->layanan, $this->chatId, ReportWizardService::STEP_COLLABORATOR, 11);

    expect($this->layanan->batalkanSesiBasi($this->chatId))->toBeTrue()
        ->and($this->layanan->hasActiveWizard($this->chatId))->toBeFalse()
        ->and($this->layanan->getState($this->chatId))->toBeNull()
        ->and(Cache::has(ReportWizardService::CACHE_PREFIX . $this->chatId))->toBeFalse();

    // Setelah dibatalkan, teks tidak lagi dibaca sebagai jawaban langkah lama.
    $respons = $this->layanan->handleTextInput($this->chatId, 'Ganti bearing pompa PFAD');

    expect($respons['message'])->toContain('Tidak ada sesi laporan aktif');
});

test('sesi yang masih dalam batas idle tidak dibatalkan', function () {
    simpanStateIdle($this->layanan, $this->chatId, ReportWizardService::STEP_COLLABORATOR, 9);

    expect($this->layanan->batalkanSesiBasi($this->chatId))->toBeFalse()
        ->and($this->layanan->hasActiveWizard($this->chatId))->toBeTrue()
        ->and($this->layanan->getState($this->chatId)['step'])->toBe(ReportWizardService::STEP_COLLABORATOR);
});

test('pembatalan sesi basi hanya dilaporkan sekali', function () {
    simpanStateIdle($this->layanan, $this->chatId, ReportWizardService::STEP_COLLABORATOR, 11);

    expect($this->layanan->batalkanSesiBasi($this->chatId))->toBeTrue()
        ->and($this->layanan->batalkanSesiBasi($this->chatId))->toBeFalse();
});

test('foto setelah menganggur tetap masuk ke sesi yang sedang berjalan', function () {
    simpanStateIdle($this->layanan, $this->chatId, ReportWizardService::STEP_PHOTO_HYGIENE, 30);

    expect($this->layanan->batasIdleMenit())->toBe(10)
        ->and($this->layanan->hasActiveWizard($this->chatId))->toBeTrue();

    $respons = $this->layanan->handlePhotoInput($this->chatId, 'reports/2026/10/04/56101/photo_idle.jpg');
    $state   = $this->layanan->getState($this->chatId);

    expect($respons['message'])->toContain('Foto 1 diterima')
        ->and($respons)->not->toHaveKey('error')
        ->and($state['step'])->toBe(ReportWizardService::STEP_PHOTO_HYGIENE)
        ->and($state['photo_hygiene_clearance'])->toBe(['reports/2026/10/04/56101/photo_idle.jpg']);
});

test('saveState memperbarui penanda aktivitas terakhir', function () {
    $this->layanan->saveState($this->chatId, [
        'chat_id'          => $this->chatId,
        'step'             => ReportWizardService::STEP_ROOT_CAUSE,
        'created_at'       => now()->subHours(2)->toIso8601String(),
        'last_activity_at' => now()->subHours(2)->toIso8601String(),
    ]);

    $state = $this->layanan->getState($this->chatId);
    $umur  = abs(now()->getTimestamp() - Carbon::parse($state['last_activity_at'])->getTimestamp());

    expect($umur)->toBeLessThan(5);
});

test('state lama tanpa penanda waktu tidak dibatalkan mendadak', function () {
    Cache::put(ReportWizardService::CACHE_PREFIX . $this->chatId, [
        'chat_id' => $this->chatId,
        'step'    => ReportWizardService::STEP_COLLABORATOR,
    ], ReportWizardService::CACHE_TTL);

    expect($this->layanan->batalkanSesiBasi($this->chatId))->toBeFalse()
        ->and($this->layanan->hasActiveWizard($this->chatId))->toBeTrue();
});

test('batas idle 0 mematikan pembatalan otomatis', function () {
    config(['cm.wizard.idle_timeout_menit' => 0]);

    simpanStateIdle($this->layanan, $this->chatId, ReportWizardService::STEP_COLLABORATOR, 600);

    expect($this->layanan->batalkanSesiBasi($this->chatId))->toBeFalse()
        ->and($this->layanan->hasActiveWizard($this->chatId))->toBeTrue();
});

test('createInitialState mengisi penanda aktivitas terakhir', function () {
    $state = (new ReflectionMethod($this->layanan, 'createInitialState'))
        ->invoke($this->layanan, $this->chatId, 'Perbaikan pompa transfer');

    expect($state['last_activity_at'] ?? null)->not->toBeNull();

    $umur = abs(now()->getTimestamp() - Carbon::parse($state['last_activity_at'])->getTimestamp());

    expect($umur)->toBeLessThan(5);
});

test('sesi tanpa penanda aktivitas memakai created_at sebagai cadangan', function () {
    // Sesi yang dibuat SEBELUM fitur batas diam dipasang hanya punya created_at.
    // Sesi seperti itu tetap wajib dikenali basi supaya sesi yang sedang
    // menggantung di produksi ikut dibatalkan ketika teks baru datang.
    Cache::put(ReportWizardService::CACHE_PREFIX . $this->chatId, [
        'chat_id'    => $this->chatId,
        'step'       => ReportWizardService::STEP_WORK_CLASSIFICATION,
        'created_at' => now()->subMinutes(30)->toIso8601String(),
    ], ReportWizardService::CACHE_TTL);

    expect($this->layanan->batalkanSesiBasi($this->chatId))->toBeTrue()
        ->and($this->layanan->hasActiveWizard($this->chatId))->toBeFalse();
});
