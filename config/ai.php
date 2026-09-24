<?php

/**
 * Konfigurasi perilaku modul AI.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Kuota
    |--------------------------------------------------------------------------
    |
    | Sumber kebenaran kuota provider adalah API provider itu sendiri: header
    | x-ratelimit-* pada setiap respons dan HTTP 429 saat batas terlampaui
    | (Groq tidak menyediakan endpoint sisa kuota - GET /usage dan /limits 404).
    | Karena itu penghitung lokal (tokens_used_today/daily_token_limit) hanya
    | dipakai sebagai perkiraan dan TIDAK memblokir pemakaian AI secara bawaan;
    | setel AI_ENFORCE_LOCAL_QUOTA=true bila tetap ingin memblokir berdasarkan
    | angka lokal tersebut.
    |
    */

    'quota' => [
        'enforce_local_limit' => (bool) env('AI_ENFORCE_LOCAL_QUOTA', false),
    ],

];
