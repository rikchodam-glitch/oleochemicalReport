<?php

use App\Models\Area;
use App\Models\Asset;
use App\Models\Company;
use App\Models\Department;
use App\Models\FunctionalLocation;
use App\Models\SubArea;
use App\Models\User;
use App\Services\ImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

/**
 * Susun isi file CSV dengan header PERSIS seperti hasil Export aset.
 *
 * @param  array<int, array<int, string>>  $baris
 * @return string
 */
function csvExportAset(array $baris): string
{
    $header = [
        'Equipment No', 'Description', 'Tech Ident No', 'Equipment Type',
        'Functional Loc', 'Company', 'Department', 'Area', 'Sub Area',
        'Manufacturer', 'Model Number', 'Construct Year', 'Status',
        'Data Source', 'Imported At', 'Created At', 'Updated At',
    ];

    $handle = fopen('php://temp', 'r+');
    fputcsv($handle, $header);

    foreach ($baris as $isi) {
        fputcsv($handle, $isi);
    }

    rewind($handle);
    $isiCsv = stream_get_contents($handle);
    fclose($handle);

    return (string) $isiCsv;
}

/**
 * Bungkus isi CSV menjadi UploadedFile yang bisa dibaca ImportService.
 *
 * @param  string  $isiCsv
 * @return UploadedFile
 */
function fileImportAset(string $isiCsv): UploadedFile
{
    return UploadedFile::fake()->createWithContent('assets-export-uji.csv', $isiCsv);
}

beforeEach(function () {
    $this->seed();

    $this->admin  = User::where('email', 'admin@oleochemical.pro')->firstOrFail();
    $this->import = app(ImportService::class);

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

    // Node FuncLoc untuk sub area ini dibuat OTOMATIS oleh SubAreaObserver,
    // jadi test cukup memakai node yang sudah ada (kalau belum ada, buat manual).
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

    // Aset industri yang akan diperbarui lewat import.
    $this->asset = Asset::create([
        'equipment_no'   => '90000001',
        'description'    => 'AC Ruang Panel Uji',
        'tech_ident_no'  => 'AC-AR01-1-1',
        'equipment_type' => 'AC',
        'functional_loc' => $this->funcloc->code,
        'funcloc_id'     => $this->funcloc->id,
        'company_id'     => $this->company->id,
        'department_id'  => $this->dept->id,
        'area_id'        => $this->area->id,
        'sub_area_id'    => $this->subArea->id,
        'manufacturer'   => 'Gree',
        'status'         => 'active',
        'data_source'    => 'import_excel',
    ]);

    // Baris cermin perangkat IT: TIDAK boleh diubah lewat impor aset.
    $this->assetIt = Asset::create([
        'equipment_no'   => '90000002',
        'description'    => 'PC Uji Cermin IT',
        'tech_ident_no'  => 'WS-SITETEST-Uji',
        'functional_loc' => $this->funcloc->code,
        'funcloc_id'     => $this->funcloc->id,
        'company_id'     => $this->company->id,
        'department_id'  => $this->dept->id,
        'area_id'        => $this->area->id,
        'sub_area_id'    => $this->subArea->id,
        'status'         => 'active',
        'data_source'    => 'it_sync',
    ]);

    // Baris CSV siap pakai untuk satu aset (urutan kolom sesuai export).
    // Memakai array_replace (bukan array_merge) supaya indeks kolom yang diubah
    // benar-benar menimpa kolom yang dituju — array_merge akan menomori ulang
    // kunci numerik dan malah MENAMBAH kolom baru.
    $this->barisAset = fn (array $ubah = []) => array_replace([
        '90000001',                    // Equipment No
        'AC Ruang Panel Uji',          // Description
        'AC-AR01-1-1',                 // Tech Ident No
        'AC',                          // Equipment Type
        $this->funcloc->code,          // Functional Loc
        $this->company->code,          // Company
        $this->dept->code,             // Department
        $this->area->code,             // Area
        $this->subArea->code,          // Sub Area
        'Gree',                        // Manufacturer
        '',                            // Model Number
        '',                            // Construct Year
        'active',                      // Status
        'import_excel',                // Data Source
        '',                            // Imported At
        '',                            // Created At
        '',                            // Updated At
    ], $ubah);
});

// ============================================================
// FILE HASIL EXPORT BISA DIIMPORT ULANG UNTUK MEMPERBARUI
// ============================================================

test('file hasil export dikenali sebagai data yang sudah ada (duplikat)', function () {
    $analysis = $this->import->analyzeFile(fileImportAset(csvExportAset([($this->barisAset)()])));

    expect($analysis['total_rows'])->toBe(1)
        ->and($analysis['duplicate'])->toHaveCount(1)
        ->and($analysis['clean'])->toHaveCount(0)
        ->and($analysis['bad_funcloc'])->toHaveCount(0);
});

test('import dengan aksi replace memperbarui deskripsi, status, dan pabrikan', function () {
    $analysis = $this->import->analyzeFile(fileImportAset(csvExportAset([
        ($this->barisAset)([
            1  => 'AC Ruang Panel Uji (revisi)',
            9  => 'Daikin',
            12 => 'needs_review',
        ]),
    ])));

    $hasil = $this->import->executeImport($analysis, [
        'duplicate_action' => 'replace',
        'no_equip_action'  => 'skip',
        'filename'         => 'assets-export-uji.csv',
    ]);

    expect($hasil['success_count'])->toBe(1)
        ->and($hasil['error_count'])->toBe(0);

    $asset = $this->asset->fresh();

    expect($asset->description)->toBe('AC Ruang Panel Uji (revisi)')
        ->and($asset->manufacturer)->toBe('Daikin')
        ->and($asset->status)->toBe('needs_review');
});

test('kolom lokasi yang diubah pada file export ikut terupdate', function () {
    // Pindahkan aset ke sub area lain lewat kolom lokasi (tanpa mengubah FuncLoc).
    $subAreaLain = SubArea::create(['area_id' => $this->area->id, 'code' => 'SA02', 'name' => 'Sub Area 2']);

    $analysis = $this->import->analyzeFile(fileImportAset(csvExportAset([
        ($this->barisAset)([8 => 'SA02']),
    ])));

    $this->import->executeImport($analysis, [
        'duplicate_action' => 'replace',
        'no_equip_action'  => 'skip',
        'filename'         => 'assets-export-uji.csv',
    ]);

    expect($this->asset->fresh()->sub_area_id)->toBe($subAreaLain->id);
});

test('kode lokasi yang tidak dikenal membuat baris dilewati tanpa mengubah data', function () {
    $analysis = $this->import->analyzeFile(fileImportAset(csvExportAset([
        ($this->barisAset)([1 => 'Judul berubah tapi lokasi salah', 6 => 'AREA-TIDAK-ADA']),
    ])));

    $hasil = $this->import->executeImport($analysis, [
        'duplicate_action' => 'replace',
        'no_equip_action'  => 'skip',
        'filename'         => 'assets-export-uji.csv',
    ]);

    expect($hasil['success_count'])->toBe(0)
        ->and($hasil['error_count'])->toBe(1)
        ->and($this->asset->fresh()->description)->toBe('AC Ruang Panel Uji');
});

test('baris cermin perangkat IT dilewati saat import memperbarui', function () {
    $barisIt = ($this->barisAset)();

    $barisIt[0]  = '90000002';
    $barisIt[1]  = 'PC Uji Cermin IT (coba diubah)';
    $barisIt[2]  = 'WS-SITETEST-Uji';

    $analysis = $this->import->analyzeFile(fileImportAset(csvExportAset([$barisIt])));
    $hasil    = $this->import->executeImport($analysis, [
        'duplicate_action' => 'replace',
        'no_equip_action'  => 'skip',
        'filename'         => 'assets-export-uji.csv',
    ]);

    expect($hasil['success_count'])->toBe(0)
        ->and($hasil['it_sync_skipped'])->toBe(1)
        ->and($hasil['message'])->toContain('perangkat IT dilewati')
        ->and($this->assetIt->fresh()->description)->toBe('PC Uji Cermin IT');
});

test('status kosong pada file tidak menimpa status yang sudah ada', function () {
    $this->asset->update(['status' => 'spare']);

    $analysis = $this->import->analyzeFile(fileImportAset(csvExportAset([
        ($this->barisAset)([12 => '']),
    ])));

    $this->import->executeImport($analysis, [
        'duplicate_action' => 'replace',
        'no_equip_action'  => 'skip',
        'filename'         => 'assets-export-uji.csv',
    ]);

    expect($this->asset->fresh()->status)->toBe('spare');
});

test('aset baru tetap dibuat dari file dengan header export', function () {
    $barisBaru = ($this->barisAset)();

    $barisBaru[0]  = '90000099';
    $barisBaru[1]  = 'Aset Baru Dari File Export';
    $barisBaru[2]  = 'AC-AR01-9-9';

    $analysis = $this->import->analyzeFile(fileImportAset(csvExportAset([$barisBaru])));

    expect($analysis['clean'])->toHaveCount(1);

    $hasil = $this->import->executeImport($analysis, [
        'duplicate_action' => 'skip',
        'no_equip_action'  => 'skip',
        'filename'         => 'assets-export-uji.csv',
    ]);

    expect($hasil['success_count'])->toBe(1)
        ->and(Asset::where('equipment_no', '90000099')->exists())->toBeTrue();
});


// ============================================================
// FORMAT EXPORT TERBARU: TANPA EQUIPMENT NO + NOMOR OTOMATIS
// ============================================================

/**
 * Susun file CSV seperti hasil Export terbaru: blok judul + keterangan filter,
 * lalu tabel TANPA kolom Equipment No.
 *
 * @param  array<int, array<int, mixed>>  $baris
 * @return string
 */
function csvExportBaru(array $baris): string
{
    $info = [
        'DAFTAR ASSET — ASSET MANAGEMENT',
        'Sistem Manajemen Pemeliharaan — Oleochemical Pro',
        'Diekspor: ' . now()->format('d/m/Y H:i') . ' WIB',
        'Filter: semua data',
        'Jumlah: ' . count($baris) . ' asset',
    ];

    $header = [
        'No', 'Description', 'Tech Ident No', 'Equipment Type',
        'Functional Loc', 'Company', 'Department', 'Area', 'Sub Area',
        'Manufacturer', 'Model Number', 'Construct Year', 'Status',
        'Data Source',
    ];

    $handle = fopen('php://temp', 'r+');

    foreach ($info as $barisInfo) {
        fputcsv($handle, [$barisInfo]);
    }

    fputcsv($handle, []);
    fputcsv($handle, $header);

    $no = 1;

    foreach ($baris as $isi) {
        fputcsv($handle, array_merge([$no], $isi));
        $no++;
    }

    rewind($handle);
    $isiCsv = stream_get_contents($handle);
    fclose($handle);

    return (string) $isiCsv;
}

test('export tidak lagi menyertakan Equipment No dan berblok judul profesional', function () {
    $csv = $this->actingAs($this->admin)
        ->get('/assets/export/csv?company_id=' . $this->company->id)
        ->assertStatus(200)
        ->streamedContent();

    expect($csv)->toContain('DAFTAR ASSET')
        ->and($csv)->toContain('Diekspor:')
        ->and($csv)->toContain('Filter: PT: ' . $this->company->code)
        ->and($csv)->toContain('Jumlah:')
        // Kolom Equipment No sudah tidak ada lagi di header.
        ->and($csv)->not->toContain('Equipment No')
        // Kolom laporan tetap lengkap.
        ->and($csv)->toContain('Tech Ident No')
        ->and($csv)->toContain('Functional Loc');
});

test('export excel memakai blok judul dan header tabel di baris ketujuh', function () {
    $isi = $this->actingAs($this->admin)
        ->get('/assets/export/excel?company_id=' . $this->company->id)
        ->assertStatus(200)
        ->streamedContent();

    $path = tempnam(sys_get_temp_dir(), 'astexp') . '.xlsx';
    file_put_contents($path, $isi);

    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
    $sheet       = $spreadsheet->getActiveSheet();

    $headerBaris = [];

    foreach (range('A', 'N') as $kolom) {
        $headerBaris[] = (string) $sheet->getCell($kolom . '7')->getValue();
    }

    expect((string) $sheet->getCell('A1')->getValue())->toContain('DAFTAR ASSET')
        ->and((string) $sheet->getCell('A2')->getValue())->toContain('Oleochemical Pro')
        ->and((string) $sheet->getCell('A3')->getValue())->toContain('Diekspor:')
        ->and((string) $sheet->getCell('A4')->getValue())->toContain('Filter:')
        ->and((string) $sheet->getCell('A5')->getValue())->toContain('Jumlah:')
        ->and((string) $sheet->getCell('A7')->getValue())->toBe('No')
        ->and((string) $sheet->getCell('C7')->getValue())->toBe('Tech Ident No')
        ->and((string) $sheet->getCell('E7')->getValue())->toBe('Functional Loc')
        ->and(implode(' | ', $headerBaris))->not->toContain('Equipment No');

    $spreadsheet->disconnectWorksheets();
    unlink($path);
});


test('file export terbaru memperbarui aset lewat Tech Ident No', function () {
    // Urutan kolom: Description, Tech Ident No, Equipment Type, Functional Loc,
    // Company, Department, Area, Sub Area, Manufacturer, Model, Tahun, Status, Data Source.
    $csv = csvExportBaru([[
        'AC Ruang Panel Uji (versi baru)',   // Description
        'AC-AR01-1-1',                       // Tech Ident No
        'AC',                                // Equipment Type
        $this->funcloc->code,                // Functional Loc
        $this->company->code,                // Company
        $this->dept->code,                   // Department
        $this->area->code,                   // Area
        $this->subArea->code,                // Sub Area
        'Daikin',                            // Manufacturer
        '',                                  // Model Number
        '',                                  // Construct Year
        'needs_review',                      // Status
        'import_excel',                      // Data Source
    ]]);

    $analysis = $this->import->analyzeFile(fileImportAset($csv));

    expect($analysis['total_rows'])->toBe(1)
        ->and($analysis['duplicate'])->toHaveCount(1)
        ->and($analysis['duplicate'][0]['asset_id'])->toBe($this->asset->id)
        ->and($analysis['clean'])->toHaveCount(0);

    $this->import->executeImport($analysis, [
        'duplicate_action' => 'replace',
        'no_equip_action'  => 'skip',
        'filename'         => 'assets-export.csv',
    ]);

    $asset = $this->asset->fresh();

    expect($asset->description)->toBe('AC Ruang Panel Uji (versi baru)')
        ->and($asset->manufacturer)->toBe('Daikin')
        ->and($asset->status)->toBe('needs_review');
});

test('baris baru dari file export dibuatkan nomor equipment otomatis', function () {
    $csv = csvExportBaru([[
        'Motor Baru Uji',
        'AC-AR01-7-7',
        'MOTOR',
        $this->funcloc->code,
        $this->company->code,
        $this->dept->code,
        $this->area->code,
        $this->subArea->code,
        'Siemens',
        'MS-100',
        '2024',
        'active',
        'import_excel',
    ]]);

    $analysis = $this->import->analyzeFile(fileImportAset($csv));

    expect($analysis['clean'])->toHaveCount(1)
        ->and($analysis['clean'][0]['equipment_no'])->toBe('');

    $hasil = $this->import->executeImport($analysis, [
        'duplicate_action' => 'skip',
        'no_equip_action'  => 'skip',
        'filename'         => 'assets-export.csv',
    ]);

    expect($hasil['success_count'])->toBe(1);

    $baru = Asset::where('tech_ident_no', 'AC-AR01-7-7')->first();

    expect($baru)->not->toBeNull()
        ->and($baru->equipment_no)->not->toBeNull()
        ->and(strlen((string) $baru->equipment_no))->toBe(8)
        ->and($baru->description)->toBe('Motor Baru Uji');
});

test('TechIdentNo yang dipakai lebih dari satu aset masuk bucket ambigu dan dilewati', function () {
    // Aset kedua dengan TechIdentNo sama pada Functional Loc yang sama.
    Asset::create([
        'equipment_no'   => '90000077',
        'description'    => 'AC Duplikat TechIdent',
        'tech_ident_no'  => 'AC-AR01-1-1',
        'functional_loc' => $this->funcloc->code,
        'funcloc_id'     => $this->funcloc->id,
        'company_id'     => $this->company->id,
        'department_id'  => $this->dept->id,
        'area_id'        => $this->area->id,
        'sub_area_id'    => $this->subArea->id,
        'status'         => 'active',
        'data_source'    => 'import_excel',
    ]);

    $csv = csvExportBaru([[
        'Deskripsi Berubah',
        'AC-AR01-1-1',
        'AC',
        $this->funcloc->code,
        $this->company->code,
        $this->dept->code,
        $this->area->code,
        $this->subArea->code,
        'Gree',
        '',
        '',
        'active',
        'import_excel',
    ]]);

    $analysis = $this->import->analyzeFile(fileImportAset($csv));

    expect($analysis['ambigu'])->toHaveCount(1)
        ->and($analysis['duplicate'])->toHaveCount(0);

    $hasil = $this->import->executeImport($analysis, [
        'duplicate_action' => 'replace',
        'no_equip_action'  => 'skip',
        'filename'         => 'assets-export.csv',
    ]);

    expect($hasil['success_count'])->toBe(0)
        ->and($hasil['error_count'])->toBe(1)
        ->and($this->asset->fresh()->description)->toBe('AC Ruang Panel Uji');
});

