<?php

return [

    /*
    |--------------------------------------------------------------------------
    | CCTV Snapshot - Konfigurasi
    |--------------------------------------------------------------------------
    |
    | Dipakai oleh App\Services\CctvSnapshotService untuk mengambil satu
    | frame gambar dari kamera CCTV/NVR (device_type CCTV atau NVR pada
    | tabel it_assets) berdasarkan snapshot_url yang diisi admin di halaman
    | detail perangkat.
    |
    | Dua mode sumber didukung, dideteksi otomatis dari skema URL:
    |   - rtsp://...   -> diambil satu frame lewat ffmpeg (butuh FFMPEG_PATH
    |                      di .env, sudah disiapkan sejak awal project).
    |   - http(s)://... -> diasumsikan HTTP snapshot endpoint bawaan
    |                      kamera (mis. Hikvision ISAPI), diambil langsung
    |                      lewat HTTP GET (Basic/Digest auth otomatis oleh
    |                      Laravel HTTP client bila snapshot_username diisi).
    |
    | snapshot_disk    : disk Storage tempat hasil snapshot disimpan (lihat
    |                     config/filesystems.php). Root-nya di dalam
    |                     storage/app/public agar ikut ter-link oleh
    |                     `php artisan storage:link` dan bisa ditampilkan
    |                     langsung di halaman detail.
    | snapshot_folder  : subfolder di dalam disk.
    | ffmpeg_path       : path binary ffmpeg (dipakai untuk sumber RTSP).
    | ffmpeg_timeout    : batas waktu (detik) proses ffmpeg mengambil satu
    |                     frame sebelum dianggap gagal/timeout. Kamera yang
    |                     tidak merespons atau jaringan lambat tidak boleh
    |                     menggantung request HTTP admin terlalu lama.
    | http_timeout      : batas waktu (detik) untuk mode HTTP snapshot
    |                     endpoint langsung.
    |
    */

    'snapshot_disk' => env('CCTV_SNAPSHOT_DISK', 'cctv_snapshots'),

    'snapshot_folder' => env('CCTV_SNAPSHOT_FOLDER', 'snapshots'),

    'ffmpeg_path' => env('FFMPEG_PATH', 'ffmpeg'),

    'ffmpeg_timeout' => (int) env('CCTV_SNAPSHOT_FFMPEG_TIMEOUT', 15),

    'http_timeout' => (int) env('CCTV_SNAPSHOT_HTTP_TIMEOUT', 10),

];
