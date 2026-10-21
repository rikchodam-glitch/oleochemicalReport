# Fitur Cuti Teknisi

Keputusan 21/10/2026: cuti **tidak lagi diisi teknisi**. Admin Reguler mencatat
cuti dari halaman teknisi; teknisi hanya melihat riwayat cutinya.

## Peran

- **Admin Reguler** (`users.role = admin_reguler`) adalah role baru, bukan
  administrator penuh. Aksesnya hanya dashboard, halaman teknisi (read-only),
  dan kelola cuti.
- Administrator tetap bisa mengelola cuti.
- Teknisi dengan akun login dan `users.technician_id` hanya bisa membuka
  **Cuti Saya** (read-only).
- Admin Reguler mengelola cuti **semua teknisi**, termasuk shift.

## Alur admin

1. Buka menu **Kelola Cuti** di sidebar (atau tombol Kelola Cuti dari profil
   teknisi).
2. Pilih teknisi pada form (nama/NIK/group), lalu isi tanggal mulai/selesai,
   jenis, dan alasan.
3. Simpan; cuti langsung berlaku, tanpa status/approval, dan langsung
   memengaruhi target man hour.
4. Admin Reguler dapat mengedit/menghapus cuti dari daftar pada halaman yang
   sama. Halaman ini global, jadi tidak perlu membuka profil teknisi satu per
   satu.

## Alur teknisi

- Login ke aplikasi, buka menu **Cuti Saya**.
- Hanya melihat periode, jenis, alasan, dan waktu dicatat.
- Tidak ada tombol edit/hapus dan tidak ada form pengajuan.

## Man hour

`ManHourService` membaca **seluruh** cuti yang tersimpan:

- Cuti pada hari kerja: target hari itu 0, status harian `cuti`, dan masuk
  `hari_cuti`.
- Cuti pada hari OFF/libur nasional: tidak mengubah target.
- Tidak ada status cuti; semua baris cuti efektif.
- Jika ada laporan pada hari cuti, menit normal dipindahkan ke lembur dan
  ditandai anomali.

## Telegram

- Perintah `/cuti` dan form token publik **dihapus**.
- Bot tidak lagi membuat tautan form cuti; jika `/cuti` dikirim, bot hanya
  membalas agar teknisi menghubungi Admin Reguler (tidak masuk wizard laporan).
- Pengajuan cuti sepenuhnya melalui Admin Reguler.
- Tabel `cuti_tokens` lama tidak dipakai lagi oleh kode; dibiarkan agar
  riwayat migrasi tidak destruktif.
- Migrasi `2026_10_21_000001_drop_status_from_cuti_teknisis` membuang kolom
  `status` (dan index-nya) karena cuti tidak lagi memakai status.

## Akses dan route

- `cuti.saya` — teknisi login, hanya lihat.
- `cuti.kelola.index/store/edit/update/destroy` — Admin Reguler/Administrator.
- Route kelola cuti berada di luar middleware hanya-baca agar Admin Reguler
  dapat menulis, tetapi dijaga `User::bolehKelolaCuti()`.
- Modul `cuti` ditambahkan ke `config/akses.php` untuk pemetaan route.
- Role `admin_reguler` mendapat daftar akses khusus di `User::daftarAkses()`.

## Route

- `/cuti-kelola` — daftar + form input global (pilih teknisi).
- `/cuti-kelola/{cuti}/edit` — edit cuti.
- `/cuti-saya` — teknisi melihat cuti sendiri.
- `/cuti-semua` — admin/supervisor/admin_general melihat seluruh cuti.

## Berkas

- `app/Models/User.php`, `app/Http/Controllers/UserController.php`,
  `resources/views/users/*`
- `app/Http/Controllers/CutiAdminController.php`
- `app/Http/Controllers/CutiSayaController.php`
- `app/Services/CutiService.php`, `app/Models/CutiTeknisi.php`
- `resources/views/cuti/kelola.blade.php`,
  `resources/views/cuti/kelola-edit.blade.php`,
  `resources/views/cuti/saya-index.blade.php`
- `resources/views/technicians/show.blade.php`
- `routes/web.php`, `config/akses.php`
- `tests/Feature/CutiAdminTest.php`, `tests/Feature/CutiSayaTest.php`

## Uji

- `CutiAdminTest` 6 test, `CutiSayaTest` 2 test: total 8 test hijau.
- Seluruh suite: 704 test hijau, 3.458 assertion.
- `php artisan view:cache` sukses.
