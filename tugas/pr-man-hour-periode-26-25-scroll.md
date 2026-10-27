# Man Hour: periode 26-25 dan grafik/tabel bisa digeser

## Ringkasan

- Ini revisi dari PR #67: periode man hour **dikembalikan ke periode jadwal
  shift terakhir (umumnya 26-25)**, bukan bulan kalender 1-31.
- Bila belum ada jadwal shift yang diimpor, tetap memakai fallback bulan
  berjalan.
- Grafik man hour tetap dibuat lebar (42 px per hari) dan bisa digeser
  horizontal dengan scrollbar atau drag mouse untuk melihat tanggal lain.
- Tabel Rincian Man Hour Harian memakai `max-h-[32rem] overflow-auto` dan
  header sticky, sehingga bisa digulir ke atas/bawah untuk melihat tanggal
  sebelum dan sesudahnya.
- Tabel tetap diurutkan terbaru di atas.

## Berkas yang disentuh

- `app/Http/Controllers/TechnicianController.php`
- `resources/views/technicians/show.blade.php`
- `tests/Feature/TechnicianShiftManHourTest.php`
- `docs/08-keputusan-arsitektur-31-33.md`

## Bukti uji

- `php artisan test --filter=TechnicianShiftManHourTest`: **17 test hijau,
  163 assertions**.
- Seluruh suite: **772 test hijau, 3.845 assertions**.
- `php artisan view:cache` sukses.

## Checklist

- [x] Periode grafik/tabel kembali ke 26-25 (fallback bulan berjalan).
- [x] Grafik bisa digeser horizontal.
- [x] Tabel bisa digulir vertikal dengan header tetap terlihat.
- [x] Tanggal 26-25 tampil pada grafik dan tabel.
- [x] Uji otomatis hijau dan `view:cache` sukses.

## Catatan deploy

- Tidak ada migrasi baru.
- Setelah deploy, buka detail teknisi; grafik dan tabel memakai periode jadwal
  26-25 dan bisa digeser.
