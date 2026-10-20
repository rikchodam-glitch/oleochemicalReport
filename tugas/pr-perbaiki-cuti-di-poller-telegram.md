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

## Bukti

- Uji baru memakai anonymous class dengan trait poller untuk membuktikan
  `/cuti mesin rusak` membuat token beralasan dan tidak membuat laporan.
- `php artisan test --filter=CutiTest`: 11 test hijau, 60 assertion.
- Seluruh suite: 687 test hijau, 3.400 assertion.
- `view:cache` sukses.

## Berkas

- `app/Console/Commands/Traits/TelegramMessageHandlerTrait.php`
- `tests/Feature/CutiTest.php`
- `docs/23-fitur-cuti.md`
- `docs/12-riwayat-insiden-33-46.md` (insiden #48)
- `AGENTS.md`
- `tugas/erik.md`
