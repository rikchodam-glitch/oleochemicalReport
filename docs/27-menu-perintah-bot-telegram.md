# Menu Perintah Bot Telegram

Dokumen ini menjelaskan menu command native Telegram (tombol di samping
emoji/attachment) untuk bot laporan (22/10/2026).

## Jenis menu

Telegram menyediakan **Bot Command Menu** melalui `setMyCommands`; tombol menu
default diatur dengan `setChatMenuButton` (`type: default`). Menu dibuka/ditutup
oleh klien Telegram secara native, bukan popup buatan aplikasi.

## Command yang didaftarkan

| Command | Deskripsi |
|---|---|
| `/start` | Mulai bot / daftar teknisi |
| `/laporan` | Buat laporan lewat form web |
| `/info` | Cari informasi perangkat |
| `/cuti` | Info cuti (diinput Admin Reguler) |
| `/reset` | Batalkan sesi laporan |

Hanya command yang sudah memiliki handler yang didaftarkan. `/stok` dan
`/settings` tidak dimasukkan karena belum ada handler-nya; tambahkan ke
`TelegramService::MENU_COMMANDS` setelah handler tersedia.

## Cara sinkronisasi

- Admin: halaman **Bot Telegram** → tombol **Sinkronkan Menu Bot**.
- CLI di PROD:
  `php artisan telegram:set-commands`
- Setelah sinkronisasi, tutup dan buka kembali chat bot (menu di-cache klien).

## Teknis

- `TelegramService::setBotCommands()` memanggil `setMyCommands` dengan scope
  default.
- `TelegramService::setDefaultMenuButton()` memanggil `setChatMenuButton`.
- `TelegramSetCommands` menyediakan perintah artisan.
- `BotController::sinkronkanMenu()` menyediakan tombol admin.
- Token diambil dari `services.telegram.bot_token`; tanpa token tidak ada
  request ke Telegram.

## Berkas

- `app/Services/TelegramService.php`
- `app/Console/Commands/TelegramSetCommands.php`
- `app/Http/Controllers/BotController.php`
- `resources/views/bot/index.blade.php`
- `routes/web.php`
- `tests/Feature/TelegramMenuTest.php`

## Uji

- `TelegramMenuTest`: 3 test hijau, 7 assertion.
- Seluruh suite: 714 test hijau, 3.505 assertion.
