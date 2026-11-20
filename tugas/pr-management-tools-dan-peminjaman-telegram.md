# Management Tools: lokasi/rak alat, peminjaman keeper dan teknisi, form token Telegram

## Ringkasan

- Modul baru `/tools` untuk master **Lokasi > Rak > Unit alat**. Satu unit alat
  punya nama, kode unik (mis. BRBT001/BRBT002), satu foto utama, dan status
  `tersedia`/`dipakai`/`rusak`.
- Dashboard menampilkan card 1:1 per unit, dikelompokkan dan dapat di-collapse
  per Lokasi > Rak, dengan pencarian nama/kode yang responsif serta filter
  Lokasi, Rak, dan status.
- Administrator dapat menambah/mengubah/menghapus lokasi, rak, dan alat.
  Lokasi yang masih punya rak, rak yang masih berisi alat, dan alat yang masih
  dipinjam tidak bisa dihapus.
- Peminjaman lewat Telegram:
  - `/pinjam` khusus **tool keeper** (teknisi aktif yang ditandai
    administrator); bot mengirim tautan form keeper yang bisa dipakai beberapa
    peminjaman sampai kedaluwarsa.
  - `/pakai "Nama Tools"` untuk semua teknisi; bot mencari alat, menampilkan
    foto + status tiap unit + peminjam terakhir/pemakai aktif, dan mengirim
    tautan dashboard peminjaman sekali pakai. Bila tidak ditemukan, bot
    menawarkan hierarki tombol inline Lokasi > Rak > Alat.
  - `/selesai` untuk keeper mengirim tautan form pengembalian semua pinjaman;
    untuk teknisi menampilkan daftar pinjaman dengan tombol kembalikan
    satu/semua.
- Keeper tetap teknisi, sehingga bisa meminjam alat untuk dirinya sendiri.
- Peminjam non-teknisi memakai nama bebas + departemen dari master
  `departments`.
- Form token publik mengikuti pola `/laporan`: hash SHA-256, TTL 30 menit,
  identitas dari token, dan sekali pakai untuk token teknisi.
- Notifikasi Telegram dikirim ke peminjam teknisi saat pinjam dan kembali.

## Berkas yang disentuh

- 6 migration `2026_11_20_0000*` (tool_locations, tool_racks, tools,
  tool_loans, tool_tokens, is_tool_keeper).
- Model `ToolLocation`, `ToolRack`, `Tool`, `ToolLoan`, `ToolToken`; tambahan
  `Technician::is_tool_keeper`.
- Service `ToolLendingService`, `ToolTokenService`, `ToolLoanNotifier`,
  `ToolTelegramService`.
- Controller `ToolController`, `ToolLoanController`, `ToolKeeperController`,
  `ToolFormController`.
- View `resources/views/tools/**`.
- `app/Services/TelegramService.php` (`sendPhoto`, `editMessageText`,
  `answerCallbackQuery`, menu command).
- `TelegramMessageHandlerTrait`, `PollTelegramUpdates`,
  `TelegramWebhookController`.
- `config/akses.php`, `routes/web.php`, `resources/views/layouts/app.blade.php`.
- Test `ManagementToolsTest`, `ToolLendingTest`, `ToolFormTest`,
  `ToolTelegramTest`.
- Dokumentasi `docs/33-management-tools.md`, `docs/27-menu-perintah-bot-telegram.md`,
  `AGENTS.md`.

## Bukti uji

- `php artisan test` seluruh suite: **832 test hijau, 4.210 assertions**.
- `php artisan view:cache`: sukses.
- `npm run build`: sukses.
- Migrasi baru berhasil dijalankan di DEV.

## Checklist

- [x] Master Lokasi/Rak/Alat + foto + aturan hapus.
- [x] Dashboard card 1:1, collapse Lokasi > Rak, pencarian dan filter responsif.
- [x] Form Pinjamkan admin dengan pemilih Teknisi (search/collapse) dan
  Non-Teknisi (nama + departemen).
- [x] `/pinjam`, `/pakai`, `/selesai` beserta menu bot baru.
- [x] Hierarki inline Lokasi > Rak > Alat dan fallback webhook.
- [x] Form token keeper multi-pakai dan token teknisi sekali pakai.
- [x] Pengembalian satu/semua beserta verifikasi kepemilikan.
- [x] Satu unit hanya bisa punya satu pinjaman aktif; alat rusak ditolak.
- [x] Riwayat pinjaman + snapshot nama/kode alat.
- [x] Hak akses modul `tools`; non-administrator hanya baca.
- [x] Penunjukan keeper hanya oleh administrator.
- [x] Dokumentasi dan test hijau.

## Catatan deploy

- Deploy lewat `Deploy-Laporan.bat`; enam migrasi berjalan otomatis.
- Jalankan `php artisan telegram:set-commands` (atau tombol **Sinkronkan Menu
  Bot**) supaya `/pakai`, `/pinjam`, dan `/selesai` muncul di menu.
- Beri akses modul **Management Tools** pada halaman Hak Akses Bagian bila akun
  non-administrator perlu melihat dashboard.
- Tandai satu/lebih teknisi aktif sebagai **Tool Keeper** di tab Tool Keeper.
- Pastikan `storage:link` ada dan `APP_URL` PROD memakai domain publik agar
  foto dan tautan form dapat dibuka dari Telegram.
- Uji `/pinjam`, `/pakai`, dan `/selesai` dari satu keeper dan satu teknisi.
- Berkas kotor sesi lain di copy DEV (`.env.example`, `.gitignore`,
  `RENCANA_MODUL_*.md`, `ProcessCmFindingsImport.php`, `_temuan.blade.php`,
  `SiapkanDeployTest.php`, `.kilo/`, `CmDriveCheck.php`) TIDAK ikut di-commit.
