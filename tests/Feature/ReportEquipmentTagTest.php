<?php

use App\Models\Asset;
use App\Models\Company;
use App\Models\Department;
use App\Models\FunctionalLocation;
use App\Models\Report;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Penjaga penautan laporan teknisi ke equipment (insiden 19/10/2026):
 *   - laporan baru harus mengisi equipment_tag dari assets.tech_ident_no
 *     (bukan equipment_no) supaya muncul di detail equipment CM;
 *   - command `reports:tag-equipment` memperbaiki data lama: tag kosong /
 *     berisi equipment_no diganti tech ident no, dan laporan tanpa aset yang
 *     menyebut TEPAT SATU kode equipment diberi tag.
 */
beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->company = Company::firstOrCreate(['code' => 'PTTAG'], ['name' => 'PT Tagging']);
    $this->dept = Department::firstOrCreate(
        ['company_id' => $this->company->id, 'code' => 'DEPTAG'],
        ['name' => 'Departemen Tagging']
    );

    $this->flSection = FunctionalLocation::create([
        'code' => 'PTTAG-DEPTAG-01',
        'segment' => '01',
        'name' => 'Section Tagging',
        'level' => FunctionalLocation::LEVEL_SECTION,
        'company_id' => $this->company->id,
        'department_id' => $this->dept->id,
        'is_active' => true,
    ]);

    $this->teknisi = Technician::create([
        'name' => 'Teknisi Tagging', 'nik' => 'TAG001', 'group' => 'reguler', 'status' => 'active',
    ]);

    $this->asset = Asset::create([
        'equipment_no' => '10009999',
        'description' => 'Pompa Tagging',
        'tech_ident_no' => 'TAG-01',
        'functional_loc' => $this->flSection->code,
        'funcloc_id' => $this->flSection->id,
        'status' => 'active',
        'data_source' => 'import_excel',
    ]);

    $this->assetLain = Asset::create([
        'equipment_no' => '10009998',
        'description' => 'Pompa Tagging 2',
        'tech_ident_no' => 'TAG-02',
        'functional_loc' => $this->flSection->code,
        'funcloc_id' => $this->flSection->id,
        'status' => 'active',
        'data_source' => 'import_excel',
    ]);
});

/**
 * Helper: buat laporan dengan data minimal.
 *
 * @param  array<string, mixed>  $atribut
 */
function buatLaporanTagging(array $atribut): Report
{
    return Report::create(array_merge([
        'technician_id' => Technician::where('nik', 'TAG001')->firstOrFail()->id,
        'report_date' => '2026-08-26',
        'work_description' => 'Pekerjaan tagging',
        'report_type' => 'equipment_repair',
        'status' => 'completed',
    ], $atribut));
}

test('laporan baru otomatis mengisi equipment_tag dari tech ident no asset', function () {
    $laporan = buatLaporanTagging([
        'report_code' => 'TAG-AUTO-001',
        'asset_id' => $this->asset->id,
    ]);

    expect($laporan->fresh()->equipment_tag)->toBe('TAG-01');
});

test('equipment_tag yang diisi manual tidak ditimpa saat menyimpan', function () {
    $laporan = buatLaporanTagging([
        'report_code' => 'TAG-MANUAL-002',
        'asset_id' => $this->asset->id,
        'equipment_tag' => 'TAG-LAIN',
    ]);

    expect($laporan->fresh()->equipment_tag)->toBe('TAG-LAIN');
});

test('command backfill mengganti equipment_no menjadi tech ident no', function () {
    $laporan = buatLaporanTagging([
        'report_code' => 'TAG-BACKFILL-003',
        'asset_id' => $this->asset->id,
        'equipment_tag' => '10009999',
    ]);

    // Tanpa --apply tidak ada data yang berubah.
    $this->artisan('reports:tag-equipment')->assertSuccessful();
    expect($laporan->fresh()->equipment_tag)->toBe('10009999');

    $this->artisan('reports:tag-equipment', ['--apply' => true])->assertSuccessful();
    expect($laporan->fresh()->equipment_tag)->toBe('TAG-01');
});

test('command backfill mengisi tag laporan lama yang menyebut tepat satu equipment', function () {
    $laporan = buatLaporanTagging([
        'report_code' => 'TAG-TEKS-004',
        'work_description' => 'Ganti kopling pompa TAG 01',
        'report_type' => 'area_work',
    ]);

    $this->artisan('reports:tag-equipment', ['--apply' => true])->assertSuccessful();

    expect($laporan->fresh()->equipment_tag)->toBe('TAG-01');
});

test('command backfill tidak menebak bila laporan menyebut lebih dari satu equipment', function () {
    $laporan = buatLaporanTagging([
        'report_code' => 'TAG-TEKS-005',
        'work_description' => 'Perbaikan TAG-01 dan TAG-02 sekaligus',
        'report_type' => 'area_work',
    ]);

    $this->artisan('reports:tag-equipment', ['--apply' => true])->assertSuccessful();

    expect($laporan->fresh()->equipment_tag)->toBeNull();
});
