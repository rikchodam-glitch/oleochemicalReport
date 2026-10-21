# Cuti oleh Admin Reguler dan form laporan teknisi

## Ringkasan

- Cuti tidak lagi diisi teknisi. Form token Telegram dan command `/cuti` dihapus.
  Jika `/cuti` dikirim, bot hanya membalas agar menghubungi Admin Reguler dan
  tidak memasukkannya ke wizard laporan.
- Role baru **Admin Reguler** (`admin_reguler`): hanya dashboard, halaman
  teknisi (read-only), dan kelola cuti; bukan administrator penuh.
- Admin Reguler mencatat cuti dari tombol **Kelola Cuti** di detail teknisi,
  untuk semua teknisi. Cuti langsung disetujui dan memengaruhi man hour.
- Teknisi hanya melihat cuti di **Cuti Saya** (tanpa edit/hapus).
- Form laporan web MVP untuk teknisi login: tanggal, tipe, klasifikasi/jenis,
  area/equipment, deskripsi, durasi, root cause/temuan, foto, kolaborator, dan
  status Done/Continue.

## Cuti

- `CutiAdminController`: index/store/edit/update/destroy dari halaman teknisi.
- `CutiSayaController`: read-only untuk teknisi.
- `CutiService`: `catatOlehAdmin()` dan `perbarui()`; validasi rentang/bentrok,
  status langsung `disetujui`.
- Admin Reguler dapat mengedit/menghapus cuti; target man hour mengikuti.
- Tombol edit/hapus teknisi dan broadcast Telegram disembunyikan untuk akun
  read-only.

## Form laporan

- `ReportFormController` + `reports/form-teknisi.blade.php`.
- Akses hanya akun login dengan `users.technician_id` dan teknisi aktif.
- Laporan dibuat atas nama akun; salinan kolaborator dibuat lewat
  `ReportCopyService`.
- Foto disimpan ke disk/folder yang sama dengan foto Telegram.
- Broadcast kolaborasi dipanggil bila class job sudah tersedia (PR broadcast).

## Batasan

- Cuti penuh hari; kuota, setengah hari, lampiran sakit belum termasuk.
- Form laporan adalah MVP: belum ada AI classification, wizard
  multi-equipment, atau auto-group kolaborasi.
- Tabel `cuti_tokens` lama tidak dipakai lagi; tidak di-drop agar migrasi
  produksi tidak destruktif.

## Berkas

- `app/Models/User.php`, `app/Http/Controllers/UserController.php`,
  `resources/views/users/*`
- `app/Http/Controllers/CutiAdminController.php` (baru)
- `app/Http/Controllers/CutiSayaController.php`
- `app/Services/CutiService.php`, `app/Models/CutiTeknisi.php`
- `app/Http/Controllers/ReportFormController.php` (baru)
- `resources/views/cuti/*`, `resources/views/reports/form-teknisi.blade.php`
- `resources/views/technicians/show.blade.php`,
  `resources/views/layouts/app.blade.php`
- `routes/web.php`, `config/akses.php`
- `tests/Feature/CutiAdminTest.php`, `tests/Feature/CutiSayaTest.php`,
  `tests/Feature/ReportFormTest.php`
- `docs/23-fitur-cuti.md`, `docs/25-form-laporan-teknisi.md`, `AGENTS.md`

## Uji

- Cuti + form: 13 test hijau, 52 assertion.
- Seluruh suite: 699 test hijau, 3.419 assertion.
- `view:cache` sukses.
- Tidak ada migrasi baru.
