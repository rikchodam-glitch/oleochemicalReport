# Perbaiki tautan cuti yang dibuka pratinjau Telegram

## Penyebab

Di PROD, token id=3 dibuat 09:54:01, `opened_at` 09:54:03, `used_at` kosong,
tetapi tabel `sessions` tidak memiliki session dengan `cuti_token_id`. URL
sudah dibuka oleh pratinjau tautan Telegram (tanpa cookie). Saat teknisi asli
membuka tautan, sistem menganggapnya "dibuka di perangkat lain" dan menampilkan
halaman tautan tidak berlaku.

## Perbaikan

- GET form tidak lagi menolak session baru hanya karena `opened_at` sudah
  terisi. Tautan boleh dibuka ulang dari browser/perangkat lain sampai
  `used_at` atau kedaluwarsa.
- Submission tetap membutuhkan session dari GET terakhir, dan token tetap
  sekali pakai.
- Pesan bot mematikan `disable_web_page_preview` agar Telegram tidak membuka
  form lebih dulu.
- Berlaku untuk jalur poller (`TelegramMessageHandlerTrait`) dan webhook.

## Uji

- `php artisan test --filter=CutiTest`: 13 test hijau, 65 assertion.
- Seluruh suite: 689 test hijau, 3.405 assertion.
- `view:cache` sukses.

## Berkas

- `app/Http/Controllers/CutiController.php`
- `app/Console/Commands/Traits/TelegramSenderTrait.php`
- `app/Console/Commands/Traits/TelegramMessageHandlerTrait.php`
- `app/Http/Controllers/TelegramWebhookController.php`
- `tests/Feature/CutiTest.php`
- `docs/23-fitur-cuti.md`
- `docs/12-riwayat-insiden-33-46.md` (insiden #50)
- `AGENTS.md`
- `tugas/erik.md`
