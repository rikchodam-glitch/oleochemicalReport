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
 * Catatan: node lokasi uji sengaja TIDAK diberi area_id supaya pembacaan area
 * lewat turunan kode FuncLoc (segmen ke-3) juga ikut teruji.
 */
beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->company = Company::firstOrCreate(['code' => 'PTUJI'], ['name' => 'PT Uji']);
    $this->dept    = Department::firstOrCreate(
        ['company_id' => $this->company->id, 'code' => 'DEPUJI'],
        ['name' => 'Departemen Uji']
    );
    $this->area = Area::firstOrCreate(
        ['department_id' => $this->dept->id, 'code' => 'AREAUJI'],
        ['name' => 'Area Uji']
    );

    // Node FuncLoc L0/L1/L2 dibuat OTOMATIS oleh AreaObserver saat Area dibuat
    // (trait ResolvesFuncLocHierarchy), jadi tidak dibuat manual lagi di sini.
    $this->flArea = $this->area->fresh()->functionalLocation;
    $this->flDept = $this->flArea->parent;

    $this->flSection = FunctionalLocation::create([
        'code'         => 'PTUJI-DEPUJI-AREAUJI-01',
        'segment'      => '01',
        'name'         => 'Section Uji',
        'level'        => FunctionalLocation::LEVEL_SECTION,
        'parent_id'    => $this->flArea->id,
        'company_id'   => $this->company->id,
        'department_id'=> $this->dept->id,
        'is_active'    => true,
    ]);

    $this->teknisi = Technician::create([
        'name'   => 'Teknisi Lokasi Uji',
        'nik'    => 'LOK001',
        'group'  => 'reguler',
        'status' => 'active',
    ]);

    $this->aset = Asset::create([
        'equipment_no'   => 'EQ-LOK-001',
        'description'    => 'Pompa Lokasi Uji',
        'tech_ident_no'  => 'LOK-P-01',
        'functional_loc' => $this->flSection->code,
        'funcloc_id'     => $this->flSection->id,
        'company_id'     => $this->company->id,
        'department_id'  => $this->dept->id,
        'status'         => 'active',
    ]);

    // Laporan ber-lokasi langsung (tanpa alat) dan laporan lewat data alat.
    $this->laporanLokasi = Report::create([
        'technician_id'    => $this->teknisi->id,
        'creator_id'       => $this->teknisi->id,
        'report_date'      => now(),
        'report_code'      => 'LOK-LANGSUNG-01',
        'work_description' => 'Pembersihan area section uji',
        'funcloc_id'       => $this->flSection->id,
        'report_type'      => 'area_work',
        'status'           => 'submitted',
    ]);

    $this->laporanAlat = Report::create([
        'technician_id'    => $this->teknisi->id,
        'creator_id'       => $this->teknisi->id,
        'report_date'      => now(),
        'report_code'      => 'LOK-LEWAT-ALAT-01',
        'work_description' => 'Ganti seal pompa lokasi uji',
        'asset_id'         => $this->aset->id,
        'report_type'      => 'equipment_repair',
        'status'           => 'submitted',
    ]);

    // Laporan di luar lokasi ini (tidak boleh muncul di halaman detail lokasi).
    $this->laporanLuar = Report::create([
        'technician_id'    => $this->teknisi->id,
        'creator_id'       => $this->teknisi->id,
        'report_date'      => now(),
        'report_code'      => 'LOK-LUAR-01',
        'work_description' => 'Pekerjaan di area lain',
        'report_type'      => 'area_work',
        'status'           => 'submitted',
    ]);
});

test('halaman detail lokasi menampilkan informasi lokasi, alat, dan laporan', function () {
    $this->actingAs($this->admin)
        ->get('/locations/' . $this->flSection->id)
        ->assertStatus(200)
        ->assertSee('PTUJI-DEPUJI-AREAUJI-01', false)
        ->assertSee('Section Uji', false)
        ->assertSee('LOK-P-01', false)
        ->assertSee('EQ-LOK-001', false)
        ->assertSee('Alat Terdaftar', false)
        ->assertSee('Laporan Lokasi Ini', false)
        ->assertSee('LOK-LANGSUNG-01', false)
        ->assertSee('LOK-LEWAT-ALAT-01', false)
        ->assertDontSee('LOK-LUAR-01', false);
});

test('halaman lokasi induk mencakup seluruh lokasi turunannya', function () {
    $this->actingAs($this->admin)
        ->get('/locations/' . $this->flArea->id)
        ->assertStatus(200)
        ->assertSee('LOK-LANGSUNG-01', false)
        ->assertSee('LOK-LEWAT-ALAT-01', false)
        ->assertSee('LOK-P-01', false);
});

test('halaman laporan menautkan lokasi dan alat yang bisa diklik', function () {
    $this->actingAs($this->admin)
        ->get('/reports?tanggal=' . now()->toDateString())
        ->assertStatus(200)
        ->assertSee(route('locations.show', $this->flSection), false)
        ->assertSee(route('assets.show', $this->aset), false)
        ->assertSee('PTUJI-DEPUJI-AREAUJI-01', false);
});

test('halaman detail laporan menautkan lokasi dan alat', function () {
    $this->actingAs($this->admin)
        ->get('/reports/' . $this->laporanLokasi->id)
        ->assertStatus(200)
        ->assertSee(route('locations.show', $this->flSection), false);

    $this->actingAs($this->admin)
        ->get('/reports/' . $this->laporanAlat->id)
        ->assertStatus(200)
        ->assertSee(route('assets.show', $this->aset), false);
});

test('halaman detail alat menautkan lokasi alat tersebut', function () {
    $this->actingAs($this->admin)
        ->get('/assets/' . $this->aset->id)
        ->assertStatus(200)
        ->assertSee(route('locations.show', $this->flSection), false);
});

test('halaman lokasi menautkan seluruh riwayat laporan lokasi tersebut', function () {
    $this->actingAs($this->admin)
        ->get('/locations/' . $this->flSection->id)
        ->assertStatus(200)
        ->assertSee('Lihat semua di halaman Laporan', false)
        ->assertSee('date_from=', false);
});

test('pencarian halaman laporan menemukan kode lokasi, kode laporan, dan kode alat', function () {
    $rentang = '&date_from=' . now()->subDays(7)->toDateString() . '&date_to=' . now()->toDateString();

    // Kode lokasi (Functional Location).
    $this->actingAs($this->admin)
        ->get('/reports?search=' . $this->flSection->code . $rentang)
        ->assertStatus(200)
        ->assertSee('LOK-LANGSUNG-01', false)
        ->assertSee('LOK-LEWAT-ALAT-01', false);

    // Kode laporan.
    $this->actingAs($this->admin)
        ->get('/reports?search=LOK-LEWAT-ALAT-01' . $rentang)
        ->assertStatus(200)
        ->assertSee('LOK-LEWAT-ALAT-01', false)
        ->assertDontSee('LOK-LANGSUNG-01', false);

    // Kode alat (Tech Ident No) milik laporan perbaikan.
    $this->actingAs($this->admin)
        ->get('/reports?search=LOK-P-01' . $rentang)
        ->assertStatus(200)
        ->assertSee('LOK-LEWAT-ALAT-01', false);
});

