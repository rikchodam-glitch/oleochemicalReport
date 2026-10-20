# Fitur Cuti Teknisi

Dokumen ini menjelaskan fitur cuti yang terhubung ke bot Telegram dan man hour
(20/10/2026).

## Alur

1. Teknisi mengirim `/cuti` atau `/cuti <alasan>` ke bot.
2. Bot memvalidasi teknisi aktif (`technicians.telegram_id` + `status=active`).
3. Bot membuat token acak 64 karakter, menyimpan **hash SHA-256** di
   `cuti_tokens`, lalu membalas tautan form.
4. Teknisi membuka `/cuti/form/<token>` tanpa login panel. Identitas diambil
   dari token; form menampilkan nama, NIK, dan group teknisi.
5. Alasan yang ditulis setelah `/cuti` disimpan pada token dan otomatis mengisi
   kolom alasan di form (tetap bisa diedit).
6. Teknisi memilih tanggal, jenis cuti, dan mengirim. Pengajuan berstatus
   `pending`.
7. Admin membuka **Cuti Teknisi** di sidebar, menyetujui/menolak dengan catatan.
8. Setelah disetujui, target man hour teknisi pada hari kerja tersebut menjadi 0.

## Keamanan tautan

- Token berlaku **30 menit**, sekali pakai, dan hanya hash yang disimpan.
- Saat tautan pertama dibuka, token diikat ke session browser
  (`cuti_token_id` + `cuti_technician_id`). Browser/perangkat lain yang membuka
  tautan yang sama ditolak.
- Form publik tidak memakai `Auth::login()`; teknisi tidak mendapat akses ke
  dashboard/admin.
- Route form memakai throttle 30 permintaan/menit. Route admin dijaga
  `pastikanAdmin()` (hanya akun yang boleh mengelola user).
- Token lama yang belum dipakai otomatis dibatalkan saat teknisi meminta tautan
  baru.
- Alasan dibatasi 1.000 karakter dan dinormalisasi spasinya.

## Data

- `cuti_teknisis`: teknisi, tanggal mulai/selesai, jenis, alasan, status,
  diajukan_via, penyetuju, waktu persetujuan, catatan approver.
- `cuti_tokens`: teknisi, token_hash, telegram_id, alasan, expires_at,
  opened_at, used_at.
- Jenis: `tahunan`, `sakit`, `izin`, `dinas`, `lainnya`.
- Status: `pending`, `disetujui`, `ditolak`, `dibatalkan` (pembatalan belum
  memiliki UI; disiapkan untuk pengembangan berikutnya).

## Dampak man hour

`ManHourService` membaca cuti **disetujui**:

- Cuti pada hari kerja: `target_menit = 0`, status hari `cuti`, dan hari itu
  tidak dihitung sebagai hari kerja; ringkasan menambah `hari_cuti`.
- Cuti pada hari OFF/libur nasional: tidak mengubah target (tetap libur) dan
  tidak menambah `hari_cuti`.
- Cuti `pending`/`ditolak` tidak mengurangi target.
- Jika ada laporan pada hari cuti, menit normal dipindahkan ke lembur dan hari
  itu ditandai anomali ("laporan pada hari cuti dihitung lembur").
- `targetPerTeknisi()` mengurangi target cuti; `menitPerTeknisi()` tetap
  menghitung realisasi laporan.

## Bot

- **PENTING:** bot PROD memakai `php artisan telegram:poll` ->
  `TelegramMessageHandlerTrait`, bukan `TelegramWebhookController`. Karena itu
  penanganan `/cuti` WAJIB ada di trait tersebut. Webhook controller juga
  disiapkan untuk deployment yang memakai webhook.
- Perintah dicek SEBELUM wizard/`handleReport()`, sehingga `/cuti ...` tidak
  pernah masuk ke klasifikasi pekerjaan atau dibuat sebagai laporan.
- `/cuti` tanpa alasan tetap membuat tautan; alasan diisi di form.
- `/cuti mesin rusak` menyimpan `mesin rusak` pada token dan form.
- Ada pembatas 10 detik agar teknisi tidak membanjiri bot dengan tautan.
- Notifikasi persetujuan/penolakan dikirim ke Telegram teknisi bila
  `telegram_id` tersedia.

## Batasan versi ini

- Cuti penuh hari. Setengah hari, kuota cuti tahunan, lampiran surat sakit,
  kalender tim, dan pembatalan oleh teknisi belum termasuk.
- Persetujuan hanya administrator; belum ada alur supervisor/HR terpisah.
- Tautan memakai `APP_URL`; pastikan domain publik PROD benar agar tombol di
  Telegram dapat dibuka.

## Berkas utama

- `database/migrations/2026_10_20_000001_create_cuti_tables.php`
- `app/Models/CutiTeknisi.php`, `app/Models/CutiToken.php`
- `app/Services/CutiService.php`
- `app/Http/Controllers/CutiController.php`
- `app/Http/Controllers/TelegramWebhookController.php`
- `app/Services/Shift/ManHourService.php`
- `resources/views/cuti/*`, `resources/views/technicians/show.blade.php`
- `routes/web.php`, `config/akses.php`
- `tests/Feature/CutiTest.php`

## Uji

`php artisan test --filter=CutiTest`: 11 test hijau, 60 assertion
(termasuk jalur poller Telegram). Seluruh suite: 687 test hijau, 3.400
assertion.
