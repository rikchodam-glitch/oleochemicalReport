# Man Hour: tampilan bulanan dan grafik bisa digeser

## Ringkasan

- Man hour halaman daftar/detail teknisi sekarang memakai **bulan berjalan**
  (tanggal 1 sampai akhir bulan), bukan periode file jadwal shift terakhir.
  Sebelumnya periode berakhir di tanggal 26 sehingga tanggal 27 dan seterusnya
  tidak tampil.
- Tabel **Rincian Man Hour Harian** diurutkan terbaru di atas dengan
  `array_reverse($manHourHarian)`, sehingga tanggal 1 berada di paling bawah.
- Grafik `grafikManHour` dibuat selebar 28-31 hari (42 px per hari, minimal
  720 px) di dalam wadah `#grafikManHourScroll` yang dapat di-scroll horizontal
  dan digeser dengan drag mouse. Layar sentuh memakai scroll native.
- Ditambah keterangan “Geser grafik ke kiri atau ke kanan...” di bawah grafik.

## Berkas yang disentuh

- `app/Http/Controllers/TechnicianController.php`
- `resources/views/technicians/show.blade.php`
- `resources/views/technicians/partials/_grafik-manhour.blade.php`
- `tests/Feature/TechnicianShiftManHourTest.php`
- `docs/08-keputusan-arsitektur-31-33.md`

## Bukti uji

- `php artisan test --filter=TechnicianShiftManHourTest`: **17 test hijau,
  160 assertions**.
- Seluruh suite: **772 test hijau, 3.842 assertions**.
- `php artisan view:cache` sukses.

## Checklist

- [x] Man hour memakai bulan berjalan, bukan periode jadwal terakhir.
- [x] Tanggal 27 sampai akhir bulan ikut tampil.
- [x] Tanggal 1 berada di paling bawah tabel.
- [x] Grafik memuat seluruh tanggal bulan berjalan.
- [x] Grafik bisa digeser horizontal dengan scrollbar/drag mouse.
- [x] Uji otomatis hijau dan `view:cache` sukses.

## Catatan deploy

- Tidak ada migrasi baru.
- Setelah deploy, buka detail teknisi; tabel dan grafik langsung memakai bulan
  berjalan.
