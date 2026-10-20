# Cuti otomatis dan halaman Cuti Saya

## Ringkasan

- Menghapus alur pengajuan/persetujuan admin. Cuti yang dikirim dari form
  Telegram **langsung disetujui** dan langsung memengaruhi target man hour.
- Menambahkan halaman **Cuti Saya** untuk akun login yang terhubung ke teknisi
  (`users.technician_id`).
- Pemilik akun dapat **mengedit** dan **menghapus** cuti miliknya sendiri.
- Edit/hapus langsung mengubah target man hour pada tanggal terkait.
- Halaman Cuti Saya tidak memakai middleware hanya-baca, tetapi kepemilikan
  data diperiksa di controller; akun tidak bisa menyentuh cuti teknisi lain.
- Menu Cuti Saya hanya tampil untuk akun yang punya `technician_id`.
- Halaman admin Cuti Teknisi dan route approval dihapus.

## Perubahan data

Tidak ada migrasi baru. Cuti baru memakai status `disetujui` dan
`disetujui_at` otomatis. Status lama `pending`/`ditolak` tetap dikenali untuk
kompatibilitas, tetapi alur baru tidak membuat status pending.

## Batasan

- Cuti penuh hari; setengah hari dan kuota belum termasuk.
- Penghapusan permanen (hard delete), belum soft delete/audit khusus.
- Pengajuan baru tetap lewat `/cuti` di Telegram; halaman Cuti Saya untuk
  mengelola cuti yang sudah ada.

## Berkas

- `app/Services/CutiService.php`
- `app/Http/Controllers/CutiController.php`
- `app/Http/Controllers/CutiSayaController.php` (baru)
- `routes/web.php`, `config/akses.php`
- `resources/views/cuti/form.blade.php`, `selesai.blade.php`
- `resources/views/cuti/saya-index.blade.php`, `saya-edit.blade.php` (baru)
- `resources/views/cuti/admin-index.blade.php` (dihapus)
- `resources/views/layouts/app.blade.php`,
  `resources/views/layouts/partials/sidebar-pengaturan.blade.php`
- `tests/Feature/CutiTest.php`, `tests/Feature/CutiSayaTest.php` (baru)
- `docs/23-fitur-cuti.md`, `AGENTS.md`
- `tugas/erik.md`

## Uji

- `php artisan test --filter="CutiTest|CutiSayaTest"`: 17 test hijau,
  80 assertion.
- Seluruh suite: 693 test hijau, 3.420 assertion.
- `view:cache` sukses.
