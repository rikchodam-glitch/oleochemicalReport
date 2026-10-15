# PR: Kalender teknisi + input hari libur nasional (lembur)

Cabang: `tugas/erik/kalender-teknisi-hari-libur`

## Ringkasan

Diminta user 15/10/2026: mengubah kalender shift menjadi **kalender teknisi** dan
memakainya untuk menginput **hari libur nasional**. Pada hari libur, target jam
kerja **0 untuk semua teknisi** dan seluruh pekerjaan dihitung **lembur**;
pekerja shift **tetap wajib masuk** sesuai rotasi tetapi hasilnya juga lembur.

## Keputusan yang dikonfirmasi user

1. Bentuk: grid grup A-D seperti sekarang + penanda hari libur + form kelola hari libur di popup yang sama.
2. Aturan hari libur: target 0 + semua jam lembur; jadwal shift tetap tampil.
3. Cakupan: berlaku untuk SEMUA grup (reguler, reguler staff, shift A-D).
4. Data: satu tabel (tanggal + nama).

## Perubahan

- **Tabel + model baru** `hari_libur` (tanggal unik + nama) dan `HariLibur`
  (+ `petaRentang()`), migration `2026_10_15_000001_create_hari_libur_table.php`.
- **Kalender teknisi** (`technicians/index.blade.php`): judul/penjelasan diganti
  "Kalender Teknisi", kolom tanggal libur ditandai merah (dengan tooltip nama),
  legenda ditambah, dan bagian **Hari Libur Nasional** berisi form tambah serta
  daftar hapus (hanya administrator; penegakan di HanyaBacaMiddleware).
- **Route + controller**: `technicians.hari-libur.store` (POST) dan
  `technicians.hari-libur.destroy` (DELETE, `HariLiburController`). Tanggal yang
  sama memperbarui nama (bukan baris ganda), dicocokkan `whereDate` agar benar di
  MySQL (DATE) maupun SQLite (datetime).
- **Perhitungan**: `JamKerjaService` memuat peta hari libur sekali per instance
  (`namaHariLibur()`/`apakahHariLibur()`), lalu `hari()` dan `jendelaDariJadwal()`
  mengembalikan null pada hari libur sehingga target 0 dan seluruh jam lembur.
  `TechnicianShift::targetMenit()/jendela()` juga libur-aware (shift tetap ada,
  tapi target 0/jendela null). `ManHourService` menandai catatan
  "Libur nasional (<nama>): N pekerjaan dihitung lembur" dan label
  "Libur Nasional", serta target 0 di `targetHari()`/`targetPerTeknisi()`.

## Bukti uji

- `tests/Feature/HariLiburManHourTest.php` (8 test): jendela hilang + semua
  lembur, shift tetap berjadwal tapi target 0, man hour harian libur,
  hari kerja biasa tidak terpengaruh, targetPerTeknisi 0, CRUD admin, akun hanya
  baca 403, dan kalender menampilkan penanda + form.
- `tests/Feature/TechnicianShiftManHourTest.php` disesuaikan ke label baru
  ("Kalender Teknisi").
- `php artisan test` **660 test hijau (3212 assertions)**.
- Uji mutasi: mematikan override hari libur di `JamKerjaService::hari()`
  membuat uji "hari libur membuat jendela kerja hilang..." GAGAL, lalu
  dikembalikan.
- Bukti render nyata DEV (`/debug-login` + curl `/technicians?jadwal_bulan=2026-10`):
  HTTP 200, memuat "Kalender Teknisi", "Hari Libur Nasional",
  `action=".../technicians/hari-libur"`, dan legenda libur.

## Berkas yang disentuh

- `database/migrations/2026_10_15_000001_create_hari_libur_table.php` (baru)
- `app/Models/HariLibur.php` (baru)
- `app/Http/Controllers/HariLiburController.php` (baru)
- `app/Http/Controllers/TechnicianController.php`
- `app/Services/Shift/JamKerjaService.php`
- `app/Services/Shift/ManHourService.php`
- `app/Models/TechnicianShift.php`
- `routes/web.php`
- `resources/views/technicians/index.blade.php`
- `tests/Feature/HariLiburManHourTest.php` (baru)
- `tests/Feature/TechnicianShiftManHourTest.php`
- `tugas/erik.md`

## Checklist

- [x] Uji otomatis hijau
- [x] `php artisan view:cache` sukses
- [x] Ada migrasi baru (`hari_libur`) - dijalankan saat deploy
- [x] Hari libur: target 0 + semua lembur; shift tetap tampil
- [x] Hanya administrator yang bisa menambah/hapus hari libur
