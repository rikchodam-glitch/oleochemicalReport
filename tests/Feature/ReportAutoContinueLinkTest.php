<?php

use App\Models\Report;
use App\Models\Technician;
use App\Services\ReportContinuationMatcher;
use App\Services\Telegram\ReportWizardService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Penautan otomatis laporan lanjutan: saat teknisi menulis laporan baru seperti
 * biasa, sistem mencocokkan deskripsi + root cause + lokasi/equipment dengan
 * laporan ber-status 'continue' yang masih terbuka, lalu MENAWARKAN tautan itu
 * di Step 8 (tidak menautkan tanpa persetujuan teknisi).
 */
beforeEach(function () {
    $this->seed();

    $this->teknisi = Technician::create([
        'name'        => 'Teknisi Auto Link',
        'nik'         => 'ALK001',
        'group'       => 'reguler',
        'status'      => 'active',
        'telegram_id' => '66001',
    ]);

    $this->chatId = '66001';

    $this->laporanContinue = Report::create([
        'technician_id'    => $this->teknisi->id,
        'creator_id'       => $this->teknisi->id,
        'report_date'      => now()->subDay()->toDateString(),
        'report_code'      => 'RPT-20260925-0007',
        'work_description' => 'Perbaikan pompa transfer line 2',
        'root_cause'       => 'Impeller aus',
        'jenis_pekerjaan'  => 'CM/PM',
        'report_type'      => 'area_work',
        'status'           => 'continue',
    ]);
});

test('matcher menemukan laporan continue yang mirip berdasarkan deskripsi dan root cause', function () {
    $matcher = app(ReportContinuationMatcher::class);

    $kandidat = $matcher->cariKandidat([
        'text'       => 'Perbaikan pompa transfer line 2',
        'root_cause' => 'Impeller aus',
    ]);

    expect($kandidat?->report_code)->toBe('RPT-20260925-0007');
});

test('matcher mengabaikan pekerjaan yang deskripsinya tidak berhubungan', function () {
    $matcher = app(ReportContinuationMatcher::class);

    expect($matcher->cariKandidat([
        'text'       => 'Pengecatan pagar gudang',
        'root_cause' => 'Cat mengelupas',
    ]))->toBeNull();
});

test('matcher mengabaikan laporan continue yang sudah dilanjutkan atau kedaluwarsa', function () {
    $matcher = app(ReportContinuationMatcher::class);

    $data = ['text' => 'Perbaikan pompa transfer line 2', 'root_cause' => 'Impeller aus'];

    expect($matcher->cariKandidat($data)?->report_code)->toBe('RPT-20260925-0007');

    // Setelah dilanjutkan laporan lain, kandidat tidak lagi ditawarkan.
    Report::create([
        'technician_id'     => $this->teknisi->id,
        'creator_id'        => $this->teknisi->id,
        'report_date'       => now()->toDateString(),
        'report_code'       => 'RPT-20260926-0008',
        'work_description'  => 'Perbaikan pompa transfer line 2',
        'continued_from_id' => $this->laporanContinue->id,
        'status'            => 'completed',
    ]);

    expect($matcher->cariKandidat($data))->toBeNull();

    // Laporan continue yang sudah lewat 30 hari juga tidak ditawarkan.
    $this->laporanContinue->continuedBy()->delete();
    $this->laporanContinue->update(['report_date' => now()->subDays(45)->toDateString()]);

    expect($matcher->cariKandidat($data))->toBeNull();
});

test('Step 8 menawarkan penautan dan menyimpannya saat teknisi menekan Ya', function () {
    $wizard = app(ReportWizardService::class);

    $wizard->saveState($this->chatId, [
        'chat_id'                 => $this->chatId,
        'text'                    => 'Perbaikan pompa transfer line 2',
        'step'                    => ReportWizardService::STEP_COLLABORATOR,
        'step_history'            => [],
        'work_classification'     => 'zpm3',
        'jenis_pekerjaan'         => 'CM/PM',
        'equipment_id'            => null,
        'is_area_work'            => true,
        'work_duration_minutes'   => 90,
        'start_time'              => '08:00',
        'end_time'                => '09:30',
        'root_cause'              => 'Impeller aus',
        'report_date'             => now()->toDateString(),
        'photo_documentation'     => [],
        'photo_hygiene_clearance' => [],
        'collaborator_niks'       => [],
        'collaborator_names'      => [],
        'created_at'              => now()->toIso8601String(),
    ]);

    // Ringkasan Step 8 (dibangun lewat penutupan step kolaborator) menawarkan
    // tautan ke laporan continue yang mirip.
    $ringkasan = $wizard->handleCallback($this->chatId, 'wizard:confirm:collab_done');
    $keyboard  = json_encode($ringkasan['keyboard']);

    expect($ringkasan['message'])->toContain('Kemungkinan lanjutan')
        ->and($ringkasan['message'])->toContain('RPT-20260925-0007')
        ->and($keyboard)->toContain('wizard:confirm:lanjutan_ya')
        ->and($keyboard)->toContain('wizard:confirm:lanjutan_tidak')
        ->and($keyboard)->toContain('wizard:confirm:save_done');

    // Teknisi menyetujui tautan, lalu menyimpan sebagai Done.
    $setuju = $wizard->handleCallback($this->chatId, 'wizard:confirm:lanjutan_ya');

    expect($setuju['message'])->toContain('Lanjutan dari')
        ->and($wizard->getState($this->chatId)['lanjutan_dari_id'])->toBe($this->laporanContinue->id)
        ->and(json_encode($setuju['keyboard']))->not->toContain('lanjutan_ya');

    $wizard->handleCallback($this->chatId, 'wizard:confirm:save_done');

    $baru = Report::where('continued_from_id', $this->laporanContinue->id)->latest('id')->first();

    expect($baru)->not->toBeNull()
        ->and($baru->status)->toBe('completed')
        ->and($baru->work_description)->toBe('Perbaikan pompa transfer line 2');

    $wizard->destroyWizard($this->chatId);
});

test('menekan Bukan lanjutan menghilangkan tawaran tanpa menautkan', function () {
    $wizard = app(ReportWizardService::class);

    $wizard->saveState($this->chatId, [
        'chat_id'                 => $this->chatId,
        'text'                    => 'Perbaikan pompa transfer line 2',
        'step'                    => ReportWizardService::STEP_COLLABORATOR,
        'step_history'            => [],
        'work_duration_minutes'   => 60,
        'root_cause'              => 'Impeller aus',
        'report_date'             => now()->toDateString(),
        'photo_documentation'     => [],
        'photo_hygiene_clearance' => [],
        'created_at'              => now()->toIso8601String(),
    ]);

    $wizard->handleCallback($this->chatId, 'wizard:confirm:collab_done');
    $tolak = $wizard->handleCallback($this->chatId, 'wizard:confirm:lanjutan_tidak');

    expect($tolak['message'])->not->toContain('Kemungkinan lanjutan')
        ->and(json_encode($tolak['keyboard']))->not->toContain('lanjutan_ya')
        ->and($wizard->getState($this->chatId))->not->toHaveKey('lanjutan_dari_id');

    $wizard->destroyWizard($this->chatId);
});
