# Assets/CM: rapikan foto, Kelola foto pindah ke galeri fullsize

## Ringkasan

- Teks "Berganti otomatis tiap 5 detik. Geser foto atau klik untuk membuka
  ukuran penuh." dihapus dari bawah kotak foto; perilaku slideshow tidak
  berubah.
- Tautan **Kelola foto** dipindah dari kepala kotak foto ke header galeri
  fullsize.
- Galeri menerima `$kelolaUrl` dari partial `_foto-equipment`, sehingga
  tautan hanya dirender di halaman Assets Management.
- Halaman CM/PM tetap lihat-saja (tanpa tautan kelola).

## Berkas yang disentuh

- `resources/views/assets/partials/_foto-equipment.blade.php`
- `resources/views/partials/_galeri-foto-aset.blade.php`
- `tests/Feature/AssetCmIntegrationTest.php`
- `docs/28-assets-management.md`

## Bukti uji

- `php artisan view:cache` sukses.
- `php artisan test --filter AssetCmIntegrationTest`: 4 test hijau,
  30 assertions.
- `php artisan test`: 738 test hijau, 3.677 assertions.

## Checklist

- [x] Teks petunjuk di bawah kotak foto dihapus.
- [x] Tautan Kelola foto hanya tampil di galeri fullsize halaman Assets
      Management.
- [x] Halaman CM/PM tidak menampilkan tautan Kelola foto.
- [x] Slideshow otomatis, geser, dan klik tetap berfungsi.
- [x] Uji otomatis hijau dan `view:cache` sukses.
