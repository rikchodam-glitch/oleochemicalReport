# Halaman semua cuti karyawan

## Ringkasan

- Halaman baru `/cuti-semua` untuk melihat seluruh cuti teknisi.
- Dapat diakses Administrator, Supervisor, dan role baru **Admin General**
  (`admin_general`).
- Halaman read-only: tanpa tambah/edit/hapus; pencatatan tetap Admin Reguler.
- Filter: nama/NIK, Group, Bagian, dan rentang tanggal (irisan rentang cuti).
- Ringkasan: total catatan cuti dan jumlah teknisi yang punya cuti.
- Admin General hanya mendapat akses dashboard + halaman semua cuti; bukan
  administrator penuh dan tidak bisa menulis.
- Role Admin General bisa dipilih di form tambah/edit user.

## Catatan

- Halaman tidak menampilkan status cuti.
- Tidak ada migrasi baru.
- Route berada di luar middleware hanya-baca; controller memeriksa
  `bolehLihatSemuaCuti()`.
- Menu **Semua Cuti** muncul di sidebar untuk role yang berhak.

## Berkas

- `app/Http/Controllers/CutiSemuaController.php` (baru)
- `resources/views/cuti/semua.blade.php` (baru)
- `app/Models/User.php` (`ROLE_ADMIN_GENERAL`, `bolehLihatSemuaCuti()`)
- `app/Http/Controllers/UserController.php`, `resources/views/users/*`
- `resources/views/layouts/app.blade.php`, `routes/web.php`, `config/akses.php`
- `tests/Feature/CutiSemuaTest.php` (baru)
- `docs/26-halaman-semua-cuti.md`, `AGENTS.md`
- `tugas/erik.md`

## Uji

- `CutiSemuaTest`: 4 test hijau, 36 assertion.
- Seluruh suite: 703 test hijau, 3.454 assertion.
- `view:cache` sukses.
