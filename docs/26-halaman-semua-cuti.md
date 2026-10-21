# Halaman Semua Cuti Karyawan

Dokumen ini menjelaskan halaman read-only seluruh cuti untuk Administrator,
Supervisor, dan Admin General (21/10/2026).

## Akses

- Route `/cuti-semua`, nama `cuti.semua`.
- Boleh dibuka role `admin`, `supervisor`, dan `admin_general`
  (`User::bolehLihatSemuaCuti()`).
- Teknisi biasa dan Admin Reguler tidak mendapat halaman ini; Admin Reguler
  tetap mencatat cuti dari halaman teknisi.
- Halaman hanya membaca; tidak ada tombol tambah/edit/hapus.

## Role Admin General

- `users.role = admin_general`.
- Daftar akses khusus: dashboard dan `cuti_semua`.
- Bukan administrator penuh, tidak boleh menulis data, dan tidak mendapat
  modul lain.
- Dapat dipilih saat administrator membuat/mengedit user.

## Isi halaman

- Kartu total catatan cuti dan jumlah teknisi yang punya cuti.
- Filter: cari nama/NIK, Group, Bagian, dan rentang tanggal.
- Tabel: teknisi (nama, NIK, Group, Bagian), periode + jumlah hari, jenis,
  alasan, waktu dicatat, dan sumber (Admin Reguler/Web/Telegram lama).
- Pagination 25 baris.
- Tidak ada kolom status karena cuti tidak memakai status.

## Filter tanggal

Filter memakai irisan rentang:
`tanggal_mulai <= sampai` dan `tanggal_selesai >= dari`. Jadi cuti yang
melewati batas filter tetap muncul.

## Berkas

- `app/Http/Controllers/CutiSemuaController.php`
- `resources/views/cuti/semua.blade.php`
- `app/Models/User.php` (`ROLE_ADMIN_GENERAL`, `bolehLihatSemuaCuti()`)
- `app/Http/Controllers/UserController.php`, `resources/views/users/*`
- `resources/views/layouts/app.blade.php`, `routes/web.php`, `config/akses.php`
- `tests/Feature/CutiSemuaTest.php`

## Uji

- `CutiSemuaTest`: 4 test hijau, 36 assertion.
- Seluruh suite: 703 test hijau, 3.454 assertion.
- `view:cache` sukses.
