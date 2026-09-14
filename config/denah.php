<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Konfigurasi Denah IT
    |--------------------------------------------------------------------------
    |
    | Pra-render PDF denah menjadi gambar (PNG) sekali di server memakai
    | Ghostscript, supaya halaman denah tidak perlu mem-parse & merender PDF
    | di browser (PDF.js) pada setiap kali dibuka. Gambar hasil cache
    | disimpan di disk 'public' dan di-serve dengan header cache kuat.
    |
    | gs_path         : path binary Ghostscript (gswin64c.exe). Bila kosong,
    |                   aplikasi mencari otomatis (PATH atau folder install).
    | preview_dpi     : resolusi render (DPI). 200 sudah tajam untuk garis
    |                   teknik dan ukuran filenya kecil.
    | preview_folder  : subfolder di dalam disk untuk menyimpan hasil render.
    | disk            : disk Storage (lihat config/filesystems.php).
    |
    */

    'gs_path' => env('GHOSTSCRIPT_PATH'),

    'preview_dpi' => (int) env('DENAH_PREVIEW_DPI', 400),

    'preview_folder' => 'layout-previews',

    'disk' => 'public',

];
