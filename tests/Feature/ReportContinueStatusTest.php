<?php

use App\Models\Report;
use App\Models\Technician;
use App\Models\User;
use App\Services\Telegram\ReportWizardService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Status pekerjaan "Continue" dan penautan laporan lanjutan:
 *   - Step 8 wizard menyediakan pilihan Done (completed) atau Continue;
 *   - laporan 'continue' bisa dilanjutkan dengan menulis "<KODE> Done|Continue",
 *     di mana jam kerja wajib diisi ulang sedangkan data lain diwarisi;
 *   - halaman laporan menampilkan status & rantai lanjutannya.
 */
beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->teknisi = Technician::create([
        'name'        => 'Teknisi Lanjutan',
        'nik'         => 'LNJ001',
        'group'       => 'reguler',
        'status'      => 'active',
        'telegram_id' => '77001',
    ]);

    $this->chatId = '77001';

    $this->laporanContinue = Report::create([
        'technician_id'    => $this->teknisi->id,
        'creator_id'       => $this->teknisi->id,
        'report_date'      => now()->subDay()->toDateString(),
        'report_code'      => 'RPT-20260925-0001',
        'work_description' => 'Perbaikan pompa transfer tahap 1',
        'root_cause'       => 'Impeller aus',
        'jenis_pekerjaan'  => 'CM/PM',
        'report_type'      => 'area_work',
        'status'           => 'continue',
    ]);
});

test('status continue tersimpan dan laporan lanjutan menunjuk balik ke asalnya', function () {
    $lanjutan = Report::create([
        'technician_id'     => $this->teknisi->id,
        'creator_id'        => $this->teknisi->id,
        'report_date'       => now()->toDateString(),
        'report_code'       => 'RPT-20260926-0002',
        'work_description'  => 'Perbaikan pompa transfer tahap 1',
        'continued_from_id' => $this->laporanContinue->id,
        'status'            => 'completed',
    ]);

    expect($lanjutan->continuedFrom?->report_code)->toBe('RPT-20260925-0001')
        ->and($this->laporanContinue->fresh()->continuedBy->pluck('report_code')->all())
        ->toBe(['RPT-20260926-0002']);

    // Laporan asal sudah punya lanjutan → tidak lagi dianggap terbuka.
    expect(Report::belumDilanjutkan()->pluck('report_code')->all())->not->toContain('RPT-20260925-0001');
});

test('perintah lanjutan dikenali dari kode laporan + kata Done atau Continue', function () {
    $wizard = app(ReportWizardService::class);

    expect($wizard->detectContinuationCommand('RPT-20260925-0001 Done'))
        ->toBe(['kode' => 'RPT-20260925-0001', 'mode' => 'done'])
        ->and($wizard->detectContinuationCommand('rpt-20260925-0001 selesai'))
        ->toBe(['kode' => 'RPT-20260925-0001', 'mode' => 'done'])
        ->and($wizard->detectContinuationCommand('RPT-20260925-0001 Continue'))
        ->toBe(['kode' => 'RPT-20260925-0001', 'mode' => 'continue'])
        ->and($wizard->detectContinuationCommand('Lanjut RPT-20260925-0001 lanjutkan'))
        ->toBe(['kode' => 'RPT-20260925-0001', 'mode' => 'continue'])
        // Hanya kode (caption foto) atau tanpa kode → bukan perintah lanjutan.
        ->and($wizard->detectContinuationCommand('RPT-20260925-0001'))->toBeNull()
        ->and($wizard->detectContinuationCommand('Perbaikan pompa transfer'))->toBeNull();
});

test('wizard lanjutan mewarisi data asal tetapi jam kerja wajib diisi ulang', function () {
    $wizard = app(ReportWizardService::class);

    $respons = $wizard->startContinuation($this->chatId, 'RPT-20260925-0001', 'done');
    $state   = $wizard->getState($this->chatId);

    expect($respons['message'])->toContain('Lanjutan dari')
        ->and($respons['message'])->toContain('RPT-20260925-0001')
        ->and($state['lanjutan_dari_id'])->toBe($this->laporanContinue->id)
        ->and($state['lanjutan_kode'])->toBe('RPT-20260925-0001')
        ->and($state['text'])->toBe('Perbaikan pompa transfer tahap 1')
        ->and($state['root_cause'])->toBe('Impeller aus')
        ->and($state['jenis_pekerjaan'])->toBe('CM/PM')
        // Durasi dikosongkan → wajib diisi teknisi karena beda hari.
        ->and($state['work_duration_minutes'])->toBeNull()
        ->and($state['step'])->toBe(ReportWizardService::STEP_WORK_DURATION)
        ->and($state['report_date'])->toBe(now()->toDateString());

    $wizard->destroyWizard($this->chatId);
});
test('laporan yang tidak berstatus continue atau tidak ada tidak bisa dilanjutkan', function () {
    $wizard = app(ReportWizardService::class);

    $tidakAda = $wizard->startContinuation($this->chatId, 'RPT-20260101-9999', 'done');

    expect($tidakAda['message'])->toContain('tidak ditemukan')
        ->and($wizard->hasActiveWizard($this->chatId))->toBeFalse();

    $this->laporanContinue->update(['status' => 'completed']);

    $bukanContinue = $wizard->startContinuation($this->chatId, 'RPT-20260925-0001', 'done');

    expect($bukanContinue['message'])->toContain('bukan Continue')
        ->and($wizard->hasActiveWizard($this->chatId))->toBeFalse();
});

test('Step 8 bisa menyimpan sebagai Done atau Continue dan mencatat penautan', function () {
    $wizard = app(ReportWizardService::class);

    $stateDasar = [
        'chat_id'                 => $this->chatId,
        'text'                    => 'Perbaikan pompa transfer tahap 1',
        'step'                    => ReportWizardService::STEP_CONFIRMATION,
        'step_history'            => [],
        'work_classification'     => 'zpm3',
        'jenis_pekerjaan'         => 'CM/PM',
        'equipment_id'            => null,
        'is_area_work'            => true,
        'work_duration_minutes'   => 120,
        'start_time'              => '08:00',
        'end_time'                => '10:00',
        'root_cause'              => 'Impeller aus',
        'report_date'             => now()->toDateString(),
        'photo_documentation'     => [],
        'photo_hygiene_clearance' => [],
        'collaborator_niks'       => [],
        'collaborator_names'      => [],
        'lanjutan_dari_id'        => $this->laporanContinue->id,
        'lanjutan_kode'           => 'RPT-20260925-0001',
        'created_at'              => now()->toIso8601String(),
    ];

    // Pilihan Done → status completed + completed_at terisi.
    $wizard->saveState($this->chatId, $stateDasar);
    $wizard->handleCallback($this->chatId, 'wizard:confirm:save_done');

    $laporanDone = Report::where('continued_from_id', $this->laporanContinue->id)->latest('id')->first();

    expect($laporanDone)->not->toBeNull()
        ->and($laporanDone->status)->toBe('completed')
        ->and($laporanDone->completed_at)->not->toBeNull();

    // Pilihan Continue → status continue, pekerjaan masih terbuka untuk dilanjutkan.
    $wizard->saveState($this->chatId, $stateDasar);
    $wizard->handleCallback($this->chatId, 'wizard:confirm:save_continue');

    $laporanContinueBaru = Report::where('status', 'continue')->latest('id')->first();

    expect($laporanContinueBaru->id)->not->toBe($this->laporanContinue->id)
        ->and($laporanContinueBaru->completed_at)->toBeNull()
        ->and($laporanContinueBaru->continued_from_id)->toBe($this->laporanContinue->id);

    // Tombol lama 'save_report' tetap bekerja sebagai Done (kompatibilitas).
    $wizard->saveState($this->chatId, $stateDasar);
    $wizard->handleCallback($this->chatId, 'wizard:confirm:save_report');

    expect(Report::latest('id')->first()->status)->toBe('completed');

    $wizard->destroyWizard($this->chatId);
});

test('halaman laporan bisa difilter status continue dan menampilkan rantai lanjutan', function () {
    $lanjutan = Report::create([
        'technician_id'     => $this->teknisi->id,
        'creator_id'        => $this->teknisi->id,
        'report_date'       => now()->toDateString(),
        'report_code'       => 'RPT-20260926-0003',
        'work_description'  => 'Perbaikan pompa transfer tahap 1',
        'root_cause'        => 'Impeller aus',
        'jenis_pekerjaan'   => 'CM/PM',
        'report_type'       => 'area_work',
        'continued_from_id' => $this->laporanContinue->id,
        'status'            => 'completed',
    ]);

    $rentang = 'date_from=' . now()->subDays(3)->toDateString() . '&date_to=' . now()->toDateString();

    // Filter status continue hanya menampilkan laporan yang masih terbuka.
    $this->actingAs($this->admin)
        ->get('/reports?' . $rentang . '&status=continue')
        ->assertStatus(200)
        ->assertSee('RPT-20260925-0001', false)
        ->assertSee('CONTINUE', false)
        ->assertDontSee('RPT-20260926-0003', false);

    // Halaman detail laporan lanjutan menunjuk ke laporan asalnya.
    $this->actingAs($this->admin)
        ->get('/reports/' . $lanjutan->id)
        ->assertStatus(200)
        ->assertSee('Lanjutan dari', false)
        ->assertSee('RPT-20260925-0001', false);

    // Halaman detail laporan asal menampilkan laporan lanjutannya.
    $this->actingAs($this->admin)
        ->get('/reports/' . $this->laporanContinue->id)
        ->assertStatus(200)
        ->assertSee('Dilanjutkan oleh', false)
        ->assertSee('RPT-20260926-0003', false);
});

