# Papan Klaim: erik

Diperbarui: 2026-10-20 09:20 WIB

- Cabang aktif: `tugas/erik/fitur-cuti-telegram-dan-man-hour`
- Mulai: 2026-10-20 09:20
- Status: SEDANG DIKERJAKAN
- Modul/berkas yang disentuh:
  - `database/migrations/2026_10_20_000001_create_cuti_tables.php`
  - `app/Models/CutiTeknisi.php`, `app/Models/CutiToken.php`
  - `app/Services/CutiService.php`
  - `app/Http/Controllers/CutiController.php`
  - `app/Http/Controllers/TelegramWebhookController.php`
  - `app/Services/Shift/ManHourService.php`
  - `resources/views/cuti/*`, `resources/views/technicians/show.blade.php`
  - `resources/views/layouts/partials/sidebar-pengaturan.blade.php`
  - `routes/web.php`, `tests/Feature/CutiTest.php`
  - `docs/23-fitur-cuti.md`, `AGENTS.md`
- Catatan: `/cuti <alasan>` dari Telegram menghasilkan link token sekali pakai; cuti disetujui mengurangi target man hour.

## Riwayat
- tugas/erik/catat-insiden-deploy-dari-cabang-tugas (mulai 2026-10-20 08:50) -> dilanjutkan 2026-10-20 09:20
