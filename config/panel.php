<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Monitoring Ruang Panel - Konfigurasi
    |--------------------------------------------------------------------------
    |
    | Dipakai oleh App\Services\PanelSensorService, perintah `panel:tarik`,
    | dan PanelRuanganController. Seluruh nilai bisa ditimpa lewat .env tanpa
    | mengubah kode.
    |
    */

    'pull' => [
        // Aktifkan atau nonaktifkan penarikan data terjadwal dari perangkat.
        'enabled' => (bool) env('PANEL_PULL_ENABLED', true),

        // Batas waktu (detik) satu permintaan HTTP ke perangkat ESP32.
        // Nilai kecil supaya halaman panel tidak ikut menunggu saat alat mati.
        'timeout_detik' => (int) env('PANEL_PULL_TIMEOUT', 3),

        // Jeda minimum (detik) antar penarikan saat halaman panel sedang
        // dibuka. Dipakai supaya polling 5 detik dari browser tidak
        // membanjiri perangkat dan tidak membebani database.
        'cache_detik' => (int) env('PANEL_PULL_CACHE', 3),

        // Jeda antar percobaan untuk perangkat yang sedang tidak bisa
        // dihubungi (detik). Tanpa jeda ini, setiap polling browser akan
        // menunggu batas waktu HTTP untuk tiap ruangan yang offline sehingga
        // halaman terasa lambat saat perangkat belum terpasang.
        'jeda_gagal_detik' => (int) env('PANEL_PULL_JEDA_GAGAL', 30),
    ],

    // Ambang batas bawaan untuk ruangan baru. Bisa diubah per ruangan.
    'ambang' => [
        'suhu_atas' => (float) env('PANEL_AMBANG_SUHU_ATAS', 30),
        'suhu_bawah' => (float) env('PANEL_AMBANG_SUHU_BAWAH', 18),
    ],

    'tampilan' => [
        // Interval polling dashboard ruang panel (detik).
        'interval_polling_detik' => (int) env('PANEL_INTERVAL_POLLING', 5),

        // Jumlah titik data pada grafik tiap ruangan.
        'jumlah_titik_grafik' => (int) env('PANEL_TITIK_GRAFIK', 60),

        // Jumlah baris riwayat terakhir yang ditampilkan di tabel.
        'jumlah_baris_riwayat' => (int) env('PANEL_BARIS_RIWAYAT', 20),
    ],

    // Jumlah pembacaan terakhir yang disimpan per penarikan. Perangkat
    // menyimpan buffer 360 pembacaan di RAM (1 jam), jadi nilai ini dibuat
    // lebih besar dari buffer supaya tumpukan data bisa dihabiskan dalam satu
    // penarikan setelah server sempat mati.
    'maks_pembacaan_per_tarik' => (int) env('PANEL_MAKS_PER_TARIK', 400),

];
