# Man Hour: periode 26-25 otomatis mengikuti tanggal berjalan

## Ringkasan

- `TechnicianController::rentangManHour()` sekarang menghitung periode dari
  tanggal hari ini, bukan dari file jadwal shift terakhir.
  - Tanggal 1-25: periode dimulai tanggal 26 bulan lalu.
  - Tanggal 26 sampai akhir bulan: periode dimulai tanggal 26 bulan ini.
  - Tanggal selesai selalu tanggal 25 bulan berikutnya.
- Saat tanggal 26 tiba, periode otomatis bergeser ke bulan berikutnya; tidak
  perlu menunggu import jadwal baru.
- Ringkasan, target, dan rincian lembur mengikuti periode berjalan.
- Grafik/tabel tetap memuat satu bulan sebelum dan sesudah periode berjalan;
  tampilan awal tetap di 26-25 dan bisa digeser/gulir ke data lain.
- Data lama tidak dihapus; baris shift masa depan tetap dibuat otomatis oleh
  scheduler `shifts:generate --execute`.
- Tes `TechnicianLemburTest` disesuaikan agar tanggal uji berada di periode
  26-25 berjalan.

## Berkas yang disentuh

- `app/Http/Controllers/TechnicianController.php`
- `tests/Feature/TechnicianShiftManHourTest.php`
- `tests/Feature/TechnicianLemburTest.php`
- `docs/08-keputusan-arsitektur-31-33.md`

## Bukti uji

- `php artisan test --filter=Technician`: **50 test hijau, 307 assertions**.
- Seluruh suite: **772 test hijau, 3.849 assertions**.
- `php artisan view:cache` sukses.

## Checklist

- [x] Periode dihitung dari tanggal berjalan (26-25).
- [x] Saat tanggal 26 tiba, periode bergeser otomatis.
- [x] Ringkasan/target/lembur memakai periode berjalan.
- [x] Grafik/tabel tetap bisa digeser ke periode sebelum/sesudah.
- [x] Data lama tidak dihapus.
- [x] Uji otomatis hijau dan `view:cache` sukses.

## Catatan deploy

- Tidak ada migrasi baru.
- Setelah deploy, periode man hour akan mengikuti tanggal berjalan.
- Scheduler `shifts:generate --execute` tetap menyiapkan baris shift masa depan
  setiap hari; data lama tetap disimpan.
