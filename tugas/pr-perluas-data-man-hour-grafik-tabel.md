# Man Hour: data sebelum/sesudah periode 26-25 dan grafik/tabel scroll

## Ringkasan

- Grafik dan tabel man hour sekarang memuat **satu bulan sebelum dan sesudah**
  periode utama 26-25, sehingga tanggal di luar periode tetap bisa dilihat.
- Tampilan awal tetap di periode 26-25:
  - grafik di-scroll ke tanggal 26;
  - tabel di-scroll ke tanggal 25.
- Ringkasan/target/rincian lembur tetap dihitung untuk periode utama 26-25
  saja, bukan seluruh data yang diperluas.
- Grafik bisa digeser horizontal (scrollbar atau drag mouse); area sebelum dan
  sesudah periode tersedia.
- Tabel memakai tinggi maksimum, header sticky, dan bisa digulir ke atas/bawah
  untuk tanggal sebelum/sesudahnya.
- Perbaikan ini menjawab laporan “hanya sampai 26/09” dan grafik yang tidak
  merespons: sebelumnya data hanya 26-09 sampai 25-10 dan tidak ada area
  tambahan untuk digeser.

## Berkas yang disentuh

- `app/Http/Controllers/TechnicianController.php`
- `resources/views/technicians/show.blade.php`
- `resources/views/technicians/partials/_grafik-manhour.blade.php`
- `tests/Feature/TechnicianShiftManHourTest.php`
- `docs/08-keputusan-arsitektur-31-33.md`

## Bukti uji

- `php artisan test --filter=TechnicianShiftManHourTest`: **17 test hijau,
  167 assertions**.
- Seluruh suite: **772 test hijau, 3.849 assertions**.
- `php artisan view:cache` sukses.

## Checklist

- [x] Data grafik/tabel mencakup satu bulan sebelum dan sesudah 26-25.
- [x] Tampilan awal grafik di tanggal 26.
- [x] Tampilan awal tabel di tanggal 25.
- [x] Ringkasan tetap periode 26-25.
- [x] Grafik bisa digeser horizontal.
- [x] Tabel bisa digulir vertikal dengan header sticky.
- [x] Uji otomatis hijau dan `view:cache` sukses.

## Catatan deploy

- Tidak ada migrasi baru.
- Setelah deploy, buka detail teknisi dan geser grafik/tabel; tanggal sebelum
  26 dan sesudah 25 sekarang tersedia.
