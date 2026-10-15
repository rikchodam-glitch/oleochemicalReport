# PR: Staff Grub A-D + Reguler Staff boleh mengelola laporan grub-nya

Cabang: `tugas/erik/staff-grub-hak-tulis-laporan`

## Ringkasan

Diminta user 15/10/2026: setiap Shift/Grub punya **Staff Grub A-D**, dan akun
**Reguler Staff** serta **Staff Grub** boleh mengedit laporan yang terhubung
dengan grub yang dinaunginya (termasuk kolaborasi bila laporan itu INDUK).
Mereka tetap **tidak boleh mengubah aset/menu lain** dan **tidak boleh import**
apa pun, **KECUALI import PO Spare Part dan sync data CM**; **export tetap
boleh** (route-nya GET).

## Keputusan yang dikonfirmasi user

1. Cakupan: staf menaungi grub-nya — Staff Grub A -> laporan grub A; Reguler Staff -> laporan grup `reguler` + `reguler_staff`.
2. Aksi tulis: seluruh pengelolaan laporan INDUK (ubah, status, foto, kolaborator, alat tambahan, hapus), TANPA buat laporan baru.
3. Grup Staff Grub A-D tampil di halaman Laporan pada tampilan **Shift** sesuai grubnya.
4. Import yang tetap boleh: hanya `sparepart.impor.proses` dan `cm.sync-google-sheets`.

## Perubahan

- **Grup baru** `grub_a_staff`..`grub_d_staff` (`Technician::GROUPS`), plus
  `GRUP_STAFF`, `GRUP_STAFF_INDUK`, `grupTampilanShift()`, `grupYangDikelola()`.
  Staff grub BUKAN pekerja shift (tidak ikut pola rotasi), tetapi laporannya
  tampil di tampilan Shift.
- **Izin tulis sebagian** lewat `config('akses.tulis_grup')` + `tulis_kemampuan`
  (daftar nama route eksplisit, `reports.store` sengaja tidak termasuk).
  `HanyaBacaMiddleware` meloloskan route yang termasuk kemampuan grup akun.
- **Penegakan per-baris** (`User::bolehKelolaLaporan()`): group STAFF hanya boleh
  mengelola laporan yang pemiliknya berada di grup yang dinaungi. Ditegakkan di
  `ReportController` (update/update-status/destroy/add-photo/deletePhoto),
  `ReportCollaborationController`, dan `ReportMultiItemController`.
- **Tampilan Shift** menyertakan grup Staff Grub via `grupTampilanShift()`.
- **UI**: `mode-hanya-baca` kini sadar izin tulis sebagian (form laporan tetap
  tampil untuk staf, form lain disembunyikan; tombol Edit/Hapus laporan grub
  lain disembunyikan). Teks halaman Hak Akses menyebut Staff Grub.
- **Kolaborasi otomatis**: grup Staff Grub dikecualikan dari pembuatan kelompok
  kolaborasi otomatis (sebelumnya ikut karena bukan `reguler`/`reguler_staff`).

## Bukti uji

- `tests/Feature/StafGrubKelolaLaporanTest.php` (11 test, 45 assertions).
- `php artisan test` **646 test hijau (3158 assertions)**.
- `php -l` seluruh berkas PHP yang diubah bersih; `php artisan view:cache` sukses.
- Uji mutasi: menetralkan `User::bolehKelolaLaporan()` (selalu `true`) membuat
  uji staf GAGAL, lalu dikembalikan.

## Berkas yang disentuh

- `app/Models/Technician.php`
- `app/Models/User.php`
- `app/Http/Middleware/HanyaBacaMiddleware.php`
- `app/Http/Controllers/ReportController.php`
- `app/Http/Controllers/ReportCollaborationController.php`
- `app/Http/Controllers/ReportMultiItemController.php`
- `app/Services/CollaborationService.php`
- `config/akses.php`
- `resources/views/layouts/partials/mode-hanya-baca.blade.php`
- `resources/views/akses/index.blade.php`
- `tests/Feature/StafGrubKelolaLaporanTest.php`
- `tugas/erik.md`

## Checklist

- [x] Uji otomatis hijau
- [x] `php artisan view:cache` sukses
- [x] Tidak menambah migrasi / tidak mengubah data
- [x] Export tetap boleh (GET tidak dibatasi)
- [x] Import diblok kecuali PO Spare Part + sync CM
