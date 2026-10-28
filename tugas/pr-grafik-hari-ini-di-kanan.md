# Man Hour: tahun kalender, tabel dimuat saat digulir, dan klik batang

## Ringkasan

- Grafik dan tabel man hour pada detail teknisi kini memakai **tahun kalender
  berjalan (01/01-31/12)**, bukan jendela 12 periode 26-25. Tahun berganti
  otomatis saat halaman dibuka pada tahun baru.
- Tampilan awal: grafik di-scroll sehingga **tanggal hari ini berada di sisi
  kanan**; tabel di-scroll sehingga **baris hari ini berada di paling atas**.
  Grafik tetap bisa digeser ke kiri sampai 01/01 dan ke kanan sampai 31/12.
- Tabel diurutkan terbaru -> terlama. Baris awal hanya bulan sebelumnya + bulan
  berjalan + bulan berikutnya; bulan lain dimuat lewat endpoint
  `technicians.man-hour-bulan` saat tabel digulir, supaya setahun penuh tidak
  dirender sekaligus. Tinggi tabel dipasang inline (`max-height: 32rem`) agar
  wadah benar-benar bisa digulir; kelas arbitrary Tailwind tidak ikut build.
- Setiap awal periode 26-25 tetap diberi **garis pemisah** putus-putus.
  Satu tahun kalender menyentuh 13 periode payroll, sehingga popup Cek Overtime
  berisi 13 bagian.
- **Batang grafik bisa diklik** untuk membuka halaman Laporan dengan filter
  teknisi, tampilan, grub (bila shift), dan tanggal. Hanya tanggal yang punya
  laporan yang memiliki tautan. Tooltip/hover tetap ada; drag tidak memicu klik.
  Klik ditangani di wadah grafik agar pointer capture saat drag tidak menelan
  event klik.
- Ringkasan kartu Man Hour tetap memakai periode 26-25 berjalan.

## Berkas yang disentuh

- `app/Http/Controllers/TechnicianController.php`
- `routes/web.php`
- `resources/views/technicians/show.blade.php`
- `resources/views/technicians/partials/_grafik-manhour.blade.php`
- `resources/views/technicians/partials/_modal-lembur.blade.php`
- `resources/views/technicians/partials/_baris-manhour.blade.php`
- `resources/views/technicians/partials/_daftar-baris-manhour.blade.php`
- `tests/Feature/TechnicianShiftManHourTest.php`
- `docs/08-keputusan-arsitektur-31-33.md`

## Bukti uji

- `php artisan test --filter=TechnicianShiftManHourTest`: **19 test hijau,
  194 assertions**.
- `php artisan test --filter="TechnicianShiftManHourTest|TechnicianLemburTest|TechnicianTautanTampilanTest|TechnicianGrafikHariOffTest"`:
  **36 test hijau, 270 assertions**.
- Seluruh suite: **774 test hijau, 3.876 assertions**.
- `php artisan view:cache` dan pemeriksaan sintaks JavaScript sukses.

## Checklist

- [x] Grafik memuat tahun kalender 01/01-31/12 dan otomatis mengikuti tahun.
- [x] Tanggal hari ini tampil di sisi kanan grafik saat halaman dibuka.
- [x] Tabel terurut terbaru -> terlama dan default fokus ke hari ini.
- [x] Tabel memuat bulan lain saat digulir lewat endpoint bulanan.
- [x] Garis pemisah periode 26-25 tetap tampil.
- [x] Batang grafik yang punya laporan bisa diklik menuju daftar laporan.
- [x] Cek Overtime memiliki 13 bagian dan mengikuti periode yang terlihat.
- [x] Uji otomatis hijau dan `view:cache` sukses.

## Catatan deploy

- Tidak ada migrasi baru.
- Setelah deploy, buka detail teknisi; grafik tampil setahun dengan hari ini di
  kanan, tabel fokus ke hari ini, dan bulan lain dimuat saat digulir.
