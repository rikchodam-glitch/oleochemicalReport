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
6. Teknisi memilih tanggal, jenis cuti, dan mengirim. Cuti **langsung
   disetujui** (tanpa persetujuan admin) dan target man hour hari kerja
   terkait menjadi 0.
7. Teknisi login ke aplikasi, membuka menu **Cuti Saya**, lalu bisa
   **mengedit atau menghapus** cuti miliknya sendiri.
8. Setiap perubahan/hapus langsung mengubah target man hour pada tanggal
   terkait.

## Keamanan tautan

- Token berlaku **30 menit**, sekali pakai, dan hanya hash yang disimpan.
- Saat tautan dibuka, session browser menyimpan `cuti_token_id` +
  `cuti_technician_id`. Token **boleh dibuka ulang dari browser/perangkat lain
  selama belum dipakai/kedaluwarsa**; submission memakai session dari GET
  terakhir. Ini penting karena pratinjau tautan Telegram membuka URL lebih dulu
  tanpa cookie.
- Form publik tidak memakai `Auth::login()`; teknisi tidak mendapat akses ke
  dashboard/admin.
- Route form memakai throttle 30 permintaan/menit. Halaman **Cuti Saya**
  hanya bisa dibuka akun yang punya `users.technician_id`; kepemilikan cuti
  diperiksa di `CutiSayaController` (tidak bisa mengubah cuti teknisi lain).
- Permintaan tautan baru hanya membatalkan tautan lama yang **belum pernah
  dibuka**. Tautan yang sudah dibuka tetap berlaku sampai kedaluwarsa agar form
  yang sedang diisi tidak berubah menjadi "tautan tidak berlaku".
- Alasan dibatasi 1.000 karakter dan dinormalisasi spasinya.

## Data

- `cuti_teknisis`: teknisi, tanggal mulai/selesai, jenis, alasan, status,
  diajukan_via, penyetuju, waktu persetujuan, catatan approver.
- `cuti_tokens`: teknisi, token_hash, telegram_id, alasan, expires_at,
  opened_at, used_at.
- Jenis: `tahunan`, `sakit`, `izin`, `dinas`, `lainnya`.
- Status utama sekarang `disetujui` (otomatis saat disimpan). Nilai
  `pending`/`ditolak`/`dibatalkan` dipertahankan untuk kompatibilitas data
  lama; man hour hanya membaca `disetujui`.

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
- Pratinjau tautan dimatikan (`disable_web_page_preview`) agar Telegram tidak
  membuka form lebih dulu dan menandai `opened_at`.
- Tidak ada notifikasi persetujuan karena cuti langsung berlaku.

## Halaman Cuti Saya

- Hanya untuk akun login yang terhubung ke teknisi (`users.technician_id`).
- Menampilkan seluruh cuti milik teknisi tersebut, terbaru di atas.
- **Edit**: tanggal mulai/selesai, jenis, dan alasan. Validasi bentrok tetap
  berlaku; tanggal lampau boleh dikoreksi.
- **Hapus**: hard delete setelah konfirmasi; target man hour kembali seperti
  sebelum cuti.
- Akun tanpa `technician_id` mendapat 403. Akun tidak bisa mengedit/menghapus
  cuti teknisi lain.

## Batasan versi ini

- Cuti penuh hari. Setengah hari, kuota cuti tahunan, lampiran surat sakit,
  kalender tim, dan pembatalan oleh teknisi belum termasuk.
- Tidak ada persetujuan admin/supervisor/HR; cuti langsung berlaku.
- Penghapusan bersifat permanen (belum soft delete/audit trail khusus).
- Tautan memakai `APP_URL`; pastikan domain publik PROD benar agar tombol di
  Telegram dapat dibuka.

## Berkas utama

- `database/migrations/2026_10_20_000001_create_cuti_tables.php`
- `app/Models/CutiTeknisi.php`, `app/Models/CutiToken.php`
- `app/Services/CutiService.php`
- `app/Http/Controllers/CutiController.php`
- `app/Http/Controllers/CutiSayaController.php`
- `app/Http/Controllers/TelegramWebhookController.php`
- `app/Services/Shift/ManHourService.php`
- `resources/views/cuti/*`, `resources/views/technicians/show.blade.php`
- `routes/web.php`, `config/akses.php`
- `tests/Feature/CutiTest.php`, `tests/Feature/CutiSayaTest.php`

## Uji

`php artisan test --filter="CutiTest|CutiSayaTest"`: 17 test hijau,
80 assertion. Seluruh suite: 693 test hijau, 3.420 assertion.
