<?php

use App\Jobs\ProcessCmFindingsImport;
use App\Jobs\SyncGoogleSheetsJob;
use App\Models\CmEquipment;
use App\Models\ImportLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Penjaga mode pemrosesan sync Google Sheets CM.
 *
 * Latar (kejadian 13/10/2026): baris tanggal baru sudah ada di spreadsheet
 * Google, tetapi halaman Finding CM tidak ikut berubah. Ternyata sync hanya
 * men-DOWNLOAD dan menaruh job di tabel `jobs`; karena DEV tidak menjalankan
 * service `queue:work` (AGENTS.md), job itu tidak pernah diproses dan baris
 * `import_logs` tertinggal berstatus 'processing'. Perbaikannya: mode import
 * 'inline' di DEV (dijalankan langsung) dan 'queue' tetap bawaan PROD.
 *
 * Test di bawah menjaga tiga hal: nilai bawaan mode, mode inline benar-benar
 * mengimpor tanpa worker, dan mode queue tidak berubah perilakunya di PROD.
 */

// ============================================================
// BANTUAN PENGUJIAN SYNC CM
// ============================================================

/**
 * Menulis berkas xlsx uji untuk sync CM: satu kunci array = satu sheet.
 *
 * @param  string $nama   Nama berkas di folder sementara
 * @param  array<string, array<int, array<int, mixed>>> $lembar Peta nama sheet => baris
 * @return string Path berkas xlsx
 */
function cmUjiXlsx(string $nama, array $lembar): string
{
    $buku  = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $mulai = true;

    foreach ($lembar as $namaLembar => $baris) {
        $sheet = $mulai ? $buku->getActiveSheet() : $buku->createSheet();
        $sheet->setTitle($namaLembar);
        $sheet->fromArray($baris, null, 'A1');
        $mulai = false;
    }

    $folder = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cm-sync-uji';

    if (!is_dir($folder)) {
        mkdir($folder, 0777, true);
    }

    $path = $folder . DIRECTORY_SEPARATOR . $nama;
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($buku))->save($path);
    $buku->disconnectWorksheets();

    return $path;
}

/**
 * Isi berkas uji Finding CM: satu baris tanggal 13/10/2026.
 *
 * @return string Path berkas xlsx
 */
function cmUjiBerkasFinding(): string
{
    // Date Found dikirim sebagai tanggal Excel asli (serial, tanpa format
    // angka) supaya dibaca jalur numerik di ProcessCmFindingsImport::parseDate
    // — sama seperti sel tanggal pada spreadsheet sumber.
    $tanggal = \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(new \DateTime('2026-10-13'));

    return cmUjiXlsx('finding-cm-uji.xlsx', [
        'Finding CM' => [
            ['No', 'Equipment Tag', 'Date Found', 'PT Location', 'Plant', 'Finding Foto 1', 'Analysis', 'Action', 'PIC', 'Severity Level', 'Status', 'Remark'],
            [1, 'TAG-UJI-SYNC-1', $tanggal, 'PT UJI', 'PLANT UJI', 'VIBRASI TINGGI', 'Analisa uji', 'Ganti bearing', 'Teknisi Uji', 'High', 'Open', 'Catatan uji'],
        ],
    ]);
}

/**
 * Menyiapkan data minimal agar import Finding CM benar-benar menyimpan baris:
 * satu user (pelaku sync) dan satu equipment CM dengan tag yang cocok.
 *
 * @return void
 */
function cmSiapkanDataSync(): void
{
    User::create([
        'name'     => 'Admin Sync Uji',
        'username' => 'adminsyncuji',
        'email'    => 'adminsyncuji@oleochemical.pro',
        'password' => bcrypt('rahasia123'),
        'role'     => 'admin',
    ]);

    CmEquipment::create([
        'equipment_tag' => 'TAG-UJI-SYNC-1',
        'pt_location'   => 'PT UJI',
        'plant'         => 'PLANT UJI',
    ]);
}

// ============================================================
// MODE BAWAAN
// ============================================================

test('mode import bawaan adalah queue di luar lingkungan local (PROD)', function () {
    // phpunit.xml memakai APP_ENV=testing, jadi nilai bawaan yang berlaku di
    // sini sama dengan PROD: queue. Mode inline hanya otomatis di APP_ENV=local.
    expect(config('cm.google_sheets.import_mode'))->toBe('queue')
        ->and(SyncGoogleSheetsJob::importInline())->toBeFalse();

    config(['cm.google_sheets.import_mode' => 'inline']);

    expect(SyncGoogleSheetsJob::importInline())->toBeTrue();
});

// ============================================================
// MODE INLINE (DEV TANPA WORKER)
// ============================================================

test('mode inline mengimpor Finding CM langsung tanpa queue worker', function () {
    Queue::fake();
    Storage::fake('local');

    config([
        'cm.google_sheets.import_mode'    => 'inline',
        'cm.google_sheets.data_cm_url'    => null,
        'cm.google_sheets.finding_cm_url' => 'https://docs.google.com/spreadsheets/d/uji-sync-finding-cm/edit',
    ]);

    cmSiapkanDataSync();
    Http::fake(['*' => Http::response(file_get_contents(cmUjiBerkasFinding()))]);

    $hasil = SyncGoogleSheetsJob::runSync();

    Queue::assertNothingPushed();

    $log = ImportLog::query()->latest('id')->first();

    expect($log->status)->toBe('completed')
        ->and($log->insert_baru)->toBe(1)
        ->and($hasil['finding_cm']['status'])->toBe('ok')
        ->and($hasil['finding_cm']['message'])->toContain('langsung');

    expect(DB::table('cm_findings')->where('kode_finding', '1')->value('tanggal_temuan'))
        ->toBe('2026-10-13');
});

// ============================================================
// MODE QUEUE (BAWAAN PROD) TIDAK BERUBAH
// ============================================================

test('mode queue hanya menjadwalkan import seperti semula (butuh queue:work)', function () {
    Queue::fake();
    Storage::fake('local');

    config([
        'cm.google_sheets.import_mode'    => 'queue',
        'cm.google_sheets.data_cm_url'    => null,
        'cm.google_sheets.finding_cm_url' => 'https://docs.google.com/spreadsheets/d/uji-sync-finding-cm/edit',
    ]);

    cmSiapkanDataSync();
    Http::fake(['*' => Http::response(file_get_contents(cmUjiBerkasFinding()))]);

    $hasil = SyncGoogleSheetsJob::runSync();

    Queue::assertPushed(ProcessCmFindingsImport::class);

    expect($hasil['finding_cm']['status'])->toBe('ok')
        ->and($hasil['finding_cm']['message'])->toContain('dijadwalkan')
        ->and(ImportLog::query()->latest('id')->first()->status)->toBe('processing')
        ->and(DB::table('cm_findings')->count())->toBe(0);
});
