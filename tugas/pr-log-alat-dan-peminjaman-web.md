# Log per alat dan peminjaman alat lewat web untuk teknisi/keeper

## Ringkasan

- Setiap unit alat kini punya halaman detail `/alat/{id}` berisi identitas alat,
  foto, status, peminjam aktif/terakhir, dan **log lengkap peminjaman**.
- Log alat bisa difilter **dari tanggal** dan **sampai tanggal**, dengan
  pagination 20 baris.
- Setiap baris log menampilkan **durasi peminjaman** (hari/jam/menit). Pinjaman
  aktif dihitung sampai waktu sekarang dan ditandai "(masih dipinjam)".
- Halaman Riwayat Peminjaman admin mendapat tombol **Log Alat** per baris menuju
  halaman detail alat tersebut. Card di dashboard admin juga menautkan nama alat
  dan tombol Log ke halaman yang sama.
- Halaman web baru `/alat` untuk akun teknisi/keeper:
  - teknisi aktif dapat **meminjam alat untuk dirinya sendiri** dan
    mengembalikannya;
  - **tool keeper/administrator** dapat memakai tombol **Pinjamkan** dengan
    pemilih peminjam Teknisi (search/collapse) atau Non-Teknisi (nama +
    departemen);
  - keeper/administrator melihat tabel **Pinjaman Aktif (Semua)** dan dapat
    mengembalikan pinjaman siapa pun;
  - pengembalian tetap memeriksa kepemilikan untuk teknisi biasa.
- Hak aksi diperiksa di controller. Halaman `/alat` sengaja berada di luar
  modul hak akses dan middleware hanya-baca karena dipakai semua teknisi.
- Menu Telegram `/pakai`, `/pinjam`, dan `/selesai` sudah terdaftar di
  `TelegramService::MENU_COMMANDS`; menu PROD sudah disinkronkan. Test menu
  ditambah agar ketiga command tidak hilang saat ada perubahan berikutnya.

## Berkas yang disentuh

- `app/Http/Controllers/AlatSayaController.php` (baru)
- `app/Models/ToolLoan.php` (durasi menit/teks)
- `routes/web.php` (route `alat.*`)
- `config/akses.php` (`alat.*` di `route_bebas_modul`)
- `resources/views/alat/index.blade.php`, `resources/views/alat/show.blade.php`
- `resources/views/tools/index.blade.php` (tautan log)
- `resources/views/tools/riwayat.blade.php` (tombol Log Alat)
- `resources/views/tools/partials/_modal-pinjam.blade.php` (aksi bisa diarahkan
  ke `alat.pinjamkan`)
- `resources/views/layouts/app.blade.php` (menu Peminjaman Alat untuk akun
  teknisi)
- `tests/Feature/AlatSayaTest.php` (baru)
- `tests/Feature/TelegramMenuTest.php` (assert command alat)
- `docs/33-management-tools.md`, `AGENTS.md`

## Bukti uji

- `php artisan test` seluruh suite: **841 test hijau, 4.255 assertions**.
- `php artisan view:cache`: sukses.
- `npm run build`: sukses.
- Tidak ada migrasi baru pada lanjutan ini.

## Checklist

- [x] Halaman detail/log per alat dengan filter tanggal.
- [x] Durasi tiap peminjaman dan penanda pinjaman aktif.
- [x] Tombol Log Alat di Riwayat Peminjaman admin.
- [x] Tautan log dari card dashboard admin.
- [x] Teknisi dapat meminjam dan mengembalikan lewat web.
- [x] Keeper dapat meminjamkan lewat web untuk teknisi/non-teknisi.
- [x] Keeper/admin dapat mengembalikan pinjaman siapa pun; teknisi hanya miliknya.
- [x] Pinjaman Saya dan Pinjaman Aktif (Semua) tampil di `/alat`.
- [x] Menu Telegram berisi `/pakai`, `/pinjam`, `/selesai` dan diuji.
- [x] Seluruh uji hijau, `view:cache` dan build sukses.

## Catatan deploy

- Tidak ada migrasi baru; deploy cukup menarik commit dan menjalankan cache/build
  seperti biasa.
- Menu Telegram PROD sudah disinkronkan dengan `telegram:set-commands`;
  setelah deploy ulang, sinkronkan lagi bila command berubah.
- Akun keeper yang ingin meminjamkan lewat web harus terhubung ke teknisi yang
  masih ditandai **Tool Keeper**.
- Berkas kotor sesi lain di copy DEV tetap tidak ikut di-commit.
