# Pencarian pintar halaman Assets dan Assets IT

## Ringkasan perubahan

- Halaman **Asset Management** dan **IT Assets** mendapat kotak pencarian utama
  di atas halaman, dengan perilaku yang sama seperti pencarian halaman Laporan:
  multi-kata (AND antar kata), prioritas hasil, sinonim ringan, dan dropdown
  saran responsif/informatif.
- Pencarian bersifat **global**: form hanya mengirim kata kunci sehingga filter
  lokasi/tipe/plant/VLAN yang sedang aktif tidak menyembunyikan hasil. Filter
  tetap bisa dipakai di panel Filter dan **membawa kata kunci** lewat isian
  tersembunyi.
- Input pencarian lama di panel Filter dihapus. Panel Filter tidak lagi terbuka
  otomatis hanya karena ada kata kunci.
- **Assets**: kolom yang dicari diperluas ke equipment no, tech ident, deskripsi,
  kode lokasi, tipe, object type, pabrikan, model, relasi PT/departemen/area/
  sub area/FuncLoc, dan perangkat IT cermin. Dropdown saran: Aset, Tipe, Lokasi.
- **IT Assets**: kolom tag, nama, IP, pabrikan, model, serial, plant,
  departemen, lokasi, aplikasi, tipe (kode + label), dan FuncLoc. Label/sinonim
  tipe dikenali (laptop, kamera→CCTV, switch managed). Dropdown saran:
  Perangkat, Tipe, Plant, Lokasi, VLAN.
- Endpoint baru: `GET /assets/cari` dan `GET /it-assets/cari` (login,
  throttle 120/menit, minimal 2 huruf).
- Mode layout CCTV tetap bekerja dengan pencarian; sort kolom dari header tabel
  tetap menang bila dipilih pengguna.

## Berkas utama

- `app/Support/PencarianKataKunci.php` (baru)
- `config/pencarian_entitas.php` (baru)
- `app/Services/PencarianAsetService.php` (baru)
- `app/Services/PencarianItAssetService.php` (baru)
- `app/Http/Controllers/AssetSearchController.php` (baru)
- `app/Http/Controllers/ItAssetSearchController.php` (baru)
- `app/Http/Controllers/AssetController.php`
- `app/Http/Controllers/ItAssetController.php`
- `routes/web.php`
- `resources/views/partials/_pencarian-entitas.blade.php` (baru)
- `resources/views/assets/index.blade.php`
- `resources/views/it-assets/index.blade.php`
- `tests/Feature/AssetSearchTest.php` (baru)
- `tests/Feature/ItAssetSearchTest.php` (baru)
- `docs/35-pencarian-asset-dan-it.md` (baru), `docs/28-...`, `AGENTS.md`

## Uji

- Uji baru: **17 test, 147 assertion**.
- Seluruh suite: **871 test hijau, 4.519 assertion**.
- `php artisan view:cache` sukses.
- Verifikasi DEV nyata: `/assets?search=pompa` (967 aset),
  `/assets/cari?q=cctv` (kelompok aset + tipe), `/it-assets?search=cctv`
  (95 perangkat), `/it-assets/cari?q=kamera` (kelompok perangkat + tipe), dan
  mode layout CCTV dengan pencarian.

## Catatan

- Tidak ada migrasi baru, tidak ada service pencarian eksternal.
- Pencarian mengabaikan filter yang sedang aktif saat kata kunci dikirim;
  ini disengaja agar hasil tidak kosong karena filter lama.
- Berkas kotor milik sesi lain sengaja tidak ikut di-commit.
- Sesudah merge: `tools\kembalikan-ke-main.ps1` lalu `Deploy-Laporan.bat` di
  komputer integrator.
