# Card alat dapat diklik ke halaman detail dan collapse rak

## Ringkasan

- Seluruh area card alat pada dashboard admin `/tools` dan halaman `/alat`
  sekarang dapat diklik menuju halaman detail alat. Klik pada tombol/form di
  dalam card (Pinjam, Pinjamkan, Kembalikan) tidak memicu navigasi.
- Halaman detail alat `/alat/{id}` menjadi pusat aksi alat: selain log
  peminjaman, administrator mendapat tombol **Edit Alat** (modal yang sama
  dengan dashboard) dan **Hapus** selama alat tidak sedang dipinjam.
- Edit dan hapus dihapus dari card dashboard agar card tetap ringkas; card
  hanya menampilkan status, peminjam, tombol Pinjamkan, dan petunjuk klik.
- Collapse kini tersedia untuk **Lokasi dan Rak** di dashboard admin,
  dashboard teknisi/keeper `/alat`, dan form token. Rak sebelumnya hanya
  heading biasa.
- Collapse dioptimalkan dengan satu script bersama
  (`tools/partials/_collapse-script.blade.php`):
  - event delegation (satu listener, bukan satu listener per tombol);
  - `aria-expanded` dan `aria-controls` untuk aksesibilitas;
  - tombol **Buka semua** dan **Tutup semua**;
  - filter/pencarian otomatis membuka panel yang diperlukan.
- Navigasi card memakai satu script bersama
  (`tools/partials/_card-klik-script.blade.php`) yang mendukung klik dan
  keyboard Enter/Space serta mengabaikan elemen interaktif di dalam card.

## Berkas yang disentuh

- `resources/views/tools/index.blade.php` (card klik, collapse rak, hapus
  edit/hapus dari card)
- `resources/views/alat/index.blade.php` (card klik, collapse bersama)
- `resources/views/alat/show.blade.php` (edit/hapus admin)
- `resources/views/tools/partials/_form-pinjam.blade.php` (collapse bersama)
- `resources/views/tools/partials/_collapse-script.blade.php` (baru)
- `resources/views/tools/partials/_card-klik-script.blade.php` (baru)
- `app/Http/Controllers/AlatSayaController.php` (data edit modal)
- `app/Http/Controllers/ToolController.php` (hapus diarahkan ke dashboard)
- `tests/Feature/ManagementToolsTest.php`, `tests/Feature/AlatSayaTest.php`
- `docs/33-management-tools.md`

## Bukti uji

- `php artisan test` seluruh suite: **844 test hijau, 4.270 assertions**.
- `php artisan view:cache`: sukses.
- `npm run build`: sukses.
- Tidak ada migrasi baru.

## Checklist

- [x] Seluruh card dapat diklik menuju halaman detail.
- [x] Tombol/form di dalam card tidak memicu navigasi card.
- [x] Halaman detail menampilkan log dan edit/hapus untuk administrator.
- [x] Card dashboard tidak lagi memuat tombol Edit/Hapus.
- [x] Rak dapat di-collapse di dashboard admin.
- [x] Lokasi dan rak dapat di-collapse di `/alat` dan form token.
- [x] Buka semua/Tutup semua berfungsi.
- [x] Filter otomatis membuka panel yang diperlukan.
- [x] ARIA dan navigasi keyboard card.
- [x] Seluruh uji hijau, `view:cache` dan build sukses.

## Catatan deploy

- Tidak ada migrasi baru; deploy cukup menarik commit lalu menjalankan
  cache/build seperti biasa.
- Tidak ada perubahan perintah bot pada lanjutan ini.
- Berkas kotor sesi lain di copy DEV tetap tidak ikut di-commit.
