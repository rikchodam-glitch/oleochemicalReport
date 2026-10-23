# Assets/CM: carousel foto di samping detail

## Ringkasan

- Foto equipment kini tampil dalam **satu kotak carousel**, bukan grid
  thumbnail.
- Foto berganti otomatis tiap 5 detik; bisa digeser (swipe), memakai tombol
  panah, atau titik navigasi.
- Klik foto membuka galeri maximized (slideshow penuh) pada posisi foto yang
  sedang tampil. Galeri tetap memakai thumbnail, panah keyboard, dan swipe.
- Penempatan:
  - **Informasi Asset** (Assets Management): detail 3/5 + foto 2/5, foto tepat
    di samping Deskripsi/Tech Ident/Type dll.
  - **Ringkasan Equipment** (CM): spek 1/4 + foto 1/4 + statistik 2/4, foto
    tepat di samping Company/Equipment No/Tech Ident dll.
- Kartu foto terpisah di bawah Ringkasan Equipment dihapus.
- Auto-slide berhenti saat kursor di atas kotak atau galeri maximized dibuka,
  lalu lanjut lagi setelah ditutup. Swipe tidak memicu klik yang tidak sengaja.

## Bukti

- Halaman DEV `/assets/2071` dan `/cm/equipment/6400P5` HTTP 200; markup
  carousel (`x-init="mulaiSlide()"`, `slideFoto()`, `aspect-[4/3]`) dan galeri
  ada di kedua halaman.
- Foto tetap satu sumber `assets.photos`; halaman CM membaca asset yang
  bertaut sehingga foto dari Edit Asset otomatis muncul.
- Tanpa migrasi baru dan tanpa perubahan data PROD.

## Uji

- `php artisan test --filter=AssetCmIntegrationTest`: **4 test hijau, 25 assertions**.
- `php artisan test` (seluruh suite): **738 test hijau, 3.672 assertions**.
- `php artisan view:cache`: sukses.

## Berkas

- `resources/views/assets/partials/_foto-equipment.blade.php`
- `resources/views/partials/_galeri-foto-aset.blade.php`
- `resources/views/assets/show.blade.php`
- `resources/views/cm/equipment-show.blade.php`
- `tests/Feature/AssetCmIntegrationTest.php`
- `docs/28-assets-management.md`
