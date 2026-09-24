<?php

use App\Models\Area;
use App\Models\Asset;
use App\Models\CmEquipment;
use App\Models\CmReading;
use App\Models\Company;
use App\Models\Department;
use App\Models\FunctionalLocation;
use App\Models\ItAsset;
use App\Models\Report;
use App\Models\SubArea;
use App\Models\Technician;
use App\Models\User;
use App\Services\Telegram\AssetInfoService;
use App\Services\Telegram\ReportWizardService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();
    $this->info  = app(AssetInfoService::class);

    // Master lokasi: PT -> Departemen -> Area -> Sub Area
    $this->company = Company::firstOrCreate(['code' => 'SITETEST'], ['name' => 'Site Uji']);
    $this->dept    = Department::firstOrCreate(
        ['company_id' => $this->company->id, 'code' => 'DEPTS'],
        ['name' => 'Departemen Uji']
    );

    $this->flSite = FunctionalLocation::create([
        'code'       => 'PT-SITEUJI',
        'segment'    => 'PT-SITEUJI',
        'name'       => 'PT Uji',
        'level'      => FunctionalLocation::LEVEL_SITE,
        'company_id' => $this->company->id,
        'is_active'  => true,
    ]);
    $this->flDept = FunctionalLocation::create([
        'code'          => 'PT-SITEUJI-DEPT',
        'segment'       => 'DEPT',
        'name'          => 'Departemen Uji',
        'level'         => FunctionalLocation::LEVEL_DEPARTMENT,
        'parent_id'     => $this->flSite->id,
        'company_id'    => $this->company->id,
        'department_id' => $this->dept->id,
        'is_active'     => true,
    ]);
    $this->flSection = FunctionalLocation::create([
        'code'          => 'PT-SITEUJI-DEPT-SEC',
        'segment'       => 'SEC',
        'name'          => 'Section Uji',
        'level'         => FunctionalLocation::LEVEL_SECTION,
        'parent_id'     => $this->flDept->id,
        'company_id'    => $this->company->id,
        'department_id' => $this->dept->id,
        'is_active'     => true,
    ]);

    $this->area = Area::create([
        'department_id' => $this->dept->id,
        'code'          => 'AR01',
        'name'          => 'Area Uji',
    ]);
    $this->subArea = SubArea::create([
        'area_id' => $this->area->id,
        'code'    => 'SA01',
        'name'    => 'Sub Area Uji',
    ]);

    // Aset industri: punya area + sub area, tag memuat kode section 6010 dan tipe MO.
    $this->asset = Asset::create([
        'equipment_no'   => 'EQ-INFO-001',
        'description'    => 'Motor Pompa Uji',
        'tech_ident_no'  => '6010MO01',
        'functional_loc' => $this->flSection->code,
        'funcloc_id'     => $this->flSection->id,
        'company_id'     => $this->company->id,
        'department_id'  => $this->dept->id,
        'area_id'        => $this->area->id,
        'sub_area_id'    => $this->subArea->id,
        'status'         => 'active',
        'data_source'    => 'import_excel',
    ]);

    // Aset tanpa area di departemen yang sama (uji pintasan "Tanpa Area").
    $this->assetTanpaArea = Asset::create([
        'equipment_no'   => 'EQ-INFO-002',
        'description'    => 'Panel Uji Tanpa Area',
        'tech_ident_no'  => '6010SDP02',
        'functional_loc' => $this->flDept->code,
        'funcloc_id'     => $this->flDept->id,
        'company_id'     => $this->company->id,
        'department_id'  => $this->dept->id,
        'status'         => 'active',
        'data_source'    => 'import_excel',
    ]);
    // Perangkat IT + baris cerminnya di tabel assets.
    $this->itAsset = ItAsset::create([
        'device_type' => 'PC',
        'tag_name'    => 'WS-SITEUJI-Uji',
        'name'        => 'Pengguna Uji',
        'ip_address'  => '10.10.10.10',
        'status'      => 'active',
        'data_source' => 'manual',
        'funcloc_id'  => $this->flDept->id,
    ]);

    $this->assetIt = Asset::create([
        'equipment_no'   => 'EQ-INFO-IT-001',
        'description'    => 'PC Pengguna Uji',
        'tech_ident_no'  => 'WS-SITEUJI-Uji',
        'functional_loc' => $this->flDept->code,
        'funcloc_id'     => $this->flDept->id,
        'company_id'     => $this->company->id,
        'department_id'  => $this->dept->id,
        'it_asset_id'    => $this->itAsset->id,
        'status'         => 'active',
        'data_source'    => 'it_sync',
    ]);

    // Riwayat: satu laporan perbaikan (CM) dan satu pengecekan (PM).
    $this->technician = Technician::create([
        'name'        => 'Teknisi Info',
        'nik'         => 'INFO001',
        'status'      => 'active',
        'telegram_id' => null,
    ]);

    Report::create([
        'technician_id'       => $this->technician->id,
        'report_date'         => now()->subDays(3)->format('Y-m-d'),
        'work_description'    => 'Ganti bearing motor',
        'root_cause'          => 'Bearing aus',
        'jenis_pekerjaan'     => 'CM',
        'work_classification' => 'zpm1',
        'asset_id'            => $this->asset->id,
        'equipment_tag'       => '6010MO01',
        'status'              => 'submitted',
    ]);

    Report::create([
        'technician_id'       => $this->technician->id,
        'report_date'         => now()->subDays(10)->format('Y-m-d'),
        'work_description'    => 'Pengecekan rutin',
        'jenis_pekerjaan'     => 'PM',
        'work_classification' => 'zpm3',
        'asset_id'            => $this->asset->id,
        'equipment_tag'       => '6010MO01',
        'status'              => 'submitted',
    ]);

    // Condition Monitoring terakhir.
    $this->cmEquipment = CmEquipment::create([
        'equipment_tag'  => '6010MO01',
        'pt_location'    => 'SITETEST',
        'plant'          => 'PROD',
        'tipe_lubrikasi' => 'oli',
    ]);

    CmReading::create([
        'cm_equipment_id' => $this->cmEquipment->id,
        'tanggal'         => now()->subDays(2)->format('Y-m-d'),
        'status'          => 'start',
        'kondisi'         => 'alarm',
        'ndev_motor'      => 5.20,
        'max_vibration'   => 4.60,
        'max_temp'        => 69.00,
    ]);

    // Tombol pencarian cepat untuk pengujian callback.
    $this->callbackPlant      = 'info:co:' . $this->company->id;
    $this->callbackDepartment = 'info:dp:' . $this->dept->id;
    $this->callbackArea       = 'info:ar:' . $this->area->id;
});


// ============================================================
// HIRARKI: PLANT -> DEPARTEMEN -> AREA -> SUB AREA -> SECTION -> TIPE -> ALAT
// ============================================================

test('tanpa kata kunci, hirarki dimulai dari Plant dan selalu ada tombol Tutup', function () {
    $respons = $this->info->search('');

    expect($respons['message'])->toContain('Asisten Informasi Perangkat');

    $teksTombol = collect($respons['keyboard'])->pluck('text')->implode(' | ');

    expect($teksTombol)->toContain('Site Uji')
        ->and($teksTombol)->toContain('Tutup')
        ->and(collect($respons['keyboard'])->pluck('callback_data'))->toContain('info:close');
});

test('pilih Plant menampilkan Departemen dan tombol kembali', function () {
    $respons   = $this->info->handleCallback($this->callbackPlant);
    $callbacks = collect($respons['keyboard'])->pluck('callback_data');

    expect($respons['message'])->toContain('Departemen')
        ->and($callbacks)->toContain('info:dp:' . $this->dept->id)
        ->and($callbacks)->toContain('info:root')
        ->and($callbacks)->toContain('info:close');
});

test('pilih Departemen menampilkan Area plus pintasan Tanpa Area', function () {
    $respons   = $this->info->handleCallback($this->callbackDepartment);
    $callbacks = collect($respons['keyboard'])->pluck('callback_data');

    expect($callbacks)->toContain('info:ar:' . $this->area->id)
        ->and($callbacks)->toContain('info:na:' . $this->dept->id)
        ->and(collect($respons['keyboard'])->pluck('text')->implode(' | '))->toContain('Tanpa Area');
});

test('pilih Area menampilkan Sub Area dan pintasan semua alat di area', function () {
    $respons   = $this->info->handleCallback($this->callbackArea);
    $callbacks = collect($respons['keyboard'])->pluck('callback_data');

    expect($callbacks)->toContain('info:sa:' . $this->subArea->id)
        ->and($callbacks)->toContain('info:all:a_' . $this->area->id)
        ->and($respons['message'])->toContain('AR01');
});

test('level Tipe alat tampil lebih dulu sebelum daftar alat', function () {
    // Tambah alat kedua dengan tipe berbeda di sub area yang sama, supaya level
    // Tipe alat ikut ditampilkan (satu tipe saja langsung ke daftar alat).
    Asset::create([
        'equipment_no'   => 'EQ-INFO-003',
        'description'    => 'Pompa Uji',
        'tech_ident_no'  => '6010P02',
        'functional_loc' => $this->flSection->code,
        'funcloc_id'     => $this->flSection->id,
        'company_id'     => $this->company->id,
        'department_id'  => $this->dept->id,
        'area_id'        => $this->area->id,
        'sub_area_id'    => $this->subArea->id,
        'status'         => 'active',
        'data_source'    => 'import_excel',
    ]);

    $responsTipe = $this->info->handleCallback('info:all:s_' . $this->subArea->id);

    expect($responsTipe['message'])->toContain('Pilih tipe alat');

    $callbackAlat = collect($responsTipe['keyboard'])
        ->pluck('callback_data')
        ->first(fn ($cb) => str_starts_with((string) $cb, 'info:eq:'));

    expect($callbackAlat)->not->toBeNull();

    $responsAlat = $this->info->handleCallback((string) $callbackAlat);

    expect($responsAlat['message'])->toContain('Pilih alat')
        ->and(collect($responsAlat['keyboard'])->pluck('callback_data'))
        ->toContain('info:dev:' . $this->asset->id);
});

test('tombol Kembali menaikkan satu level hirarki', function () {
    $respons = $this->info->handleCallback('info:up:a_' . $this->area->id);

    expect($respons['message'])->toContain('Departemen Uji')
        ->and(collect($respons['keyboard'])->pluck('callback_data'))->toContain('info:ar:' . $this->area->id);
});

test('tombol Tutup mengembalikan pesan penutup tanpa tombol', function () {
    $respons = $this->info->handleCallback('info:close');

    expect($respons['message'])->toContain('Pencarian informasi ditutup')
        ->and($respons['keyboard'])->toBe([]);
});

// ============================================================
// PESAN BANTUAN: CONTOH GENERIK (TANPA NAMA ORANG TERTENTU)
// ============================================================

test('pesan bantuan memakai contoh generik PC dengan penanda nama pemakai', function () {
    $respons = $this->info->search('kata-kunci-yang-tidak-ada-sama-sekali');

    expect($respons['message'])->toContain('/info PC <nama pemakai>')
        ->and($respons['message'])->not->toContain('Hafidz');
});


// ============================================================
// DETAIL ASET INDUSTRI (LOKASI, TEKNISI, KERUSAKAN, PENGECEKAN)
// ============================================================

test('detail aset industri menampilkan lokasi lengkap dan riwayat perawatan', function () {
    $respons = $this->info->detail((int) $this->asset->id);
    $pesan   = $respons['message'];

    expect($pesan)->toContain('6010MO01')
        ->and($pesan)->toContain('Motor Pompa Uji')
        ->and($pesan)->toContain('Site Uji')
        ->and($pesan)->toContain('Departemen Uji')
        ->and($pesan)->toContain('AR01 - Area Uji')
        ->and($pesan)->toContain('SA01 - Sub Area Uji')
        ->and($pesan)->toContain('Teknisi terakhir: Teknisi Info')
        ->and($pesan)->toContain('Kerusakan/pekerjaan terakhir:')
        ->and($pesan)->toContain('Bearing aus')
        ->and($pesan)->toContain('Pengecekan (PM) terakhir:')
        ->and($pesan)->toContain('Condition Monitoring terakhir:')
        ->and($pesan)->toContain('ALARM')
        ->and($pesan)->toContain('/assets/' . $this->asset->id);
});

test('detail aset industri menyediakan tombol Buat Laporan untuk aset itu', function () {
    $respons = $this->info->detail((int) $this->asset->id);
    $tombol  = collect($respons['keyboard']);

    expect($tombol->firstWhere('callback_data', 'info:report:' . $this->asset->id))->not->toBeNull()
        ->and($tombol->pluck('callback_data'))->toContain('info:close');
});

test('detail perangkat IT menampilkan pemakai, IP, dan tombol Buat Laporan', function () {
    $respons = $this->info->detail((int) $this->assetIt->id);

    expect($respons['message'])->toContain('WS-SITEUJI-Uji')
        ->and($respons['message'])->toContain('Pengguna Uji')
        ->and($respons['message'])->toContain('10.10.10.10')
        ->and(collect($respons['keyboard'])->pluck('callback_data'))
        ->toContain('info:report:' . $this->assetIt->id);
});

// ============================================================
// TOMBOL REPORT -> WIZARD LAPORAN DENGAN ALAT SUDAH TERKUNCI
// ============================================================

test('tombol Buat Laporan membuka wizard dengan alat terkunci dan langsung ke jenis pekerjaan', function () {
    $wizard = app(ReportWizardService::class);
    $chatId = 'info-uji-001';

    $respons = $wizard->startWizardForAsset($chatId, $this->asset);

    expect($respons['message'])->toContain('dimulai')
        ->and($respons['message'])->toContain('klasifikasi pekerjaan');

    $state = $wizard->getState($chatId);

    expect($state['equipment_id'])->toBe($this->asset->id)
        ->and($state['equipment_ident'])->toBe('6010MO01')
        ->and($state['step'])->toBe('work_classification');

    // Klasifikasi ZPM 1 -> pilih jenis pekerjaan.
    $jenis = $wizard->handleCallback($chatId, 'classification:zpm1');
    expect($jenis['message'])->toContain('Pilih jenis pekerjaan');

    // Setelah jenis pekerjaan dipilih, pencarian equipment DILEWATI karena alat
    // sudah terkunci - teknisi langsung masuk ke step durasi kerja.
    $durasi = $wizard->handleCallback($chatId, 'jenis_pekerjaan:Mekanik');

    expect($durasi['message'])->toContain('Ketik rentang waktu')
        ->and($durasi['message'])->not->toContain('Pilih Equipment');

    $wizard->destroyWizard($chatId);
});

test('halaman bot tetap bisa dibuka setelah asisten info diganti', function () {
    $this->actingAs($this->admin)->get('/bot')->assertStatus(200);
});
