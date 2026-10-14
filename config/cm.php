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
    'google_sheets' => [

        // Spreadsheet Data CM (sheet: Data AppSheet & Status CM)
        'data_cm_url' => env('GOOGLE_SHEETS_DATA_CM_URL'),

        // Spreadsheet Finding CM (sheet: Finding CM)
        'finding_cm_url' => env('GOOGLE_SHEETS_FINDING_CM_URL'),

        // Cara memproses hasil download:
        //   'inline' = import dijalankan LANGSUNG di proses yang sama
        //              (bawaan DEV, karena DEV tidak menjalankan service
        //              queue:work sehingga job hanya menumpuk di tabel jobs
        //              dan data tidak pernah masuk);
        //   'queue'  = import diserahkan ke queue:work (bawaan PROD).
        // Isi CM_SYNC_IMPORT_MODE di .env untuk memaksa salah satunya.
        'import_mode' => env('CM_SYNC_IMPORT_MODE', env('APP_ENV') === 'local' ? 'inline' : 'queue'),
    ],

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

        // Target menit kerja untuk satu hari kerja penuh (shift / reguler penuh)
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

        // Jam kerja teknisi non-shift. Target harian dihitung dari (selesai -
        // mulai) dikurangi istirahat, jadi hari Sabtu/Minggu dan Jumat otomatis
        // punya target lebih kecil. Kunci hari: 0 = Minggu ... 6 = Sabtu.
        'jam_kerja' => [
            'reguler' => [
                'label' => 'Reguler',
                // Senin - Kamis: 08.00-16.00, istirahat 12.00-13.00 (7 jam)
                1 => ['mulai' => '08:00', 'selesai' => '16:00', 'istirahat' => [['12:00', '13:00']]],
                2 => ['mulai' => '08:00', 'selesai' => '16:00', 'istirahat' => [['12:00', '13:00']]],
                3 => ['mulai' => '08:00', 'selesai' => '16:00', 'istirahat' => [['12:00', '13:00']]],
                4 => ['mulai' => '08:00', 'selesai' => '16:00', 'istirahat' => [['12:00', '13:00']]],
                // Jumat: 08.00-16.00, istirahat 2 jam 11.00-13.00 (6 jam)
                5 => ['mulai' => '08:00', 'selesai' => '16:00', 'istirahat' => [['11:00', '13:00']]],
                // Sabtu: 08.00-14.00, istirahat 12.00-13.00 (5 jam)
                6 => ['mulai' => '08:00', 'selesai' => '14:00', 'istirahat' => [['12:00', '13:00']]],
                // Minggu libur
                0 => null,
            ],
            'reguler_staff' => [
                'label' => 'Reguler Staff',
                // Senin - Kamis: 08.00-17.00, istirahat 12.00-13.00 (8 jam)
                1 => ['mulai' => '08:00', 'selesai' => '17:00', 'istirahat' => [['12:00', '13:00']]],
                2 => ['mulai' => '08:00', 'selesai' => '17:00', 'istirahat' => [['12:00', '13:00']]],
                3 => ['mulai' => '08:00', 'selesai' => '17:00', 'istirahat' => [['12:00', '13:00']]],
                4 => ['mulai' => '08:00', 'selesai' => '17:00', 'istirahat' => [['12:00', '13:00']]],
                // Jumat: 08.00-17.00, istirahat 2 jam 11.00-13.00 (7 jam)
                5 => ['mulai' => '08:00', 'selesai' => '17:00', 'istirahat' => [['11:00', '13:00']]],
                // Sabtu & Minggu libur
                6 => null,
                0 => null,
            ],
        ],

        // Hari Minggu dianggap libur (target 0 menit) untuk teknisi reguler
        'minggu_libur_reguler' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Pola Rotasi Shift (agar tidak perlu upload jadwal tiap bulan)
    |--------------------------------------------------------------------------
    |
    | Hasil analisa 4 file jadwal (26 Juni - 25 Oktober 2026): semua group
    | memakai siklus 20 hari yang sama, hanya fasenya berbeda.
    |   Siklus: 5 hari Shift 1 -> 2 OFF -> 5 hari Shift 3 -> 2 OFF ->
    |           5 hari Shift 2 -> 1 OFF (total 20 hari)
    | Fase dihitung dari tanggal acuan: kode(group, tanggal) =
    |   siklus[(jarak_hari(tanggal, acuan) + offset[group]) mod 20].
    | Verifikasi: 976 dari 976 hari-orang pada 4 file cocok tanpa selisih.
    | Ubah 'offset' bila perusahaan mengubah komposisi group.
    |
    */
    'shift_pattern' => [

        // Tanggal acuan perhitungan fase
        'acuan' => '2026-06-26',

        // Siklus dalam bentuk blok: [kode, jumlah hari]
        'siklus' => [
            ['1', 5],
            ['x', 2],
            ['3', 5],
            ['x', 2],
            ['2', 5],
            ['x', 1],
        ],

        // Fase tiap group (A-D) terhadap tanggal acuan
        'offset' => [
            'A' => 2,
            'B' => 12,
            'C' => 17,
            'D' => 7,
        ],

        // Pemetaan group teknisi di aplikasi ke group jadwal
        'grup_ke_huruf' => [
            'grub_a' => 'A',
            'grub_b' => 'B',
            'grub_c' => 'C',
            'grub_d' => 'D',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Wizard Laporan Telegram — Batas Diam (Idle) Sesi
    |--------------------------------------------------------------------------
    |
    | Satu sesi wizard laporan Telegram menyimpan progres teknisi di cache
    | (ReportWizardService, batas keras 2 jam). Bila teknisi menghilang di
    | tengah alur, sesi lama itu tetap hidup sehingga teks BARU dari teknisi
    | dibaca sebagai jawaban langkah lama — inilah gejala teknisi "tersangkut
    | di sesi/tahap tertentu".
    |
    | Nilai di bawah ini menentukan berapa menit sesi boleh menganggur sebelum
    | teks baru dianggap memulai laporan baru. Pembatalan HANYA dipicu pesan
    | TEKS: foto yang masuk setelah batas ini tetap dimasukkan ke laporan yang
    | sedang berjalan supaya progres teknisi yang kembali dari lapangan tidak
    | hilang (batas kerasnya tetap CACHE_TTL 2 jam).
    |
    */
    'wizard' => [

        // Menit tanpa aktivitas sebelum sesi basi dibatalkan saat teks baru
        // datang. Isi 0 untuk mematikan pembatalan otomatis.
        'idle_timeout_menit' => 10,

    ],

];
