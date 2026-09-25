<?php

/**
 * Konfigurasi modul Asset Management.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Nomor Equipment Otomatis
    |--------------------------------------------------------------------------
    |
    | Baris import yang TIDAK menyertakan Equipment No akan dibuatkan nomornya
    | otomatis. Nomor mengikuti seri milik PT bersangkutan (angka pertama pada
    | nomor yang sudah ada), dengan awalan cadangan di bawah ini bila PT tersebut
    | belum punya nomor sama sekali.
    |
    | Contoh data nyata: EPE 10000001..10003396, NPA 70000001.., CES 60000001..
    |
    */

    'equipment_no' => [
        'panjang'      => 8,
        'awalan_pt'    => [
            'EPE' => '1',
            'NPA' => '7',
            'CES' => '6',
        ],
    ],

];
