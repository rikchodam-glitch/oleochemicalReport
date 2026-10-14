# Aturan lembur: istirahat bukan lembur + man hour dihitung dari jam laporan (perbaikan angka dobel)

## Ringkasan perubahan

Perbaikan angka man hour/lembur, dua perbaikan pengelolaan laporan, dan dua alat
kerja baru. Tidak ada migrasi baru, jadi `php artisan migrate` tidak perlu
dijalankan.

1. **Menit yang sama tidak lagi terhitung dua kali.** Dulu kolom
   `reports.work_duration_minutes` dibaca apa adanya sebagai man hour NORMAL,
   sedangkan LEMBUR dihitung lagi dari jam laporan — jadi menit di luar jendela
   kerja (termasuk jam pada hari libur) muncul sebagai man hour normal DAN
   sebagai lembur. Sekarang menit normal selalu dihitung ULANG dari Jam
   Mulai/Jam Selesai lewat `App\Services\Shift\JamKerjaService::ringkasJamKerja()`,
   satu sumber untuk semua tempat: `ManHourService::harian()`,
   `lemburPerTanggal()`, `menitPerTeknisi()`, halaman detail laporan, dan
   wizard Telegram.
2. **Jam istirahat bukan lembur.** Rumus akhirnya `total = normal + lembur +
   istirahat` dengan `istirahat = min(menitIstirahat, menitDalamJendela)`,
   `normal = menitDalamJendela - istirahat`, dan
   `lembur = total - menitDalamJendela`. Jendela istirahat dibaca dari config
   `cm.man_hour.jam_kerja` (Senin-Kamis & Sabtu 12:00-13:00, Jumat
   11:00-13:00). HANYA group non-shift (`reguler`, `reguler_staff`) yang
   dipotong; pekerja shift grub A-D tetap 8 jam penuh (keputusan 26/09/2026).
3. **Hapus laporan salinan tidak lagi menyentuh foto laporan INDUK** (salinan
   memakai berkas foto yang sama), dan menghapus laporan INDUK ikut
   membersihkan baris salinannya (kolaborasi / multi alat) supaya tidak ada
   baris yatim.
4. Dua alat kerja baru supaya pembuatan Pull Request dan langkah
   `git checkout main` sebelum deploy bisa dikerjakan satu klik tanpa token
   GitHub: `tools\buat-pr-tugas.ps1` + `tools\kembalikan-ke-main.ps1`, beserta
   peluncur Desktop `Buat-PR-Laporan.bat` dan `Kembali-Ke-Main-Laporan.bat`.

## Checklist wajib (agenda integrator)

- [x] `php artisan test` HIJAU (576 test, 2741 assertions, 92 detik).
- [x] `php artisan view:cache` sukses (167 berkas view ter-cache).
- [x] Perubahan HANYA pada berkas yang relevan (bukan `git add -A`).
- [x] Tidak ada berkas sampah (skrip `tmp-*`, `.bak`, hasil export sekali pakai).
- [x] TIDAK ada migrasi baru.
- [x] Sudah `git rebase origin/main` (bukan merge) sebelum dibuka.
- [x] `AGENTS.md` diperbarui (Keputusan Arsitektur poin 31 + daftar peluncur Desktop).

## Bukti uji

```
php artisan test
  Tests:    576 passed (2741 assertions)
  Duration: 92.36s

php artisan view:cache
  INFO  Blade templates cached successfully.
  (167 berkas di storage\framework\views)
```

Bukti pada data asli PROD `oleochemical`, perintah HANYA BACA (14/10/2026):

```
php artisan reports:pangkas-istirahat        (simulasi, tidak menulis apa pun)
  Laporan berjam diperiksa          : 2020
  Durasi berubah                    : 397
  Durasi sudah benar                : 1623
  Rentang tidak sah / tanpa tanggal : 0
  Gagal                             : 0
  Total menit sebelum               : 336593
  Total menit sesudah               : 288408
  Total menit lembur                : 32010
```

336593 - 288408 = **48185 menit** yang dulu terhitung sebagai man hour normal
padahal menitnya sudah masuk LEMBUR juga — itulah angka dobel yang diperbaiki.
Sesudah perubahan ini angka pada layar langsung benar TANPA menyentuh data,
karena man hour normal selalu dihitung dari jam laporan; kolom
`work_duration_minutes` hanya dipakai sebagai CADANGAN untuk laporan lama yang
tidak punya jam mulai/selesai. Menjalankan
`php artisan reports:pangkas-istirahat --execute` sesudah deploy hanya
menyamakan nilai TERSIMPAN pada 397 baris itu (opsional, bukan syarat).

## Berkas yang disentuh

Daftar lengkap + ringkasan otomatis ada pada bagian "Ringkasan berkas yang
berubah" di bawah (dibuat dari git saat PR ini disiapkan). Ringkas per bagian:

1. `app/Services/Shift/JamKerjaService.php` — istirahat, jendela kerja,
   pemisahan normal/lembur.
2. `app/Services/Shift/ManHourService.php` — satu sumber menit normal + lembur.
3. `app/Models/Report.php` — durasi dari jam, lintas tengah malam, label durasi.
4. `app/Console/Commands/ReportPangkasIstirahatCommand.php` — laporan + lembur.
5. `resources/views/reports/partials/_waktu-kerja*.blade.php` dan
   `resources/views/reports/show.blade.php` — kartu jam kerja.
6. `resources/views/technicians/show.blade.php` +
   `technicians/partials/_modal-lembur.blade.php` — chip lembur, segmen batang
   oranye, popup "Cek Overtime".
7. `tests/Feature/ReportLemburTanpaIstirahatTest.php`,
   `tests/Feature/ReportHapusSalinanAmanTest.php`,
   `tests/Feature/TechnicianLemburTest.php` — penjaga aturan ini.
8. `tools/buat-pr-tugas.ps1`, `tools/kembalikan-ke-main.ps1`, `AGENTS.md`,
   `tugas/erik.md`, dan berkas catatan PR ini.

## Risiko / hal yang perlu diperhatikan integrator

- Perubahan ini menyentuh DEFINISI angka man hour dan lembur. Uji penjaganya:
  `ReportLemburTanpaIstirahatTest`, `ReportIstirahatTest`,
  `TechnicianLemburTest`, `ReportEditJamKerjaTest`, `ReportDurasiKonsistenTest`
  — semuanya hijau.
- Akibat yang DISENGAJA (keputusan user 13/10/2026): laporan pada hari libur/OFF
  ber-man hour normal 0 karena seluruh menitnya tercatat sebagai LEMBUR.
- Backup database PROD WAJIB ada sebelum deploy; `Deploy-Laporan.bat` sudah
  membuatnya otomatis. Pemulihan bila diperlukan:
  `mysql -u root oleochemical < <berkas-backup>.sql`.
- Sesudah deploy, opsional (bukan syarat):
  `php artisan reports:pangkas-istirahat --execute` di PROD untuk menyamakan
  397 nilai tersimpan; simulasi angkanya sudah ada di bagian Bukti uji.
