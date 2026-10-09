<?php

use App\Models\Area;
use App\Models\Asset;
use App\Models\CmEquipment;
use App\Models\CmFinding;
use App\Models\CmReading;
use App\Models\Company;
use App\Models\Department;
use App\Models\FunctionalLocation;
use App\Models\Report;
use App\Models\SubArea;
use App\Models\Technician;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    // --- Data master dasar ---
    $this->area  = Area::firstOrFail();
    $this->subArea = SubArea::firstOrCreate([
        'area_id' => $this->area->id,
        'code'    => 'TEST01',
    ], [
        'name' => 'Test Sub Area',
    ]);

    $this->technician = Technician::create([
        'name'        => 'Teknisi Smoke',
        'nik'         => 'SMOKE001',
        'status'      => 'active',
        'telegram_id' => null,
    ]);

    // Functional Location (level Site -> Department -> Area -> Section)
    $company = Company::firstOrCreate(['code' => 'SITETEST'], ['name' => 'Site Test']);
    $dept    = Department::firstOrCreate(
        ['company_id' => $company->id, 'code' => 'DEPTS'],
        ['name' => 'Departemen Test']
    );

    $this->flSite = FunctionalLocation::create([
        'code'       => 'PT-TEST',
        'segment'    => 'PT-TEST',
        'name'       => 'PT Test',
        'level'      => FunctionalLocation::LEVEL_SITE,
        'company_id' => $company->id,
        'is_active'  => true,
    ]);
    $this->flDept = FunctionalLocation::create([
        'code'         => 'PT-TEST-DEPT',
        'segment'      => 'DEPT',
        'name'         => 'Departemen Test',
        'level'        => FunctionalLocation::LEVEL_DEPARTMENT,
        'parent_id'    => $this->flSite->id,
        'company_id'   => $company->id,
        'department_id'=> $dept->id,
        'is_active'    => true,
    ]);
    $this->flArea = FunctionalLocation::create([
        'code'         => 'PT-TEST-DEPT-AREA',
        'segment'      => 'AREA',
        'name'         => 'Area Test',
        'level'        => FunctionalLocation::LEVEL_AREA,
        'parent_id'    => $this->flDept->id,
        'company_id'   => $company->id,
        'department_id'=> $dept->id,
        'area_id'      => $this->area->id,
        'is_active'    => true,
    ]);
    $this->flSection = FunctionalLocation::create([
        'code'         => 'PT-TEST-DEPT-AREA-SEC',
        'segment'      => 'SEC',
        'name'         => 'Section Test',
        'level'        => FunctionalLocation::LEVEL_SECTION,
        'parent_id'    => $this->flArea->id,
        'company_id'   => $company->id,
        'department_id'=> $dept->id,
        'area_id'      => $this->area->id,
        'sub_area_id'  => $this->subArea->id,
        'is_active'    => true,
    ]);

    $this->asset = Asset::create([
        'equipment_no'   => 'EQ-TEST-001',
        'description'    => 'Pompa Test',
        'tech_ident_no'  => 'TEST-P-01',
        'functional_loc' => $this->flSection->code,
        'funcloc_id'     => $this->flSection->id,
        'company_id'     => $company->id,
        'department_id'  => $dept->id,
        'area_id'        => $this->area->id,
        'sub_area_id'    => $this->subArea->id,
        'status'         => 'active',
    ]);

    $this->cmEquipment = CmEquipment::create([
        'equipment_tag'      => 'TEST-6821MX01',
        'pt_location'        => 'PT-TEST',
        'plant'              => 'PLANT-1',
        'tipe_lubrikasi'     => 'oli',
    ]);

    $this->cmReading = CmReading::create([
        'cm_equipment_id' => $this->cmEquipment->id,
        'tanggal'         => now()->format('Y-m-d'),
        'status'          => 'start',
        'kondisi'         => 'alarm',
        'ndev_motor'      => 5.20,
        'ndev_pompa'      => 6.10,
    ]);

    $this->cmFinding = CmFinding::create([
        'cm_equipment_id' => $this->cmEquipment->id,
        'cm_reading_id'   => $this->cmReading->id,
        'kode_finding'    => 'FIND-' . now()->format('Ymd') . '-001',
        'severity'        => 'high',
        'kategori'        => 'Bearing Defect',
        'deskripsi'       => 'Vibrasi tinggi pada bearing motor.',
        'analysis'        => 'Spektrum menunjukkan sideband bearing.',
        'status'          => 'open',
        'tanggal_temuan'  => now()->format('Y-m-d'),
    ]);

    $this->workOrder = WorkOrder::create([
        'equipment_tag'            => $this->cmEquipment->equipment_tag,
        'tanggal_kejadian'         => now()->format('Y-m-d'),
        'tanggal_eksekusi_selesai' => now()->addDay()->format('Y-m-d'),
        'jenis_pekerjaan'          => 'CM',
        'linked_finding_id'        => $this->cmFinding->id,
        'deskripsi'                => 'Penggantian bearing motor.',
        'root_cause'               => 'Keausan bearing.',
        'created_by'               => $this->admin->id,
    ]);

    $this->report = Report::create([
        'technician_id'         => $this->technician->id,
        'creator_id'            => $this->technician->id,
        'report_date'           => now(),
        'report_code'           => 'RPT-' . now()->format('Ymd') . '-SMOKE',
        'work_description'      => 'Perbaikan pompa test',
        'work_duration_minutes' => 90,
        'root_cause'            => 'Keausan normal.',
        'area_id'               => $this->area->id,
        'funcloc_id'            => $this->flArea->id,
        'asset_id'              => $this->asset->id,
        'report_type'           => 'equipment_repair',
        'work_classification'   => 'zpm1',
        'status'                => 'completed',
    ]);
});

// ============================================================
// HALAMAN DASHBOARD & INFORMASI
// ============================================================

test('POST /login dengan kredensial admin berhasil', function () {
    $response = $this->post('/login', [
        'email'    => 'admin@oleochemical.pro',
        'password' => 'password123',
    ]);

    $response->assertRedirect('/dashboard');
    $this->assertAuthenticated();
});

test('GET /dashboard -> 200', function () {
    $this->actingAs($this->admin)->get('/dashboard')->assertStatus(200);
});

test('GET /reliability -> 200', function () {
    $this->actingAs($this->admin)->get('/reliability')->assertStatus(200);
});

test('GET /bot -> 200', function () {
    $this->actingAs($this->admin)->get('/bot')->assertStatus(200);
});

test('GET /bot/polling/status -> 200', function () {
    $this->actingAs($this->admin)->get('/bot/polling/status')->assertStatus(200);
});

test('GET /ai-providers -> 200', function () {
    $this->actingAs($this->admin)->get('/ai-providers')->assertStatus(200);
});

// ============================================================
// HALAMAN CONDITION MONITORING
// ============================================================

test('GET /cm/overview -> 200', function () {
    $this->actingAs($this->admin)->get('/cm/overview')->assertStatus(200);
});

test('GET /cm/findings -> 200', function () {
    $this->actingAs($this->admin)->get('/cm/findings')->assertStatus(200);
});

test('GET /cm/monitoring -> 200', function () {
    $this->actingAs($this->admin)->get('/cm/monitoring')->assertStatus(200);
});

test('GET /cm/equipment-status -> 200', function () {
    $this->actingAs($this->admin)->get('/cm/equipment-status')->assertStatus(200);
});

test('GET /cm/report-analysis -> 200', function () {
    $this->actingAs($this->admin)->get('/cm/report-analysis')->assertStatus(200);
});

test('GET /cm/import redirects to overview (sengaja redirect oleh controller)', function () {
    $this->actingAs($this->admin)->get('/cm/import')->assertRedirect(route('cm.overview'));
});

test('GET /cm/equipment/{tag} -> 200', function () {
    $this->actingAs($this->admin)->get("/cm/equipment/{$this->cmEquipment->equipment_tag}")->assertStatus(200);
});

test('GET /cm/findings/{kodeFinding} -> 200', function () {
    $this->actingAs($this->admin)->get("/cm/findings/{$this->cmFinding->kode_finding}")->assertStatus(200);
});

// ============================================================
// HALAMAN ASSETS
// ============================================================

test('GET /assets -> 200', function () {
    $this->actingAs($this->admin)->get('/assets')->assertStatus(200);
});

test('GET /assets/create -> 200', function () {
    $this->actingAs($this->admin)->get('/assets/create')->assertStatus(200);
});

test('GET /assets/import -> 200', function () {
    $this->actingAs($this->admin)->get('/assets/import')->assertStatus(200);
});

test('GET /assets/{asset} -> 200', function () {
    $this->actingAs($this->admin)->get("/assets/{$this->asset->id}")->assertStatus(200);
});

test('GET /assets/{asset}/edit -> 200', function () {
    $this->actingAs($this->admin)->get("/assets/{$this->asset->id}/edit")->assertStatus(200);
});

test('GET /assets/unlinked-funcloc -> 200 (fitur rekonsiliasi FuncLoc)', function () {
    $this->actingAs($this->admin)->get('/assets/unlinked-funcloc')->assertStatus(200);
});

// ============================================================
// HALAMAN FUNC LOCS
// ============================================================

test('GET /func-locs -> 200', function () {
    $this->actingAs($this->admin)->get('/func-locs')->assertStatus(200);
});

test('GET /func-locs/create -> 200', function () {
    $this->actingAs($this->admin)->get('/func-locs/create')->assertStatus(200);
});

test('GET /func-locs/{func_loc}/edit -> 200', function () {
    $this->actingAs($this->admin)->get("/func-locs/{$this->flSection->id}/edit")->assertStatus(200);
});

test('GET /func-locs/{func_loc}/move -> 200 (fitur pindah node)', function () {
    $this->actingAs($this->admin)->get("/func-locs/{$this->flSection->id}/move")->assertStatus(200);
});

// ============================================================
// HALAMAN REPORTS (fitur ZPM yang di-port)
// ============================================================

test('GET /reports -> 200', function () {
    $this->actingAs($this->admin)->get('/reports')->assertStatus(200);
});

test('GET /reports/create -> 200', function () {
    $this->actingAs($this->admin)->get('/reports/create')->assertStatus(200);
});

test('GET /reports/{report} -> 200', function () {
    $this->actingAs($this->admin)->get("/reports/{$this->report->id}")->assertStatus(200);
});

test('GET /reports/{report}/edit -> 404 karena halaman edit sudah dihapus', function () {
    $this->actingAs($this->admin)->get("/reports/{$this->report->id}/edit")->assertStatus(404);
});

// ============================================================
// HALAMAN TECHNICIANS
// ============================================================

test('GET /technicians -> 200', function () {
    $this->actingAs($this->admin)->get('/technicians')->assertStatus(200);
});

test('GET /technicians/create -> 200', function () {
    $this->actingAs($this->admin)->get('/technicians/create')->assertStatus(200);
});

test('GET /technicians/{technician} -> 200', function () {
    $this->actingAs($this->admin)->get("/technicians/{$this->technician->id}")->assertStatus(200);
});

test('GET /technicians/{technician}/edit -> 200', function () {
    $this->actingAs($this->admin)->get("/technicians/{$this->technician->id}/edit")->assertStatus(200);
});

// ============================================================
// HALAMAN WORK ORDERS
// ============================================================

test('GET /work-orders -> 200', function () {
    $this->actingAs($this->admin)->get('/work-orders')->assertStatus(200);
});

test('GET /work-orders/create -> 200', function () {
    $this->actingAs($this->admin)->get('/work-orders/create')->assertStatus(200);
});

test('GET /work-orders/{work_order} -> 200', function () {
    $this->actingAs($this->admin)->get("/work-orders/{$this->workOrder->id}")->assertStatus(200);
});

test('GET /work-orders/{work_order}/edit -> 200', function () {
    $this->actingAs($this->admin)->get("/work-orders/{$this->workOrder->id}/edit")->assertStatus(200);
});

// ============================================================
// HALAMAN INTEGRASI PR/PO SPARE PART
// ============================================================

test('GET /sparepart -> 200', function () {
    $this->actingAs($this->admin)->get('/sparepart')->assertStatus(200);
});
