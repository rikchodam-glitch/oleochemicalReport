# Request Spare Part: filter dan kolom Bagian

## Ringkasan

- Menambah pilihan **Bagian** pada form saringan halaman Request Spare Part.
- Filter memakai `technicians.section` (label `Technician::SECTIONS`) dan
  berlaku untuk daftar utama serta tabel **Daftar PR Menunggu PO**.
- Menambah kolom **Bagian** pada tabel Pengajuan, Kejar PO (tabel atas dan
  utama), Kejar Delivery, dan Selesai.
- Kolom Bagian menampilkan nama bagian dan Group teknisi di bawahnya.
- Partial teknisi kini hanya menampilkan nama/NIK agar tidak duplikat dengan
  kolom Bagian.
- Pilihan Bagian dipertahankan saat berpindah tab/status.

## Berkas yang disentuh

- `app/Http/Controllers/SparepartRequestController.php`
- `resources/views/sparepart/request/index.blade.php`
- `resources/views/sparepart/request/partials/_pengajuan.blade.php`
- `resources/views/sparepart/request/partials/_kejar-po.blade.php`
- `resources/views/sparepart/request/partials/_kejar-delivery.blade.php`
- `resources/views/sparepart/request/partials/_selesai.blade.php`
- `resources/views/sparepart/request/partials/_teknisi.blade.php`
- `resources/views/sparepart/request/partials/_bagian.blade.php` (baru)
- `tests/Feature/SparepartRequestWebTest.php`
- `docs/30-request-sparepart.md`

## Bukti uji

- `php artisan test --filter=SparepartRequest`: **29 test hijau,
  110 assertions**.
- Seluruh suite: **771 test hijau, 3.833 assertions**.
- `php artisan view:cache` sukses.

## Checklist

- [x] Pilihan Bagian muncul di form saringan.
- [x] Filter Bagian menyaring Pengajuan dan Kejar PO.
- [x] Tabel PR Menunggu PO ikut tersaring.
- [x] Kolom Bagian tampil di semua tabel request.
- [x] Group teknisi tetap terlihat di bawah nama bagian.
- [x] Tab/status tetap mempertahankan pilihan Bagian.
- [x] Uji otomatis hijau dan `view:cache` sukses.

## Catatan deploy

- Tidak ada migrasi baru.
- Setelah deploy, filter dan kolom Bagian langsung tersedia di tab Request
  Spare Part.
