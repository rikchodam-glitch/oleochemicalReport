<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Storage & Kapasitas - Konfigurasi
    |--------------------------------------------------------------------------
    |
    | Dipakai oleh App\Services\StorageService (perintah `storage:report`,
    | `storage:clean`, dan panel Storage di UI) untuk mendeteksi pemakaian
    | ruang, batas retensi berkas, serta lokasi penyimpanan aplikasi.
    |
    | snapshot_retention_days : jumlah hari berkas snapshot CCTV dipertahankan.
    |                           Berkas lebih tua dari ini dihapus oleh
    |                           pembersih otomatis. Aplikasi hanya merujuk
    |                           snapshot TERBARU (last_snapshot_path), jadi
    |                           berkas lama murni arsip.
    | warn_free_bytes         : ambang peringatan sisa ruang disk (byte).
    |                           Dipakai untuk notifikasi bila ruang menipis.
    | data_root               : folder induk data besar (snapshot/foto/denah)
    |                           di luar folder project. Isi dengan drive HDD
    |                           supaya SSD sistem tidak penuh. Contoh Windows:
    |                           D:/oleochemical-data
    | mysql_ini_paths         : pola lokasi file my.ini MySQL yang dicari untuk
    |                           membaca datadir (dipakai panel & pemindahan DB).
    | storage_reports         : jumlah hari ringkasan laporan disimpan.
    |
    */

    'snapshot_retention_days' => (int) env('STORAGE_SNAPSHOT_RETENTION_DAYS', 30),

    'warn_free_bytes' => (int) env('STORAGE_WARN_FREE_BYTES', 20 * 1024 * 1024 * 1024),

    'data_root' => env('STORAGE_DATA_ROOT'),

    // Nama folder bawaan untuk data besar di drive tujuan, mis. bila drive
    // D: dipilih dan nama diisi 'oleochemical-data' maka datanya berada di
    // D:\oleochemical-data. Dipakai sebagai nilai awal di panel Storage.
    'data_folder' => env('STORAGE_DATA_FOLDER', 'oleochemical-data'),

    'mysql_ini_paths' => array_filter(array_map('trim', explode(',', (string) env(
        'STORAGE_MYSQL_INI_PATHS',
        'C:/laragon/bin/mysql/*/my.ini'
    )))),

];
