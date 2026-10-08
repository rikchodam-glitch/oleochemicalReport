<?php

use App\Models\Area;
use App\Models\Asset;
use App\Models\Company;
use App\Models\Department;
use App\Models\FunctionalLocation;
use App\Models\Report;
use App\Models\SubArea;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

uses(RefreshDatabase::class);

/**
 * Unduh berkas xlsx hasil export dan buka sebagai Spreadsheet.
 *
 * @param  TestResponse  $respons
 * @return array{0: Spreadsheet, 1: string}  Spreadsheet + path berkas sementara
 */
function bukaExcelExport(TestResponse $respons): array
{
    $path = tempnam(sys_get_temp_dir(), 'lexp') . '.xlsx';
    file_put_contents($path, $respons->streamedContent());

    return [IOFactory::load($path), $path];
}

/**
 * Seluruh nilai satu kolom pada sebuah lembar kerja.
 *
 * @param  Spreadsheet  $sheet
 * @param  string       $huruf
 * @return array<int, string>
 */
function nilaiKolomExport($sheet, string $huruf): array
{
    $nilai = [];

    foreach ($sheet->getRowIterator() as $baris) {
        $teks = (string) $sheet->getCell($huruf . $baris->getRowIndex())->getValue();

        if ($teks !== '') {
            $nilai[] = $teks;
        }
    }

    return $nilai;
}

/**
 * Daftar nama berkas gambar di dalam berkas xlsx (folder xl/media).
 *
 * @param  string  $path  Path berkas xlsx
 * @return array<int, string>
 */
function mediaExcelExport(string $path): array
{
    $zip = new ZipArchive();

    if ($zip->open($path) !== true) {
        return [];
    }

    $media = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $nama = (string) $zip->getNameIndex($i);

        if (str_starts_with($nama, 'xl/media/')) {
            $media[] = $nama;
        }
    }

    $zip->close();

    return $media;
}

/**
 * Byte gambar PNG kecil untuk menguji sematan foto pada berkas Excel.
 *
 * @return string
 */
function gambarUjiPng(): string
{
    $gambar = imagecreatetruecolor(8, 8);
    imagefill($gambar, 0, 0, imagecolorallocate($gambar, 14, 158, 142));

    ob_start();
    imagepng($gambar);
    $isi = (string) ob_get_clean();

    imagedestroy($gambar);

    return $isi;
}

beforeEach(function () {
    $this->seed();

    $this->admin = User::where('email', 'admin@oleochemical.pro')->firstOrFail();

    $this->company = Company::firstOrCreate(['code' => 'EXPCO'], ['name' => 'PT Export Uji']);
    $this->dept = Department::firstOrCreate(
        ['company_id' => $this->company->id, 'code' => 'EXPDEPT'],
        ['name' => 'Departemen Export']
    );
    $this->area = Area::create([
        'department_id' => $this->dept->id,
        'code'          => 'EXPAREA',
        'name'          => 'Area Export',
    ]);
    $this->subArea = SubArea::create([
        'area_id' => $this->area->id,
        'code'    => 'EXPSUB',
        'name'    => 'Sub Area Export',
    ]);

    $this->funcloc = FunctionalLocation::create([
        'code'          => 'EXP-PROD-TF01-6010',
        'segment'       => 'TF01',
        'name'          => 'Section Export Uji',
        'level'         => FunctionalLocation::LEVEL_SECTION,
        'company_id'    => $this->company->id,
        'department_id' => $this->dept->id,
        'area_id'       => $this->area->id,
        'sub_area_id'   => $this->subArea->id,
        'is_active'     => true,
    ]);

    $this->asset = Asset::create([
        'equipment_no'   => '70009901',
        'description'    => 'Pompa Export Uji',
        'tech_ident_no'  => 'EXP-PU01',
        'functional_loc' => $this->funcloc->code,
        'funcloc_id'     => $this->funcloc->id,
        'company_id'     => $this->company->id,
        'department_id'  => $this->dept->id,
        'area_id'        => $this->area->id,
        'sub_area_id'    => $this->subArea->id,
        'data_source'    => 'manual',
    ]);

    $this->teknisi = Technician::create([
        'name'    => 'Teknisi Export Uji',
        'nik'     => 'EXP001',
        'group'   => 'reguler',
        'section' => 'mekanik',
        'status'  => 'active',
    ]);

    $this->rekan = Technician::create([
        'name'    => 'Rekan Kolaborasi Uji',
        'nik'     => 'EXP002',
        'group'   => 'reguler',
        'section' => 'mekanik',
        'status'  => 'active',
    ]);

    $this->tanggal = now()->subDay()->toDateString();

    // Laporan induk lengkap: lokasi (funcloc + alat), jam kerja, temuan, penyebab.
    $this->laporan = Report::create([
        'technician_id'           => $this->teknisi->id,
        'creator_id'              => $this->teknisi->id,
        'report_date'             => $this->tanggal,
        'report_code'             => 'RPT-EXPORT-0001',
        'work_description'        => 'Ganti seal pompa export uji',
        'work_duration_minutes'   => 480,
        'start_time'              => '08:00',
        'end_time'                => '16:00',
        'finding'                 => 'Seal bocor ringan',
        'root_cause'              => 'Seal aus',
        'area_id'                 => $this->area->id,
        'funcloc_id'              => $this->funcloc->id,
        'asset_id'                => $this->asset->id,
        'equipment_tag'           => 'EXP-PU01',
        'report_type'             => 'equipment_repair',
        'work_classification'     => 'zpm1',
        'jenis_pekerjaan'         => 'CM',
        'status'                  => 'completed',
        'ai_confidence'           => 92,
        'photo_documentation'     => ['foto-a.jpg'],
        'photo_hygiene_clearance' => ['foto-b.jpg'],
    ]);

    // Salinan kolaborasi: TIDAK boleh menjadi baris tambahan di berkas export.
    $this->kolaborator = Report::create([
        'technician_id'    => $this->rekan->id,
        'creator_id'       => $this->teknisi->id,
        'collaborator_of'  => $this->laporan->id,
        'report_date'      => $this->tanggal,
        'report_code'      => 'SALINAN-KOLAB-EXP',
        'work_description' => 'Ganti seal pompa export uji',
        'jenis_pekerjaan'  => 'CM',
        'status'           => 'completed',
    ]);

    // Laporan lama (40 hari lalu) untuk menguji filter rentang tanggal.
    $this->laporanLama = Report::create([
        'technician_id'    => $this->teknisi->id,
        'creator_id'       => $this->teknisi->id,
        'report_date'      => now()->subDays(40)->toDateString(),
        'report_code'      => 'RPT-EXPORT-LAMA',
        'work_description' => 'Pekerjaan lama export uji',
        'jenis_pekerjaan'  => 'PM',
        'status'           => 'continue',
    ]);

    // Teknisi pekerja SHIFT + laporannya, dipakai menguji pilihan tampilan
    // "Keduanya (Reguler + Shift)" pada popup export. Secara bawaan (tampilan
    // reguler) laporan ini TIDAK ikut, jadi test lama tidak berubah hasilnya.
    $this->teknisiShift = Technician::create([
        'name'    => 'Teknisi Shift Export Uji',
        'nik'     => 'EXP003',
        'group'   => 'grub_b',
        'section' => 'mekanik',
        'status'  => 'active',
    ]);

    $this->laporanShift = Report::create([
        'technician_id'         => $this->teknisiShift->id,
        'creator_id'            => $this->teknisiShift->id,
        'report_date'           => $this->tanggal,
        'report_code'           => 'RPT-EXPORT-SHIFT',
        'work_description'      => 'Pekerjaan shift export uji',
        'work_duration_minutes' => 480,
        'jenis_pekerjaan'       => 'CM',
        'status'                => 'completed',
    ]);
});

// ============================================================
// POPUP EXPORT DI HALAMAN LAPORAN
// ============================================================

test('halaman laporan memuat tombol dan popup export dengan pengaturan tanggal & jenis', function () {
    $html = $this->actingAs($this->admin)
        ->get('/reports?tanggal=' . $this->tanggal)
        ->assertStatus(200)
        ->getContent();

    expect($html)->toContain('Export Laporan')
        ->and($html)->toContain("bukaExport('excel')")
        ->and($html)->toContain('id="modal-export-laporan"')
        ->and($html)->toContain('id="form-export-laporan"')
        ->and($html)->toContain('name="date_from"')
        ->and($html)->toContain('name="date_to"')
        ->and($html)->toContain('name="jenis_pekerjaan_multi[]"')
        ->and($html)->toContain('name="tanpa_batas"')
        ->and($html)->toContain('data-export-cepat="bulan-lalu"')
        ->and($html)->toContain('id="export-baris-grub"')
        // Pilihan tampilan boleh "Keduanya" (Reguler + Shift sekaligus).
        ->and($html)->toContain('<option value="semua"')
        ->and($html)->toContain('Keduanya (Reguler + Shift)')
        // Foto: centang sertakan gambar (khusus Excel).
        ->and($html)->toContain('id="export-baris-sertakan-foto"')
        ->and($html)->toContain('name="sertakan_foto"')
        ->and($html)->toContain('Sertakan foto dokumentasi')
        // Form mengarah ke rute Excel secara bawaan.
        ->and($html)->toContain(route('reports.export-excel'));
});

// ============================================================
// STRUKTUR BERKAS EXCEL
// ============================================================

test('export excel memuat blok judul, kolom lokasi/alat lengkap, dan tiga lembar', function () {
    $respons = $this->actingAs($this->admin)
        ->get('/reports/export/excel?tanggal=' . $this->tanggal)
        ->assertStatus(200);

    [$spreadsheet, $path] = bukaExcelExport($respons);

    expect($spreadsheet->getSheetNames())->toBe(['Daftar Laporan', 'Ringkasan', 'Petunjuk']);

    $sheet = $spreadsheet->getSheet(0);

    // Blok judul & keterangan (baris 1-6), header tabel di baris 7.
    expect((string) $sheet->getCell('A1')->getValue())->toContain('DAFTAR LAPORAN PEKERJAAN')
        ->and((string) $sheet->getCell('A2')->getValue())->toContain('Oleochemical Maintenance')
        ->and((string) $sheet->getCell('A3')->getValue())->toContain('Periode:')
        ->and((string) $sheet->getCell('A4')->getValue())->toContain('Diekspor:')
        ->and((string) $sheet->getCell('A5')->getValue())->toContain('Filter:')
        ->and((string) $sheet->getCell('A6')->getValue())->toContain('Jumlah:')
        ->and((string) $sheet->getCell('A7')->getValue())->toBe('No')
        ->and((string) $sheet->getCell('B7')->getValue())->toBe('Kode Laporan')
        ->and((string) $sheet->getCell('C7')->getValue())->toBe('Tanggal')
        ->and((string) $sheet->getCell('G7')->getValue())->toBe('Durasi (menit)')
        ->and((string) $sheet->getCell('K7')->getValue())->toBe('Bagian / Group')
        ->and((string) $sheet->getCell('L7')->getValue())->toBe('Kolaborator')
        ->and((string) $sheet->getCell('M7')->getValue())->toBe('Jenis Pekerjaan')
        ->and((string) $sheet->getCell('P7')->getValue())->toBe('Status')
        ->and((string) $sheet->getCell('R7')->getValue())->toBe('PT')
        ->and((string) $sheet->getCell('V7')->getValue())->toBe('Section (Lokasi)')
        ->and((string) $sheet->getCell('W7')->getValue())->toBe('Kode Alat')
        ->and((string) $sheet->getCell('X7')->getValue())->toBe('Deskripsi Alat')
        ->and((string) $sheet->getCell('Y7')->getValue())->toBe('Equipment No')
        ->and((string) $sheet->getCell('AA7')->getValue())->toBe('Deskripsi Pekerjaan')
        ->and((string) $sheet->getCell('AB7')->getValue())->toBe('Temuan')
        ->and((string) $sheet->getCell('AC7')->getValue())->toBe('Penyebab (Root Cause)')
        ->and((string) $sheet->getCell('AI7')->getValue())->toBe('Dicatat Pada')
        // Dua kolom foto berupa TAUTAN selalu ada (kolom gambar hanya bila diminta).
        ->and((string) $sheet->getCell('AJ7')->getValue())->toBe('Foto Dokumentasi (tautan)')
        ->and((string) $sheet->getCell('AK7')->getValue())->toBe('Foto Hygiene (tautan)');

    // Baris data pertama: satu laporan lengkap dengan lokasi, alat, dan kolaborator.
    expect((string) $sheet->getCell('B8')->getValue())->toBe('RPT-EXPORT-0001')
        ->and((string) $sheet->getCell('G8')->getValue())->toBe('480')
        ->and((string) $sheet->getCell('H8')->getValue())->toBe('8 jam 00 mnt')
        ->and((string) $sheet->getCell('I8')->getValue())->toBe('Teknisi Export Uji')
        ->and((string) $sheet->getCell('J8')->getValue())->toBe('EXP001')
        ->and((string) $sheet->getCell('K8')->getValue())->toBe('Mekanik - Reguler')
        ->and((string) $sheet->getCell('L8')->getValue())->toBe('Rekan Kolaborasi Uji')
        ->and((string) $sheet->getCell('M8')->getValue())->toBe('CM')
        ->and((string) $sheet->getCell('N8')->getValue())->toBe('ZPM 1 - Corrective')
        ->and((string) $sheet->getCell('O8')->getValue())->toBe('Perbaikan Equipment')
        ->and((string) $sheet->getCell('P8')->getValue())->toBe('Done')
        ->and((string) $sheet->getCell('R8')->getValue())->toBe('EXPCO - PT Export Uji')
        ->and((string) $sheet->getCell('S8')->getValue())->toBe('EXPDEPT - Departemen Export')
        ->and((string) $sheet->getCell('T8')->getValue())->toBe('EXPAREA - Area Export')
        ->and((string) $sheet->getCell('U8')->getValue())->toBe('EXPSUB - Sub Area Export')
        ->and((string) $sheet->getCell('V8')->getValue())->toBe('EXP-PROD-TF01-6010 - Section Export Uji')
        ->and((string) $sheet->getCell('W8')->getValue())->toBe('EXP-PU01')
        ->and((string) $sheet->getCell('X8')->getValue())->toBe('Pompa Export Uji')
        ->and((string) $sheet->getCell('Y8')->getValue())->toBe('70009901')
        ->and((string) $sheet->getCell('AA8')->getValue())->toBe('Ganti seal pompa export uji')
        ->and((string) $sheet->getCell('AB8')->getValue())->toBe('Seal bocor ringan')
        ->and((string) $sheet->getCell('AC8')->getValue())->toBe('Seal aus')
        ->and((string) $sheet->getCell('AF8')->getValue())->toBe('1')
        ->and((string) $sheet->getCell('AG8')->getValue())->toBe('1')
        ->and((string) $sheet->getCell('AH8')->getValue())->toBe('92%')
        // Tautan foto ditulis sebagai alamat absolut supaya bisa diklik dari Excel.
        ->and((string) $sheet->getCell('AJ8')->getValue())->toContain('http')
        ->and((string) $sheet->getCell('AJ8')->getValue())->toContain('foto-a.jpg')
        ->and((string) $sheet->getCell('AK8')->getValue())->toContain('foto-b.jpg');

    $spreadsheet->disconnectWorksheets();
    unlink($path);
});


// ============================================================
// ATURAN BARIS & FILTER
// ============================================================

test('laporan kolaborasi tidak menambah baris pada berkas export', function () {
    $respons = $this->actingAs($this->admin)
        ->get('/reports/export/excel?tanggal=' . $this->tanggal)
        ->assertStatus(200);

    [$spreadsheet, $path] = bukaExcelExport($respons);
    $sheet = $spreadsheet->getSheet(0);

    // Hanya satu laporan pada tanggal itu (salinan kolaborasi tidak jadi baris).
    expect($sheet->getHighestRow())->toBe(8)
        ->and(nilaiKolomExport($sheet, 'B'))->not->toContain('SALINAN-KOLAB-EXP');

    // Nama kolaborator tetap muncul pada baris laporan induknya.
    expect((string) $sheet->getCell('L8')->getValue())->toContain('Rekan Kolaborasi Uji');

    $spreadsheet->disconnectWorksheets();
    unlink($path);
});

test('export excel mengikuti rentang tanggal yang dipilih', function () {
    $dari = now()->subDays(45)->toDateString();
    $sampai = now()->toDateString();

    $respons = $this->actingAs($this->admin)
        ->get('/reports/export/excel?date_from=' . $dari . '&date_to=' . $sampai)
        ->assertStatus(200);

    [$spreadsheet, $path] = bukaExcelExport($respons);
    $sheet = $spreadsheet->getSheet(0);

    // Rentang mencakup laporan kemarin DAN laporan 40 hari lalu.
    expect($sheet->getHighestRow())->toBe(9)
        ->and(nilaiKolomExport($sheet, 'B'))->toContain('RPT-EXPORT-0001')
        ->and(nilaiKolomExport($sheet, 'B'))->toContain('RPT-EXPORT-LAMA')
        ->and((string) $sheet->getCell('A3')->getValue())->toContain('Periode:');

    $spreadsheet->disconnectWorksheets();
    unlink($path);
});

test('export menyaring jenis pekerjaan yang dicentang (boleh banyak)', function () {
    // Satu jenis saja: hanya laporan PM (laporan lama) yang ikut.
    $respons = $this->actingAs($this->admin)
        ->get('/reports/export/excel?jenis_pekerjaan_multi[]=PM&tanpa_batas=1')
        ->assertStatus(200);

    [$spreadsheet, $path] = bukaExcelExport($respons);
    $kode = nilaiKolomExport($spreadsheet->getSheet(0), 'B');

    expect($kode)->toContain('RPT-EXPORT-LAMA')
        ->and($kode)->not->toContain('RPT-EXPORT-0001')
        ->and((string) $spreadsheet->getSheet(0)->getCell('A5')->getValue())->toContain('Jenis Pekerjaan: PM');

    $spreadsheet->disconnectWorksheets();
    unlink($path);

    // Dua jenis sekaligus: keduanya ikut.
    $respons = $this->actingAs($this->admin)
        ->get('/reports/export/excel?jenis_pekerjaan_multi[]=PM&jenis_pekerjaan_multi[]=CM&tanpa_batas=1')
        ->assertStatus(200);

    [$spreadsheet, $path] = bukaExcelExport($respons);
    $kode = nilaiKolomExport($spreadsheet->getSheet(0), 'B');

    expect($kode)->toContain('RPT-EXPORT-LAMA')
        ->and($kode)->toContain('RPT-EXPORT-0001');

    $spreadsheet->disconnectWorksheets();
    unlink($path);
});

test('centang seluruh data mengabaikan batas tanggal', function () {
    // Tanpa rentang dan tanpa tanpa_batas: hanya tanggal aktif yang diekspor.
    $respons = $this->actingAs($this->admin)
        ->get('/reports/export/excel?tanggal=' . $this->tanggal)
        ->assertStatus(200);

    [$spreadsheet, $path] = bukaExcelExport($respons);
    expect(nilaiKolomExport($spreadsheet->getSheet(0), 'B'))->not->toContain('RPT-EXPORT-LAMA');
    $spreadsheet->disconnectWorksheets();
    unlink($path);

    // Dengan tanpa_batas: seluruh tanggal ikut.
    $respons = $this->actingAs($this->admin)
        ->get('/reports/export/excel?tanpa_batas=1')
        ->assertStatus(200);

    [$spreadsheet, $path] = bukaExcelExport($respons);
    $sheet = $spreadsheet->getSheet(0);

    expect(nilaiKolomExport($sheet, 'B'))->toContain('RPT-EXPORT-LAMA')
        ->and((string) $sheet->getCell('A3')->getValue())->toContain('seluruh data');

    $spreadsheet->disconnectWorksheets();
    unlink($path);
});


// ============================================================
// CSV & LEMBAR DOKUMENTASI
// ============================================================

test('export csv memuat blok judul profesional, BOM UTF-8, dan tanpa baris kolaborasi', function () {
    $isiCsv = $this->actingAs($this->admin)
        ->get('/reports/export/csv?tanggal=' . $this->tanggal)
        ->assertStatus(200)
        ->streamedContent();

    expect(str_starts_with($isiCsv, "\xEF\xBB\xBF"))->toBeTrue()
        ->and($isiCsv)->toContain('DAFTAR LAPORAN PEKERJAAN')
        ->and($isiCsv)->toContain('Periode:')
        ->and($isiCsv)->toContain('Diekspor:')
        ->and($isiCsv)->toContain('Filter:')
        ->and($isiCsv)->toContain('Jumlah:')
        // Kolom 'Kolaborator' tetap ada (dipakai tautan/uji lama juga).
        ->and($isiCsv)->toContain('Kolaborator')
        ->and($isiCsv)->toContain('Section (Lokasi)')
        ->and($isiCsv)->toContain('RPT-EXPORT-0001')
        ->and($isiCsv)->toContain('Rekan Kolaborasi Uji')
        ->and($isiCsv)->toContain('EXP-PU01')
        ->and($isiCsv)->not->toContain('SALINAN-KOLAB-EXP');
});

test('lembar Ringkasan dan Petunjuk memuat rekap serta dokumentasi kolom', function () {
    $respons = $this->actingAs($this->admin)
        ->get('/reports/export/excel?date_from=' . now()->subDays(45)->toDateString()
            . '&date_to=' . now()->toDateString())
        ->assertStatus(200);

    [$spreadsheet, $path] = bukaExcelExport($respons);

    $ringkasan = $spreadsheet->getSheetByName('Ringkasan');
    $petunjuk  = $spreadsheet->getSheetByName('Petunjuk');

    expect((string) $ringkasan->getCell('A1')->getValue())->toContain('RINGKASAN LAPORAN PEKERJAAN')
        ->and((string) $ringkasan->getCell('A6')->getValue())->toBe('Per Jenis Pekerjaan')
        // Rekap memuat kedua jenis pekerjaan pada rentang itu.
        ->and(nilaiKolomExport($ringkasan, 'A'))->toContain('CM')
        ->and(nilaiKolomExport($ringkasan, 'A'))->toContain('PM')
        ->and(nilaiKolomExport($ringkasan, 'A'))->toContain('TOTAL')
        // Rekap per teknisi juga tersedia.
        ->and(nilaiKolomExport($ringkasan, 'A'))->toContain('Teknisi Export Uji');

    expect((string) $petunjuk->getCell('A1')->getValue())->toContain('PETUNJUK')
        ->and((string) $petunjuk->getCell('A8')->getValue())->toBe('ATURAN BARIS DATA')
        // Dokumentasi kolom dijelaskan satu per satu.
        ->and(nilaiKolomExport($petunjuk, 'B'))->toContain('Kolaborator')
        ->and(nilaiKolomExport($petunjuk, 'B'))->toContain('Section (Lokasi)')
        ->and(nilaiKolomExport($petunjuk, 'B'))->toContain('Equipment No')
        // Kolom foto (tautan & gambar) juga didokumentasikan.
        ->and(nilaiKolomExport($petunjuk, 'B'))->toContain('Foto Dokumentasi (tautan)')
        ->and(nilaiKolomExport($petunjuk, 'B'))->toContain('Foto Hygiene (tautan)');

    $spreadsheet->disconnectWorksheets();
    unlink($path);
});

test('export memakai filter halaman yang sedang aktif (pencarian dan area)', function () {
    $respons = $this->actingAs($this->admin)
        ->get('/reports/export/excel?search=seal+pompa+export&tanpa_batas=1')
        ->assertStatus(200);

    [$spreadsheet, $path] = bukaExcelExport($respons);
    $kode = nilaiKolomExport($spreadsheet->getSheet(0), 'B');

    expect($kode)->toContain('RPT-EXPORT-0001')
        ->and($kode)->not->toContain('RPT-EXPORT-LAMA')
        ->and((string) $spreadsheet->getSheet(0)->getCell('A5')->getValue())->toContain('Pencarian:');

    $spreadsheet->disconnectWorksheets();
    unlink($path);

    // Area yang dipilih membatasi berkas.
    $respons = $this->actingAs($this->admin)
        ->get('/reports/export/excel?area_id=' . $this->area->id . '&tanpa_batas=1')
        ->assertStatus(200);

    [$spreadsheet, $path] = bukaExcelExport($respons);
    $kode = nilaiKolomExport($spreadsheet->getSheet(0), 'B');

    expect($kode)->toContain('RPT-EXPORT-0001')
        ->and($kode)->not->toContain('RPT-EXPORT-LAMA')
        ->and((string) $spreadsheet->getSheet(0)->getCell('A5')->getValue())->toContain('Area: EXPAREA - Area Export');

    $spreadsheet->disconnectWorksheets();
    unlink($path);
});


// ============================================================
// FOTO PADA BERKAS EXPORT
// ============================================================

test('export excel menyematkan gambar foto ketika diminta', function () {
    Storage::fake('telegram_photos', ['url' => '/storage/telegram-photos']);
    Storage::disk('telegram_photos')->put('foto-a.jpg', gambarUjiPng());
    Storage::disk('telegram_photos')->put('foto-b.jpg', gambarUjiPng());

    $respons = $this->actingAs($this->admin)
        ->get('/reports/export/excel?tanggal=' . $this->tanggal . '&sertakan_foto=1')
        ->assertStatus(200);

    [$spreadsheet, $path] = bukaExcelExport($respons);
    $sheet = $spreadsheet->getSheet(0);

    // Dua kolom gambar ditambahkan di belakang kolom tautan.
    expect((string) $sheet->getCell('AL7')->getValue())->toBe('Foto Dokumentasi (gambar)')
        ->and((string) $sheet->getCell('AM7')->getValue())->toBe('Foto Hygiene (gambar)')
        ->and((string) $sheet->getCell('AL8')->getValue())->toBe('lihat gambar')
        ->and((string) $sheet->getCell('AM8')->getValue())->toBe('lihat gambar')
        // Keterangan jumlah pada blok judul menyebut gambar disertakan.
        ->and((string) $sheet->getCell('A6')->getValue())->toContain('Foto:')
        // Kolom tautan tetap terisi.
        ->and((string) $sheet->getCell('AJ8')->getValue())->toContain('foto-a.jpg');

    $spreadsheet->disconnectWorksheets();

    // Bukti nyata: gambar benar-benar tertanam di dalam berkas xlsx.
    expect(mediaExcelExport($path))->toHaveCount(2);

    unlink($path);
});

test('export excel menandai foto yang berkasnya tidak ada di server', function () {
    Storage::fake('telegram_photos', ['url' => '/storage/telegram-photos']);

    $respons = $this->actingAs($this->admin)
        ->get('/reports/export/excel?tanggal=' . $this->tanggal . '&sertakan_foto=1')
        ->assertStatus(200);

    [$spreadsheet, $path] = bukaExcelExport($respons);
    $sheet = $spreadsheet->getSheet(0);

    expect((string) $sheet->getCell('AL8')->getValue())->toBe('1 foto, berkasnya tidak ada di server ini')
        ->and((string) $sheet->getCell('AM8')->getValue())->toBe('1 foto, berkasnya tidak ada di server ini');

    $spreadsheet->disconnectWorksheets();

    // Tanpa berkas foto, export tetap berhasil dan tanpa gambar sama sekali.
    expect(mediaExcelExport($path))->toBeEmpty();

    unlink($path);
});

test('export csv memuat kolom tautan foto tanpa kolom gambar', function () {
    $isiCsv = $this->actingAs($this->admin)
        ->get('/reports/export/csv?tanggal=' . $this->tanggal . '&sertakan_foto=1')
        ->assertStatus(200)
        ->streamedContent();

    expect($isiCsv)->toContain('Foto Dokumentasi (tautan)')
        ->and($isiCsv)->toContain('Foto Hygiene (tautan)')
        ->and($isiCsv)->toContain('foto-a.jpg')
        // Format CSV tidak menyematkan gambar, jadi kolom gambarnya tidak ada.
        ->and($isiCsv)->not->toContain('Foto Dokumentasi (gambar)');
});

// ============================================================
// TAMPILAN KEDUANYA (REGULER + SHIFT)
// ============================================================

test('pilihan tampilan keduanya memuat laporan reguler dan shift', function () {
    // Reguler: laporan pekerja shift tidak ikut.
    [$spreadsheet, $path] = bukaExcelExport(
        $this->actingAs($this->admin)
            ->get('/reports/export/excel?tampilan=reguler&tanpa_batas=1')
            ->assertStatus(200)
    );

    $kode = nilaiKolomExport($spreadsheet->getSheet(0), 'B');

    expect($kode)->toContain('RPT-EXPORT-0001')
        ->and($kode)->not->toContain('RPT-EXPORT-SHIFT');

    $spreadsheet->disconnectWorksheets();
    unlink($path);

    // Shift: laporan pekerja reguler tidak ikut.
    [$spreadsheet, $path] = bukaExcelExport(
        $this->actingAs($this->admin)
            ->get('/reports/export/excel?tampilan=shift&tanpa_batas=1')
            ->assertStatus(200)
    );

    $kode = nilaiKolomExport($spreadsheet->getSheet(0), 'B');

    expect($kode)->toContain('RPT-EXPORT-SHIFT')
        ->and($kode)->not->toContain('RPT-EXPORT-0001')
        ->and((string) $spreadsheet->getSheet(0)->getCell('A5')->getValue())->toContain('Tampilan: Shift');

    $spreadsheet->disconnectWorksheets();
    unlink($path);

    // Keduanya: dua-duanya ikut, dan grub DIABAIKAN (grub hanya berlaku untuk
    // tampilan Shift).
    [$spreadsheet, $path] = bukaExcelExport(
        $this->actingAs($this->admin)
            ->get('/reports/export/excel?tampilan=semua&grub=grub_b&tanpa_batas=1')
            ->assertStatus(200)
    );

    $kode = nilaiKolomExport($spreadsheet->getSheet(0), 'B');

    expect($kode)->toContain('RPT-EXPORT-0001')
        ->and($kode)->toContain('RPT-EXPORT-SHIFT')
        ->and((string) $spreadsheet->getSheet(0)->getCell('A5')->getValue())->toContain('Tampilan: Reguler + Shift (keduanya)');

    $spreadsheet->disconnectWorksheets();
    unlink($path);
});

