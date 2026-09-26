<?php

use App\Console\Commands\PollTelegramUpdates;
use App\Services\Telegram\PhotoStorageService;
use App\Services\Telegram\ReportWizardService;
use App\Models\Technician;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Regresi "foto double" & "pesan bot double":
 *   1. satu update Telegram hanya diproses SEKALI (penanda update_id);
 *   2. foto dengan isi sama disimpan ke path yang sama (tidak ada file kembar);
 *   3. entri foto yang sudah ada tidak ditambahkan lagi ke state wizard.
 */
beforeEach(function () {
    $this->seed();

    $this->teknisi = Technician::create([
        'name'        => 'Teknisi Foto Ganda',
        'nik'         => 'FDG001',
        'group'       => 'reguler',
        'status'      => 'active',
        'telegram_id' => '44001',
    ]);

    $this->chatId = '44001';
});

test('satu update Telegram hanya ditandai diproses sekali', function () {
    $poll = app(PollTelegramUpdates::class);

    expect($poll->updateSudahDiproses(987654321))->toBeFalse()
        // Panggilan kedua untuk update yang sama → sudah diproses.
        ->and($poll->updateSudahDiproses(987654321))->toBeTrue()
        // Update lain tetap boleh diproses.
        ->and($poll->updateSudahDiproses(987654322))->toBeFalse();
});

test('foto berisi sama disimpan ke path yang sama, bukan file kembar', function () {
    $konten = "\xFF\xD8\xFF\xE0" . str_repeat('konten-foto-uji', 20);

    // Token bot diisi agar URL Telegram bisa dibangun (request tetap di-fake).
    config(['services.telegram.bot_token' => 'token-uji-dedupe']);

    // Pola fake harus memuat wildcard di depan karena Laravel mencocokkan
    // seluruh URL; preventStrayRequests memastikan tidak ada request nyata
    // ke Telegram saat test.
    Http::preventStrayRequests();
    Http::fake([
        '*api.telegram.org/bot*/getFile*' => Http::response([
            'ok'     => true,
            'result' => ['file_path' => 'photos/uji-dedupe.jpg'],
        ], 200),
        '*api.telegram.org/file/bot*' => Http::response($konten, 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $layanan = app(PhotoStorageService::class);
    $disk    = Storage::disk(config('telegram.photo_disk', 'telegram_photos'));

    $path1 = $layanan->store('file-uji-dedupe', (string) $this->chatId);
    $path2 = $layanan->store('file-uji-dedupe', (string) $this->chatId);

    expect($path1)->not->toBeNull()
        ->and($path1)->toBe($path2)
        ->and($disk->exists($path1))->toBeTrue();

    // Hanya satu file yang dibuat untuk isi yang sama.
    expect($disk->files(dirname($path1)))->toHaveCount(1);

    $disk->delete($path1);
});

test('foto yang sama tidak ditambahkan dua kali ke state wizard', function () {
    $wizard = app(ReportWizardService::class);

    $wizard->saveState($this->chatId, [
        'chat_id'                 => $this->chatId,
        'text'                    => 'Perbaikan pompa',
        'step'                    => ReportWizardService::STEP_PHOTO_DOCUMENTATION,
        'step_history'            => [],
        'photo_documentation'     => [],
        'photo_hygiene_clearance' => [],
        'created_at'              => now()->toIso8601String(),
    ]);

    $pertama = $wizard->handlePhotoInput($this->chatId, 'reports/2026/09/26/44001/photo_uji.jpg');
    $kedua   = $wizard->handlePhotoInput($this->chatId, 'reports/2026/09/26/44001/photo_uji.jpg');
    $state   = $wizard->getState($this->chatId);

    expect($pertama['message'])->toContain('Foto 1 diterima')
        ->and($kedua['message'])->toContain('sudah tersimpan')
        ->and($state['photo_documentation'])->toBe(['reports/2026/09/26/44001/photo_uji.jpg']);

    $wizard->destroyWizard($this->chatId);
});
