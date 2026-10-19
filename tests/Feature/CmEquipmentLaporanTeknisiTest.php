<?php

use App\Models\Asset;
use App\Models\CmEquipment;
use App\Models\Company;
use App\Models\Department;
use App\Models\FunctionalLocation;
use App\Models\Report;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Penjaga bagian "Laporan Teknisi (Daftar Perbaikan)" pada halaman detail
 * equipment CM (/cm/equipment/{tag}).
 *
 * Permintaan user (14/10/2026): hasil laporan tiap teknisi (yang laporannya
 * sudah punya tagging equipment) ditampilkan pada halaman tiap equipment, dan
 * TIDAK boleh salah equipment — hanya laporan yang bertag equipment itu yang
 * boleh tampil.
 *
 * Tagging yang dipakai (lihat CmController::whereLaporanEquipment()):
 *   1. reports.equipment_tag sama persis dengan cm_equipment.equipment_tag;
 *   2. reports.asset_id menunjuk asset yang tech_ident_no-nya sama persis
 *      dengan cm_equipment.equipment_tag (relasi CmEquipment::asset());
 *   3. laporan LAMA yang belum punya tag & asset, tetapi deskripsinya menyebut
 *      tag tersebut (EquipmentMentionService — cocok persis di batas kata,
 *      bukan LIKE wildcard). Aturan tersebut ditambahkan 19/10/2026 karena
 *      laporan wizard Telegram lama tidak menyimpan equipment_tag sama sekali.
 * Laporan "2-TEST-LAP01" tetap TIDAK boleh tampil di "TEST-LAP01" karena
 * penyebutan tag yang lebih panjang mengalahkan tag pendek di dalamnya.
 */

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->teknisi = Technician::create([
        'name' => 'Teknisi Laporan', 'nik' => 'LAP001', 'group' => 'reguler', 'status' => 'active',
    ]);

    // Lokasi (funcloc) wajib diisi asset — sama seperti berkas uji lain.
    $this->company = Company::firstOrCreate(['code' => 'PTLAP'], ['name' => 'PT Laporan']);
    $this->dept    = Department::firstOrCreate(
        ['company_id' => $this->company->id, 'code' => 'DEPLAP'],
        ['name' => 'Departemen Laporan']
    );

    $this->flSection = FunctionalLocation::create([
        'code'          => 'PTLAP-DEPLAP-01',
        'segment'       => '01',
        'name'          => 'Section Laporan',
        'level'         => FunctionalLocation::LEVEL_SECTION,
        'company_id'    => $this->company->id,
        'department_id' => $this->dept->id,
        'is_active'     => true,
    ]);

    $this->equipment = CmEquipment::create([
        'equipment_tag'  => 'TEST-LAP01',
        'pt_location'    => 'PT-TEST',
        'plant'          => 'PLANT-1',
        'tipe_lubrikasi' => 'oli',
    ]);

    // Asset yang BERTAG equipment di atas (tech_ident_no = equipment_tag).
    $this->asset = Asset::create([
        'equipment_no'   => 'EQ-LAP-001',
        'description'    => 'Pompa Laporan',
        'tech_ident_no'  => 'TEST-LAP01',
        'functional_loc' => $this->flSection->code,
        'funcloc_id'     => $this->flSection->id,
        'status'         => 'active',
        'data_source'    => 'import_excel',
    ]);

    // Asset equipment LAIN yang kodenya hanya MIRIP ("2-TEST-LAP01").
    $this->assetLain = Asset::create([
        'equipment_no'   => 'EQ-LAP-002',
        'description'    => 'Pompa Laporan BD2',
        'tech_ident_no'  => '2-TEST-LAP01',
        'functional_loc' => $this->flSection->code,
        'funcloc_id'     => $this->flSection->id,
        'status'         => 'active',
        'data_source'    => 'import_excel',
    ]);

    // Laporan yang BERTAG equipment ini (lewat asset).
    $this->laporanBertag = Report::create([
        'technician_id'    => $this->teknisi->id,
        'report_date'      => '2026-05-01',
        'report_code'      => 'LAP-BERTAG-001',
        'work_description' => 'Ganti bearing pompa laporan',
        'root_cause'       => 'Bearing aus',
        'asset_id'         => $this->asset->id,
        'report_type'      => 'equipment_repair',
        'status'           => 'completed',
    ]);

    // Laporan equipment LAIN: deskripsinya MENYEBUT kode equipment ini, tetapi
    // tertag ke asset "2-TEST-LAP01" (equipment berbeda) — tidak boleh tampil.
    $this->laporanAlatLain = Report::create([
        'technician_id'    => $this->teknisi->id,
        'report_date'      => '2026-05-02',
        'report_code'      => 'LAP-ALAT-LAIN-002',
        'work_description' => 'Balancing TEST-LAP01 versi BD2',
        'asset_id'         => $this->assetLain->id,
        'report_type'      => 'equipment_repair',
        'status'           => 'completed',
    ]);

    // Laporan pekerjaan area: TIDAK bertag equipment dan TIDAK menyebut kode
    // equipment mana pun, jadi tidak boleh muncul di halaman equipment.
    $this->laporanArea = Report::create([
        'technician_id'    => $this->teknisi->id,
        'report_date'      => '2026-05-03',
        'report_code'      => 'LAP-AREA-003',
        'work_description' => 'Bersih-bersih area sekitar pompa laporan',
        'report_type'      => 'area_work',
        'status'           => 'completed',
    ]);
});

test('halaman equipment menampilkan laporan teknisi yang bertag equipment ini', function () {
    $response = $this->actingAs($this->admin)->get('/cm/equipment/TEST-LAP01?range=all');

    $response->assertStatus(200);
    $response->assertSee('Laporan Teknisi (Daftar Perbaikan)', false);
    $response->assertSee('Ganti bearing pompa laporan', false);
    $response->assertSee('Penyebab: Bearing aus', false);
    $response->assertSee('Teknisi Laporan', false);
    $response->assertSee(route('reports.show', $this->laporanBertag), false);
    $response->assertSee(route('assets.show', $this->asset), false);
    $response->assertSee('1 laporan', false);
});

test('laporan equipment lain tidak ikut tampil walau kodenya disebut di deskripsi', function () {
    $response = $this->actingAs($this->admin)->get('/cm/equipment/TEST-LAP01?range=all');

    $response->assertStatus(200);
    $response->assertDontSee('Balancing TEST-LAP01 versi BD2', false);
    $response->assertDontSee('Pompa Laporan BD2', false);
});

test('laporan area tanpa tagging equipment tidak ditampilkan', function () {
    $response = $this->actingAs($this->admin)->get('/cm/equipment/TEST-LAP01?range=all');

    $response->assertStatus(200);
    $response->assertDontSee('Bersih-bersih area sekitar TEST-LAP01', false);
});

test('laporan juga ikut tampil bila equipment_tag pada laporan terisi', function () {
    $laporan = Report::create([
        'technician_id'    => $this->teknisi->id,
        'report_date'      => '2026-05-04',
        'report_code'      => 'LAP-TAG-004',
        'work_description' => 'Cek vibrasi lewat tagging equipment_tag',
        'equipment_tag'    => 'TEST-LAP01',
        'report_type'      => 'equipment_repair',
        'status'           => 'completed',
    ]);

    $response = $this->actingAs($this->admin)->get('/cm/equipment/TEST-LAP01?range=all');

    $response->assertStatus(200);
    $response->assertSee('Cek vibrasi lewat tagging equipment_tag', false);
    $response->assertSee(route('reports.show', $laporan), false);
});

test('laporan milik equipment lain tidak muncul di halaman equipment yang berbeda', function () {
    CmEquipment::create([
        'equipment_tag'  => '2-TEST-LAP01',
        'pt_location'    => 'PT-TEST',
        'plant'          => 'PLANT-1',
        'tipe_lubrikasi' => 'oli',
    ]);

    $response = $this->actingAs($this->admin)->get('/cm/equipment/2-TEST-LAP01?range=all');

    $response->assertStatus(200);
    // Laporan milik equipment ini (asset 2-TEST-LAP01) tampil...
    $response->assertSee('Balancing TEST-LAP01 versi BD2', false);
    // ...tetapi laporan milik equipment TEST-LAP01 TIDAK ikut tampil.
    $response->assertDontSee('Ganti bearing pompa laporan', false);
});

test('laporan lama tanpa tag tetap tampil bila deskripsinya menyebut kode equipment', function () {
    Report::create([
        'technician_id'    => $this->teknisi->id,
        'report_date'      => '2026-05-05',
        'report_code'      => 'LAP-MENTION-005',
        'work_description' => 'Cek vibrasi pompa TEST-LAP01 area BD',
        'report_type'      => 'area_work',
        'status'           => 'completed',
    ]);

    $response = $this->actingAs($this->admin)->get('/cm/equipment/TEST-LAP01?range=all');

    $response->assertStatus(200);
    $response->assertSee('Cek vibrasi pompa TEST-LAP01 area BD', false);
});

test('penyebutan kode equipment yang lebih panjang tidak dianggap equipment pendek', function () {
    CmEquipment::create([
        'equipment_tag'  => '2-TEST-LAP01',
        'pt_location'    => 'PT-TEST',
        'plant'          => 'PLANT-1',
        'tipe_lubrikasi' => 'oli',
    ]);

    Report::create([
        'technician_id'    => $this->teknisi->id,
        'report_date'      => '2026-05-06',
        'report_code'      => 'LAP-MENTION-006',
        'work_description' => 'Perbaikan pompa 2-TEST-LAP01',
        'report_type'      => 'area_work',
        'status'           => 'completed',
    ]);

    // Tidak boleh ikut tampil di equipment pendek (TEST-LAP01)...
    $this->actingAs($this->admin)
        ->get('/cm/equipment/TEST-LAP01?range=all')
        ->assertStatus(200)
        ->assertDontSee('Perbaikan pompa 2-TEST-LAP01', false);

    // ...tetapi tampil di equipment yang kodenya disebut penuh.
    $this->actingAs($this->admin)
        ->get('/cm/equipment/2-TEST-LAP01?range=all')
        ->assertStatus(200)
        ->assertSee('Perbaikan pompa 2-TEST-LAP01', false);
});

test('equipment tanpa laporan bertag tetap tampil dengan pesan kosong', function () {
    CmEquipment::create([
        'equipment_tag'  => 'TEST-LAP09',
        'pt_location'    => 'PT-TEST',
        'plant'          => 'PLANT-1',
        'tipe_lubrikasi' => 'oli',
    ]);

    $response = $this->actingAs($this->admin)->get('/cm/equipment/TEST-LAP09?range=all');

    $response->assertStatus(200);
    $response->assertSee('Laporan Teknisi (Daftar Perbaikan)', false);
    $response->assertSee('Belum ada laporan teknisi yang bertag equipment ini.', false);
});

