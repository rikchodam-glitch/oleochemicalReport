# Telegram: hapus /start dari menu command

## Ringkasan

- `/start` dihapus dari `TelegramService::MENU_COMMANDS`, sehingga tidak muncul
  lagi di menu command bot Telegram.
- Handler `/start` TIDAK dihapus; perintah ini tetap berfungsi bila diketik
  manual untuk pendaftaran teknisi baru.
- Deskripsi perintah artisan `telegram:set-commands` diperbarui agar tidak lagi
  menyebut daftar command yang sudah berubah.
- `TelegramMenuTest` sekarang memastikan menu berisi `laporan` dan `req`, serta
  TIDAK berisi `start` dan `cuti`.
- Menu bot live sudah disinkronkan tanpa `/start` lewat Telegram API.

## Berkas yang disentuh

- `app/Services/TelegramService.php`
- `app/Console/Commands/TelegramSetCommands.php`
- `tests/Feature/TelegramMenuTest.php`
- `docs/27-menu-perintah-bot-telegram.md`

## Bukti uji

- `php artisan test --filter=TelegramMenuTest`: **3 test hijau, 7 assertions**.
- Seluruh suite: **766 test hijau, 3.780 assertions**.

## Checklist

- [x] `/start` tidak lagi terdaftar di menu command.
- [x] Handler `/start` tetap ada untuk pendaftaran teknisi.
- [x] `laporan` dan `req` tetap ada di menu.
- [x] Tes menu hijau dan seluruh suite hijau.
- [x] Menu bot live sudah diperbarui tanpa `/start`.

## Catatan deploy

- Tidak ada migrasi baru.
- Jangan menjalankan `telegram:set-commands` dari kode PROD lama sebelum PR ini
  di-deploy, karena kode lama masih mendaftarkan `/start`.
- Setelah PR di-deploy, `telegram:set-commands` akan menghasilkan daftar menu
  yang sama dengan menu live saat ini (tanpa `/start`).
