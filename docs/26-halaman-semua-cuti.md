# Halaman Semua Cuti Karyawan

Dokumen ini menjelaskan halaman read-only seluruh cuti untuk Administrator,
Supervisor, Admin General, dan Admin Reguler (21/10/2026).

## Akses

- Route `/cuti-semua`, nama `cuti.semua`.
- Boleh dibuka role `admin`, `supervisor`, `admin_general`, dan `admin_reguler`
  (`User::bolehLihatSemuaCuti()`).
- Admin Reguler memakai halaman ini untuk melihat seluruh cuti; input/edit
  tetap melalui **Kelola Cuti**.
- Teknisi biasa tidak mendapat halaman ini.
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

- `CutiSemuaTest`: 4 test hijau, 41 assertion.
- Seluruh suite: 704 test hijau, 3.466 assertion.
- `view:cache` sukses.
