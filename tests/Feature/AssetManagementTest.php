<?php

use App\Models\Asset;
use App\Models\CmEquipment;
use App\Models\Company;
use App\Models\Department;
use App\Models\FunctionalLocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $perusahaan = Company::first() ?? Company::create(['code' => 'AST', 'name' => 'PT Asset Uji']);

    $this->funcLoc = FunctionalLocation::create([
        'code' => 'AST-PT', 'segment' => 'AST-PT', 'name' => 'PT Asset Uji',
        'level' => 0, 'company_id' => $perusahaan->id, 'is_active' => true,
    ]);

    $this->pompa = Asset::create([
        'tech_ident_no' => 'AST-POMPA-01', 'description' => 'Pompa Asset Uji',
        'equipment_type' => 'PUMP', 'funcloc_id' => $this->funcLoc->id,
        'company_id' => $perusahaan->id, 'status' => 'active', 'data_source' => 'manual',
    ]);

    $this->motor = Asset::create([
        'tech_ident_no' => 'AST-MOTOR-01', 'description' => 'Motor Asset Uji',
        'equipment_type' => 'MOTOR', 'funcloc_id' => $this->funcLoc->id,
        'company_id' => $perusahaan->id, 'status' => 'active', 'data_source' => 'manual',
    ]);
});

test('pencarian asset langsung memfilter equipment dan tabel tanpa status aksi', function () {
    $this->actingAs($this->admin)
        ->get(route('assets.index', ['search' => 'Pompa']))
        ->assertOk()
        ->assertSee('Pompa Asset Uji')
        ->assertDontSee('Motor Asset Uji')
        ->assertSee('id="searchAsset"', false)
        ->assertSee(route('assets.show', $this->pompa), false)
        ->assertDontSee('>Aksi</th>', false);
});

test('detail asset dapat dibuka dari tabel dan memiliki edit serta hapus', function () {
    $this->actingAs($this->admin)
        ->get(route('assets.show', $this->pompa))
        ->assertOk()
        ->assertSee('Edit Asset')
        ->assertSee('Hapus Asset')
        ->assertSee(route('assets.edit', $this->pompa), false)
        ->assertSee(route('assets.destroy', $this->pompa), false);
});

test('asset dapat menyimpan lebih dari satu foto', function () {
    Storage::fake('public');

    $this->actingAs($this->admin)
        ->put(route('assets.update', $this->pompa), [
            'description'    => 'Pompa Asset Uji',
            'tech_ident_no'  => 'AST-POMPA-01',
            'equipment_type' => 'PUMP',
            'funcloc_id'     => $this->funcLoc->id,
            'manufacturer'   => 'Test',
            'model_number'   => 'X1',
            'construct_year' => '2026',
            'status'         => 'active',
            'photos'         => [
                UploadedFile::fake()->image('foto-a.jpg'),
                UploadedFile::fake()->image('foto-b.jpg'),
            ],
        ])
        ->assertRedirect(route('assets.index'));

    $foto = $this->pompa->refresh()->photos;

    expect($foto)->toHaveCount(2);
    Storage::disk('public')->assertExists($foto[0]);
    Storage::disk('public')->assertExists($foto[1]);
});

test('detail asset menampilkan ringkasan equipment CM', function () {
    CmEquipment::create([
        'equipment_tag' => 'AST-POMPA-01',
        'pt_location'   => 'PT Asset Uji',
        'plant'         => 'Plant Uji',
    ]);

    $this->actingAs($this->admin)
        ->get(route('assets.show', $this->pompa))
        ->assertOk()
        ->assertSee('Ringkasan Equipment (CM)')
        ->assertSee('AST-POMPA-01');
});

test('dropdown departemen mengikuti hierarki Functional Location per PT', function () {
    $ces = Company::create(['code' => 'CES-TST', 'name' => 'CES Test']);
    $pt = FunctionalLocation::create([
        'code' => 'CES-TST', 'segment' => 'CES-TST', 'name' => 'CES Test',
        'level' => 0, 'company_id' => $ces->id, 'is_active' => true,
    ]);
    $dept = Department::create([
        'company_id' => $ces->id, 'code' => 'CES-TST-PROD', 'name' => 'Produksi CES Test',
    ]);
    FunctionalLocation::create([
        'code' => 'CES-TST-PROD', 'segment' => 'CES-TST-PROD', 'name' => 'Produksi CES Test',
        'level' => 1, 'parent_id' => $pt->id, 'department_id' => $dept->id,
        'company_id' => $ces->id, 'is_active' => true,
    ]);
    FunctionalLocation::create([
        'code' => 'CES-TST-PROD-BLDG', 'segment' => 'CES-TST-PROD-BLDG', 'name' => 'Building',
        'level' => 2, 'parent_id' => FunctionalLocation::where('code', 'CES-TST-PROD')->value('id'),
        'department_id' => $dept->id, 'company_id' => $ces->id, 'is_active' => true,
    ]);

    $this->actingAs($this->admin)
        ->getJson(route('assets.locations.departments', ['company_id' => $ces->id]))
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonFragment(['code' => 'CES-TST-PROD']);
});

test('sinkronisasi CM membuat asset yang belum ada dan melewati yang sudah ada', function () {
    CmEquipment::create(['equipment_tag' => 'AST-POMPA-01', 'pt_location' => 'PT Asset Uji', 'plant' => 'Plant Uji', 'tipe_lubrikasi' => 'Grease']);
    CmEquipment::create(['equipment_tag' => 'AST-CM-BARU-01', 'pt_location' => 'PT Asset Uji', 'plant' => 'Plant Uji', 'tipe_lubrikasi' => 'Grease']);

    $this->actingAs($this->admin)
        ->post(route('assets.sync-cm'))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(Asset::where('tech_ident_no', 'AST-CM-BARU-01')->where('data_source', 'cm_sync')->exists())->toBeTrue()
        ->and(Asset::where('tech_ident_no', 'AST-POMPA-01')->count())->toBe(1);
});
