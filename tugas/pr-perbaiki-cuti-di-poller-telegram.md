# Perbaiki /cuti di poller Telegram

## Penyebab

Fitur cuti sebelumnya hanya dipasang di `TelegramWebhookController`. Bot PROD
menjalankan `php artisan telegram:poll` -> `TelegramMessageHandlerTrait`, jadi
`/cuti` tidak dikenali dan jatuh ke `handleReport()`; akibatnya bot memunculkan
klasifikasi pekerjaan seperti laporan baru.

## Perbaikan

- `handleCutiCommand()` ditambahkan ke `TelegramMessageHandlerTrait`.
- Perintah dicek **sebelum** wizard/`handleReport()`, sehingga `/cuti` tidak
  pernah masuk klasifikasi pekerjaan.
- Alasan setelah `/cuti` tetap disimpan ke token dan otomatis mengisi form.
- Pembatas 10 detik tetap berlaku.
- Jalur webhook tetap dipertahankan untuk deployment yang memakai webhook.

## Perbaikan tautan "tidak berlaku"

Ditemukan token ganda di PROD: token id=1 dibuat 09:44:38, dibuka 09:44:39,
lalu `used_at` 09:44:53 tepat saat token id=2 dibuat. Permintaan `/cuti`
berikutnya membatalkan token pertama yang sedang dibuka. Sekarang
`CutiService::buatTautan()` hanya membatalkan token lama yang **belum pernah
dibuka**; token yang sudah dibuka tetap berlaku sampai kedaluwarsa.

## Bukti

- Uji baru memakai anonymous class dengan trait poller untuk membuktikan
  `/cuti mesin rusak` membuat token beralasan dan tidak membuat laporan.
- `php artisan test --filter=CutiTest`: 13 test hijau, 65 assertion.
- Seluruh suite: 689 test hijau, 3.405 assertion.
- `view:cache` sukses.

## Berkas

- `app/Console/Commands/Traits/TelegramMessageHandlerTrait.php`
- `app/Services/CutiService.php` (invalidasi token)
- `tests/Feature/CutiTest.php`
- `docs/23-fitur-cuti.md`
- `docs/12-riwayat-insiden-33-46.md` (insiden #48 dan #49)
- `AGENTS.md`
- `tugas/erik.md`
