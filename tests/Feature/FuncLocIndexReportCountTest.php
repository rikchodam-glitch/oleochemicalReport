<?php

use App\Models\Area;
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
 * Halaman Functional Location: kode/nama node bisa diklik menuju halaman
 * detail lokasi, dan setiap node menampilkan jumlah laporan di lokasi tersebut
 * (termasuk turunannya).
 */
beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->company = Company::firstOrCreate(['code' => 'PTFLI'], ['name' => 'PT FuncLoc']);
    $this->dept    = Department::firstOrCreate(
        ['company_id' => $this->company->id, 'code' => 'DEPFLI'],
        ['name' => 'Departemen FuncLoc']
    );
    $this->area = Area::firstOrCreate(
        ['department_id' => $this->dept->id, 'code' => 'AREAFLI'],
        ['name' => 'Area FuncLoc']
    );

    $this->flArea = $this->area->fresh()->functionalLocation;

    $this->flSection = FunctionalLocation::create([
        'code'         => 'PTFLI-DEPFLI-AREAFLI-01',
        'segment'      => '01',
        'name'         => 'Section FuncLoc',
        'level'        => FunctionalLocation::LEVEL_SECTION,
        'parent_id'    => $this->flArea->id,
        'company_id'   => $this->company->id,
        'department_id'=> $this->dept->id,
        'is_active'    => true,
    ]);

    $this->teknisi = Technician::create([
        'name' => 'Teknisi FuncLoc', 'nik' => 'FLI001', 'group' => 'reguler', 'status' => 'active',
    ]);

    $this->aset = Asset::create([
        'equipment_no'   => 'EQ-FLI-001',
        'description'    => 'Pompa FuncLoc',
        'tech_ident_no'  => 'FLI-P-01',
        'functional_loc' => $this->flSection->code,
        'funcloc_id'     => $this->flSection->id,
        'status'         => 'active',
    ]);

    // 2 laporan ber-lokasi section + 1 laporan lewat data alat (semuanya di section).
    Report::create([
        'technician_id' => $this->teknisi->id,
        'report_date'   => now()->toDateString(),
        'report_code'   => 'FLI-LOK-01',
        'work_description' => 'Pekerjaan lokasi FuncLoc 1',
        'funcloc_id'    => $this->flSection->id,
        'report_type'   => 'area_work',
        'status'        => 'submitted',
    ]);

    Report::create([
        'technician_id' => $this->teknisi->id,
        'report_date'   => now()->toDateString(),
        'report_code'   => 'FLI-LOK-02',
        'work_description' => 'Pekerjaan lokasi FuncLoc 2',
        'funcloc_id'    => $this->flSection->id,
        'report_type'   => 'area_work',
        'status'        => 'submitted',
    ]);

    Report::create([
        'technician_id' => $this->teknisi->id,
        'report_date'   => now()->toDateString(),
        'report_code'   => 'FLI-ALAT-01',
        'work_description' => 'Perbaikan pompa FuncLoc',
        'asset_id'      => $this->aset->id,
        'report_type'   => 'equipment_repair',
        'status'        => 'submitted',
    ]);
});

test('halaman Functional Location menautkan tiap node ke halaman detail lokasi', function () {
    $this->actingAs($this->admin)
        ->get('/func-locs')
        ->assertStatus(200)
        ->assertSee(route('locations.show', $this->flSection), false)
        ->assertSee(route('locations.show', $this->flArea), false);
});

test('halaman Functional Location menampilkan jumlah laporan per node termasuk turunannya', function () {
    $html = $this->actingAs($this->admin)->get('/func-locs')->assertStatus(200)->getContent();

    // Node section punya 3 laporan (2 ber-lokasi + 1 lewat data alat); node
    // induknya (area/departemen/PT) ikut menghitung laporan turunan tersebut.
    expect($html)->toContain(route('locations.show', $this->flSection))
        ->and(substr_count($html, '3 laporan'))->toBeGreaterThanOrEqual(2);
});

test('tampilan datar hasil filter juga menampilkan jumlah asset dan laporan', function () {
    $this->actingAs($this->admin)
        ->get('/func-locs?level=3')
        ->assertStatus(200)
        ->assertSee('3 laporan', false)
        ->assertSee('1 asset', false);
});
