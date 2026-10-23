# Sparepart: filter header dua mode (kelompok dan lanjutan)

## Ringkasan

- Klik **judul kolom** membuka filter pengelompokan: pilih kelompok Status,
  Aksi, PR, PO, atau MRP dan langsung diterapkan lewat tautan.
- Klik **tanda panah** di sebelah judul membuka filter lanjutan (rentang
  angka, teks, atau tanggal) sebagai popup, bukan panel yang mengembang di
  dalam header tabel.
- Kolom tanpa kelompok (Kode/Deskripsi, Stok Min, Stok Gudang,
  Purchaser/Vendor, Diperbarui) langsung membuka filter lanjutan dari judul.
- Filter lanjutan memakai partial input tersembunyi supaya saringan kolom lain
  tidak ter-reset. Tabel tidak lagi dibungkus form filter.
- Aturan tampilan dicatat di `docs/13` dan rincian modul di `docs/15`.

## Bukti

- `/sparepart` HTTP 200.
- Popup memuat 5 kelompok (`Semua kelompok` muncul 5 kali), teks
  `Filter lanjutan` 16 kali, dan 21 pemanggilan `bukaFilter(` pada tombol
  header.
- Perubahan hanya tampilan/filter UI; definisi bisnis dan agregasi data tidak
  berubah.

## Uji

- `php artisan test --filter=Sparepart`: **42 test hijau, 318 assertions**.
- `php artisan test` (seluruh suite): **734 test hijau, 3.647 assertions**.
- `php artisan view:cache`: sukses.

## Berkas

- `resources/views/sparepart/index.blade.php`
- `resources/views/sparepart/partials/_filter-grup.blade.php` (baru)
- `resources/views/sparepart/partials/_saringan-tersembunyi.blade.php` (baru)
- `tests/Feature/SparepartMrpFilterTest.php`
- `docs/13-nama-sistem-kode-alur-sesi-kebersihan.md`
- `docs/15-modul-spare-part.md`
