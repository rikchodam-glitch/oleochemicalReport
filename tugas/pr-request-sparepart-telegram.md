# Request Spare Part lewat Bot Telegram

## Ringkasan

- Teknisi aktif (reguler/shift) dapat mengirim `/req <keterangan>` ke bot.
- Wizard menanyakan deskripsi ukuran (opsional), jumlah (wajib), satuan
  (opsional), dan foto (opsional), lalu meminta konfirmasi.
- Request disimpan di tabel baru `sp_requests` dengan status `menunggu`.
- Staff CM/PM mengelola request di tab **Request Spare Part** pada halaman
  Sparepart: setujui/tolak, isi PR, isi PO, lalu tandai barang diterima.
- Notifikasi Telegram dikirim ke pemohon pada setiap tahap; Staff CM/PM
  menerima notifikasi request baru lengkap dengan nama pemohon.

## Alur status

`menunggu` -> `disetujui` -> `kejar_po` -> `kejar_delivery` -> `selesai`.
Request dapat menjadi `ditolak` saat masih `menunggu`.

## Hak akses

- Modul baru `sparepart_request` mengatur tab Request.
- Pemroses: Bagian `cm_pm` + Group `reguler_staff`; Administrator/Supervisor
  tetap bisa karena akses penuh.
- Reguler Staff dari Bagian lain tidak bisa menyetujui/mengisi PR/PO.
- Staff CM/PM juga perlu modul `sparepart` (halaman stok) untuk membuka tab.

## Berkas yang disentuh

- Migrasi: `database/migrations/2026_10_24_000001_create_sp_requests_table.php`
- Model: `app/Models/SpRequest.php`
- Service: `app/Services/Sparepart/SparepartRequestService.php`,
  `app/Services/Sparepart/SparepartRequestNotifier.php`
- Bot: `app/Services/Telegram/SparepartRequestWizardService.php`,
  `app/Console/Commands/PollTelegramUpdates.php`,
  `app/Console/Commands/Traits/TelegramMessageHandlerTrait.php`,
  `app/Http/Controllers/TelegramWebhookController.php`
- Web: `app/Http/Controllers/SparepartRequestController.php`,
  `resources/views/sparepart/request/**`,
  `resources/views/sparepart/partials/_tabs.blade.php`
- Akses: `config/akses.php`, `app/Models/User.php`
- Pendukung: `app/Services/TelegramService.php`,
  `app/Services/Telegram/PhotoStorageService.php`, `routes/web.php`
- Dokumentasi: `docs/30-request-sparepart.md`, `docs/27-...`,
  `docs/15-...`, `AGENTS.md`

## Bukti uji

- `php artisan test --filter=SparepartRequest`: **18 test hijau,
  67 assertions**.
- Seluruh suite: **756 test hijau, 3.744 assertions**.
- `php artisan view:cache` sukses.
- Migrasi DEV `2026_10_24_000001_create_sp_requests_table` sudah dijalankan.

## Checklist

- [x] `/req` berjalan untuk teknisi aktif dan menyimpan request.
- [x] Field opsional bisa dilewati; jumlah divalidasi angka positif.
- [x] Foto request disimpan dan dibersihkan saat batal.
- [x] Staff CM/PM bisa approve/reject/isi PR/isi PO/terima.
- [x] Reguler Staff non-CM/PM ditolak server.
- [x] Tab Request, Kejar PO (collapsible), Kejar Delivery, dan Selesai tampil.
- [x] Notifikasi pemohon dan Staff CM/PM terkirim.
- [x] Uji otomatis hijau dan `view:cache` sukses.

## Catatan deploy

1. Jalankan migrasi.
2. Sinkronkan menu bot agar `/req` muncul.
3. Centang modul `sparepart` dan `sparepart_request` untuk CM/PM - Reguler
   Staff di Hak Akses Bagian.
4. Pastikan akun Staff CM/PM terhubung teknisi CM/PM Reguler Staff dan punya
   `telegram_id` bila ingin menerima notifikasi.
