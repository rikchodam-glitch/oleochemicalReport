<?php

return [

    /*
    |--------------------------------------------------------------------------
    | IT Assets - Konfigurasi Monitoring
    |--------------------------------------------------------------------------
    |
    | Dipakai oleh ItMonitorService, perintah `it:ping`, `it:snapshot`,
    | dan controller IT Assets.
    |
    */

    'ping' => [
        // Aktifkan atau nonaktifkan ping otomatis terjadwal.
        'enabled' => (bool) env('IT_PING_ENABLED', true),

        // Interval penjadwalan dalam menit.
        'interval_minutes' => (int) env('IT_PING_INTERVAL_MINUTES', 5),

        // Latency (ms) yang dianggap "ping tinggi" dan memicu notifikasi.
        'high_latency_ms' => (int) env('IT_PING_HIGH_LATENCY_MS', 500),
    ],

    'snapshot' => [
        // Aktifkan atau nonaktifkan pengambilan snapshot CCTV/NVR berkala.
        'enabled' => (bool) env('IT_SNAPSHOT_ENABLED', true),

        // Interval penjadwalan dalam menit.
        'interval_minutes' => (int) env('IT_SNAPSHOT_INTERVAL_MINUTES', 60),
    ],

    'notification' => [
        // Jeda minimum (menit) antar notifikasi sejenis untuk perangkat
        // yang sama, agar tidak membanjiri saat perangkat offline lama.
        'cooldown_minutes' => (int) env('IT_NOTIFICATION_COOLDOWN_MINUTES', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sinkronisasi IT Assets ke tabel assets
    |--------------------------------------------------------------------------
    |
    | Perangkat IT disambungkan ke tabel assets (sumber lokasi dan pencarian
    | wizard laporan Telegram) memakai Functional Location yang sama. Aturan di
    | bawah menentukan funcloc tujuan berdasarkan `plant` dan kata kunci pada
    | `department` perangkat IT. Aturan dievaluasi berurutan; kata kunci ''
    | berarti cocok untuk semua department pada plant tersebut.
    |
    | Aturan ini adalah kebijakan data, bukan konstanta teknis — bila peta
    | lokasi berubah, cukup ubah di sini tanpa menyentuh kode service.
    |
    */

    'sync' => [
        // Aturan utama: plant -> daftar aturan (kata kunci department -> funcloc
        // level 1). Urutan penting: aturan paling spesifik diletakkan dulu.
        'funcloc_rules' => [
            'EPE' => [
                ['keywords' => ['MTC', 'MAINT'], 'funcloc' => 'EPE-MTCE'],
                ['keywords' => ['PRO', 'PRODUKSI'], 'funcloc' => 'EPE-PROD'],
                ['keywords' => ['LOG'], 'funcloc' => 'EPE-LOGE'],
                ['keywords' => ['QC', 'QA', 'LAB', 'QTC'], 'funcloc' => 'EPE-QCRD'],
                ['keywords' => ['ACC', 'EHS', 'GA', 'HRG', 'OFFICE', 'PPIC', 'MGM', 'UNITY', 'GENERAL'], 'funcloc' => 'EPE-HRGA'],
            ],
            'NPA' => [
                ['keywords' => ['PRO', 'PRODUKSI'], 'funcloc' => 'NPA-PROD'],
                ['keywords' => ['LOG'], 'funcloc' => 'NPA-LOGE'],
                ['keywords' => ['QC', 'QA', 'LAB'], 'funcloc' => 'NPA-QCRD'],
                ['keywords' => ['GA', 'ACC', 'OFFICE'], 'funcloc' => 'NPA-PROD'],
            ],
            'CES' => [
                ['keywords' => [''], 'funcloc' => 'CES-PROD'],
            ],
        ],

        // Aturan cadangan bila departemen tidak diisi: dipakai untuk perangkat
        // yang secara fisik berada di area kantor/plant (mis. CCTV EPE yang di
        // data industri lama memang berada di bawah HRGA).
        'device_fallback_rules' => [
            ['plants' => ['EPE'], 'device_types' => ['CCTV', 'NVR', 'ACCESS_DOOR'], 'funcloc' => 'EPE-HRGA'],
            ['plants' => ['NPA'], 'device_types' => ['CCTV', 'NVR', 'ACCESS_DOOR'], 'funcloc' => 'NPA-PROD'],
        ],
    ],

];
