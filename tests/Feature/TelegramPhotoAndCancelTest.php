<?php

use App\Models\Technician;
use App\Services\Telegram\PhotoNotificationBuffer;
use App\Services\Telegram\ReportWizardService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Perubahan alur laporan Telegram:
 *   1. Kiriman berisi banyak foto hanya dibalas SATU notifikasi (bukan per foto);
 *   2. Tombol "Batalkan Laporan" meminta konfirmasi YAKIN/TIDAK, dan pilihan
 *      TIDAK mengembalikan wizard ke step terakhir tanpa mengulang riwayat.
 */
beforeEach(function () {
    $this->seed();

    $this->teknisi = Technician::create([
        'name'        => 'Teknisi Uji Foto',
        'nik'         => 'FTO001',
        'group'       => 'reguler',
        'status'      => 'active',
        'telegram_id' => '88001',
    ]);

    $this->chatId = '88001';
});

// ============================================================
// 1. NOTIFIKASI FOTO (Media Group)
// ============================================================

test('tiga foto dalam satu kiriman hanya menghasilkan satu notifikasi', function () {
    $buffer = new PhotoNotificationBuffer();

    foreach ([1, 2, 3] as $nomor) {
        $buffer->tahan($this->chatId, 'grup-foto-a', [
            'message'  => "Foto {$nomor} diterima.\nKirim foto berikutnya, atau tekan *Selesai* untuk lanjut.",
            'keyboard' => [['text' => 'Selesai Foto', 'callback_data' => 'wizard:confirm:photo_doc_done']],
        ]);
    }

    $hasil = $buffer->ambilSemua();

    expect($hasil)->toHaveCount(1)
        ->and($hasil[0]['chat_id'])->toBe($this->chatId)
        ->and($hasil[0]['message'])->toBe("3 foto diterima.\nKirim foto berikutnya, atau tekan *Selesai* untuk lanjut.")
        ->and($hasil[0]['keyboard'])->toHaveCount(1);

    // Penahan dikosongkan setelah diambil supaya tidak terkirim dua kali.
    expect($buffer->adaTertahan())->toBeFalse()
        ->and($buffer->ambilSemua())->toBe([]);
});

test('foto tunggal tetap memakai pesan aslinya', function () {
    $buffer = new PhotoNotificationBuffer();

    $buffer->tahan($this->chatId, 'grup-foto-tunggal', [
        'message'  => "Foto 1 diterima.\nKirim foto berikutnya, atau tekan *Selesai* untuk lanjut.",
        'keyboard' => [],
    ]);

    expect($buffer->ambilSemua()[0]['message'])
        ->toBe("Foto 1 diterima.\nKirim foto berikutnya, atau tekan *Selesai* untuk lanjut.");
});

test('kiriman berbeda dipisah per grup dan per chat', function () {
    $buffer = new PhotoNotificationBuffer();

    $buffer->tahan('111', 'grup-1', ['message' => 'Foto 1 diterima.']);
    $buffer->tahan('111', 'grup-1', ['message' => 'Foto 2 diterima.']);
    $buffer->tahan('111', 'grup-2', ['message' => 'Foto 1 diterima.']);
    $buffer->tahan('222', 'grup-1', ['message' => 'Foto 1 diterima.']);

    $hasil = $buffer->ambilSemua();

    expect($hasil)->toHaveCount(3)
        ->and($hasil[0]['message'])->toBe('2 foto diterima.')
        ->and($hasil[1]['message'])->toBe('Foto 1 diterima.')
        ->and($hasil[2]['message'])->toBe('Foto 1 diterima.');
});

test('pesan tanpa pola jumlah foto dikembalikan apa adanya', function () {
    $buffer = new PhotoNotificationBuffer();

    $buffer->tahan($this->chatId, 'grup-rpt', ['message' => 'Foto ditambahkan ke laporan RPT-20260926-00005.']);
    $buffer->tahan($this->chatId, 'grup-rpt', ['message' => 'Foto ditambahkan ke laporan RPT-20260926-00005.']);

    expect($buffer->ambilSemua()[0]['message'])->toBe('Foto ditambahkan ke laporan RPT-20260926-00005.');
});

// ============================================================
// 2. KONFIRMASI PEMBATALAN LAPORAN
// ============================================================

test('tombol batalkan meminta konfirmasi YAKIN atau TIDAK', function () {
    $wizard = app(ReportWizardService::class);

    $wizard->startWizard($this->chatId, 'Perbaikan pompa transfer');
    $wizard->handleCallback($this->chatId, 'classification:zpm3');

    $stepSebelum  = $wizard->getState($this->chatId)['step'];
    $riwayatAwal  = count($wizard->getState($this->chatId)['step_history'] ?? []);

    $konfirmasi = $wizard->handleCallback($this->chatId, 'wizard:cancel_wizard');
    $keyboard   = json_encode($konfirmasi['keyboard']);

    expect($konfirmasi['message'])->toContain('Batalkan laporan ini?')
        ->and($keyboard)->toContain('wizard:confirm:cancel_yes')
        ->and($keyboard)->toContain('wizard:confirm:cancel_no')
        // Wizard belum dibatalkan, hanya menunggu jawaban.
        ->and($wizard->hasActiveWizard($this->chatId))->toBeTrue();

    // Pilihan TIDAK → kembali ke step terakhir tanpa menambah riwayat.
    $lanjut = $wizard->handleCallback($this->chatId, 'wizard:confirm:cancel_no');
    $state  = $wizard->getState($this->chatId);

    expect($lanjut['message'])->toContain('Baik, laporan dilanjutkan.')
        ->and($state['step'])->toBe($stepSebelum)
        ->and(count($state['step_history'] ?? []))->toBe($riwayatAwal)
        ->and($wizard->hasActiveWizard($this->chatId))->toBeTrue();

    // Pilihan YAKIN → wizard benar-benar dihapus.
    $selesai = $wizard->handleCallback($this->chatId, 'wizard:confirm:cancel_yes');

    expect($selesai['message'])->toContain('dibatalkan')
        ->and($wizard->hasActiveWizard($this->chatId))->toBeFalse();
});

test('tombol Batalkan pada konfirmasi Step 8 juga memakai konfirmasi YAKIN atau TIDAK', function () {
    $wizard = app(ReportWizardService::class);

    $wizard->startWizard($this->chatId, 'Perbaikan pompa transfer');
    $wizard->handleCallback($this->chatId, 'classification:zpm3');

    $respons = $wizard->handleCallback($this->chatId, 'wizard:confirm:cancel_report');

    expect($respons['message'])->toContain('Batalkan laporan ini?')
        ->and(json_encode($respons['keyboard']))->toContain('wizard:confirm:cancel_no')
        ->and($wizard->hasActiveWizard($this->chatId))->toBeTrue();
});
