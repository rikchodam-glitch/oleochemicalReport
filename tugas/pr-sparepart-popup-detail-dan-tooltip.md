# Sparepart: popup detail PR/PO dan tooltip penjelasan

## Ringkasan

- Detail material tidak lagi dibuka sebagai baris panjang di dalam tabel,
  melainkan **popup bertab**: PR (ringkasan + History PR ME5A), PO (ringkasan +
  semua nomor PO), Stok G001, dan MRP.
- Kolom PR/PO di tabel dirampingkan: hanya dokumen terpilih, status singkat,
  jumlah baris/terbuka. Daftar semua nomor dan riwayat pindah ke popup.
- Penjelasan tiap fungsi muncul saat kursor diarahkan (atribut `title`):
  judul kolom, kartu ringkasan, kartu aksi, lencana status/aksi, dan tombol.
- Kolom Purchaser/Vendor hanya menampilkan nilai terpilih; riwayat PO
  sebelumnya/terakhir ada di tab PO.
- Aturan tampilan minimalis dan informatif dicatat di `docs/13` dan rincian
  modul di `docs/15`.

## Bukti

- `/sparepart` HTTP 200; 50 baris + 50 template popup detail.
- Teks `History PR (EXPORT ME5A)` dan tooltip "Klik nomor PR untuk membuka
  History PR di popup" ada di HTML.
- Tidak ada perubahan logika bisnis/agregasi data; hanya tampilan.

## Uji

- `php artisan test --filter=Sparepart`: **42 test hijau, 316 assertions**.
- `php artisan test` (seluruh suite): **734 test hijau, 3.645 assertions**.
- `php artisan view:cache`: sukses.

## Berkas

- `resources/views/sparepart/index.blade.php`
- `tests/Feature/SparepartMultiPrMrpTest.php`
- `tests/Feature/SparepartMrpFilterTest.php`
- `docs/13-nama-sistem-kode-alur-sesi-kebersihan.md`
- `docs/15-modul-spare-part.md`
