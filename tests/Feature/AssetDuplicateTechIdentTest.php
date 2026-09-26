<?php

use App\Models\Asset;
use App\Models\Company;
use App\Models\FunctionalLocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();

    $this->admin   = User::where('email', 'admin@oleochemical.pro')->firstOrFail();
    $this->company = Company::firstOrCreate(['code' => 'SITETEST'], ['name' => 'Site Uji']);

    $this->funcloc = FunctionalLocation::create([
        'code'       => 'SITETEST-PROD-AR01-SA01',
        'segment'    => 'SA01',
        'name'       => 'Sub Area Uji',
        'level'      => FunctionalLocation::LEVEL_SECTION,
        'company_id' => $this->company->id,
        'is_active'  => true,
    ]);

    // Dua aset dengan Tech Ident No sama (kondisi yang membuat import
    // menandai baris "Perlu Diperiksa").
    foreach (['90000101', '90000102'] as $nomor) {
        Asset::create([
            'equipment_no'   => $nomor,
            'description'    => 'BLENDING',
            'tech_ident_no'  => 'AGT02A',
            'equipment_type' => 'AGITATOR',
            'functional_loc' => $this->funcloc->code,
            'funcloc_id'     => $this->funcloc->id,
            'company_id'     => $this->company->id,
            'status'         => 'active',
            'data_source'    => 'import_excel',
        ]);
    }
});

test('command mendata Tech Ident No dobel beserta identitas tiap aset', function () {
    $kode = Artisan::call('assets:duplicate-tech-ident');

    $keluaran = Artisan::output();

    expect($kode)->toBe(0)
        ->and($keluaran)->toContain('AGT02A')
        ->and($keluaran)->toContain('90000101')
        ->and($keluaran)->toContain('90000102');
});

test('command melaporkan bersih bila tidak ada Tech Ident No dobel', function () {
    Asset::where('tech_ident_no', 'AGT02A')->where('equipment_no', '90000102')->update(['tech_ident_no' => 'AGT02B']);

    Artisan::call('assets:duplicate-tech-ident');

    expect(Artisan::output())->toContain('Tidak ada Tech Ident No yang dobel');
});
