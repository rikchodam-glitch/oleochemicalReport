# Fitur cuti Telegram dan dampak man hour

## Ringkasan

- Teknisi mengirim `/cuti` atau `/cuti <alasan>` ke bot Telegram.
- Bot membuat tautan form **sekali pakai** (30 menit) yang terikat ke teknisi.
- Form terbuka tanpa login; nama/NIK/group diambil dari token. Alasan setelah
  `/cuti` otomatis mengisi kolom alasan dan tetap bisa diedit.
- Pengajuan berstatus `pending`; admin menyetujui/menolak di menu **Cuti
  Teknisi**.
- Cuti disetujui pada hari kerja membuat target man hour hari itu **0**,
  menambah `hari_cuti`, dan mengecualikan hari itu dari `hari_kerja`.
- Cuti pada hari OFF/libur tidak mengubah target. Cuti `pending` belum
  mengurangi target.
- Jika ada laporan pada hari cuti, jamnya dipindahkan ke lembur dan ditandai
  anomali, bukan man hour normal.

## Keamanan

- Hanya hash SHA-256 token yang disimpan.
- Token diikat ke session browser saat pertama dibuka; perangkat lain ditolak.
- Form publik tidak memakai login panel dan tidak memberi akses dashboard.
- Route admin dijaga khusus administrator.
- Token lama yang belum dipakai dibatalkan saat meminta tautan baru.
- Tidak ada kredensial baru; tautan memakai `APP_URL`.

## Data & migrasi

- Tabel baru: `cuti_teknisis`, `cuti_tokens`.
- Migrasi: `2026_10_20_000001_create_cuti_tables.php`.
- Deploy PROD akan menjalankan migrasi ini; backup DB tetap dilakukan
  `Deploy-Laporan.bat`.

## Batasan

- Cuti penuh hari; setengah hari, kuota tahunan, lampiran sakit, kalender tim,
  dan pembatalan oleh teknisi belum termasuk.
- Persetujuan hanya administrator.

## Berkas

- `app/Models/CutiTeknisi.php`, `app/Models/CutiToken.php`
- `app/Services/CutiService.php`
- `app/Http/Controllers/CutiController.php`
- `app/Http/Controllers/TelegramWebhookController.php`
- `app/Services/Shift/ManHourService.php`
- `resources/views/cuti/*`, `resources/views/technicians/show.blade.php`
- `resources/views/layouts/partials/sidebar-pengaturan.blade.php`
- `routes/web.php`, `config/akses.php`
- `tests/Feature/CutiTest.php`
- `docs/23-fitur-cuti.md`, `AGENTS.md`

## Uji

- `php artisan test --filter=CutiTest`: **10 test hijau, 56 assertion**.
- `php artisan test` (seluruh suite): **686 test hijau, 3.396 assertion**.
- `php artisan view:cache`: sukses.
- Form publik dan halaman admin sudah dicek HTTP 200 di DEV.
