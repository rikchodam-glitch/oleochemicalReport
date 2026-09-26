<?php

/*
|--------------------------------------------------------------------------
| Condition Monitoring (CM) Configuration
|--------------------------------------------------------------------------
|
| File ini berisi konfigurasi untuk modul Condition Monitoring, termasuk
| threshold untuk insight otomatis dari trend slope vibrasi dan parameter
| lainnya yang perlu mudah diubah tanpa mengubah kode.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Vibrasi — Threshold Trending & Insight
    |--------------------------------------------------------------------------
    |
    | Konfigurasi deteksi slope kenaikan signifikan untuk rekomendasi
    | PdM action berdasarkan data trending vibrasi motor (NDEV, NDEH, NDEA).
    |
    */
    'vibration_insight' => [

        // Jumlah minimal titik data terakhir untuk perhitungan slope
        'min_data_points' => 3,

        // Persentase kenaikan dianggap "signifikan" dibanding rata-rata
        // N titik sebelumnya (default: kenaikan > 20%)
        'significant_rise_pct' => 20,

        // Jumlah titik data untuk rata-rata baseline perbandingan
        'baseline_count' => 3,

        // Proyeksi: berapa hari ke depan untuk cek apakah slope akan
        // menyentuh batas Danger (default: 30 hari)
        'projection_days' => 30,

        // Threshold vibrasi (mm/s) untuk level Alarm & Danger
        // Berdasarkan ISO 10816-3 untuk pompa/screw (Group 2)
        'thresholds' => [
            'alarm'  => 7.1,  // mm/s — batas Alarm
            'danger' => 11.2, // mm/s — batas Danger
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Analisis Vibrasi Tinggi — Threshold & Filter
    |--------------------------------------------------------------------------
    |
    | Threshold untuk menentukan equipment dengan vibrasi tinggi, digunakan
    | di halaman Report & Analysis untuk breakdown per PT dan ranking
    | kategori bulanan. Nilai default 4.5 mm/s sesuai ISO 10816-3.
    |
    */
    'high_vibration' => [
        // Batas minimal max_vibration untuk dianggap "vibrasi tinggi" (mm/s)
        'threshold' => 4.5,

        // Status kondisi yang termasuk kategori alarm/danger
        'status_filter' => ['alarm', 'danger'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Sheets — Sumber Data Sync
    |--------------------------------------------------------------------------
    |
    | Link spreadsheet online (Google Sheets) yang menjadi sumber data CM.
    | Di-download sebagai xlsx (export) lalu diimport lewat jalur queued job
    | yang sama dengan upload manual. Link harus berstatus "Anyone with the
    | link can view" agar bisa di-download tanpa Google Cloud API.
    |
    */
    /*
    |--------------------------------------------------------------------------
    | Man Hour & Shift Teknisi
    |--------------------------------------------------------------------------
    |
    | Target jam kerja harian teknisi (default 8 jam = 480 menit) dan jendela
    | waktu tiap shift sesuai jadwal shift maintenance:
    | Shift 1 (08.00-16.00), Shift 2 (16.00-24.00), Shift 3 (00.00-08.00).
    | Kode 'x' pada jadwal berarti OFF (tidak ada target), kode 'R' berarti
    | teknisi reguler (non-shift).
    |
    */
    'man_hour' => [

        // Target menit kerja untuk satu hari kerja penuh
        'target_minutes' => 480,

        // Toleransi menit saat memeriksa jam laporan terhadap jendela shift
        'toleransi_menit' => 60,

        // Jendela waktu tiap kode shift (dipakai untuk deteksi laporan di luar
        // jam shift). '24:00' berarti tengah malam akhir hari.
        'windows' => [
            '1' => ['mulai' => '08:00', 'selesai' => '16:00'],
            '2' => ['mulai' => '16:00', 'selesai' => '24:00'],
            '3' => ['mulai' => '00:00', 'selesai' => '08:00'],
        ],

        // Jendela kerja teknisi reguler (non-shift)
        'reguler_window' => ['mulai' => '08:00', 'selesai' => '16:00'],

        // Hari Minggu dianggap libur (target 0 menit) untuk teknisi reguler
        'minggu_libur_reguler' => true,
    ],

];
