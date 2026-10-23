# Assets Management ↔ CM Equipment: preview, tautan, dan galeri foto

## Ringkasan

- Detail asset kini punya tombol **Buka Halaman CM** di header dan kartu
  Ringkasan Equipment, plus foto equipment di dalam kartu **Informasi Asset**.
- Halaman CM Equipment menampilkan deskripsi asset sebagai tautan ke Asset
  Management dan kartu **Foto Equipment** tepat di bawah **Ringkasan
  Equipment**.
- Foto memakai satu sumber `assets.photos`; halaman CM membaca foto dari asset
  yang bertaut, jadi foto yang ditambahkan lewat Edit Asset muncul di CM tanpa
  duplikasi data.
- Galeri foto memakai UX dokumentasi report: foto besar, sebelumnya/berikutnya,
  panah keyboard, swipe, thumbnail, dan penghitung posisi. Galeri lihat-saja;
  upload/hapus tetap di Edit Asset.
- Asset manual tetap menyembunyikan spek SAP, tetapi foto dan tautan Asset
  Management tetap tampil.
- CM Equipment Status: nama equipment yang punya asset menjadi tautan ke detail
  Asset Management.

## Bukti

- Halaman DEV `/assets/2071` (asset `6400P5`) HTTP 200; memuat preview CM,
  tombol Buka Halaman CM, dan galeri foto.
- Halaman DEV `/cm/equipment/6400P5` HTTP 200; memuat Foto Equipment dan tautan
  Buka Asset Management.
- `/cm/equipment-status` HTTP 200 setelah migration lama
  `2026_10_21_000001_add_active_status_to_cm_equipment` dijalankan di DEV
  (bukan PROD).
- Tidak ada migrasi baru dan tidak ada perubahan data PROD.

## Uji

- `php artisan test --filter=AssetCmIntegrationTest`: **4 test hijau, 21 assertions**.
- `php artisan test` (seluruh suite): **738 test hijau, 3.668 assertions**.
- `php artisan view:cache`: sukses.

## Berkas

- `app/Http/Controllers/CmController.php`
- `resources/views/assets/show.blade.php`
- `resources/views/assets/partials/_cm-ringkasan.blade.php`
- `resources/views/assets/partials/_foto-equipment.blade.php` (baru)
- `resources/views/partials/_galeri-foto-aset.blade.php` (baru)
- `resources/views/cm/equipment-show.blade.php`
- `resources/views/cm/equipment-status.blade.php`
- `tests/Feature/AssetCmIntegrationTest.php` (baru)
- `docs/28-assets-management.md`
