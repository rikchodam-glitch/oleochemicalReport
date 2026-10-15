# PR: Popup kalender teknisi tidak terbuka sendiri

Cabang: `tugas/erik/popup-kalender-tidak-terbuka-sendiri`

## Ringkasan

Laporan user 15/10/2026: popup kalender teknisi terbuka sendiri saat menginput
tanggal hari libur dan saat memilih halaman lanjut (paginasi) di halaman Teknisi.

## Akar masalah (dibuktikan)

Popup dibuka otomatis setiap kali URL memuat parameter `jadwal_bulan`
(`resources/views/technicians/index.blade.php`, sebelum diperbaiki:
`indexOf('jadwal_bulan=')`). Karena:

- paginasi memakai `->withQueryString()` sehingga tautan halaman menyalin
  `jadwal_bulan` (dan `buka_kalender`), lalu halaman tujuan membuka popup; dan
- simpan hari libur memakai `back()` yang kembali ke URL ber-`jadwal_bulan`
  sehingga popup terbuka lagi,

maka popup muncul tanpa diminta pada dua kejadian itu.

## Perbaikan

- **Penanda khusus**: popup hanya dibuka otomatis bila URL memuat
  `buka_kalender=1`, dan penanda itu HANYA ditambahkan oleh tombol "Bulan
  Sebelumnya / Bulan Berikutnya" di dalam popup.
- **Penanda sekali pakai**: setelah dipakai membuka popup,
  `history.replaceState` membuang `buka_kalender` dari address bar sehingga
  tidak ikut terbawa saat halaman dimuat ulang (paginasi/simpan hari libur).
- **Paginasi bersih**: `TechnicianController::index` memakai
  `->appends($request->only([...filter...]))` (bukan `withQueryString()`),
  sehingga tautan paginasi hanya membawa filter daftar (status, department_id,
  group, section, search) tanpa parameter kalender.

## Bukti uji

- `tests/Feature/TechnicianKalenderAutoBukaTest.php` (3 test): tautan bulan
  membawa penanda & pemicu memakai penanda; tautan paginasi tidak membawa
  `jadwal_bulan`/`buka_kalender`; paginasi tetap membawa filter daftar.
- `php artisan test` **665 test hijau (3238 assertions)**.
- Uji mutasi: mengembalikan pemicu ke `indexOf('jadwal_bulan=')` membuat uji
  pertama GAGAL, lalu dikembalikan.
- Verifikasi nyata DEV (`/debug-login` + curl `/technicians?jadwal_bulan=2026-11`):
  memuat `indexOf('buka_kalender=1')` dan tautan `buka_kalender=1`, serta TIDAK
  lagi memuat `indexOf('jadwal_bulan=')`.

## Berkas yang disentuh

- `resources/views/technicians/index.blade.php`
- `app/Http/Controllers/TechnicianController.php`
- `tests/Feature/TechnicianKalenderAutoBukaTest.php` (baru)
- `tugas/erik.md`

## Checklist

- [x] Uji otomatis hijau
- [x] `php artisan view:cache` sukses
- [x] Tanpa migrasi / tanpa perubahan data
- [x] Popup hanya dibuka dari tombol bulan di dalam popup
