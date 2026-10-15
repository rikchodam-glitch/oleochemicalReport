<?php

use App\Models\Area;
use App\Models\Asset;
use App\Models\FunctionalLocation;
use App\Models\Report;
use App\Models\Technician;
use App\Services\Telegram\ReportWizardService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Perbaikan pesan & alur wizard Telegram untuk laporan LANJUTAN (15/10/2026):
 *   1. Wizard lanjutan mewarisi data laporan asal termasuk EQUIPMENT dan
 *      LOKASI (funcloc_id/area_id), baik untuk laporan alat maupun laporan
 *      area (yang tidak punya aset) — sebelumnya lokasi hilang untuk laporan
 *      area.
 *   2. Khusus lanjutan, teknisi ditawari mengisi TANGGAL laporan; bila dilewati
 *      dipakai tanggal hari ini.
 *   3. Pesan akhir laporan memuat DESKRIPSI pekerjaan dan TAUTAN halaman detail.
 */

/**
 * Bentuk teknisi uji dengan telegram_id.
 *
 * @param  string $chatId Chat ID Telegram
 * @param  string $nik    NIK unik
 * @return Technician
 */
function tlTeknisi(string $chatId, string $nik): Technician
{
    return Technician::create([
        'name'        => 'Teknisi Lanjutan Pesan',
        'nik'         => $nik,
        'group'       => 'reguler',
        'status'      => 'active',
        'telegram_id' => $chatId,
    ]);
}

/**
 * Bentuk aset uji dengan lokasi lengkap.
 *
 * @param  int $areaId   ID area
 * @param  int $funclocId ID funcloc
 * @return Asset
 */
function tlAset(int $areaId, int $funclocId): Asset
{
    return Asset::create([
        'equipment_no'   => '99110001',
        'description'    => 'Pompa uji lanjutan',
        'tech_ident_no'  => 'TL-PUMP-01',
        'functional_loc' => 'TL-PROD-AR01-0001',
        'funcloc_id'     => $funclocId,
        'company_id'     => null,
        'department_id'  => null,
        'area_id'        => $areaId,
        'status'         => 'active',
    ]);
}

beforeEach(function () {
    $this->seed();

    $this->area = Area::query()->orderBy('id')->firstOrFail();
    $this->node = FunctionalLocation::query()->orderBy('id')->firstOrFail();
});

test('lanjutan laporan ALAT mewarisi equipment dan lokasi dari laporan asal', function () {
    $teknisi = tlTeknisi('88001', 'TL001');
    $aset    = tlAset($this->area->id, $this->node->id);

    $asal = Report::create([
        'technician_id'    => $teknisi->id,
        'creator_id'       => $teknisi->id,
        'report_date'      => now()->subDay()->toDateString(),
        'report_code'      => 'RPT-20260925-1001',
        'work_description' => 'Perbaikan pompa transfer tahap 1',
        'root_cause'       => 'Impeller aus',
        'finding'          => 'Bunyi abnormal',
        'jenis_pekerjaan'  => 'CM',
        'asset_id'         => $aset->id,
        'funcloc_id'       => $this->node->id,
        'area_id'          => $this->area->id,
        'report_type'      => 'equipment_repair',
        'status'           => 'continue',
    ]);

    $wizard = app(ReportWizardService::class);
    $wizard->startContinuation('88001', $asal->report_code, 'done');
    $state = $wizard->getState('88001');

    expect($state['equipment_id'])->toBe($aset->id)
        ->and($state['funcloc_id'])->toBe($this->node->id)
        ->and($state['area_id'])->toBe($this->area->id)
        ->and($state['finding'])->toBe('Bunyi abnormal');
});

test('lanjutan laporan AREA (tanpa aset) tetap mewarisi lokasi', function () {
    $teknisi = tlTeknisi('88002', 'TL002');

    $asal = Report::create([
        'technician_id'    => $teknisi->id,
        'creator_id'       => $teknisi->id,
        'report_date'      => now()->subDay()->toDateString(),
        'report_code'      => 'RPT-20260925-1002',
        'work_description' => 'Pengecatan lantai area produksi',
        'root_cause'       => 'Lantai terkelupas',
        'jenis_pekerjaan'  => 'Sipil',
        'funcloc_id'       => $this->node->id,
        'area_id'          => $this->area->id,
        'report_type'      => 'area_work',
        'status'           => 'continue',
    ]);

    $wizard = app(ReportWizardService::class);
    $wizard->startContinuation('88002', $asal->report_code, 'done');
    $state = $wizard->getState('88002');

    expect($state['is_area_work'])->toBeTrue()
        ->and($state['funcloc_id'])->toBe($this->node->id)
        ->and($state['area_id'])->toBe($this->area->id)
        ->and($state['funcloc_code'])->toBe($this->node->code);
});

test('lanjutan menawarkan tanggal dan tombol Pakai Hari Ini memakai hari ini', function () {
    $teknisi = tlTeknisi('88003', 'TL003');

    $asal = Report::create([
        'technician_id'    => $teknisi->id,
        'creator_id'       => $teknisi->id,
        'report_date'      => now()->subDay()->toDateString(),
        'report_code'      => 'RPT-20260925-1003',
        'work_description' => 'Perbaikan pompa tahap lanjutan',
        'jenis_pekerjaan'  => 'CM',
        'report_type'      => 'general',
        'status'           => 'continue',
    ]);

    $wizard = app(ReportWizardService::class);
    $prompt = $wizard->startContinuation('88003', $asal->report_code, 'done');

    expect($prompt['message'])->toContain('Tanggal pekerjaan lanjutan')
        ->and($wizard->getState('88003')['step'])->toBe(ReportWizardService::STEP_CONTINUATION_DATE);

    $respons = $wizard->handleCallback('88003', 'wizard:confirm:lanjutan_tanggal_hari_ini');
    $state   = $wizard->getState('88003');

    expect($state['report_date'])->toBe(now()->toDateString())
        ->and($state['step'])->toBe(ReportWizardService::STEP_MULTI_EQUIPMENT)
        ->and($respons['message'])->toContain('hari ini');
});

test('lanjutan bisa memakai tanggal yang diketik teknisi', function () {
    $teknisi = tlTeknisi('88004', 'TL004');

    $asal = Report::create([
        'technician_id'    => $teknisi->id,
        'creator_id'       => $teknisi->id,
        'report_date'      => now()->subDays(2)->toDateString(),
        'report_code'      => 'RPT-20260925-1004',
        'work_description' => 'Perbaikan pompa tahap lanjutan',
        'jenis_pekerjaan'  => 'CM',
        'report_type'      => 'general',
        'status'           => 'continue',
    ]);

    $wizard = app(ReportWizardService::class);
    $wizard->startContinuation('88004', $asal->report_code, 'done');

    $respons = $wizard->handleTextInput('88004', 'kemarin');
    $state   = $wizard->getState('88004');

    expect($state['report_date'])->toBe(now()->subDay()->toDateString())
        ->and($state['step'])->toBe(ReportWizardService::STEP_MULTI_EQUIPMENT)
        ->and($respons['message'])->toContain('Tanggal lanjutan diset');
});

test('tanggal lanjutan yang tidak terbaca membuat bot bertanya ulang', function () {
    $teknisi = tlTeknisi('88005', 'TL005');

    $asal = Report::create([
        'technician_id'    => $teknisi->id,
        'creator_id'       => $teknisi->id,
        'report_date'      => now()->subDay()->toDateString(),
        'report_code'      => 'RPT-20260925-1005',
        'work_description' => 'Perbaikan pompa tahap lanjutan',
        'jenis_pekerjaan'  => 'CM',
        'report_type'      => 'general',
        'status'           => 'continue',
    ]);

    $wizard = app(ReportWizardService::class);
    $wizard->startContinuation('88005', $asal->report_code, 'done');

    $respons = $wizard->handleTextInput('88005', 'entah kapan');
    $state   = $wizard->getState('88005');

    expect($respons['message'])->toContain('Tanggal tidak terbaca')
        ->and($state['step'])->toBe(ReportWizardService::STEP_CONTINUATION_DATE);
});

test('pesan akhir laporan memuat deskripsi dan tautan halaman detail', function () {
    $teknisi = tlTeknisi('88006', 'TL006');

    $asal = Report::create([
        'technician_id'    => $teknisi->id,
        'creator_id'       => $teknisi->id,
        'report_date'      => now()->subDay()->toDateString(),
        'report_code'      => 'RPT-20260925-1006',
        'work_description' => 'Perbaikan pompa transfer tahap 1',
        'jenis_pekerjaan'  => 'CM',
        'report_type'      => 'general',
        'status'           => 'continue',
    ]);

    $wizard = app(ReportWizardService::class);
    $wizard->startContinuation('88006', $asal->report_code, 'done');
    $wizard->handleCallback('88006', 'wizard:confirm:lanjutan_tanggal_hari_ini');

    $respons = $wizard->handleCallback('88006', 'wizard:confirm:save_done');

    expect($respons['saved'] ?? false)->toBeTrue()
        ->and($respons['success_message'])->toContain('Deskripsi')
        ->and($respons['success_message'])->toContain('Perbaikan pompa transfer tahap 1')
        ->and($respons['success_message'])->toContain('/reports/');
});
