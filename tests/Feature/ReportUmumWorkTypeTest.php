<?php

use App\Models\Report;
use App\Models\Technician;
use App\Models\User;
use App\Services\Telegram\ReportWizardService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Jenis pekerjaan 'UMUM' (tombol baru di wizard Telegram tepat setelah
 * 'ZPM 8 - Project', tanpa kode ZPM) dan pilihan jenis pekerjaan 'IT' pada form
 * edit halaman detail:
 *   - wizard mengisi jenis pekerjaan UMUM tanpa klasifikasi ZPM dan tanpa step
 *     pilihan EI/Mekanik/IT;
 *   - halaman Laporan punya kartu ringkasan, badge, dan filter UMUM;
 *   - halaman detail mode edit menawarkan jenis pekerjaan IT dan UMUM.
 */
beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->teknisi = Technician::create([
        'name'   => 'Teknisi Umum',
        'nik'    => 'UMU001',
        'group'  => 'reguler',
        'status' => 'active',
    ]);

    $this->laporanUmum = Report::create([
        'technician_id'       => $this->teknisi->id,
        'creator_id'          => $this->teknisi->id,
        'report_date'         => now(),
        'report_code'         => 'UMU-LAPORAN-01',
        'work_description'     => 'Pekerjaan umum membersihkan area gudang',
        'work_classification' => null,
        'jenis_pekerjaan'     => 'UMUM',
        'report_type'         => 'general',
        'status'              => 'completed',
    ]);

    $this->laporanIT = Report::create([
        'technician_id'       => $this->teknisi->id,
        'creator_id'          => $this->teknisi->id,
        'report_date'         => now(),
        'report_code'         => 'UMU-LAPORAN-02',
        'work_description'     => 'Perbaikan jaringan kantor',
        'work_classification' => 'zpm1',
        'jenis_pekerjaan'     => 'IT',
        'report_type'         => 'general',
        'status'              => 'completed',
    ]);
});

test('wizard Telegram menawarkan tombol UMUM tepat setelah ZPM 8 - Project', function () {
    $wizard = app(ReportWizardService::class);
    $chatId = 'uji-umum-tombol-001';

    $respons  = $wizard->startWizard($chatId, 'Pekerjaan umum membersihkan area gudang');
    $keyboard = $respons['keyboard'];

    $callback = array_map(fn (array $tombol): string => (string) ($tombol['callback_data'] ?? ''), $keyboard);

    expect($callback)->toContain('classification:umum')
        ->and(array_search('classification:umum', $callback, true))
        ->toBe(array_search('classification:zpm8', $callback, true) + 1)
        ->and(json_encode($keyboard))->toContain('UMUM');
});

test('pilihan UMUM mengisi jenis pekerjaan UMUM tanpa klasifikasi ZPM dan tanpa step EI/Mekanik/IT', function () {
    $wizard = app(ReportWizardService::class);
    $chatId = 'uji-umum-jenis-001';

    $wizard->startWizard($chatId, 'Pekerjaan umum membersihkan area gudang');
    $respons = $wizard->handleCallback($chatId, 'classification:umum');
    $state   = $wizard->getState($chatId);

    expect($state['jenis_pekerjaan'])->toBe('UMUM')
        ->and($state['work_classification'])->toBeNull()
        // Tidak masuk step pilihan jenis pekerjaan (EI/Mekanik/IT).
        ->and($state['step'])->not->toBe(ReportWizardService::STEP_JENIS_PEKERJAAN)
        ->and(json_encode($respons))->not->toContain('jenis_pekerjaan:');
});

test('halaman laporan menampilkan baris, kartu, dan filter jenis UMUM', function () {
    $this->actingAs($this->admin)
        ->get('/reports?tanggal=' . now()->toDateString())
        ->assertStatus(200)
        // Kode laporan tidak lagi tampil di tabel, baris dikenali lewat penanda
        // data-laporan-id (id laporan).
        ->assertSee('data-laporan-id="' . $this->laporanUmum->id . '"', false)
        // Filter jenis pekerjaan dan kotak centang export memuat pilihan UMUM.
        ->assertSee('value="UMUM"', false);

    // Filter jenis pekerjaan UMUM hanya menampilkan laporan UMUM.
    $this->actingAs($this->admin)
        ->get('/reports?tanggal=' . now()->toDateString() . '&jenis_pekerjaan=UMUM')
        ->assertStatus(200)
        ->assertSee('data-laporan-id="' . $this->laporanUmum->id . '"', false)
        ->assertDontSee('data-laporan-id="' . $this->laporanIT->id . '"', false);
});

test('halaman detail mode edit menawarkan jenis pekerjaan IT dan UMUM lalu menyimpannya', function () {
    $this->actingAs($this->admin)
        ->get('/reports/' . $this->laporanIT->id)
        ->assertStatus(200)
        ->assertSee('>IT</option>', false)
        ->assertSee('>UMUM</option>', false)
        // Daftar jenis pekerjaan saat klasifikasi kosong memuat IT dan UMUM.
        ->assertSee("['IT', 'IT']", false)
        ->assertSee("['UMUM', 'UMUM']", false);

    // Laporan IT diubah menjadi UMUM lewat form edit.
    $this->actingAs($this->admin)
        ->put('/reports/' . $this->laporanIT->id, [
            'work_description'      => 'Pekerjaan umum perbaikan atap gudang',
            'work_duration_minutes' => 90,
            'report_date'           => now()->toDateString(),
            'jenis_pekerjaan'       => 'UMUM',
        ])
        ->assertRedirect();

    expect($this->laporanIT->fresh()->jenis_pekerjaan)->toBe('UMUM');
});

test('jenis pekerjaan IT tetap bisa dipilih ulang lewat form edit', function () {
    $this->actingAs($this->admin)
        ->put('/reports/' . $this->laporanUmum->id, [
            'work_description'      => 'Perbaikan jaringan kantor lanjutan',
            'work_duration_minutes' => 45,
            'report_date'           => now()->toDateString(),
            'jenis_pekerjaan'       => 'IT',
        ])
        ->assertRedirect();

    expect($this->laporanUmum->fresh()->jenis_pekerjaan)->toBe('IT');
});

test('jenis pekerjaan di luar daftar tetap ditolak validasi', function () {
    $this->actingAs($this->admin)
        ->from('/reports/' . $this->laporanUmum->id)
        ->put('/reports/' . $this->laporanUmum->id, [
            'work_description'      => 'Pekerjaan dengan jenis tidak dikenal',
            'work_duration_minutes' => 60,
            'report_date'           => now()->toDateString(),
            'jenis_pekerjaan'       => 'Jenis Ngawur',
        ])
        ->assertSessionHasErrors('jenis_pekerjaan');

    expect($this->laporanUmum->fresh()->jenis_pekerjaan)->toBe('UMUM');
});

