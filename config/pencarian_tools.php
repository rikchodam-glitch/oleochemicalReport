<?php

/**
 * Pengaturan pencarian halaman Management Tools.
 */
return [
    'min_huruf' => 2,
    'maks_token' => 8,
    'batas_saran_alat' => 5,
    'batas_saran_entitas' => 3,

    /*
     * Sinonim ringan istilah alat yang sering dipakai teknisi.
     */
    'sinonim' => [
        'bor'        => ['drill'],
        'drill'      => ['bor'],
        'obeng'      => ['screwdriver'],
        'screwdriver'=> ['obeng'],
        'kunci'      => ['wrench', 'spanner'],
        'wrench'     => ['kunci'],
        'spanner'    => ['kunci'],
        'tang'       => ['pliers'],
        'pliers'     => ['tang'],
        'gerinda'    => ['grinder'],
        'grinder'    => ['gerinda'],
        'palu'       => ['hammer'],
        'hammer'     => ['palu'],
        'meter'      => ['multimeter', 'ukur'],
        'multimeter' => ['meter'],
        'gunting'    => ['scissors'],
        'scissors'   => ['gunting'],
        'kabel'      => ['cable'],
        'cable'      => ['kabel'],
    ],
];
