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
 * Pencarian lokasi/alat pada mode edit halaman DETAIL laporan.
 *
 * Aturan yang dijaga di sini:
 *   1. Kotak "Cari Alat (semua area)" ada di halaman detail laporan (mode
 *      edit) dan memakai rute reports.locations.cari-alat.
 *   2. Pencarian lintas area hanya mengeluarkan hasil bila kata kunci minimal
 *      2 huruf, dan hasilnya dibatasi 50 baris.
 *   3. Hasil pencarian membawa area & funcloc alat supaya Area, Functional
 *      Location, dan Kode Alat bisa diisi sekaligus (komponen Alpine
 *      terapkanAset) — termasuk alat yang area_id-nya belum terisi.
 *   4. Dropdown alat per area (reports.locations.assets) ikut mendukung `q`
 *      supaya area dengan ratusan alat (data nyata: 756 alat) bisa dicari.
 *   5. Pencarian hanya untuk pengguna yang sudah login.
 */

// Helper pembuat aset uji dengan identitas unik per test.
// funcloc_id WAJIB diisi karena kolomnya NOT NULL sejak migration
// 2026_08_03_000000_make_funcloc_id_required_on_assets_table; nilai bawaannya
// diambil dari FuncLoc pertama yang ada supaya pemanggil tidak perlu repot.
function asetCari(array $data): Asset
{
    return Asset::create(array_merge([
        'equipment_no'   => '90000100',
        'description'    => 'Alat Uji',
        'tech_ident_no'  => 'UJI-01',
        'functional_loc' => null,
        'funcloc_id'     => FunctionalLocation::query()->value('id'),
        'company_id'     => null,
        'department_id'  => null,
        'area_id'        => null,
        'status'         => 'active',
    ], $data));
}

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->company = Company::firstOrCreate(['code' => 'PTCARI'], ['name' => 'PT Cari']);
    $this->dept    = Department::firstOrCreate(
        ['company_id' => $this->company->id, 'code' => 'DEPCARI'],
        ['name' => 'Departemen Cari']
    );
    $this->area = Area::firstOrCreate(
        ['department_id' => $this->dept->id, 'code' => 'AREACARI'],
        ['name' => 'Area Cari']
    );

    $this->flSection = FunctionalLocation::create([
        'code'          => 'PTCARI-DEPCARI-AREACARI-01',
        'segment'       => '01',
        'name'          => 'Section Cari',
        'level'         => FunctionalLocation::LEVEL_SECTION,
        'area_id'       => $this->area->id,
        'company_id'    => $this->company->id,
        'department_id' => $this->dept->id,
        'is_active'     => true,
    ]);

    $this->teknisi = Technician::create([
        'name'   => 'Teknisi Cari Alat',
        'nik'    => 'CARI001',
        'group'  => 'reguler',
        'status' => 'active',
    ]);

    $this->laporan = Report::create([
        'technician_id'         => $this->teknisi->id,
        'creator_id'            => $this->teknisi->id,
        'report_date'           => now()->toDateString(),
        'report_code'           => 'CARI-LAPORAN-01',
        'work_description'      => 'Pekerjaan uji pencarian alat',
        'work_duration_minutes' => 60,
        'status'                => 'completed',
    ]);
});

test('halaman detail memuat kotak pencarian alat lintas area', function () {
    $this->actingAs($this->admin)
        ->get('/reports/' . $this->laporan->id)
        ->assertStatus(200)
        ->assertSee('Cari Alat (semua area)', false)
        ->assertSee('cari_alat_lintas_area', false)
        // Petunjuk teknis: komponen Alpine pengisi lokasi ikut terkirim.
        ->assertSee('terapkanAset', false);
});

test('pencarian alat lintas area tidak keluar untuk kata kunci kurang dari 2 huruf', function () {
    asetCari(['tech_ident_no' => 'ACUJI01', 'equipment_no' => '9000AC1', 'description' => 'Chiller Uji']);

    $this->actingAs($this->admin)
        ->getJson('/reports/locations/cari-alat?q=A')
        ->assertStatus(200)
        ->assertExactJson([]);
});

test('pencarian alat lintas area menemukan alat lewat kode, nomor equipment, dan deskripsi', function () {
    asetCari([
        'tech_ident_no'  => 'ACUJI01',
        'equipment_no'   => '9000AC1',
        'description'    => 'Chiller Ruang Panel',
        'functional_loc' => $this->flSection->code,
        'funcloc_id'     => $this->flSection->id,
        'company_id'     => $this->company->id,
        'department_id'  => $this->dept->id,
        'area_id'        => $this->area->id,
    ]);

    asetCari([
        'tech_ident_no'  => 'MTRUJI99',
        'equipment_no'   => '9000MT9',
        'description'    => 'Motor Blower',
    ]);

    // Lewat kode alat.
    $lewatKode = $this->actingAs($this->admin)
        ->getJson('/reports/locations/cari-alat?q=ACUJI01')
        ->assertStatus(200);

    expect($lewatKode->json())->toHaveCount(1)
        ->and($lewatKode->json()[0]['tech_ident_no'])->toBe('ACUJI01')
        // Area ikut dikirim supaya dropdown Area bisa diisi otomatis.
        ->and($lewatKode->json()[0]['area_id'])->toBe($this->area->id)
        ->and($lewatKode->json()[0]['funcloc_id'])->toBe($this->flSection->id)
        ->and($lewatKode->json()[0]['area_label'])->toContain('AREACARI');

    // Lewat nomor equipment.
    $lewatNomor = $this->actingAs($this->admin)
        ->getJson('/reports/locations/cari-alat?q=9000MT9')
        ->assertStatus(200);

    expect($lewatNomor->json())->toHaveCount(1)
        ->and($lewatNomor->json()[0]['tech_ident_no'])->toBe('MTRUJI99');

    // Lewat deskripsi.
    $lewatDeskripsi = $this->actingAs($this->admin)
        ->getJson('/reports/locations/cari-alat?q=Chiller')
        ->assertStatus(200);

    expect($lewatDeskripsi->json())->toHaveCount(1)
        ->and($lewatDeskripsi->json()[0]['description'])->toBe('Chiller Ruang Panel');
});

test('pencarian alat lintas area menyertakan alat yang belum punya area', function () {
    asetCari([
        'tech_ident_no' => 'TANPAAR01',
        'equipment_no'  => '9000TA1',
        'description'   => 'Alat Tanpa Area',
        'area_id'       => null,
    ]);

    $respons = $this->actingAs($this->admin)
        ->getJson('/reports/locations/cari-alat?q=TANPAAR01')
        ->assertStatus(200);

    expect($respons->json())->toHaveCount(1)
        ->and($respons->json()[0]['area_id'])->toBeNull()
        ->and($respons->json()[0]['area_label'])->toBeNull();
});

test('pencarian alat lintas area dibatasi 50 hasil', function () {
    for ($nomor = 1; $nomor <= 55; $nomor++) {
        asetCari([
            'tech_ident_no' => 'BANJIR' . str_pad((string) $nomor, 3, '0', STR_PAD_LEFT),
            'equipment_no'  => '9001' . str_pad((string) $nomor, 4, '0', STR_PAD_LEFT),
            'description'   => 'Alat Banyak',
        ]);
    }

    $respons = $this->actingAs($this->admin)
        ->getJson('/reports/locations/cari-alat?q=BANJIR')
        ->assertStatus(200);

    expect($respons->json())->toHaveCount(50);
});

test('dropdown alat per area mendukung pencarian q dan tetap memuat semua tanpa q', function () {
    asetCari([
        'tech_ident_no' => 'ACUJI01',
        'equipment_no'  => '9000AC1',
        'description'   => 'Chiller Uji',
        'area_id'       => $this->area->id,
        'funcloc_id'    => $this->flSection->id,
    ]);

    asetCari([
        'tech_ident_no' => 'MTRUJI99',
        'equipment_no'  => '9000MT9',
        'description'   => 'Motor Uji',
        'area_id'       => $this->area->id,
        'funcloc_id'    => $this->flSection->id,
    ]);

    $tanpaKunci = $this->actingAs($this->admin)
        ->getJson('/reports/locations/assets?area_id=' . $this->area->id)
        ->assertStatus(200);

    expect($tanpaKunci->json())->toHaveCount(2);

    $denganKunci = $this->actingAs($this->admin)
        ->getJson('/reports/locations/assets?area_id=' . $this->area->id . '&q=Chiller')
        ->assertStatus(200);

    expect($denganKunci->json())->toHaveCount(1)
        ->and($denganKunci->json()[0]['tech_ident_no'])->toBe('ACUJI01');
});

test('pencarian lokasi funcloc mendukung q berdasarkan kode dan nama', function () {
    // Catatan: membuat Area ikut membuat node FuncLoc lewat AreaObserver,
    // sehingga jumlah total node area ini tidak dipatok — yang diuji adalah
    // penyaringan `q`-nya.
    $cocok = $this->actingAs($this->admin)
        ->getJson('/reports/locations/funclocs?area_id=' . $this->area->id . '&q=' . urlencode('Section Cari'))
        ->assertStatus(200);

    expect($cocok->json())->toHaveCount(1)
        ->and($cocok->json()[0]['code'])->toBe($this->flSection->code);

    // Pencarian lewat kode lokasi juga jalan.
    $lewatKode = $this->actingAs($this->admin)
        ->getJson('/reports/locations/funclocs?area_id=' . $this->area->id . '&q=' . $this->flSection->code)
        ->assertStatus(200);

    expect($lewatKode->json())->toHaveCount(1);

    $tidakCocok = $this->actingAs($this->admin)
        ->getJson('/reports/locations/funclocs?area_id=' . $this->area->id . '&q=TidakAdaSamaSekali')
        ->assertStatus(200);

    expect($tidakCocok->json())->toHaveCount(0);

    // Tanpa `q`, seluruh lokasi area tetap dikirim (tidak dipotong batas 50).
    $semua = $this->actingAs($this->admin)
        ->getJson('/reports/locations/funclocs?area_id=' . $this->area->id)
        ->assertStatus(200);

    expect(count($semua->json()))->toBeGreaterThanOrEqual(1);
});

test('tamu tidak bisa memakai pencarian alat', function () {
    $this->get('/reports/locations/cari-alat?q=ACUJI01')
        ->assertRedirect('/login');
});
