# Menu perintah bot Telegram

## Ringkasan

- Mendaftarkan command bot ke menu native Telegram (`setMyCommands`) dan
  mengaktifkan menu button default (`setChatMenuButton`).
- Menu berisi `/start`, `/laporan`, `/info`, `/cuti`, `/reset`.
- Tombol **Sinkronkan Menu Bot** ditambahkan di halaman Bot Telegram.
- Perintah CLI: `php artisan telegram:set-commands`.
- Tanpa token bot, tidak ada request ke Telegram.

## Catatan

- `/stok` dan `/settings` belum dimasukkan karena belum ada handler.
- Menu native dibuka/ditutup oleh klien Telegram; setelah sinkronisasi tutup dan
  buka kembali chat bot karena menu di-cache.
- Tidak ada perubahan data laporan/cuti dan tidak ada migrasi.

## Berkas

- `app/Services/TelegramService.php`
- `app/Console/Commands/TelegramSetCommands.php`
- `app/Http/Controllers/BotController.php`
- `resources/views/bot/index.blade.php`
- `routes/web.php`
- `tests/Feature/TelegramMenuTest.php`
- `docs/27-menu-perintah-bot-telegram.md`, `AGENTS.md`
- `tugas/erik.md`

## Uji

- `TelegramMenuTest`: 3 test hijau, 7 assertion.
- Seluruh suite: 714 test hijau, 3.505 assertion.
