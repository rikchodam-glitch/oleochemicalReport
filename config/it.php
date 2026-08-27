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

];
