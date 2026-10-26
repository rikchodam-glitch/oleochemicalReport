# Request spare part: tombol kembali tiap tahap dan menu /req

## Ringkasan

- `/req` dipastikan terdaftar di menu command bot Telegram
  (`TelegramService::MENU_COMMANDS`) dan dijaga oleh tes menu.
- Wizard request sekarang menampilkan tombol **Kembali** dan **Batalkan** di
  setiap langkah setelah keterangan.
- Konfirmasi menampilkan **Kembali**, **Kirim**, dan **Batalkan**.
- Tombol Kembali mengembalikan ke langkah sebelumnya tanpa menghapus isian,
  sehingga keterangan, ukuran, jumlah, satuan, atau foto bisa diedit lalu
  dilanjutkan kembali.
- Prompt langkah yang dibuka ulang menampilkan nilai saat ini.
- Kembali dari konfirmasi membuka langkah foto. Jika teknisi melewati foto,
  foto lama yang tersimpan di sesi ikut dihapus.
- Teks `KEMBALI`, `BACK`, atau `UBAH` juga berfungsi sebagai tombol kembali,
  sehingga jalur webhook tetap bisa dipakai.

## Berkas yang disentuh

- `app/Services/Telegram/SparepartRequestWizardService.php`
- `tests/Feature/SparepartRequestTelegramTest.php`
- `tests/Feature/TelegramMenuTest.php`
- `docs/27-menu-perintah-bot-telegram.md`
- `docs/30-request-sparepart.md`

## Bukti uji

- `php artisan test --filter=SparepartRequest`: **28 test hijau,
  103 assertions**.
- Seluruh suite: **766 test hijau, 3.780 assertions**.
- `php artisan view:cache` sukses.

## Checklist

- [x] `/req` ada di daftar menu command bot.
- [x] Tombol Kembali tersedia di ukuran, jumlah, satuan, foto, dan konfirmasi.
- [x] Kembali tidak menghapus isian yang sudah diisi.
- [x] Perubahan ukuran dan jumlah tersimpan pada request akhir.
- [x] Kembali dari konfirmasi lalu melewati foto menghapus foto lama.
- [x] Teks KEMBALI/BACK/UBAH berfungsi.
- [x] Uji otomatis hijau dan `view:cache` sukses.

## Catatan deploy

- Tidak ada migrasi baru.
- Setelah deploy, sinkronkan menu bot agar `/req` dan deskripsi terbaru muncul
  di klien Telegram.
