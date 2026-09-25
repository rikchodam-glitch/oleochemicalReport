<?php

use App\Models\Area;
use App\Models\Asset;
use App\Models\Company;
use App\Models\Department;
use App\Models\FunctionalLocation;
use App\Models\SubArea;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    // PT uji dengan satu nomor seri 1xxxxxxx supaya nomor berikutnya bisa dihitung.
    $this->company = Company::firstOrCreate(['code' => 'SITETEST'], ['name' => 'Site Uji']);
    $this->dept    = Department::firstOrCreate(
        ['company_id' => $this->company->id, 'code' => 'DEPTS'],
        ['name' => 'Departemen Uji']
    );
    $this->area    = Area::firstOrCreate(
        ['department_id' => $this->dept->id, 'code' => 'AR01'],
        ['name' => 'Area Uji']
    );
    $this->subArea = SubArea::firstOrCreate(
        ['area_id' => $this->area->id, 'code' => 'SA01'],
        ['name' => 'Sub Area Uji']
    );

    // Node FuncLoc dibuat OTOMATIS oleh SubAreaObserver.
    $this->funcloc = $this->subArea->fresh()->functionalLocation
        ?? FunctionalLocation::create([
            'code'          => 'SITETEST-DEPTS-AR01-SA01',
            'segment'       => 'SA01',
            'name'          => 'Sub Area Uji',
            'level'         => FunctionalLocation::LEVEL_SECTION,
            'company_id'    => $this->company->id,
            'department_id' => $this->dept->id,
            'area_id'       => $this->area->id,
            'sub_area_id'   => $this->subArea->id,
            'is_active'     => true,
        ]);

    // Nomor terbesar milik PT ini: nomor berikutnya harus 10000010.
    Asset::create([
        'equipment_no'   => '10000009',
        'description'    => 'Asset Seri Uji',
        'tech_ident_no'  => 'SERI-UJI-1',
        'equipment_type' => 'PUMP',
        'functional_loc' => $this->funcloc->code,
        'funcloc_id'     => $this->funcloc->id,
        'company_id'     => $this->company->id,
        'department_id'  => $this->dept->id,
        'area_id'        => $this->area->id,
        'sub_area_id'    => $this->subArea->id,
        'status'         => 'active',
        'data_source'    => 'manual',
    ]);
});

test('asset baru tanpa Equipment No dibuatkan nomor otomatis saat disimpan', function () {
    $respons = $this->actingAs($this->admin)->post('/assets', [
        'equipment_no'   => '',
        'description'    => 'Pompa Tanpa Nomor',
        'tech_ident_no'  => 'SERI-UJI-2',
        'equipment_type' => 'PUMP',
        'funcloc_id'     => $this->funcloc->id,
        'status'         => 'active',
    ]);

    $respons->assertRedirect(route('assets.index'))
        ->assertSessionHas('success', fn (string $pesan) => str_contains($pesan, '10000010'));

    $asset = Asset::where('tech_ident_no', 'SERI-UJI-2')->first();

    expect($asset)->not->toBeNull()
        ->and($asset->equipment_no)->toBe('10000010')
        ->and($asset->has_equipment_no)->toBeTrue()
        ->and($asset->data_source)->toBe('manual')
        ->and($asset->funcloc_id)->toBe($this->funcloc->id);
});

test('Equipment No yang dikirim dari form diabaikan karena nomor dibuat sistem', function () {
    $this->actingAs($this->admin)->post('/assets', [
        'equipment_no'   => '80000001',
        'description'    => 'Motor Nomor Manual',
        'tech_ident_no'  => 'SERI-UJI-3',
        'equipment_type' => 'MOTOR',
        'funcloc_id'     => $this->funcloc->id,
        'status'         => 'active',
    ])->assertRedirect(route('assets.index'));

    expect(Asset::where('tech_ident_no', 'SERI-UJI-3')->value('equipment_no'))
        ->toBe('10000010')
        ->and(Asset::where('equipment_no', '80000001')->doesntExist())->toBeTrue();
});

test('endpoint pratinjau mengembalikan nomor otomatis berikutnya untuk lokasi terpilih', function () {
    $this->actingAs($this->admin)
        ->getJson('/assets/locations/next-equipment-no?funcloc_id=' . $this->funcloc->id)
        ->assertStatus(200)
        ->assertJson([
            'success'      => true,
            'equipment_no' => '10000010',
            'pt'           => 'SITETEST',
        ]);

    // Lokasi tidak dikenal tidak boleh memunculkan nomor.
    $this->actingAs($this->admin)
        ->getJson('/assets/locations/next-equipment-no?funcloc_id=999999')
        ->assertStatus(404)
        ->assertJson(['success' => false]);
});

test('PT diambil dari awalan kode lokasi bila node FuncLoc belum punya company_id', function () {
    // Node tanpa relasi ke master Company, kode berawalan kode PT.
    $node = FunctionalLocation::create([
        'code'      => 'SITETEST-PROD-TX01',
        'segment'   => 'TX01',
        'name'      => 'Node Tanpa PT',
        'level'     => FunctionalLocation::LEVEL_SECTION,
        'is_active' => true,
    ]);

    $layanan = app(\App\Services\AssetNumberService::class);

    expect($layanan->companyIdDariKodeFuncLoc($node->code))->toBe($this->company->id)
        ->and($layanan->companyIdDariKodeFuncLoc('KODETANPAPT'))->toBeNull();

    $this->actingAs($this->admin)->post('/assets', [
        'equipment_no'   => '',
        'description'    => 'Blower Node Tanpa PT',
        'tech_ident_no'  => 'SERI-UJI-4',
        'funcloc_id'     => $node->id,
        'status'         => 'active',
    ])->assertRedirect(route('assets.index'));

    $asset = Asset::where('tech_ident_no', 'SERI-UJI-4')->first();

    expect($asset)->not->toBeNull()
        ->and($asset->equipment_no)->toBe('10000010')
        ->and($asset->company_id)->toBe($this->company->id);
});

test('form tambah asset menjelaskan nomor equipment dibuat otomatis', function () {
    $this->actingAs($this->admin)
        ->get('/assets/create')
        ->assertStatus(200)
        ->assertSee('pratinjau-equipment-no', false)
        ->assertSee('Equipment No dibuat otomatis oleh sistem', false)
        // Tidak ada kolom input nomor equipment lagi.
        ->assertDontSee('name="equipment_no"', false);
});
