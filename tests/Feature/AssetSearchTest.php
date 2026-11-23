<?php

use App\Models\Area;
use App\Models\Asset;
use App\Models\Company;
use App\Models\Department;
use App\Models\FunctionalLocation;
use App\Models\ItAsset;
use App\Models\SubArea;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Pencarian halaman Asset Management.
 *
 * Aturan yang dijaga:
 *   1. Multi-kata AND; kolom baru (pabrikan, model, tipe, relasi lokasi) ikut.
 *   2. Kode alat persis/awalan diprioritaskan.
 *   3. Dropdown saran: aset, tipe, lokasi; klik item membawa URL yang benar.
 *   4. Kotak pencarian utama (id searchAsset) menggantikan input lama dan
 *      filter lokasi/tipe tetap bisa dipakai bersama kata kunci.
 *   5. Endpoint saran minimal 2 huruf dan hanya untuk pengguna login.
 */

/**
 * Buat satu asset uji dengan funcloc wajib.
 *
 * @param  FunctionalLocation  $funcloc  Lokasi wajib asset
 * @param  array<string, mixed>  $kolom  Kolom tambahan/penimpa
 */
function asetPencarianUji(FunctionalLocation $funcloc, array $kolom = []): Asset
{
    return Asset::create(array_merge([
        'equipment_no' => 'EQ-PCARI-001',
        'description' => 'Asset uji pencarian',
        'tech_ident_no' => 'PCARI-01',
        'functional_loc' => $funcloc->code,
        'funcloc_id' => $funcloc->id,
        'status' => 'active',
        'data_source' => 'manual',
    ], $kolom));
}

/**
 * Posisi URL detail asset pada HTML halaman.
 *
 * @param  string  $html  Isi halaman
 * @param  int  $id  ID asset
 * @return int|false
 */
function posisiAsetPencarianUji(string $html, int $id)
{
    return strpos($html, route('assets.show', $id));
}

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->company = Company::create(['code' => 'PTZQ', 'name' => 'PT Zq']);
    $this->dept = Department::create([
        'company_id' => $this->company->id,
        'code' => 'DEPZQ',
        'name' => 'Departemen Zq',
    ]);
    $this->area = Area::create([
        'department_id' => $this->dept->id,
        'code' => 'AREAZQ',
        'name' => 'Area Zq',
    ]);
    $this->subArea = SubArea::create([
        'area_id' => $this->area->id,
        'code' => 'SUBZQ',
        'name' => 'Sub Area Zq',
    ]);
    $this->funcloc = FunctionalLocation::create([
        'code' => 'PTZQ-DEPZQ-AREAZQ-01',
        'segment' => '01',
        'name' => 'Section Zq',
        'level' => FunctionalLocation::LEVEL_SECTION,
        'area_id' => $this->area->id,
        'company_id' => $this->company->id,
        'department_id' => $this->dept->id,
        'is_active' => true,
    ]);

    $this->pompa = asetPencarianUji($this->funcloc, [
        'equipment_no' => 'EQ-ZQ-POMPA',
        'tech_ident_no' => 'ZQ-POMPA-01',
        'description' => 'Ganti seal pompa utama',
        'equipment_type' => 'PUMP',
        'manufacturer' => 'Grundfos',
        'model_number' => 'CR-10',
        'company_id' => $this->company->id,
        'department_id' => $this->dept->id,
        'area_id' => $this->area->id,
        'sub_area_id' => $this->subArea->id,
    ]);

    $this->motor = asetPencarianUji($this->funcloc, [
        'equipment_no' => 'EQ-ZQ-MOTOR',
        'tech_ident_no' => 'ZQ-MOTOR-01',
        'description' => 'Motor listrik conveyor',
        'equipment_type' => 'MOTOR',
        'company_id' => $this->company->id,
        'area_id' => $this->area->id,
    ]);
});

test('pencarian asset multi kata dan kolom baru menemukan asset yang tepat', function () {
    $this->actingAs($this->admin)
        ->get('/assets?search='.urlencode('seal pompa'))
        ->assertStatus(200)
        ->assertSee(route('assets.show', $this->pompa), false)
        ->assertDontSee(route('assets.show', $this->motor), false);

    // Semua kata wajib cocok (AND).
    $this->actingAs($this->admin)
        ->get('/assets?search='.urlencode('seal conveyor'))
        ->assertStatus(200)
        ->assertDontSee(route('assets.show', $this->pompa), false)
        ->assertDontSee(route('assets.show', $this->motor), false);

    // Pabrikan dan model.
    $this->actingAs($this->admin)
        ->get('/assets?search=Grundfos')
        ->assertStatus(200)
        ->assertSee(route('assets.show', $this->pompa), false);

    $this->actingAs($this->admin)
        ->get('/assets?search=CR-10')
        ->assertStatus(200)
        ->assertSee(route('assets.show', $this->pompa), false);
});

test('pencarian asset menjangkau relasi PT departemen area sub area dan lokasi', function () {
    foreach (['PTZQ', 'DEPZQ', 'AREAZQ', 'SUBZQ', $this->funcloc->code] as $kunci) {
        $this->actingAs($this->admin)
            ->get('/assets?search='.urlencode($kunci))
            ->assertStatus(200)
            ->assertSee(route('assets.show', $this->pompa), false);
    }

    // Nama Functional Location juga dicari.
    $this->actingAs($this->admin)
        ->get('/assets?search='.urlencode('Section Zq'))
        ->assertStatus(200)
        ->assertSee(route('assets.show', $this->pompa), false);
});

test('pencarian asset menemukan baris cermin IT lewat tag dan nama perangkat', function () {
    $itAsset = ItAsset::create([
        'device_type' => 'PC',
        'tag_name' => 'PC-ZQ-MIRROR',
        'name' => 'Komputer Cermin Zq',
        'status' => 'active',
    ]);

    $cermin = asetPencarianUji($this->funcloc, [
        'equipment_no' => 'EQ-ZQ-MIRROR',
        'tech_ident_no' => 'ZQ-MIRROR-01',
        'description' => 'Baris cermin perangkat IT',
        'equipment_type' => 'PC',
        'data_source' => 'it_sync',
        'it_asset_id' => $itAsset->id,
    ]);

    $this->actingAs($this->admin)
        ->get('/assets?search=PC-ZQ-MIRROR')
        ->assertStatus(200)
        ->assertSee(route('assets.show', $cermin), false);
});

test('hasil pencarian asset mendahulukan kode alat yang cocok persis', function () {
    $kodePersis = asetPencarianUji($this->funcloc, [
        'equipment_no' => 'EQ-ZQ-RANK-1',
        'tech_ident_no' => 'ZQ-RANK-99',
        'description' => 'Asset lama biasa',
    ]);

    $sebutDiDeskripsi = asetPencarianUji($this->funcloc, [
        'equipment_no' => 'EQ-ZQ-RANK-2',
        'tech_ident_no' => 'ZQ-LAIN-01',
        'description' => 'Asset baru yang menyebut ZQ-RANK-99 di deskripsi',
    ]);

    $html = $this->actingAs($this->admin)
        ->get('/assets?search=ZQ-RANK-99')
        ->assertStatus(200)
        ->getContent();

    $posisiKode = posisiAsetPencarianUji($html, $kodePersis->id);
    $posisiDeskripsi = posisiAsetPencarianUji($html, $sebutDiDeskripsi->id);

    expect($posisiKode)->not->toBeFalse()
        ->and($posisiDeskripsi)->not->toBeFalse()
        ->and($posisiKode)->toBeLessThan($posisiDeskripsi);
});

test('endpoint saran asset mengembalikan kelompok aset tipe dan lokasi', function () {
    $asetZeta = asetPencarianUji($this->funcloc, [
        'equipment_no' => 'EQ-ZQ-ZETA',
        'tech_ident_no' => 'ZETA-ASSET-01',
        'description' => 'Zeta Pump Utama',
        'equipment_type' => 'ZETA PUMP',
        'area_id' => $this->area->id,
    ]);

    $lokasiZeta = FunctionalLocation::create([
        'code' => 'ZETA-LOC-01',
        'segment' => '01',
        'name' => 'Zeta Location',
        'level' => FunctionalLocation::LEVEL_SECTION,
        'area_id' => $this->area->id,
        'company_id' => $this->company->id,
        'department_id' => $this->dept->id,
        'is_active' => true,
    ]);

    asetPencarianUji($lokasiZeta, [
        'equipment_no' => 'EQ-ZQ-ZETA-2',
        'tech_ident_no' => 'ZQ-ZETA-02',
        'description' => 'Asset di lokasi zeta',
    ]);

    $respons = $this->actingAs($this->admin)
        ->getJson('/assets/cari?q=Zeta');

    $respons->assertStatus(200)
        ->assertJsonPath('kata_kunci', 'Zeta')
        ->assertJsonStructure([
            'kata_kunci',
            'total',
            'kelompok' => [
                '*' => ['tipe', 'label', 'jumlah', 'items'],
            ],
        ]);

    $data = $respons->json();
    $tipes = array_column($data['kelompok'], 'tipe');

    expect($data['total'])->toBeGreaterThanOrEqual(1)
        ->and($tipes)->toContain('aset')
        ->and($tipes)->toContain('tipe')
        ->and($tipes)->toContain('lokasi');

    $grupAset = collect($data['kelompok'])->firstWhere('tipe', 'aset');

    expect(array_column($grupAset['items'], 'url'))->toContain(route('assets.show', $asetZeta->id))
        ->and(count($grupAset['items']))->toBeLessThanOrEqual(5);
});

test('endpoint saran asset mengabaikan kata kunci kurang dari dua huruf', function () {
    $this->actingAs($this->admin)
        ->getJson('/assets/cari?q=Z')
        ->assertStatus(200)
        ->assertJsonPath('total', 0)
        ->assertJsonPath('kelompok', []);
});

test('tamu tidak bisa memakai endpoint saran asset', function () {
    $this->get('/assets/cari?q=pompa')
        ->assertRedirect('/login');
});

test('kotak pencarian asset di atas halaman dan filter tetap bisa dipakai bersama', function () {
    $this->actingAs($this->admin)
        ->get('/assets')
        ->assertStatus(200)
        ->assertSee('id="searchAsset"', false)
        ->assertSee('name="search"', false)
        // Input lama di panel Filter sudah tidak ada.
        ->assertDontSee('placeholder="Equipment, deskripsi, tech ident..."', false);

    // Kata kunci + filter area harus berlaku bersama.
    $this->actingAs($this->admin)
        ->get('/assets?search='.urlencode('pompa').'&area_id='.$this->area->id)
        ->assertStatus(200)
        ->assertSee(route('assets.show', $this->pompa), false)
        ->assertDontSee(route('assets.show', $this->motor), false);
});
