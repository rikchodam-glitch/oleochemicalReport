# Man Hour: grafik setahun, hari ini di kanan, dan Cek Overtime per periode

## Ringkasan

- Grafik man hour pada detail teknisi kini memuat **12 periode 26-25** (periode
  berjalan + 11 periode sebelumnya, satu tahun penuh).
- Saat halaman pertama dibuka, grafik di-scroll sehingga **tanggal hari ini
  berada di sisi kanan**. Tabel tetap memuat satu bulan sebelum/sesudah periode
  berjalan dan tetap fokus ke baris hari ini di paling atas.
- Setiap awal periode 26-25 diberi **garis pemisah** putus-putus.
- **Batang grafik bisa diklik** untuk membuka halaman Laporan dengan filter
  teknisi, tampilan, grub (bila shift), dan tanggal. Hanya tanggal yang punya
  laporan yang memiliki tautan. Tooltip dan hover tetap tersedia; drag grafik
  tidak memicu klik. Klik ditangani di wadah grafik agar pointer capture saat
  drag tidak menelan event klik.
- Popup **Cek Overtime** kini memiliki satu bagian untuk setiap periode grafik.
  Saat halaman pertama dibuka, badge dan bagian periode berjalan yang tampil;
  setelah grafik digeser, periode di tengah tampilan menentukan bagian yang
  ditampilkan. Badge `Lembur: X j`, header periode, total jam, dan total tanggal
  ikut diperbarui.
- Ringkasan, target, dan data tabel tetap mengikuti aturan sebelumnya (periode
  berjalan untuk ringkasan; satu bulan sebelum/sesudah untuk tabel).

## Berkas yang disentuh

- `app/Http/Controllers/TechnicianController.php`
- `resources/views/technicians/show.blade.php`
- `resources/views/technicians/partials/_grafik-manhour.blade.php`
- `resources/views/technicians/partials/_modal-lembur.blade.php`
- `tests/Feature/TechnicianShiftManHourTest.php`
- `docs/08-keputusan-arsitektur-31-33.md`

## Bukti uji

- `php artisan test --filter=TechnicianShiftManHourTest`: **18 test hijau,
  184 assertions**.
- `php artisan test --filter="TechnicianShiftManHourTest|TechnicianLemburTest"`:
  **23 test hijau, 211 assertions**.
- Seluruh suite: **773 test hijau, 3.866 assertions**.
- `php artisan view:cache` sukses.

## Checklist

- [x] Grafik memuat 12 periode 26-25 / satu tahun.
- [x] Tanggal hari ini tampil di sisi kanan saat halaman pertama dibuka.
- [x] Garis pemisah tampil di awal setiap periode.
- [x] Batang grafik yang punya laporan bisa diklik menuju daftar laporan.
- [x] Cek Overtime memiliki 12 bagian dan mengikuti periode yang terlihat.
- [x] Tabel tetap fokus ke hari ini dan memuat satu bulan sebelum/sesudah.
- [x] Uji otomatis hijau dan `view:cache` sukses.

## Catatan deploy

- Tidak ada migrasi baru.
- Setelah deploy, buka detail teknisi; grafik akan tampil setahun dengan hari ini
  di kanan, dan Cek Overtime mengikuti periode yang sedang dilihat.
