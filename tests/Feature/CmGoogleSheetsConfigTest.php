<?php

use App\Jobs\SyncGoogleSheetsJob;

/**
 * Penjaga kunci konfigurasi sync Google Sheets.
 *
 * Latar: blok 'google_sheets' di config/cm.php pernah HILANG (judul
 * komentarnya tertinggal) sehingga config('cm.google_sheets.*') bernilai
 * null dan tombol Sync Google Sheets selalu berakhir "Data CM: URL belum
 * dikonfigurasi di .env | Finding CM: URL belum dikonfigurasi di .env"
 * padahal .env sudah berisi kedua URL. Test di bawah memastikan kuncinya
 * ada DAN benar-benar dipakai SyncGoogleSheetsJob.
 */

test('config cm memuat kunci google sheets untuk Data CM dan Finding CM', function () {
    expect(config('cm.google_sheets'))->toBeArray()
        ->and(array_keys(config('cm.google_sheets')))
        ->toContain('data_cm_url')
        ->toContain('finding_cm_url');
});

test('sync melaporkan skipped beserta nama kunci env bila URL memang kosong', function () {
    config([
        'cm.google_sheets.data_cm_url'    => null,
        'cm.google_sheets.finding_cm_url' => null,
    ]);

    $hasil = SyncGoogleSheetsJob::runSync();

    expect($hasil['data_cm']['status'])->toBe('skipped')
        ->and($hasil['data_cm']['message'])->toContain('URL belum dikonfigurasi di .env')
        ->and($hasil['data_cm']['message'])->toContain('GOOGLE_SHEETS_DATA_CM_URL')
        ->and($hasil['finding_cm']['status'])->toBe('skipped')
        ->and($hasil['finding_cm']['message'])->toContain('GOOGLE_SHEETS_FINDING_CM_URL');
});

test('nilai config dipakai sync: URL yang bukan spreadsheet dianggap gagal, bukan skipped', function () {
    config([
        'cm.google_sheets.data_cm_url'    => 'https://contoh.test/bukan-spreadsheet',
        'cm.google_sheets.finding_cm_url' => 'https://contoh.test/bukan-spreadsheet-juga',
    ]);

    $hasil = SyncGoogleSheetsJob::runSync();

    expect($hasil['data_cm']['status'])->toBe('error')
        ->and($hasil['data_cm']['message'])->toContain('tidak valid')
        ->and($hasil['finding_cm']['status'])->toBe('error')
        ->and($hasil['finding_cm']['message'])->toContain('tidak valid');
});
