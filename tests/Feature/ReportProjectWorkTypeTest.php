<?php

use App\Enums\WorkClassification;
use App\Models\Report;
use App\Models\Technician;
use App\Models\User;
use App\Services\Telegram\ReportWizardService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Jenis pekerjaan baru 'Project' (klasifikasi ZPM 8 - Project):
 *   - tersedia di wizard Telegram dan langsung mengisi jenis pekerjaan Project
 *     tanpa step pilihan tambahan;
 *   - tampil di halaman Laporan (badge, kartu ringkasan, filter) dan di form
 *     tambah/edit laporan.
 */
beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->teknisi = Technician::create([
        'name'   => 'Teknisi Project',
        'nik'    => 'PRJ001',
        'group'  => 'reguler',
        'status' => 'active',
    ]);

    $this->laporanProject = Report::create([
        'technician_id'       => $this->teknisi->id,
        'creator_id'          => $this->teknisi->id,
        'report_date'         => now(),
        'report_code'         => 'PRJ-LAPORAN-01',
        'work_description'     => 'Pekerjaan project pemasangan panel baru',
        'work_classification' => 'zpm8',
        'jenis_pekerjaan'     => 'Project',
        'report_type'         => 'area_work',
        'status'              => 'submitted',
    ]);

    $this->laporanSipil = Report::create([
        'technician_id'       => $this->teknisi->id,
        'creator_id'          => $this->teknisi->id,
        'report_date'         => now(),
        'report_code'         => 'PRJ-LAPORAN-02',
        'work_description'     => 'Pekerjaan sipil cor lantai',
        'work_classification' => 'zpm7',
        'jenis_pekerjaan'     => 'Sipil',
        'report_type'         => 'area_work',
        'status'              => 'submitted',
    ]);
});

test('enum klasifikasi pekerjaan memuat ZPM 8 Project', function () {
    expect(WorkClassification::Zpm8->value)->toBe('zpm8')
        ->and(WorkClassification::Zpm8->label())->toBe('ZPM 8 - Project')
        ->and(WorkClassification::Zpm8->shortLabel())->toBe('ZPM 8');
});

test('wizard Telegram menawarkan tombol ZPM 8 - Project', function () {
    $wizard = app(ReportWizardService::class);
    $chatId = 'uji-project-tombol-001';

    $respons = $wizard->startWizard($chatId, 'Pekerjaan project pemasangan panel baru');
    $teks    = $respons['message'] . ' ' . json_encode($respons['keyboard']);

    expect($teks)->toContain('classification:zpm8')
        ->and($teks)->toContain('ZPM 8 - Project');
});

test('pilihan ZPM 8 langsung mengisi jenis pekerjaan Project tanpa step tambahan', function () {
    $wizard = app(ReportWizardService::class);
    $chatId = 'uji-project-jenis-001';

    $wizard->startWizard($chatId, 'Pekerjaan project pemasangan panel baru');
    $respons = $wizard->handleCallback($chatId, 'classification:zpm8');
    $state   = $wizard->getState($chatId);

    expect($state['work_classification'])->toBe('zpm8')
        ->and($state['jenis_pekerjaan'])->toBe('Project')
        // Tidak masuk step pilihan jenis pekerjaan (EI/Mekanik/IT).
        ->and($state['step'])->not->toBe(ReportWizardService::STEP_JENIS_PEKERJAAN)
        ->and(json_encode($respons))->not->toContain('jenis_pekerjaan:');
});

test('halaman laporan menampilkan badge, kartu, dan filter jenis Project', function () {
    $this->actingAs($this->admin)
        ->get('/reports?tanggal=' . now()->toDateString())
        ->assertStatus(200)
        ->assertSee('PRJ-LAPORAN-01', false)
        ->assertSee('Project', false)
        // Pilihan filter jenis pekerjaan dan klasifikasi ZPM.
        ->assertSee('value="Project"', false)
        ->assertSee('ZPM 8 - Project', false);

    // Filter jenis pekerjaan Project hanya menampilkan laporan Project.
    $this->actingAs($this->admin)
        ->get('/reports?tanggal=' . now()->toDateString() . '&jenis_pekerjaan=Project')
        ->assertStatus(200)
        ->assertSee('PRJ-LAPORAN-01', false)
        ->assertDontSee('PRJ-LAPORAN-02', false);

    // Filter klasifikasi ZPM 8 juga menyaring laporan Project.
    $this->actingAs($this->admin)
        ->get('/reports?tanggal=' . now()->toDateString() . '&work_classification=zpm8')
        ->assertStatus(200)
        ->assertSee('PRJ-LAPORAN-01', false)
        ->assertDontSee('PRJ-LAPORAN-02', false);
});

test('form edit laporan menawarkan ZPM 8 dan jenis Project lalu menyimpannya', function () {
    $this->actingAs($this->admin)
        ->get('/reports/' . $this->laporanSipil->id . '/edit')
        ->assertStatus(200)
        ->assertSee('ZPM 8 - Project', false)
        ->assertSee('value="Project"', false);

    $this->actingAs($this->admin)
        ->put('/reports/' . $this->laporanSipil->id, [
            'work_description'      => 'Pekerjaan project cor lantai',
            'work_duration_minutes' => 120,
            'report_date'           => now()->toDateString(),
            'work_classification'   => 'zpm8',
            'jenis_pekerjaan'       => 'Project',
            'report_type'           => 'area_work',
        ])
        ->assertRedirect();

    expect($this->laporanSipil->fresh()->jenis_pekerjaan)->toBe('Project')
        ->and($this->laporanSipil->fresh()->work_classification)->toBe('zpm8');
});

test('jenis pekerjaan di luar daftar tetap ditolak validasi', function () {
    $this->actingAs($this->admin)
        ->from('/reports/' . $this->laporanSipil->id . '/edit')
        ->put('/reports/' . $this->laporanSipil->id, [
            'work_description'      => 'Pekerjaan dengan jenis tidak dikenal',
            'work_duration_minutes' => 60,
            'report_date'           => now()->toDateString(),
            'jenis_pekerjaan'       => 'Jenis Ngawur',
        ])
        ->assertSessionHasErrors('jenis_pekerjaan');

    expect($this->laporanSipil->fresh()->jenis_pekerjaan)->toBe('Sipil');
});
