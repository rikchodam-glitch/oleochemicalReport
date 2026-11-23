<?php

/*
 * Pengaturan pencarian laporan.
 *
 * Dipakai oleh App\Services\PencarianLaporanService untuk kotak pencarian
 * utama halaman Laporan. Semua batas sengaja dibuat di sini supaya perilaku
 * pencarian dan dropdown saran bisa diubah tanpa menyentuh logika query.
 */
return [

    // Kata kunci lebih pendek dari ini tidak dijalankan (mencegah query besar
    // hanya karena satu huruf).
    'min_huruf' => 2,

    // Jumlah kata berbeda yang dipakai dari satu kata kunci. Kata sisanya
    // diabaikan supaya pencarian tetap ringan.
    'maks_token' => 8,

    // Batas jumlah baris pada dropdown saran.
    'batas_saran_laporan' => 4,
    'batas_saran_entitas' => 3,

    /*
     * Sinonim ringan: kata kunci dicocokkan dengan variasi ini pada kolom yang
     * sama. Tujuannya menangkap istilah lapangan yang sering bercampur
     * Indonesia/Inggris. Jangan menambah sinonim yang terlalu umum karena
     * hasil pencarian akan melebar.
     */
    'sinonim' => [
        'pompa'      => ['pump'],
        'pump'       => ['pompa'],
        'valve'      => ['kran', 'katup'],
        'kran'       => ['valve', 'katup'],
        'katup'      => ['valve', 'kran'],
        'bearing'    => ['bantalan'],
        'bantalan'   => ['bearing'],
        'kompresor'  => ['compressor'],
        'compressor' => ['kompresor'],
        'konveyor'   => ['conveyor'],
        'conveyor'   => ['konveyor'],
        'gearbox'    => ['gear box'],
        'kabel'      => ['cable'],
        'cable'      => ['kabel'],
        'lampu'      => ['light', 'lamp'],
        'light'      => ['lampu'],
    ],

];
