# Klik Tengah (Roda Gulir) pada Baris Tabel

> Bagian dari dokumentasi proyek di folder `docs`.
> Peta dokumen: `AGENTS.md` di akar repo.
>
> Perubahan 23/11/2026. Tidak ada migrasi baru dan tidak ada perubahan data.

## Aturan

- Baris tabel yang ingin mendukung klik tengah cukup menambahkan atribut
  `data-buka-tab="<url>"`.
- Klik tengah (tekan roda gulir) pada baris tersebut membuka URL di **tab
  baru** (`auxclick`, `button === 1`).
- Klik kiri tetap memakai `onclick` baris seperti sebelumnya (tidak berubah).
- Tautan dan tombol di dalam baris **tidak diambil alih**:
  - klik tengah pada tautan tetap membuka tautan itu secara native,
  - klik tengah pada tombol/input tidak melakukan navigasi baris.
- Mode autoscroll browser saat roda gulir ditekan di baris dicegah
  (`mousedown` button 1 + `preventDefault`).
- Script dipasang sekali per halaman (penanda
  `window.__bukaTabTengahTerpasang`) dan memakai event delegation, sehingga
  baris baru hasil render apa pun tetap bekerja.

## Halaman yang memakai

- **Laporan** (`resources/views/reports/index.blade.php`): setiap baris laporan
  memiliki `data-buka-tab` ke `reports.show`.
- **Assets Management** (`resources/views/assets/index.blade.php`): setiap baris
  aset memiliki `data-buka-tab` ke `assets.show`.

Halaman lain dapat memakai partial yang sama dengan menambahkan atribut
`data-buka-tab` pada barisnya dan meng-include
`resources/views/partials/_buka-tab-tengah.blade.php`.

## Berkas

- `resources/views/partials/_buka-tab-tengah.blade.php` (baru)
- `resources/views/reports/index.blade.php`
- `resources/views/assets/index.blade.php`
- `tests/Feature/BukaTabTengahTest.php` (baru)

## Uji

- `BukaTabTengahTest`: 2 test, 10 assertion. Memastikan baris laporan dan aset
  membawa URL tujuan yang benar, `onclick` lama tetap ada, dan script
  `auxclick`/penanda pemasangan termuat.
- Seluruh suite 23/11/2026: **882 test hijau, 4.559 assertion**.
- `php artisan view:cache` sukses.
