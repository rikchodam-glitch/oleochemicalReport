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
| `/req` | Request spare part |
| `/info` | Cari informasi perangkat |
| `/reset` | Batalkan sesi laporan atau request |

`/cuti` tidak ditampilkan di menu; handler info cuti tetap ada bila command
dikirim manual. `/stok` dan `/settings` tidak dimasukkan karena belum ada
handler-nya; tambahkan ke `TelegramService::MENU_COMMANDS` setelah handler
tersedia.

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

## Request spare part

- `/req` memakai `SparepartRequestWizardService` dengan state cache
  `sparepart_request_wizard:<chat_id>` (TTL 1 jam, basi setelah 30 menit).
- Tombol inline memakai callback prefix `spr:` dan hanya dilayani jalur
  polling. Jalur webhook tetap bisa memakai teks `KEMBALI`/`KIRIM`/`BATAL`.
- Setiap langkah setelah keterangan punya tombol **Kembali** dan **Batalkan**;
  konfirmasi punya **Kembali**, **Kirim**, dan **Batalkan**. Kembali tidak
  menghapus isian sehingga teknisi bisa mengedit nilai sebelumnya.
- `/reset` membatalkan sesi request (dan sesi laporan bila ada).

## Berkas

- `app/Services/TelegramService.php`
- `app/Console/Commands/TelegramSetCommands.php`
- `app/Http/Controllers/BotController.php`
- `resources/views/bot/index.blade.php`
- `routes/web.php`
- `tests/Feature/TelegramMenuTest.php`
- `app/Services/Telegram/SparepartRequestWizardService.php`
- `docs/30-request-sparepart.md`

## Uji

- `TelegramMenuTest`: 3 test hijau, 7 assertion.
- Seluruh suite: 714 test hijau, 3.505 assertion.
