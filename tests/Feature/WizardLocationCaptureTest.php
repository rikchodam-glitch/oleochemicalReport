<?php

use App\Models\Area;
use App\Models\Asset;
use App\Models\Company;
use App\Models\Department;
use App\Models\FunctionalLocation;
use App\Services\Telegram\ReportWizardService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Regresi penyebab "lokasi kosong" pada halaman laporan: jalur penguncian alat
 * dan jalur penguncian lokasi WAJIB mengisi funcloc_id + area_id, termasuk saat
 * node/aset tidak punya area_id (area diturunkan dari segmen ke-3 kode FuncLoc).
 *
 * Method yang diuji bersifat protected, jadi dipanggil lewat Reflection supaya
 * tidak perlu membuka API baru hanya untuk keperluan test.
 */
function panggilLengkapiLokasi(ReportWizardService $layanan, array $state, ?FunctionalLocation $node, ?Asset $aset = null): array
{
    $metode = new ReflectionMethod($layanan, 'lengkapiStateLokasi');

    return $metode->invoke($layanan, $state, $node, $aset);
}

beforeEach(function () {
    $this->seed();

    $this->company = Company::firstOrCreate(['code' => 'PTWIZ'], ['name' => 'PT Wizard']);
    $this->dept    = Department::firstOrCreate(
        ['company_id' => $this->company->id, 'code' => 'DEPWIZ'],
        ['name' => 'Departemen Wizard']
    );
    $this->area = Area::firstOrCreate(
        ['department_id' => $this->dept->id, 'code' => 'AREAWIZ'],
        ['name' => 'Area Wizard']
    );

    // Node lokasi TANPA area_id (kondisi nyata di data produksi).
    $this->flSection = FunctionalLocation::create([
        'code'         => 'PTWIZ-DEPWIZ-AREAWIZ-01',
        'segment'      => '01',
        'name'         => 'Section Wizard',
        'level'        => FunctionalLocation::LEVEL_SECTION,
        'company_id'   => $this->company->id,
        'department_id'=> $this->dept->id,
        'is_active'    => true,
    ]);

    $this->aset = Asset::create([
        'equipment_no'   => 'EQ-WIZ-001',
        'description'    => 'Pompa Wizard',
        'tech_ident_no'  => 'WIZ-P-01',
        'functional_loc' => $this->flSection->code,
        'funcloc_id'     => $this->flSection->id,
        'company_id'     => $this->company->id,
        'department_id'  => $this->dept->id,
        'status'         => 'active',
    ]);

    $this->layanan = app(ReportWizardService::class);
});

test('penguncian alat mengisi funcloc_id dan area_id walau node lokasi tanpa area', function () {
    $state = panggilLengkapiLokasi($this->layanan, [], null, $this->aset);

    expect($state['funcloc_id'])->toBe($this->flSection->id)
        ->and($state['funcloc_code'])->toBe('PTWIZ-DEPWIZ-AREAWIZ-01')
        ->and($state['funcloc_name'])->toBe('Section Wizard')
        ->and($state['area_id'])->toBe($this->area->id)
        ->and($state['area_code'])->toBe('AREAWIZ');
});

test('penguncian lokasi mengisi funcloc_id dan area_id dari kode FuncLoc', function () {
    $state = panggilLengkapiLokasi($this->layanan, [], $this->flSection);

    expect($state['funcloc_id'])->toBe($this->flSection->id)
        ->and($state['funcloc_code'])->toBe('PTWIZ-DEPWIZ-AREAWIZ-01')
        ->and($state['area_id'])->toBe($this->area->id)
        ->and($state['area_code'])->toBe('AREAWIZ');
});

test('area yang sudah terpasang pada node atau aset dipakai lebih dulu', function () {
    $this->flSection->update(['area_id' => $this->area->id]);

    $state = panggilLengkapiLokasi($this->layanan, [], $this->flSection->fresh());

    expect($state['area_id'])->toBe($this->area->id);
});

test('kode lokasi di luar pola tidak menimbulkan error dan area dibiarkan kosong', function () {
    $nodeAneh = FunctionalLocation::create([
        'code'         => 'TANPAPOLA',
        'segment'      => 'TANPAPOLA',
        'name'         => 'Node Tanpa Pola',
        'level'        => FunctionalLocation::LEVEL_SITE,
        'is_active'    => true,
    ]);

    $state = panggilLengkapiLokasi($this->layanan, [], $nodeAneh);

    expect($state['funcloc_id'])->toBe($nodeAneh->id)
        ->and($state)->not->toHaveKey('area_id');
});
