# Hapus /cuti dari menu bot

## Ringkasan

- Command `/cuti` tidak lagi ditampilkan pada menu native Telegram.
- Handler `/cuti` tetap ada bila teknisi mengetiknya manual (mengarahkan ke
  Admin Reguler).
- Menu sekarang: `/start`, `/laporan`, `/info`, `/reset`.

## Tindakan setelah deploy

Klik **Sinkronkan Menu Bot** di halaman Bot Telegram, atau jalankan:

```powershell
& "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan telegram:set-commands
```

lalu tutup dan buka kembali chat bot.

## Berkas

- `app/Services/TelegramService.php`
- `tests/Feature/TelegramMenuTest.php`
- `docs/27-menu-perintah-bot-telegram.md`
- `tugas/erik.md`

## Uji

- `TelegramMenuTest`: 3 test hijau, 7 assertion.
