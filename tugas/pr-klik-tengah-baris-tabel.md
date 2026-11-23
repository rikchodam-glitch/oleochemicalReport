# Klik tengah baris tabel membuka tab baru

## Ringkasan perubahan

- Baris tabel halaman **Laporan** dan **Assets Management** kini mendukung
  **klik tengah (tekan roda gulir)** untuk membuka halaman tujuan di tab baru.
- Implementasi memakai atribut `data-buka-tab="<url>"` per baris dan satu
  script bersama `resources/views/partials/_buka-tab-tengah.blade.php`
  (`auxclick`, `button === 1`, event delegation).
- Klik kiri dan tombol keyboard Enter pada baris tetap seperti sebelumnya.
- Tautan/tombol di dalam baris tidak diambil alih: klik tengah pada tautan
  tetap membuka tautan itu secara native.
- Mode autoscroll browser saat roda gulir ditekan di baris dicegah
  (`mousedown` + `preventDefault`).
- Script dipasang sekali per halaman (penanda
  `window.__bukaTabTengahTerpasang`), aman untuk baris hasil render apa pun.

## Berkas

- `resources/views/partials/_buka-tab-tengah.blade.php` (baru)
- `resources/views/reports/index.blade.php`
- `resources/views/assets/index.blade.php`
- `tests/Feature/BukaTabTengahTest.php` (baru)
- `docs/36-klik-tengah-baris.md` (baru), `AGENTS.md`

## Uji

- Uji baru: **2 test, 10 assertion**.
- Seluruh suite: **882 test hijau, 4.559 assertion**.
- `php artisan view:cache` sukses.
- Verifikasi DEV: halaman `/reports?search=pompa&cakupan=semua` dan `/assets`
  memuat `data-buka-tab`, script `auxclick`, dan penanda pemasangan.

## Catatan

- Tidak ada migrasi baru dan tidak ada perubahan data.
- Klik tengah hanya aktif pada baris yang memang punya tujuan (atribut
  `data-buka-tab`); halaman lain bisa memakai partial yang sama.
- Berkas kotor milik sesi lain sengaja tidak ikut di-commit.
- Sesudah merge: `tools\kembalikan-ke-main.ps1` lalu `Deploy-Laporan.bat` di
  komputer integrator.
