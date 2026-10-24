# Papan Klaim: erik

Diperbarui: 2026-10-24 10:17 WIB

- Cabang aktif: `tugas/erik/request-sparepart-telegram`
- Mulai: 2026-10-24 10:17
- Status: SELESAI DIUJI, SIAP PR
- Modul/berkas yang disentuh:
  - Fitur request spare part lewat bot Telegram `/req` dan tab Request Spare Part di halaman Sparepart
  - `database/migrations/2026_10_24_000001_create_sp_requests_table.php`
  - `app/Models/SpRequest.php`
  - `app/Services/Sparepart/SparepartRequestService.php`
  - `app/Services/Sparepart/SparepartRequestNotifier.php`
  - `app/Services/Telegram/SparepartRequestWizardService.php`
  - `app/Http/Controllers/SparepartRequestController.php`
  - `resources/views/sparepart/request/**`, `resources/views/sparepart/partials/_tabs.blade.php`
  - `config/akses.php`, `app/Models/User.php`, `routes/web.php`
  - `app/Console/Commands/PollTelegramUpdates.php`, `app/Console/Commands/Traits/TelegramMessageHandlerTrait.php`
  - `app/Http/Controllers/TelegramWebhookController.php`, `app/Services/TelegramService.php`, `app/Services/Telegram/PhotoStorageService.php`
  - `tests/Feature/SparepartRequestTelegramTest.php`, `tests/Feature/SparepartRequestWebTest.php`
  - `docs/15-modul-spare-part.md`, `docs/27-menu-perintah-bot-telegram.md`, `docs/30-request-sparepart.md`, `AGENTS.md`
- Catatan: 18 test request hijau; seluruh suite 756 test hijau, 3.744 assertions; `view:cache` sukses. Migrasi DEV sudah dijalankan. Setelah deploy, centang modul `sparepart` dan `sparepart_request` untuk CM/PM - Reguler Staff dan sinkronkan menu bot.

## Riwayat
- tugas/erik/catat-aturan-deploy-setelah-merge (mulai 2026-10-24 08:39) -> dilanjutkan 2026-10-24 10:17
