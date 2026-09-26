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

    // Baris perangkat IT masuk bucket sendiri, bukan daftar "akan diperbarui".
    expect($analysis['it_sync'])->toHaveCount(1)
        ->and($analysis['duplicate'])->toHaveCount(0)
        ->and($analysis['it_sync'][0]['equipment_no_lama'])->toBe('90000002');

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

test('aset baru dari file dibuatkan nomor otomatis, nomor di file diabaikan', function () {
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

    $baru = Asset::where('tech_ident_no', 'AC-AR01-9-9')->first();

    expect($hasil['success_count'])->toBe(1)
        ->and($hasil['inserted_count'])->toBe(1)
        ->and($hasil['updated_count'])->toBe(0)
        ->and($baru)->not->toBeNull()
        // Nomor dari file TIDAK dipakai: nomor dibuat sistem meneruskan seri
        // nomor yang sudah ada (aset uji memakai 90000001 dan 90000002).
        ->and($baru->equipment_no)->toBe('90000003')
        ->and(Asset::where('equipment_no', '90000099')->doesntExist())->toBeTrue();
});

test('nomor equipment aset lama tidak pernah diubah oleh import', function () {
    $baris = ($this->barisAset)();

    // Nomor di file sengaja berbeda dan belum dipakai aset mana pun: dengan
    // pencocokan Tech Ident No, baris ini tetap memperbarui aset lama dan
    // nomornya tidak boleh ikut berubah.
    $baris[0] = '99999999';
    $baris[1] = 'AC Ruang Panel Uji (nomor file diabaikan)';

    $analysis = $this->import->analyzeFile(fileImportAset(csvExportAset([$baris])));

    expect($analysis['duplicate'])->toHaveCount(1)
        ->and($analysis['clean'])->toHaveCount(0)
        ->and($analysis['duplicate'][0]['asset_id'])->toBe($this->asset->id)
        ->and($analysis['duplicate'][0]['equipment_no_lama'])->toBe('90000001');

    $hasil = $this->import->executeImport($analysis, [
        'duplicate_action' => 'replace',
        'no_equip_action'  => 'skip',
        'filename'         => 'assets-export-uji.csv',
    ]);

    $asset = $this->asset->fresh();

    expect($hasil['updated_count'])->toBe(1)
        ->and($asset->equipment_no)->toBe('90000001')
        ->and($asset->description)->toBe('AC Ruang Panel Uji (nomor file diabaikan)');
});

test('daftar perubahan disiapkan untuk baris yang akan diperbarui', function () {
    $analysis = $this->import->analyzeFile(fileImportAset(csvExportAset([
        ($this->barisAset)([1 => 'Nama Baru', 3 => 'ACU']),
    ])));

    $baris = $analysis['duplicate'][0];

    expect(collect($baris['perubahan'])->pluck('field')->all())->toBe(['Description', 'Equipment Type'])
        ->and($baris['perubahan'][0])->toBe([
            'field' => 'Description',
            'lama'  => 'AC Ruang Panel Uji',
            'baru'  => 'Nama Baru',
        ])
        ->and($baris['perubahan'][1]['lama'])->toBe('AC')
        ->and($baris['perubahan'][1]['baru'])->toBe('ACU');

    // Baris tanpa perubahan tidak menampilkan daftar kosong yang menyesatkan.
    $tanpaUbah = $this->import->analyzeFile(fileImportAset(csvExportAset([($this->barisAset)()])));

    expect($tanpaUbah['duplicate'][0]['perubahan'])->toBe([]);
});

test('Tech Ident No yang dobel di dalam satu file masuk bucket ambigu', function () {
    $barisA = ($this->barisAset)();
    $barisB = ($this->barisAset)();

    $barisA[0] = '90000011';
    $barisB[0] = '90000012';
    $barisB[1] = 'Duplikat Tech Ident No';

    $analysis = $this->import->analyzeFile(fileImportAset(csvExportAset([$barisA, $barisB])));

    expect($analysis['ambigu'])->toHaveCount(2)
        ->and($analysis['duplicate'])->toHaveCount(0)
        ->and($analysis['clean'])->toHaveCount(0)
        ->and($analysis['ambigu'][0]['alasan'])->toContain('lebih dari sekali di file');

    $hasil = $this->import->executeImport($analysis, [
        'duplicate_action' => 'replace',
        'no_equip_action'  => 'skip',
        'filename'         => 'assets-export-uji.csv',
    ]);

    expect($hasil['success_count'])->toBe(0)
        ->and($hasil['error_count'])->toBe(2)
        ->and($this->asset->fresh()->description)->toBe('AC Ruang Panel Uji');
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

test('export mengurutkan data menurut lokasi (PT, departemen, area, sub area, FuncLoc)', function () {
    // Lokasi kedua dengan kode lebih besar supaya urutannya terlihat jelas.
    $deptB = Department::firstOrCreate(
        ['company_id' => $this->company->id, 'code' => 'DEPTZ'],
        ['name' => 'Departemen Z']
    );
    $areaB = Area::firstOrCreate(
        ['department_id' => $deptB->id, 'code' => 'AR99'],
        ['name' => 'Area Z']
    );
    $subB = SubArea::firstOrCreate(
        ['area_id' => $areaB->id, 'code' => 'SA99'],
        ['name' => 'Sub Area Z']
    );

    $funclocB = $subB->fresh()->functionalLocation;

    Asset::create([
        'equipment_no'   => '90000088',
        'description'    => 'Motor Area B',
        'tech_ident_no'  => 'MTR-AR02-1-1',
        'equipment_type' => 'MOTOR',
        'functional_loc' => $funclocB->code,
        'funcloc_id'     => $funclocB->id,
        'company_id'     => $this->company->id,
        'department_id'  => $deptB->id,
        'area_id'        => $areaB->id,
        'sub_area_id'    => $subB->id,
        'status'         => 'active',
        'data_source'    => 'import_excel',
    ]);

    $csv = $this->actingAs($this->admin)
        ->get('/assets/export/csv?company_id=' . $this->company->id)
        ->assertStatus(200)
        ->streamedContent();

    // Parsing CSV lewat file supaya tanda kutip dan baris baru di deskripsi tetap benar.
    $path = tempnam(sys_get_temp_dir(), 'astexp') . '.csv';
    file_put_contents($path, $csv);

    $rows   = [];
    $handle = fopen($path, 'r');

    while (($row = fgetcsv($handle)) !== false) {
        $rows[] = $row;
    }

    fclose($handle);
    unlink($path);

    // Header tabel ada di baris ke-7 (indeks 6), data mulai indeks 7.
    // Kolom: 4 Functional Loc, 6 Department, 7 Area, 8 Sub Area.
    $kunci = array_map(
        static fn (array $row): array => [$row[6], $row[7], $row[8], $row[4]],
        array_slice($rows, 7)
    );

    $kunciTerurut = $kunci;
    sort($kunciTerurut);

    expect($kunci)->toBe($kunciTerurut)
        // Baris cermin perangkat IT (assetIt, data_source it_sync) TIDAK ikut
        // diexport, jadi yang tersisa hanya aset industri: 1 di DEPTS + 1 di DEPTZ.
        ->and($kunci)->toHaveCount(2)
        ->and($kunci[0][0])->toBe('DEPTS')
        ->and($kunci[0][1])->toBe('AR01')
        ->and($kunci[1][0])->toBe('DEPTZ')
        ->and($kunci[1][3])->toContain('DEPTZ-AR99-SA99');
});

test('export tidak menyertakan baris cermin perangkat IT (it_sync)', function () {
    // Tanpa filter: baris IT tidak boleh muncul sama sekali.
    $csv = $this->actingAs($this->admin)
        ->get('/assets/export/csv')
        ->assertStatus(200)
        ->streamedContent();

    expect($csv)->toContain('tidak disertakan')
        ->and($csv)->not->toContain('WS-SITETEST-Uji')
        ->and($csv)->not->toContain('PC Uji Cermin IT')
        // Aset industri tetap ikut diexport.
        ->and($csv)->toContain('AC-AR01-1-1');

    // Filter pencarian yang mengarah ke perangkat IT -> tidak ada baris data.
    $csvCari = $this->actingAs($this->admin)
        ->get('/assets/export/csv?search=WS-SITETEST-Uji')
        ->assertStatus(200)
        ->streamedContent();

    expect($csvCari)->toContain('Jumlah: 0 asset')
        ->and($csvCari)->not->toContain('PC Uji Cermin IT');

    $xlsx = $this->actingAs($this->admin)
        ->get('/assets/export/excel?search=WS-SITETEST-Uji')
        ->assertStatus(200)
        ->streamedContent();

    $path = tempnam(sys_get_temp_dir(), 'astexp') . '.xlsx';
    file_put_contents($path, $xlsx);

    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
    $sheet       = $spreadsheet->getActiveSheet();

    // Baris 7 = header tabel; baris 8 harus kosong karena tidak ada data.
    expect((string) $sheet->getCell('A8')->getValue())->toBe('');

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


test('import ulang tanpa mengubah apa pun melaporkan jumlah baris tanpa perubahan', function () {
    // File berisi data yang persis sama dengan database (tidak ada perubahan).
    $analysis = $this->import->analyzeFile(fileImportAset(csvExportAset([($this->barisAset)()])));

    expect($analysis['duplicate'])->toHaveCount(1)
        ->and($analysis['duplicate'][0]['perubahan'])->toBe([]);

    $hasil = $this->import->executeImport($analysis, [
        'duplicate_action' => 'replace',
        'no_equip_action'  => 'skip',
        'filename'         => 'assets-export-uji.csv',
    ]);

    expect($hasil['unchanged_count'])->toBe(1)
        ->and($hasil['message'])->toContain('cocok dengan data lama tanpa perubahan nilai')
        // Nilai aset tidak boleh ikut berubah.
        ->and($this->asset->fresh()->description)->toBe('AC Ruang Panel Uji')
        ->and($this->asset->fresh()->equipment_no)->toBe('90000001');
});

test('sel angka pada file Excel dibaca rapi tanpa noise float', function () {
    // Persis kasus nyata: sel Model Number bertipe angka berisi 4373.2602
    // sebelumnya terbaca "4373.26019999999971" oleh pembaca spreadsheet.
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet       = $spreadsheet->getActiveSheet();

    $sheet->fromArray([
        'Tech Ident No', 'Equipment Type', 'Functional Loc', 'Description', 'Model Number',
    ], null, 'A1');

    $sheet->setCellValue('A2', 'AC-AR01-1-1');
    $sheet->setCellValue('B2', 'AC');
    $sheet->setCellValueExplicit('C2', $this->funcloc->code, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValue('D2', 'AC Ruang Panel Uji');
    $sheet->setCellValue('E2', 4373.2602);

    $path = tempnam(sys_get_temp_dir(), 'imp') . '.xlsx';
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);

    $analysis = $this->import->analyzeFile(new UploadedFile($path, 'uji-angka.xlsx', null, null, true));

    unlink($path);

    $baris = $analysis['duplicate'][0] ?? $analysis['clean'][0];

    expect($baris['model_number'])->toBe('4373.2602');
});

test('nilai yang hanya beda bentuk angka tidak dianggap berubah dan tidak menimpa database', function () {
    $this->asset->update(['model_number' => '4373.2602']);

    $csv = csvExportAset([($this->barisAset)([10 => '4373.26019999999971'])]);

    $analysis = $this->import->analyzeFile(fileImportAset($csv));

    expect($analysis['duplicate'])->toHaveCount(1)
        ->and($analysis['duplicate'][0]['perubahan'])->toBe([]);

    $this->import->executeImport($analysis, [
        'duplicate_action' => 'replace',
        'no_equip_action'  => 'skip',
        'filename'         => 'assets-export-uji.csv',
    ]);

    expect($this->asset->fresh()->model_number)->toBe('4373.2602');
});

test('sel kosong tidak mengosongkan Manufacturer, Model Number, dan Construct Year', function () {
    $this->asset->update([
        'manufacturer'   => 'Leser',
        'model_number'   => '4373.2602',
        'construct_year' => '2020',
    ]);

    // File tanpa kolom tersebut (sel kosong) tidak boleh menghapus data lama.
    $csv = csvExportAset([($this->barisAset)([9 => '', 10 => '', 11 => ''])]);

    $analysis = $this->import->analyzeFile(fileImportAset($csv));

    expect($analysis['duplicate'][0]['perubahan'])->toBe([]);

    $this->import->executeImport($analysis, [
        'duplicate_action' => 'replace',
        'no_equip_action'  => 'skip',
        'filename'         => 'assets-export-uji.csv',
    ]);

    $aset = $this->asset->fresh();

    expect($aset->manufacturer)->toBe('Leser')
        ->and($aset->model_number)->toBe('4373.2602')
        ->and($aset->construct_year)->toBe('2020');
});

test('perubahan Model Number yang benar-benar berbeda tetap terdeteksi', function () {
    $this->asset->update(['model_number' => '4373.2602']);

    $analysis = $this->import->analyzeFile(fileImportAset(csvExportAset([
        ($this->barisAset)([10 => '4373.2603']),
    ])));

    expect(collect($analysis['duplicate'][0]['perubahan'])->pluck('field')->all())
        ->toContain('Model Number');
});

test('Tech Ident No angka yang terpotong Excel dicocokkan ke aset "E" dan nilainya di database tidak diubah', function () {
    // Aset dengan Tech Ident No teks berkode E seperti data nyata (6153E1),
    // sementara file berisi nilai yang sudah dikonversi spreadsheet (61530).
    $aset = Asset::create([
        'equipment_no'   => '90000091',
        'description'    => 'Oils Pre Neutralization Heater',
        'tech_ident_no'  => '6153E1',
        'equipment_type' => 'HEAT EXCHANGER (HE)',
        'functional_loc' => $this->funcloc->code,
        'funcloc_id'     => $this->funcloc->id,
        'company_id'     => $this->company->id,
        'department_id'  => $this->dept->id,
        'area_id'        => $this->area->id,
        'sub_area_id'    => $this->subArea->id,
        'status'         => 'active',
        'data_source'    => 'import_excel',
    ]);

    $analysis = $this->import->analyzeFile(fileImportAset(csvExportAset([[
        '90000091', 'Oils Pre Neutralization Heater (revisi)', '61530', 'HEAT EXCHANGER (HE)',
        $this->funcloc->code, $this->company->code, $this->dept->code,
        $this->area->code, $this->subArea->code, 'Alfa Laval', '', '', 'active', 'import_excel',
        '', '', '',
    ]])));

    // Dicocokkan sebagai aset lama (bukan data baru) dan nilai terpotongnya dicatat.
    expect($analysis['clean'])->toHaveCount(0)
        ->and($analysis['duplicate'])->toHaveCount(1)
        ->and($analysis['duplicate'][0]['asset_id'])->toBe($aset->id)
        ->and($analysis['duplicate'][0]['tech_ident_terpotong'])->toBe([
            'nilai_file'     => '61530',
            'nilai_database' => '6153E1',
        ])
        // Tech Ident No tidak pernah muncul sebagai "perubahan".
        ->and(collect($analysis['duplicate'][0]['perubahan'])->pluck('field')->all())
        ->toBe(['Description', 'Manufacturer']);

    $hasil = $this->import->executeImport($analysis, [
        'duplicate_action' => 'replace',
        'no_equip_action'  => 'skip',
        'filename'         => 'assets-export-uji.csv',
    ]);

    $asset = $aset->fresh();

    expect($hasil['truncated_matched_count'])->toBe(1)
        ->and($hasil['message'])->toContain('terpotong Excel')
        // Field lain diperbarui, Tech Ident No aset tetap nilai aslinya.
        ->and($asset->description)->toBe('Oils Pre Neutralization Heater (revisi)')
        ->and($asset->manufacturer)->toBe('Alfa Laval')
        ->and($asset->tech_ident_no)->toBe('6153E1')
        ->and($asset->equipment_no)->toBe('90000091');
});

test('nilai angka yang cocok dengan lebih dari satu Tech Ident No tidak ditebak', function () {
    $buat = function (string $equipmentNo, string $techIdent) {
        return Asset::create([
            'equipment_no'   => $equipmentNo,
            'description'    => 'Aset Kembar ' . $techIdent,
            'tech_ident_no'  => $techIdent,
            'equipment_type' => 'HE',
            'functional_loc' => $this->funcloc->code,
            'funcloc_id'     => $this->funcloc->id,
            'company_id'     => $this->company->id,
            'department_id'  => $this->dept->id,
            'area_id'        => $this->area->id,
            'sub_area_id'    => $this->subArea->id,
            'status'         => 'active',
            'data_source'    => 'import_excel',
        ]);
    };

    // Dua bentuk E yang nilai angkanya sama (6153E1 dan 61530E0 -> 61530).
    $buat('90000092', '6153E1');
    $buat('90000093', '61530E0');

    $analysis = $this->import->analyzeFile(fileImportAset(csvExportAset([[
        '90000999', 'Aset Kembar', '61530', 'HE',
        $this->funcloc->code, $this->company->code, $this->dept->code,
        $this->area->code, $this->subArea->code, '', '', '', 'active', 'import_excel',
        '', '', '',
    ]])));

    expect($analysis['ambigu'])->toHaveCount(1)
        ->and($analysis['clean'])->toHaveCount(0)
        ->and($analysis['duplicate'])->toHaveCount(0)
        ->and($analysis['ambigu'][0]['alasan'])->toContain('lebih dari satu Tech Ident No');

    $hasil = $this->import->executeImport($analysis, [
        'duplicate_action' => 'replace',
        'no_equip_action'  => 'skip',
        'filename'         => 'assets-export-uji.csv',
    ]);

    expect($hasil['success_count'])->toBe(0)
        ->and($hasil['error_count'])->toBe(1);
});

test('nilai angka tanpa kandidat E tetap dianggap data baru', function () {
    // Deskripsi sudah ada, tapi tidak ada aset ber-Tech-Ident pola E dengan
    // nilai itu -> baris harus tetap dianggap data baru (bukan ditebak).
    Asset::create([
        'equipment_no'   => '90000094',
        'description'    => 'Aset Tanpa Pola E',
        'tech_ident_no'  => 'AC-AR01-5-5',
        'equipment_type' => 'AC',
        'functional_loc' => $this->funcloc->code,
        'funcloc_id'     => $this->funcloc->id,
        'company_id'     => $this->company->id,
        'department_id'  => $this->dept->id,
        'area_id'        => $this->area->id,
        'sub_area_id'    => $this->subArea->id,
        'status'         => 'active',
        'data_source'    => 'import_excel',
    ]);

    $analysis = $this->import->analyzeFile(fileImportAset(csvExportAset([[
        '90000998', 'Aset Tanpa Pola E Baru', '99001', 'AC',
        $this->funcloc->code, $this->company->code, $this->dept->code,
        $this->area->code, $this->subArea->code, '', '', '', 'active', 'import_excel',
        '', '', '',
    ]])));

    expect($analysis['clean'])->toHaveCount(1)
        ->and($analysis['duplicate'])->toHaveCount(0)
        ->and($analysis['clean'][0]['tech_ident_no'])->toBe('99001');
});

test('Tech Ident No mirip notasi ilmiah tidak berubah jadi angka saat dibaca', function () {
    // is_numeric('6153E1') bernilai TRUE, sehingga tanpa penjaga khusus nilai
    // ini terbaca 61530 oleh pembaca spreadsheet dan barisnya salah dianggap
    // data baru (aset duplikat).
    $aset = Asset::create([
        'equipment_no'   => '90000077',
        'description'    => 'Oils Pre Neutralization Heater',
        'tech_ident_no'  => '6153E1',
        'equipment_type' => 'HEATER',
        'functional_loc' => $this->funcloc->code,
        'funcloc_id'     => $this->funcloc->id,
        'company_id'     => $this->company->id,
        'department_id'  => $this->dept->id,
        'area_id'        => $this->area->id,
        'sub_area_id'    => $this->subArea->id,
        'status'         => 'active',
        'data_source'    => 'import_excel',
    ]);

    $analysis = $this->import->analyzeFile(fileImportAset(csvExportAset([[
        '90000077', 'Oils Pre Neutralization Heater', '6153E1', 'HEATER',
        $this->funcloc->code, $this->company->code, $this->dept->code,
        $this->area->code, $this->subArea->code, '', '', '', 'active', 'import_excel',
        '', '', '',
    ]])));

    expect($analysis['duplicate'])->toHaveCount(1)
        ->and($analysis['clean'])->toHaveCount(0)
        ->and($analysis['duplicate'][0]['tech_ident_no'])->toBe('6153E1')
        ->and($analysis['duplicate'][0]['asset_id'])->toBe($aset->id);
});

test('export excel menulis nilai seperti 6153E1 sebagai teks, bukan angka', function () {
    Asset::create([
        'equipment_no'   => '90000078',
        'description'    => 'Methanol Heater',
        'tech_ident_no'  => '6153E1',
        'equipment_type' => 'HEATER',
        'functional_loc' => $this->funcloc->code,
        'funcloc_id'     => $this->funcloc->id,
        'company_id'     => $this->company->id,
        'department_id'  => $this->dept->id,
        'area_id'        => $this->area->id,
        'sub_area_id'    => $this->subArea->id,
        'status'         => 'active',
        'data_source'    => 'import_excel',
    ]);

    $isi = $this->actingAs($this->admin)
        ->get('/assets/export/excel?search=Methanol Heater')
        ->assertStatus(200)
        ->streamedContent();

    $path = tempnam(sys_get_temp_dir(), 'astexp') . '.xlsx';
    file_put_contents($path, $isi);

    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
    $sheet       = $spreadsheet->getActiveSheet();

    // Data mulai baris 8 (baris 7 = header tabel). Tipe sel harus string,
    // bukan angka, supaya nilai ini tidak berubah saat diimport ulang.
    expect((string) $sheet->getCell('C8')->getValue())->toBe('6153E1')
        ->and($sheet->getCell('C8')->getDataType())->toBe('s');

    $spreadsheet->disconnectWorksheets();
    unlink($path);
});

