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

    /*
    |--------------------------------------------------------------------------
    | Perangkat jaringan (Switch Managed dan Access Point/Radio)
    |--------------------------------------------------------------------------
    |
    | Daftar perangkat jaringan yang ditambahkan lewat perintah
    | `php artisan it:seed-network`. Sumbernya adalah sheet MODUL JARINGAN pada
    | file "List Perangkat IT Oleochemicals.xlsx" (14 Switch, 8 Radio) ditambah
    | nama switch yang dipakai kolom "Connect To" pada sheet CCTV
    | (mis. Switch Main Office, Switch Produksi, Switch Gudang, Switch Lab NPA,
    | Switch Pos 1 NPA).
    |
    | Radio diperlakukan sebagai ACCESS_POINT karena perangkat ini pemancar /
    | penerima nirkabel, bukan switch.
    |
    | plant/department dipakai untuk pemetaan Functional Location otomatis
    | (lihat sync.funcloc_rules). Bila data lapangan berbeda, cukup ubah di sini
    | lalu jalankan ulang perintahnya (perintah tidak membuat duplikat).
    |
    */

    'network_devices' => [
        // Switch Managed
        ['tag_name' => 'Switch Pos 1',                  'device_type' => 'SWITCH_MANAGED', 'plant' => 'EPE', 'department' => 'GA',  'location' => 'Pos 1 EPE'],
        ['tag_name' => 'Switch Jembatan Timbang (Baru)', 'device_type' => 'SWITCH_MANAGED', 'plant' => 'EPE', 'department' => 'LOG', 'location' => 'Jembatan Timbang EPE'],
        ['tag_name' => 'Switch Ruang Serbaguna',        'device_type' => 'SWITCH_MANAGED', 'plant' => 'EPE', 'department' => 'GA',  'location' => 'Ruang Serbaguna'],
        ['tag_name' => 'Switch Unloading',              'device_type' => 'SWITCH_MANAGED', 'plant' => 'EPE', 'department' => 'LOG', 'location' => 'Unloading EPE'],
        ['tag_name' => 'Switch BD 1 (CCR)',             'device_type' => 'SWITCH_MANAGED', 'plant' => 'EPE', 'department' => 'PRO', 'location' => 'CCR BD 1'],
        ['tag_name' => 'Switch Lab',                    'device_type' => 'SWITCH_MANAGED', 'plant' => 'EPE', 'department' => 'QC',  'location' => 'Laboratorium EPE'],
        ['tag_name' => 'Switch Tangki',                 'device_type' => 'SWITCH_MANAGED', 'plant' => 'EPE', 'department' => 'PRO', 'location' => 'Tank Farm EPE'],
        ['tag_name' => 'Switch Daily Tank',             'device_type' => 'SWITCH_MANAGED', 'plant' => 'EPE', 'department' => 'PRO', 'location' => 'Daily Tank EPE'],
        ['tag_name' => 'Switch Utility',                'device_type' => 'SWITCH_MANAGED', 'plant' => 'EPE', 'department' => 'MTC', 'location' => 'Utility EPE'],
        ['tag_name' => 'Switch Gudang',                 'device_type' => 'SWITCH_MANAGED', 'plant' => 'EPE', 'department' => 'LOG', 'location' => 'Gudang EPE'],
        ['tag_name' => 'Switch Produksi',               'device_type' => 'SWITCH_MANAGED', 'plant' => 'EPE', 'department' => 'PRO', 'location' => 'Area Produksi EPE'],
        ['tag_name' => 'Switch Main Office',            'device_type' => 'SWITCH_MANAGED', 'plant' => 'EPE', 'department' => 'GA',  'location' => 'Main Office EPE'],
        ['tag_name' => 'Switch CES',                    'device_type' => 'SWITCH_MANAGED', 'plant' => 'CES', 'department' => 'PRO', 'location' => 'CES'],
        ['tag_name' => 'Switch Gudang NPA',             'device_type' => 'SWITCH_MANAGED', 'plant' => 'NPA', 'department' => 'LOG', 'location' => 'Gudang NPA'],
        ['tag_name' => 'Switch Jembatan Timbang NPA',   'device_type' => 'SWITCH_MANAGED', 'plant' => 'NPA', 'department' => 'LOG', 'location' => 'Jembatan Timbang NPA'],
        ['tag_name' => 'Switch Produksi NPA',           'device_type' => 'SWITCH_MANAGED', 'plant' => 'NPA', 'department' => 'PRO', 'location' => 'Area Produksi NPA'],
        ['tag_name' => 'Switch Unloading NPA',          'device_type' => 'SWITCH_MANAGED', 'plant' => 'NPA', 'department' => 'LOG', 'location' => 'Unloading NPA'],
        ['tag_name' => 'Switch Pos 1 NPA',              'device_type' => 'SWITCH_MANAGED', 'plant' => 'NPA', 'department' => 'GA',  'location' => 'Pos 1 NPA'],
        ['tag_name' => 'Switch Lab NPA',                'device_type' => 'SWITCH_MANAGED', 'plant' => 'NPA', 'department' => 'QC',  'location' => 'Laboratorium NPA'],

        // Radio (dipetakan sebagai Access Point). connect_to = switch tempat
        // radio ini terhubung (konfirmasi user 23/09/2026: radio NPA terhubung
        // ke Switch Unloading NPA, radio Utility EPE ke Switch Utility).
        ['tag_name' => 'Radio POS 1 NPA',               'device_type' => 'ACCESS_POINT',   'plant' => 'NPA', 'department' => 'GA',  'location' => 'Pos 1 NPA',           'ip_address' => '192.168.50.100', 'model' => 'Nanostation LocoM5', 'connect_to' => 'Switch Unloading NPA'],
        ['tag_name' => 'Radio Timbang NPA',             'device_type' => 'ACCESS_POINT',   'plant' => 'NPA', 'department' => 'LOG', 'location' => 'Jembatan Timbang NPA', 'ip_address' => '192.168.50.101', 'model' => 'Nanostation LocoM5', 'connect_to' => 'Switch Unloading NPA'],
        ['tag_name' => 'Radio (Station) Unloading NPA', 'device_type' => 'ACCESS_POINT',   'plant' => 'NPA', 'department' => 'LOG', 'location' => 'Unloading NPA',       'ip_address' => '192.168.50.102', 'model' => 'Nanostation LocoM5', 'connect_to' => 'Switch Unloading NPA'],
        ['tag_name' => 'Radio (AP) Unloading NPA',      'device_type' => 'ACCESS_POINT',   'plant' => 'NPA', 'department' => 'LOG', 'location' => 'Unloading NPA',       'ip_address' => '192.168.50.103', 'model' => 'Nanostation LocoM5', 'connect_to' => 'Switch Unloading NPA'],
        ['tag_name' => 'Radio Belakang NPA',            'device_type' => 'ACCESS_POINT',   'plant' => 'NPA', 'department' => 'LOG', 'location' => 'Area Belakang NPA',   'ip_address' => '192.168.50.104', 'model' => 'Nanostation LocoM5', 'connect_to' => 'Switch Unloading NPA'],
        ['tag_name' => 'Radio (AP) Server',             'device_type' => 'ACCESS_POINT',   'plant' => 'NPA', 'department' => 'PRO', 'location' => 'Server Room NPA',     'ip_address' => '192.168.50.105', 'model' => 'Nanostation LocoM5', 'connect_to' => 'Switch Unloading NPA'],
        ['tag_name' => 'Radio Utility Pojok EPE',       'device_type' => 'ACCESS_POINT',   'plant' => 'EPE', 'department' => 'MTC', 'location' => 'Utility Pojok EPE',   'ip_address' => '192.168.50.70',  'model' => 'Ubiquiti NS-5AC NanoStation 5AC', 'connect_to' => 'Switch Utility'],
        ['tag_name' => 'Radio Gudang Belakang',         'device_type' => 'ACCESS_POINT',   'plant' => 'EPE', 'department' => 'LOG', 'location' => 'Belakang Gudang EPE', 'ip_address' => '192.168.50.71',  'model' => 'Ubiquiti NS-5AC NanoStation 5AC'],

        // Radio yang sudah tercatat lebih dulu di it_assets (bukan dari sheet
        // MODUL JARINGAN). Disertakan di sini agar nilai Connect To-nya juga
        // diselaraskan setiap kali `it:seed-network` dijalankan.
        ['tag_name' => 'RADIO-NPA',                     'device_type' => 'ACCESS_POINT',   'plant' => 'NPA', 'department' => 'GA',  'location' => 'CCR NPA',             'ip_address' => '192.168.49.107', 'connect_to' => 'Switch Unloading NPA'],
        ['tag_name' => 'Radio Utility EPE',             'device_type' => 'ACCESS_POINT',   'plant' => 'EPE', 'department' => 'MTC', 'location' => 'MTC EPE',             'ip_address' => '192.168.49.106', 'connect_to' => 'Switch Utility'],
    ],

];
