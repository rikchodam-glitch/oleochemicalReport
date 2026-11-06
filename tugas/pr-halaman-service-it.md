# Service IT: kasus perangkat IT dari laporan continue/done dan entri manual

## Ringkasan

- Halaman baru `/it-service` menampilkan kasus service khusus perangkat IT.
- Laporan **continue** membuka kasus; laporan **done** menutupnya. Tanggal
  masuk dan tanggal selesai memakai `reports.report_date`, bukan waktu status
  diubah.
- Satu kasus per aset: laporan continue berikutnya tidak membuat kasus ganda.
  Laporan done tanpa kasus terbuka tidak membuat kasus baru.
- Jika laporan done diubah kembali menjadi continue, kasus dibuka lagi (tanggal
  selesai dikosongkan, status kembali proses).
- Baris salinan kolaborasi (`collaborator_of`) dan multi alat (`multi_of`)
  diabaikan agar satu pekerjaan tidak menjadi beberapa kasus.
- Setiap kasus punya satu catatan bebas yang opsional dan bisa diedit.
- Entri manual: modal pencarian perangkat dengan pengelompokan lokasi lalu jenis
  perangkat (collapsible), tanggal masuk default hari ini, tanggal selesai
  opsional, status proses/selesai/rusak.
- Kasus proses tanpa tanggal selesai bisa ditandai **rusak** dan wajib mengisi
  tanggal selesai. Menandai rusak tidak mengubah status master `it_assets`.
- Filter default **Aktif (Proses + Rusak)**; kasus selesai tetap tampil lewat
  filter Selesai/Semua.
- Sinkronisasi dari laporan berjalan otomatis saat halaman dibuka, termasuk
  untuk data laporan lama setelah deploy.

## Berkas yang disentuh

- `database/migrations/2026_11_06_000001_create_it_service_records_table.php`
- `app/Models/ItServiceRecord.php`
- `app/Models/ItAsset.php` - relasi `serviceRecords()` dan `locationLabel()`
- `app/Services/ItServiceSyncService.php`
- `app/Http/Controllers/ItServiceController.php`
- `routes/web.php`
- `config/akses.php` - modul `it_service`
- `resources/views/it-service/index.blade.php`
- `resources/views/layouts/app.blade.php` - menu Service IT
- `tests/Feature/ItServiceTest.php`
- `docs/32-service-it.md`, `AGENTS.md`

## Bukti uji

- `php artisan test --filter=ItServiceTest`: **8 test hijau, 57 assertions**.
- `php artisan test --filter="ItServiceTest|AksesBagianUserTest|PageSmokeTest"`:
  **68 test hijau, 183 assertions**.
- Seluruh suite: **785 test hijau, 3.965 assertions**.
- `php artisan view:cache`: sukses.
- Migrasi `it_service_records` berhasil dijalankan di DEV (MySQL).

## Checklist

- [x] Laporan continue membuka kasus dan laporan done menutupnya.
- [x] Tanggal masuk/selesai memakai tanggal laporan.
- [x] Done tanpa continue tidak membuat kasus.
- [x] Salinan kolaborasi/multi alat tidak membuat kasus.
- [x] Done yang diubah kembali menjadi continue membuka kasus lagi.
- [x] Catatan bebas dapat diisi/diedit.
- [x] Entri manual dengan pencarian dan pengelompokan lokasi/jenis.
- [x] Aset dengan kasus terbuka tidak bisa ditambah manual dua kali.
- [x] Status rusak wajib tanggal selesai; tanggal selesai tidak boleh sebelum
      tanggal masuk.
- [x] Kasus selesai tetap tampil lewat filter.
- [x] Modul hak akses Service IT ditambahkan; role non-penulis hanya melihat.
- [x] Seluruh uji hijau dan `view:cache` sukses.

## Catatan deploy

- Ada migrasi baru `it_service_records`; deploy menjalankannya otomatis.
- Tidak ada perintah sinkronisasi manual: kasus dari laporan lama terbentuk
  saat halaman Service IT pertama kali dibuka setelah deploy.
- Tidak mengubah data laporan, aset, atau status master perangkat IT.
