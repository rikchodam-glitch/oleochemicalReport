<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Telegram Bot - Konfigurasi Penyimpanan Foto
    |--------------------------------------------------------------------------
    |
    | Dipakai oleh App\Services\Telegram\PhotoStorageService saat
    | mendownload dan menyimpan foto yang dikirim teknisi lewat bot.
    |
    | photo_disk      : nama disk Storage (lihat config/filesystems.php).
    |                    Default 'telegram_photos' — disk khusus dengan root
    |                    storage/app/public/telegram-photos, sudah ikut
    |                    ter-link oleh `php artisan storage:link` dan bisa
    |                    diakses lewat /storage/telegram-photos/...
    | photo_folder    : subfolder di dalam disk tempat foto disimpan.
    |                    Default 'reports' sehingga path tersimpan berbentuk
    |                    reports/YYYY/MM/DD/{chat_id}/{filename}.jpg
    | photo_max_bytes : ukuran maksimum satu file foto (bytes).
    |                    Default 20MB.
    |
    */

    'photo_disk' => env('TELEGRAM_PHOTO_DISK', 'telegram_photos'),

    'photo_folder' => env('TELEGRAM_PHOTO_FOLDER', 'reports'),

    'photo_max_bytes' => (int) env('TELEGRAM_PHOTO_MAX_BYTES', 20 * 1024 * 1024),

];
