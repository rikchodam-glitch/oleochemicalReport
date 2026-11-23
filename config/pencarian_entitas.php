<?php

/*
 * Pengaturan pencarian halaman Asset Management dan IT Assets.
 *
 * Dipakai oleh App\Services\PencarianAsetService dan
 * App\Services\PencarianItAssetService. Sengaja dipisah dari konfigurasi
 * pencarian laporan supaya batas dan sinonim tiap modul bisa diubah sendiri.
 */
return [

    // Kata kunci lebih pendek dari ini tidak dijalankan.
    'min_huruf' => 2,

    // Jumlah kata berbeda yang dipakai dari satu kata kunci.
    'maks_token' => 8,

    // Batas jumlah baris pada dropdown saran.
    'batas_saran_aset' => 5,
    'batas_saran_entitas' => 3,

    /*
     * Sinonim ringan untuk Asset Management: istilah lapangan yang sering
     * bercampur Indonesia/Inggris.
     */
    'sinonim_aset' => [
        'pompa' => ['pump'],
        'pump' => ['pompa'],
        'valve' => ['kran', 'katup'],
        'kran' => ['valve', 'katup'],
        'katup' => ['valve', 'kran'],
        'bearing' => ['bantalan'],
        'bantalan' => ['bearing'],
        'kompresor' => ['compressor'],
        'compressor' => ['kompresor'],
        'konveyor' => ['conveyor'],
        'conveyor' => ['konveyor'],
        'motor' => ['electrical motor', 'electric motor'],
        'kabel' => ['cable'],
        'cable' => ['kabel'],
        'lampu' => ['light', 'lamp'],
        'light' => ['lampu'],
    ],

    /*
     * Sinonim ringan untuk IT Assets: istilah sehari-hari ke kode/label tipe
     * perangkat atau nama perangkat.
     */
    'sinonim_it' => [
        'kamera' => ['cctv'],
        'cctv' => ['kamera'],
        'komputer' => ['pc'],
        'pc' => ['komputer', 'computer'],
        'laptop' => ['notebook'],
        'notebook' => ['laptop'],
        'jaringan' => ['network'],
        'network' => ['jaringan'],
        'wifi' => ['access point'],
        'printer' => ['pencetak'],
        'pencetak' => ['printer'],
        'switch' => ['switching'],
    ],

    /*
     * Label tipe perangkat untuk dropdown saran (klik tipe = filter).
     * Samakan dengan label pada halaman IT Assets.
     */
    'label_tipe_it' => [
        'PC' => 'PC',
        'LAPTOP' => 'Laptop',
        'PRINTER' => 'Printer',
        'CCTV' => 'CCTV',
        'NVR' => 'NVR',
        'ACCESS_DOOR' => 'Access Door',
        'WIFI' => 'WiFi',
        'NETWORK' => 'Network',
        'SWITCH_MANAGED' => 'Switch Managed',
        'SWITCH_POE_UNMANAGED' => 'Switch PoE Unmanaged',
        'ACCESS_POINT' => 'Access Point',
        'HUB' => 'HUB',
        'UPS' => 'UPS',
        'OTHER' => 'Lainnya',
    ],

];
