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
 * Pemilih lokasi bertingkat pada mode edit halaman detail laporan
 * (08/10/2026).
 *
 * Aturan yang dijaga di sini:
 *   1. Endpoint reports.locations.hirarki mengembalikan anak node, alat pada
 *      node itu, dan area node induknya dalam satu permintaan.
 *   2. Tanpa parent_id, daftar yang keluar adalah PT (level 0).
 *   3. Node tidak aktif tidak ikut ditawarkan.
 *   4. Halaman detail memuat pemilihnya beserta jalur lokasi laporan yang
 *      sedang tersimpan (dipakai untuk membuka kembali pilihan saat dimuat).
 *   5. Penambahan alat/lokasi tambahan memakai pemilih yang sama (mode item).
 */
beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->company = Company::firstOrCreate(['code' => 'PTHIRARKI'], ['name' => 'PT Hirarki']);
    $this->dept    = Department::firstOrCreate(
        ['company_id' => $this->company->id, 'code' => 'DEPHIRARKI'],
        ['name' => 'Departemen Hirarki']
    );
    $this->area = Area::firstOrCreate(
        ['department_id' => $this->dept->id, 'code' => 'AREAHIRARKI'],
        ['name' => 'Area Hirarki']
    );

    // Node L0/L1/L2 dibuat OTOMATIS oleh observer Company/Department/Area
    // (ResolvesFuncLocHierarchy), jadi di sini hanya diambil, bukan dibuat ulang.
    $this->pt = FunctionalLocation::where('company_id', $this->company->id)
        ->ofLevel(FunctionalLocation::LEVEL_SITE)
        ->firstOrFail();

    $this->departemen = FunctionalLocation::where('department_id', $this->dept->id)
        ->ofLevel(FunctionalLocation::LEVEL_DEPARTMENT)
        ->firstOrFail();

    $this->nodeArea = FunctionalLocation::where('area_id', $this->area->id)
        ->ofLevel(FunctionalLocation::LEVEL_AREA)
        ->firstOrFail();

    $this->section = FunctionalLocation::create([
        'code'       => 'PTHIRARKI-DEPHIRARKI-AREAHIRARKI-SEC01',
        'segment'    => 'SEC01',
        'name'       => 'Section Satu',
        'level'      => FunctionalLocation::LEVEL_SECTION,
        'parent_id'  => $this->nodeArea->id,
        'area_id'    => $this->area->id,
        'is_active'  => true,
    ]);

    $this->sectionMati = FunctionalLocation::create([
        'code'       => 'PTHIRARKI-DEPHIRARKI-AREAHIRARKI-SEC02',
        'segment'    => 'SEC02',
        'name'       => 'Section Non Aktif',
        'level'      => FunctionalLocation::LEVEL_SECTION,
        'parent_id'  => $this->nodeArea->id,
        'area_id'    => $this->area->id,
        'is_active'  => false,
    ]);

    $this->alat = Asset::create([
        'equipment_no'   => '91000001',
        'description'    => 'Pompa Hirarki',
        'tech_ident_no'  => 'HIR-P01',
        'functional_loc' => $this->section->code,
        'funcloc_id'     => $this->section->id,
        'company_id'     => $this->company->id,
        'department_id'  => $this->dept->id,
        'area_id'        => $this->area->id,
        'status'         => 'active',
    ]);

    Asset::create([
        'equipment_no'   => '91000002',
        'description'    => 'Kipas Hirarki',
        'tech_ident_no'  => 'HIR-K01',
        'functional_loc' => $this->section->code,
        'funcloc_id'     => $this->section->id,
        'company_id'     => $this->company->id,
        'department_id'  => $this->dept->id,
        'area_id'        => $this->area->id,
        'status'         => 'active',
    ]);

    $this->teknisi = Technician::create([
        'name'   => 'Teknisi Hirarki',
        'nik'    => 'HIR001',
        'group'  => 'reguler',
        'status' => 'active',
    ]);

    $this->laporan = Report::create([
        'technician_id'         => $this->teknisi->id,
        'creator_id'            => $this->teknisi->id,
        'report_date'           => now()->toDateString(),
        'report_code'           => 'RPT-HIRARKI-1',
        'work_description'      => 'Uji pemilih hierarki lokasi',
        'work_duration_minutes' => 60,
        'area_id'               => $this->area->id,
        'funcloc_id'            => $this->section->id,
        'asset_id'              => $this->alat->id,
        'report_type'           => 'equipment_repair',
        'status'                => 'completed',
    ]);
});

test('tanpa parent_id endpoint mengembalikan daftar PT', function () {
    $balasan = $this->actingAs($this->admin)->getJson('/reports/locations/hirarki');

    $balasan->assertStatus(200)
        ->assertJsonPath('induk', null)
        ->assertJsonPath('assets', []);

    $kode = collect($balasan->json('nodes'))->pluck('code');

    expect($kode)->toContain('PTHIRARKI');
});

test('endpoint mengembalikan anak, alat, dan area node induk', function () {
    $balasan = $this->actingAs($this->admin)
        ->getJson('/reports/locations/hirarki?parent_id=' . $this->section->id);

    $balasan->assertStatus(200)
        ->assertJsonPath('induk.id', $this->section->id)
        ->assertJsonPath('induk.area_id', $this->area->id)
        ->assertJsonPath('nodes', []);

    $alat = collect($balasan->json('assets'));

    expect($alat->pluck('tech_ident_no'))->toContain('HIR-P01');

    // area_label dipakai pemilih untuk mengisi Functional Location laporan
    // tanpa harus menebak kode lokasinya.
    expect($alat->firstWhere('tech_ident_no', 'HIR-P01')['area_label'])->toBe($this->section->code);
});

test('node tidak aktif tidak ditawarkan pada pemilih', function () {
    $balasan = $this->actingAs($this->admin)
        ->getJson('/reports/locations/hirarki?parent_id=' . $this->nodeArea->id);

    $kode = collect($balasan->json('nodes'))->pluck('code');

    expect($kode)->toContain($this->section->code)
        ->and($kode)->not->toContain($this->sectionMati->code);
});

test('pencarian alat pada satu node memakai kata kunci', function () {
    $balasan = $this->actingAs($this->admin)
        ->getJson('/reports/locations/hirarki?parent_id=' . $this->section->id . '&q=Kipas');

    $kode = collect($balasan->json('assets'))->pluck('tech_ident_no');

    expect($kode)->toContain('HIR-K01')
        ->and($kode)->not->toContain('HIR-P01');
});

test('daftar alat dipotong 50 baris dan jumlah_alat tetap total', function () {
    // Satu section di data nyata bisa memuat 200+ alat; endpoint memotongnya
    // 50 baris tapi tetap melaporkan jumlah seluruhnya supaya pemanggil bisa
    // menyuruh pengguna memakai kotak penyaring.
    for ($i = 1; $i <= 55; $i++) {
        Asset::create([
            'equipment_no'   => '920' . str_pad((string) $i, 5, '0', STR_PAD_LEFT),
            'description'    => 'Alat Uji ' . $i,
            'tech_ident_no'  => 'HIR-UJI-' . $i,
            'functional_loc' => $this->section->code,
            'funcloc_id'     => $this->section->id,
            'area_id'        => $this->area->id,
            'status'         => 'active',
        ]);
    }

    $balasan = $this->actingAs($this->admin)
        ->getJson('/reports/locations/hirarki?parent_id=' . $this->section->id);

    // 2 alat bawaan + 55 alat uji baru.
    $balasan->assertStatus(200)
        ->assertJsonPath('jumlah_alat', 57);

    expect(count($balasan->json('assets')))->toBe(50);
});

test('halaman detail memuat pemilih hierarki beserta jalur lokasi laporan', function () {
    $balasan = $this->actingAs($this->admin)->get('/reports/' . $this->laporan->id);

    $balasan->assertStatus(200)
        ->assertSee('Telusuri Hierarki Lokasi', false)
        ->assertSee('data-pilih-hirarki', false)
        ->assertSee('data-mode="laporan"', false)
        ->assertSee('reports/locations/hirarki', false);

    // Jalur lokasi laporan dikirim sebagai JSON (root -> node terpilih) supaya
    // pilihan lama terbuka kembali saat halaman dimuat.
    $jalur = [$this->pt->id, $this->departemen->id, $this->nodeArea->id, $this->section->id];

    expect($balasan->getContent())->toContain(e(json_encode($jalur)));
});

test('panel alat tambahan memakai pemilih hierarki yang sama dengan mode item', function () {
    $this->actingAs($this->admin)
        ->get('/reports/' . $this->laporan->id)
        ->assertStatus(200)
        ->assertSee('Tambah dari Hirarki Lokasi', false)
        ->assertSee('data-mode="item"', false)
        ->assertSee('Tambah sebagai alat', false)
        ->assertSee('Tambah lokasi ini', false);
});
