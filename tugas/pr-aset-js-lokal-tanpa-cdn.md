# Aset JS lokal: Alpine.js, Chart.js, dan PDF.js tanpa CDN

## Ringkasan

- Sebagian komputer pengguna gagal memuat `cdn.min.js` dengan
  `net::ERR_NAME_NOT_RESOLVED` karena DNS tidak bisa me-resolve
  `cdn.jsdelivr.net`. Aplikasi sebelumnya memuat Alpine.js dan Chart.js dari
  jsdelivr serta PDF.js dari unpkg, sehingga interaksi Alpine dan grafik mati
  saat CDN tidak dapat diakses.
- Alpine.js dan Chart.js kini diimpor dari `node_modules` lewat
  `resources/js/app.js` (Vite) dan dipasang sebagai `window.Alpine` /
  `window.Chart`. PDF.js beserta worker-nya dibundel lewat
  `resources/js/pdfjs.js`, sehingga halaman denah IT tidak lagi mengambil
  berkas dari unpkg.
- Script grafik yang sebelumnya berjalan langsung dipindahkan ke
  `DOMContentLoaded` supaya Chart.js dari bundel Vite sudah siap. Inisialisasi
  denah IT juga menunggu `DOMContentLoaded` agar modul PDF.js selesai dimuat.
- Tidak ada perubahan definisi bisnis, filter, atau data.

## Berkas yang disentuh

- `package.json`, `package-lock.json` - dependensi `alpinejs`, `chart.js`,
  `pdfjs-dist`
- `vite.config.js` - entry `resources/js/pdfjs.js`
- `resources/js/app.js` - impor Alpine.js dan Chart.js
- `resources/js/pdfjs.js` - impor PDF.js dan worker lokal
- `resources/views/layouts/app.blade.php`
- `resources/views/partials/_grafik-perbaikan-script.blade.php`
- `resources/views/reliability/index.blade.php`
- `resources/views/reports/partials/_kartu-teknisi.blade.php`
- `resources/views/dashboard/index.blade.php`
- `resources/views/technicians/partials/_grafik-manhour.blade.php`
- `resources/views/cm/equipment-show.blade.php`
- `resources/views/it-assets/layouts/show.blade.php`
- `docs/01-ikhtisar-dev-prod-dan-deploy.md`

## Bukti uji

- `npm run build`: sukses (app 258,65 kB; pdfjs 306,72 kB; worker 1.087,21 kB).
- `php artisan view:cache`: sukses.
- `php artisan test`: **777 test hijau, 3.908 assertions**.

## Checklist

- [x] Tidak ada lagi URL CDN Alpine.js, Chart.js, atau PDF.js di view.
- [x] Alpine.js dan Chart.js tersedia sebagai variabel global dari bundel Vite.
- [x] Worker PDF.js memakai berkas lokal hasil build.
- [x] Grafik tetap tampil pada dashboard, analitik perbaikan, reliability,
      kartu teknisi, man hour, dan equipment CM.
- [x] Halaman denah IT tetap merender PDF.
- [x] Uji otomatis hijau dan `view:cache` sukses.

## Catatan deploy

- Tidak ada migrasi baru.
- `Deploy-Laporan.bat` menjalankan `npm run build` otomatis lewat
  `-BangunAset`; tidak ada langkah manual tambahan saat deploy.
- Setelah deploy, halaman yang sebelumnya gagal memuat CDN dapat dipakai tanpa
  akses internet ke jsdelivr/unpkg.
