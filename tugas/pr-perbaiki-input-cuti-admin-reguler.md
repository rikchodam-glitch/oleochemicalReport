# Perbaiki halaman input cuti Admin Reguler

## Ringkasan

- Halaman kelola cuti sekarang **global** di `/cuti-kelola`, bukan hanya dari
  profil satu teknisi.
- Form input memiliki pilihan **nama teknisi** (nama/NIK/group) sehingga Admin
  Reguler bisa langsung mencatat cuti tanpa membuka profil teknisi.
- Menu **Kelola Cuti** muncul di sidebar untuk Admin Reguler dan Administrator.
- Tombol **Kelola Cuti** di profil teknisi tetap ada dan membuka halaman global
  dengan teknisi tersebut sudah terpilih.
- Daftar cuti menampilkan kolom teknisi; edit/hapus tetap tersedia.
- Akses Admin Reguler: dashboard, teknisi (read-only), dan kelola cuti.

## Perbaikan akses

Sebelumnya halaman hanya dapat dicapai lewat tautan di profil teknisi dan tidak
ada menu langsung. Sekarang route `cuti.kelola.index/store` berada di
`/cuti-kelola` dan menu sidebar terlihat untuk `bolehKelolaCuti()`.

## Berkas

- `app/Http/Controllers/CutiAdminController.php`
- `resources/views/cuti/kelola.blade.php`
- `resources/views/technicians/show.blade.php`
- `resources/views/layouts/app.blade.php`
- `routes/web.php`, `config/akses.php`
- `tests/Feature/CutiAdminTest.php`
- `docs/23-fitur-cuti.md`, `AGENTS.md`
- `tugas/erik.md`

## Uji

- `CutiAdminTest` + `CutiSayaTest`: 8 test hijau, 37 assertion.
- Seluruh suite: 704 test hijau, 3.458 assertion.
- `view:cache` sukses.
- Tidak ada migrasi baru.
