<?php

use App\Models\Area;
use App\Models\Asset;
use App\Models\Company;
use App\Models\Department;
use App\Models\FunctionalLocation;
use App\Models\Report;
use App\Models\Technician;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Regresi perbaikan data laporan lama: funcloc_id/area_id yang kosong diisi
 * dari data alat / kode Functional Location, dan baris tanpa sumber lokasi
 * dibiarkan apa adanya (dicatat sebagai "tanpa sumber lokasi").
 */
beforeEach(function () {
    $this->seed();

    $this->company = Company::firstOrCreate(['code' => 'PTBF'], ['name' => 'PT Backfill']);
    $this->dept    = Department::firstOrCreate(
        ['company_id' => $this->company->id, 'code' => 'DEPBF'],
        ['name' => 'Departemen Backfill']
    );
    $this->area = Area::firstOrCreate(
        ['department_id' => $this->dept->id, 'code' => 'AREABF'],
        ['name' => 'Area Backfill']
    );

    // Node section manual tanpa area_id (kondisi nyata): area harus bisa
    // diturunkan dari segmen ke-3 kode FuncLoc.
    $this->flSection = FunctionalLocation::create([
        'code'         => 'PTBF-DEPBF-AREABF-01',
        'segment'      => '01',
        'name'         => 'Section Backfill',
        'level'        => FunctionalLocation::LEVEL_SECTION,
        'company_id'   => $this->company->id,
        'department_id'=> $this->dept->id,
        'is_active'    => true,
    ]);

    $this->teknisi = Technician::create([
        'name'   => 'Teknisi Backfill',
        'nik'    => 'BF001',
        'group'  => 'reguler',
        'status' => 'active',
    ]);

    $this->aset = Asset::create([
        'equipment_no'   => 'EQ-BF-001',
        'description'    => 'Pompa Backfill',
        'tech_ident_no'  => 'BF-P-01',
        'functional_loc' => $this->flSection->code,
        'funcloc_id'     => $this->flSection->id,
        'status'         => 'active',
    ]);

    // A: punya alat, lokasi kosong -> diisi dari alat.
    $this->laporanAlat = Report::create([
        'technician_id'    => $this->teknisi->id,
        'report_date'      => now(),
        'report_code'      => 'BF-ALAT-01',
        'work_description' => 'Perbaikan pompa backfill',
        'asset_id'         => $this->aset->id,
        'report_type'      => 'equipment_repair',
        'status'           => 'submitted',
    ]);

    // B: tanpa alat, punya funcloc, area kosong -> area diisi dari kode FuncLoc.
    $this->laporanArea = Report::create([
        'technician_id'    => $this->teknisi->id,
        'report_date'      => now(),
        'report_code'      => 'BF-AREA-01',
        'work_description' => 'Pekerjaan area backfill',
        'funcloc_id'       => $this->flSection->id,
        'report_type'      => 'area_work',
        'status'           => 'submitted',
    ]);

    // C: tanpa alat dan tanpa lokasi -> tidak bisa diperbaiki otomatis.
    $this->laporanKosong = Report::create([
        'technician_id'    => $this->teknisi->id,
        'report_date'      => now(),
        'report_code'      => 'BF-KOSONG-01',
        'work_description' => 'Pekerjaan tanpa sumber lokasi',
        'report_type'      => 'general',
        'status'           => 'submitted',
    ]);
});

test('tanpa flag execute command hanya menyimulasikan perubahan', function () {
    $this->artisan('reports:backfill-location')
        ->expectsOutputToContain('MODE SIMULASI')
        ->assertSuccessful();

    expect($this->laporanAlat->fresh()->funcloc_id)->toBeNull()
        ->and($this->laporanAlat->fresh()->area_id)->toBeNull()
        ->and($this->laporanArea->fresh()->area_id)->toBeNull();
});

test('flag execute mengisi funcloc_id dan area_id dari data alat dan kode lokasi', function () {
    $this->artisan('reports:backfill-location --execute')
        ->expectsOutputToContain('MODE SIMPAN')
        ->assertSuccessful();

    $laporanAlat = $this->laporanAlat->fresh();
    expect($laporanAlat->funcloc_id)->toBe($this->flSection->id)
        ->and($laporanAlat->area_id)->toBe($this->area->id);

    // Laporan area: area diturunkan dari kode FuncLoc walau node tanpa area_id.
    expect($this->laporanArea->fresh()->area_id)->toBe($this->area->id);

    // Laporan tanpa sumber lokasi dibiarkan kosong.
    expect($this->laporanKosong->fresh()->funcloc_id)->toBeNull()
        ->and($this->laporanKosong->fresh()->area_id)->toBeNull();
});
