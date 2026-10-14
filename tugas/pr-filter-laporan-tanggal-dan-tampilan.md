# Filter halaman Laporan tidak lagi reset tanggal + Total Pekerjaan pindah ke samping Done/Continue

## Ringkasan perubahan

Tiga keluhan user 14/10/2026 sekaligus:

1. Pada halaman Laporan, "Total Pekerjaan" perlu dipindah tepat di
   Done/Continue.
2. Filter bug: sudah memilih tanggal lalu memindahkan tampilan dari Reguler ke
   Shift, tanggalnya kembali ke hari ini.
3. Membuka laporan dari profil teknisi SHIFT ternyata masuk mode Reguler; begitu
   dipindah ke Shift kena bug nomor 2.

TIDAK ada migrasi baru dan TIDAK ada perubahan data: seluruh perubahan adalah
tata letak dan parameter yang dibawa tautan.

## Akar masalah (dibuktikan ke copy DEV)

1. `$tautanTampilan()` di `resources\views\reports\index.blade.php` membangun
   URL dari `$dasarTautan` (`request()->except([... 'tanggal' ... 'date_from',
   'date_to'])`) TANPA menambahkan kunci tanggal kembali. Bukti curl atas
   `/reports?tanggal=2026-09-20`: tab Shift berbunyi `?tampilan=shift` saja ->
   halaman tujuan memakai hari ini. Rentang juga hilang, dan form pemilih grub
   hanya mengirim satu isian `tanggal` tetap.
2. `resources\views\technicians\show.blade.php` menaut ke halaman Laporan dengan
   `['technician_id' => ...]` tanpa `tampilan`, sehingga terbuka pada tampilan
   Reguler yang hanya memuat grup non-shift. Bukti data DEV: teknisi id 20
   (grub_a) punya 3 laporan pada 26/09/2026, tetapi
   `/reports?technician_id=20&tanggal=2026-09-26` = **0 baris**, sedangkan
   `&tampilan=shift` = **3 baris**.

## Perubahan

1. `resources/views/reports/index.blade.php`
   - `$kunciTanggal` (tanggal / date_from / date_to yang ada di query) disertakan
     `$tautanTampilan()`, jadi pindah tab/grub mempertahankan tanggal & rentang.
   - Form pemilih grub mengirim `$kunciTanggal` (bukan `tanggal` tetap lagi).
   - Kartu "Total Pekerjaan" dipindah ke grup kartu status: urutan markup Total,
     Continue, Done, lalu baris kartu jenis pekerjaan (kini 8 kartu = satu baris
     penuh di `lg:grid-cols-8`). Angkanya tetap angka paginator.
2. `resources/views/technicians/show.blade.php`
   - `$tampilanTeknisi` (Shift untuk Grub A-D, Reguler untuk reguler/reguler
     staff) + `$parameterLaporan` (technician_id + tampilan + grub) dipakai oleh
     tautan "Lihat semua di halaman Laporan" dan tautan baris Rincian Man Hour
     Harian (yang sekaligus membawa `tanggal` hari itu).
3. Uji baru: `tests/Feature/ReportFilterTampilanTanggalTest.php` (4 test) dan
   `tests/Feature/TechnicianTautanTampilanTest.php` (4 test).
4. Dokumentasi: `AGENTS.md` (bagian "FILTER HALAMAN LAPORAN TIDAK LAGI RESET
   TANGGAL..."), `tugas/erik.md`, berkas ini.

## Checklist wajib (agenda integrator)

- [x] `php artisan test` HIJAU (635 test, 3113 assertions).
- [x] `php artisan view:cache` sukses.
- [x] Tag blade tetap seimbang (`<div>`/`</div>`, `@php`/`@endphp`,
      `@foreach`/`@endforeach` dibandingkan dengan versi HEAD).
- [x] Uji mutasi dijalankan (4 mutasi, empat-empatnya tertangkap).
- [x] Hanya berkas yang relevan yang di-commit (bukan `git add -A`).
- [x] TIDAK ada berkas sampah baru (tidak ada skrip sementara tertinggal).
- [x] TIDAK ada migrasi baru dan TIDAK ada perubahan data.
- [x] `AGENTS.md` diperbarui.
- [x] Sudah `git rebase origin/main` (bukan merge) sebelum dibuka.

## Bukti uji

```
php artisan test
  Tests:    635 passed (3113 assertions)

php artisan view:cache
  INFO  Blade templates cached successfully.
```

Uji mutasi (dijalankan lalu dikembalikan):

1. `, $kunciTanggal` dihapus dari `$tautanTampilan` -> 2 test tab GAGAL.
2. Tautan profil teknisi dikembalikan ke `['technician_id' => ...]` -> 3 test
   profil teknisi GAGAL.
3. Isian tersembunyi `$kunciTanggal` pada form grub dikembalikan menjadi satu
   `tanggal` tetap -> test "pemilih grub membawa tanggal atau rentang" GAGAL.
4. Kartu Total dikembalikan ke baris kartu jenis pekerjaan -> test "kartu Total
   Pekerjaan berada tepat di samping kartu Done dan Continue" GAGAL
   ("Failed asserting that 39201 is less than 31620").

Bukti halaman NYATA di DEV (Apache `http://oleochemicalReport.test`, sesudah
`/debug-login`):

- `/reports?tanggal=2026-09-20` -> `?tampilan=reguler&tanggal=2026-09-20` dan
  `?tampilan=shift&tanggal=2026-09-20` (dulu keduanya tanpa tanggal).
- `/reports?date_from=2026-09-26&date_to=2026-09-27` -> tab Shift membawa
  `date_from` + `date_to`.
- `/technicians/20` -> `?technician_id=20&tampilan=shift&grub=grub_a&tanggal=2026-09-26`;
  membukanya menampilkan 3 baris (dulu 0 baris bila `tampilan` tidak dikirim).
- Urutan kartu `/reports`: total (31388) < status-continue (31942) <
  jenis-EI (41865).

## Berkas yang disentuh

1. `resources/views/reports/index.blade.php` - `$kunciTanggal`, `$tautanTampilan`,
   form pemilih grub, kartu Total Pekerjaan.
2. `resources/views/technicians/show.blade.php` - `$parameterLaporan` + dua
   tautan ke halaman Laporan.
3. `tests/Feature/ReportFilterTampilanTanggalTest.php` (BARU, 4 test).
4. `tests/Feature/TechnicianTautanTampilanTest.php` (BARU, 4 test).
5. `AGENTS.md`, `tugas/erik.md`, `tugas/pr-filter-laporan-tanggal-dan-tampilan.md`.

## Risiko / hal yang perlu diperhatikan integrator

- Murni tampilan + parameter tautan; definisi filter di server (grup/tanggal)
  tidak berubah. Deploy cukup `Deploy-Laporan.bat` seperti biasa.
- Perilaku yang SENGAJA tetap: tautan tanggal (chip/tombol Hari Ini/tombol Buka)
  membuang rentang supaya memilih satu hari benar-benar satu hari; tab tampilan
  justru kebalikannya (mempertahankan apa pun yang sedang dipakai).
- Kalau nanti ada tautan BARU ke halaman Laporan dari halaman lain dan tautan itu
  menyaring satu teknisi, sertakan `tampilan` sesuai tipe jadwal teknisi; tanpa
  itu laporan pekerja shift akan tampak kosong pada tampilan Reguler.
