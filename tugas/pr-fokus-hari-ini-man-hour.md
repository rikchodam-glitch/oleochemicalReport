# Man Hour: fokus awal di tanggal hari ini

## Ringkasan

- Saat halaman detail teknisi pertama kali dibuka:
  - **tabel** langsung di-scroll ke baris **tanggal hari ini** sehingga baris
    tersebut ada di paling atas tampilan;
  - **grafik** langsung di-scroll ke tanggal **hari ini** sehingga tanggal
    tersebut ada di paling kiri.
- Tidak perlu mencari atau menggeser saat pertama membuka halaman.
- Data grafik/tabel tetap mencakup satu bulan sebelum dan sesudah periode
  26-25 berjalan; tanggal lain tetap bisa dilihat dengan menggeser grafik atau
  menggulir tabel.
- Ringkasan, target, dan rincian lembur tetap mengikuti periode 26-25 berjalan.
- Atribut bantuan grafik diganti dari `data-indeks-mulai` menjadi
  `data-indeks-fokus`, berisi indeks tanggal hari ini di data yang diperluas.

## Berkas yang disentuh

- `app/Http/Controllers/TechnicianController.php`
- `resources/views/technicians/show.blade.php`
- `resources/views/technicians/partials/_grafik-manhour.blade.php`
- `tests/Feature/TechnicianShiftManHourTest.php`
- `docs/08-keputusan-arsitektur-31-33.md`

## Bukti uji

- `php artisan test --filter=TechnicianShiftManHourTest`: **17 test hijau,
  168 assertions**.
- Seluruh suite: **772 test hijau, 3.850 assertions**.
- `php artisan view:cache` sukses.

## Checklist

- [x] Tabel di-scroll ke baris hari ini (paling atas).
- [x] Grafik di-scroll ke tanggal hari ini (paling kiri).
- [x] Data sebelum/sesudah tetap bisa digeser/digulir.
- [x] Ringkasan/target/lembur tetap periode berjalan.
- [x] Uji otomatis hijau dan `view:cache` sukses.

## Catatan deploy

- Tidak ada migrasi baru.
- Setelah deploy, buka detail teknisi; grafik dan tabel langsung fokus ke
  tanggal hari ini.
