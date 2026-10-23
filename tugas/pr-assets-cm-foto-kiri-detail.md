# Assets/CM: foto carousel di kiri detail

## Ringkasan

- Carousel foto dipindahkan ke **kiri** detail, ukuran tetap seperti
  sebelumnya:
  - **Informasi Asset**: 2/5 foto + 3/5 detail, foto di kiri
    Deskripsi/Tech Ident/Type dll.
  - **Ringkasan Equipment CM**: 1/4 foto + 1/4 spek + 2/4 statistik, foto di
    kiri Company/Equipment No/Tech Ident dll.
- Perilaku carousel tidak berubah: auto-slide 5 detik, swipe/panah/titik
  navigasi, klik foto membuka galeri maximized.
- Hanya penempatan/urutan kolom yang diubah; tidak ada perubahan data,
  controller, atau migrasi.

## Bukti

- DEV `/assets/2071` HTTP 200; posisi `Foto Equipment` muncul sebelum
  `Deskripsi`.
- DEV `/cm/equipment/6400P5` HTTP 200; posisi `Foto Equipment` muncul sebelum
  `Company`.
- PROD tidak disentuh.

## Uji

- `php artisan test --filter=AssetCmIntegrationTest`: **4 test hijau, 25 assertions**.
- `php artisan test` (seluruh suite): **738 test hijau, 3.672 assertions**.
- `php artisan view:cache`: sukses.

## Berkas

- `resources/views/assets/show.blade.php`
- `resources/views/cm/equipment-show.blade.php`
- `docs/28-assets-management.md`
