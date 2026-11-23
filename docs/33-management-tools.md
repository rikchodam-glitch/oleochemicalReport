# Management Tools dan Peminjaman Alat

Fitur ini mencatat master alat beserta lokasi/rak penyimpanannya dan transaksi
peminjaman/pengembalian lewat bot Telegram. Aturan bisnis dikonfirmasi user
20/11/2026.

## Master data

- **Lokasi** (`tool_locations`): nama lokasi fisik buatan administrator,
  terpisah dari `functional_locations`. Nama unik, punya keterangan dan status
  aktif.
- **Rak** (`tool_racks`): selalu berada di bawah satu lokasi. Nama rak unik di
  dalam lokasinya.
- **Jenis** (`tool_types`): kategori bebas buatan administrator (mis. Bor,
  Kunci, Alat Ukur). Nama unik dan dapat dinonaktifkan.
- **Kelompok** (`tool_groups`): menaungi beberapa unit dengan nama yang sama
  (mis. Bor Batrai berisi BRBT001 dan BRBT002). Kelompok punya jenis dan
  keterangan; nama kelompok unik. Mengganti nama kelompok ikut memperbarui
  nama unit agar pencarian tetap konsisten.
- **Unit alat** (`tools`): satu baris = satu unit fisik dan wajib memilih
  kelompok. `code` wajib unik per unit. Setiap unit punya satu foto utama
  opsional.

Status unit: `tersedia`, `dipakai`, `rusak`. Status `dipakai` hanya diubah oleh
layanan peminjaman; `rusak` diatur administrator saat unit tidak sedang
dipinjam.

Aturan hapus:

- Lokasi tidak bisa dihapus bila masih punya rak.
- Rak tidak bisa dihapus bila masih berisi alat.
- Jenis tidak bisa dihapus bila masih dipakai kelompok.
- Kelompok tidak bisa dihapus bila masih berisi unit.
- Unit alat tidak bisa dihapus bila masih dipinjam/menunggu diterima.
- Riwayat pinjaman menyimpan salinan nama/kode alat sehingga tetap terbaca
  setelah unit dihapus (`tool_id` menjadi null).

## Halaman web `/tools`

Modul hak akses `tools` (grup menu **Menu Utama**). Penulisan hanya untuk
administrator; akun lain yang diberi akses hanya bisa membaca (middleware
`hanya_baca`).

Tab:

1. **Dashboard Tools**
   - Ringkasan total/tersedia/dipakai/rusak/barang kembali.
   - Pencarian pintar dengan dropdown saran (kelompok, kode unit, jenis,
     lokasi, rak) serta filter Lokasi, Rak, Jenis, Kelompok, dan status.
   - Card ringkas per unit (thumbnail kecil + teks), dikelompokkan dan dapat
     di-collapse per **Lokasi dan Rak**. Tersedia **Buka semua/Tutup semua**.
   - Seluruh area card dapat diklik menuju halaman detail alat. Tombol aksi di
     dalam card tidak memicu navigasi.
   - Card menampilkan kelompok, kode, jenis, status, peminjam, dan tombol
     Pinjamkan untuk administrator.
   - Edit dan hapus alat dilakukan dari halaman detail alat.
2. **Lokasi & Rak** - CRUD lokasi dan rak, termasuk aturan hapus.
3. **Jenis & Kelompok** - CRUD jenis dan kelompok alat dengan aturan hapus.
4. **Barang Kembali** - daftar pengajuan pengembalian yang menunggu diterima,
   dengan tombol **Terima** dan filter.
5. **Riwayat Peminjaman** - filter status/tanggal/lokasi/rak dan pencarian,
   tombol Kembalikan per pinjaman.
6. **Tool Keeper** - daftar teknisi aktif ber-`telegram_id`; administrator
   dapat menjadikan/melepas keeper. Hanya administrator yang boleh mengubah.

Form Pinjamkan di dashboard memakai pemilih peminjam:

- **Teknisi**: pencarian nama/NIK responsif dan daftar dikelompokkan per
  Bagian; hasil pencarian otomatis membuka grup.
- **Non-Teknisi**: nama bebas + departemen dari master `departments`.

## Halaman web teknisi/keeper `/alat`

Halaman ini bisa dibuka semua akun yang login. Hak aksi diperiksa controller:

- **Teknisi aktif** dapat meminjam satu unit untuk dirinya sendiri dari card
  alat atau halaman detail alat.
- **Tool keeper/administrator** dapat memakai tombol **Pinjamkan** dengan
  pemilih peminjam Teknisi/Non-Teknisi yang sama seperti dashboard admin.
- **Teknisi pemilik** menekan **Kembalikan/Ajukan Kembali** untuk mengajukan
  pengembalian; alat tetap `dipakai` sampai keeper menerimanya.
- **Keeper/administrator** dapat langsung menerima pengembalian dari halaman
  **Barang Kembali** atau dari aksi web.
- Bagian **Pinjaman Saya** menampilkan pinjaman aktif/pengajuan yang menunggu
  diterima; keeper/administrator juga melihat **Pinjaman Aktif (Semua)** dan
  tautan **Barang Kembali** dengan jumlah pengajuan.
- Halaman ini berada di luar modul hak akses dan middleware hanya-baca; hak
  aksi tidak bergantung pada modul `tools`.

## Log per alat `/alat/{id}`

Setiap unit alat punya halaman detail yang dapat dibuka dari card dashboard,
halaman `/alat`, atau tombol **Log Alat** di Riwayat Peminjaman admin. Halaman
ini menampilkan:

- identitas alat, foto, lokasi/rak, status, dan peminjam aktif/terakhir;
- aksi pinjam/pinjamkan/kembalikan sesuai hak akun;
- administrator dapat **Edit Alat** (modal yang sama dengan dashboard) dan
  **Hapus** selama alat tidak sedang dipinjam;
- log lengkap pinjaman dengan filter **dari tanggal** dan **sampai tanggal**,
  pagination 20 baris;
- **durasi** tiap pinjaman (hari/jam/menit); pinjaman aktif dihitung sampai
  waktu sekarang dan ditandai "(masih dipinjam)".

## Bot Telegram

### `/pinjam` (tool keeper)

1. Hanya teknisi aktif dengan `technicians.is_tool_keeper = true`.
2. Bot membuat token `keeper_borrow` berlaku 30 menit dan mengirim tautan form.
3. Tautan keeper bisa dipakai beberapa peminjaman sampai kedaluwarsa.
4. Keeper tetap teknisi, jadi boleh meminjam alat untuk dirinya sendiri lewat
   `/pakai` atau memilih dirinya pada form keeper.

### `/pakai "Nama Tools"` (semua teknisi)

1. Bot mencari nama/kode alat (LIKE). Hasil dikelompokkan per nama + rak,
   maksimal 3 grup, dan menampilkan:
   - foto unit (bila ada),
   - jumlah unit tersedia/total,
   - kode, status tiap unit,
   - peminjam terakhir (bila tersedia) atau pemakai aktif (bila dipakai),
   - tombol URL **Buka Dashboard Peminjaman**.
2. Bila tidak ditemukan, bot menawarkan hierarki tombol inline
   Lokasi > Rak > Alat. Tombol hierarki hanya dilayani jalur polling.
3. Tautan dashboard teknisi sekali pakai (30 menit). Form menampilkan card alat
   yang sama dengan dashboard admin dan memilih satu unit per transaksi.
4. Peminjaman self-service langsung tercatat (`handled_by_type = self`) dan
   unit langsung berstatus `dipakai`.

Jalur webhook tidak menerima callback; `/pakai` di webhook langsung mengirim
tautan dashboard.

### `/selesai`

- **Keeper**: bot mengirim tautan **Barang Kembali** (multi-pakai sampai
  kedaluwarsa) berisi pengajuan yang menunggu diterima serta pinjaman aktif.
- **Teknisi non-keeper**: bot menampilkan daftar pinjaman yang belum diajukan
  dengan tombol Kembalikan per alat dan Kembalikan semua. Aksi ini hanya
  **mengajukan** pengembalian; alat tetap dipakai sampai keeper menekan
  Terima.
- Bila pinjaman lebih dari 10 atau jalur webhook, bot mengirim tautan form
  sekali pakai.

Callback pengembalian memverifikasi pemilik pinjaman dari `callback.from.id`,
bukan dari chat.

### Notifikasi

- Peminjam teknisi menerima konfirmasi Telegram saat pinjaman dibuat (termasuk
  saat keeper meminjamkan untuknya) dan saat alat benar-benar diterima kembali.
- Saat teknisi mengajukan pengembalian, semua keeper aktif menerima notifikasi
  berisi tombol inline **Terima** dan tombol **Buka Dashboard** (token keeper).
- Kegagalan kirim Telegram hanya dicatat di log dan tidak membatalkan transaksi.

## Token form publik

Mengikuti pola form laporan:

- Token asli hanya ada di tautan; database menyimpan hash SHA-256.
- Masa berlaku 30 menit.
- `keeper_borrow` dan `keeper_return` multi-pakai; `technician_borrow` dan
  `technician_return` sekali pakai (`used_at` diisi setelah transaksi).
- Setiap aksi memeriksa ulang bahwa pemilik token masih teknisi aktif; token
  keeper juga memeriksa `is_tool_keeper` masih aktif.
- Identitas peminjam teknisi selalu dari token, bukan input form.
- Route publik memakai `throttle:60,1`.

## Aturan transaksi

- Satu unit hanya boleh punya satu pinjaman aktif (`returned_at` null).
  Pemeriksaan dilakukan dalam transaksi + `lockForUpdate()` pada baris alat.
- Unit `rusak` atau sudah dipakai ditolak.
- **Pengajuan pengembalian** mengisi `return_requested_at`; status unit tetap
  `dipakai`.
- **Penerimaan pengembalian** (keeper/admin) mengisi `returned_at` dan
  mengubah unit kembali `tersedia` (hanya bila status unit masih `dipakai`).
- Pengembalian kedua tidak mengubah waktu kembali.
- Kode pinjaman: `PJM-YYYYMMDD-XXXX`.

## Tabel

- `tool_types`, `tool_groups`, `tool_locations`, `tool_racks`, `tools`,
  `tool_loans`, `tool_tokens`.
- `tools.tool_group_id` (nullable, backfill dari nama alat lama).
- `technicians.is_tool_keeper` (boolean, default false).

`tool_loans` menyimpan snapshot `tool_name`/`tool_code`, data peminjam
(`borrower_type`, `technician_id`, `borrower_name`, `borrower_department_id`,
`borrower_department_nama`), waktu pinjam/kembali, pengajuan pengembalian
(`return_requested_at`, `return_requested_by_type`,
`return_requested_by_technician_id`, `return_requested_by_user_id`), pelaku
penyerahan dan penerimaan, serta catatan.

## Hak akses

- Modul `tools` pada `config/akses.php`, pola route `tools.*`, menu
  `tools.index`. Penulisan halaman master hanya administrator
  (`role_penulis`).
- Halaman `/alat`, `/alat/{id}`, `/alat/kembali`, dan endpoint saran `/alat/cari`
  sengaja berada di luar modul/hanya-baca; route-nya terdaftar di
  `route_bebas_modul` dan hak aksi diperiksa controller (teknisi aktif untuk
  meminjam, keeper/admin untuk meminjamkan dan menerima pengembalian).
- Keeper tidak butuh akun web; aksinya bisa lewat token Telegram atau halaman
  `/alat` bila akun web-nya terhubung ke teknisi keeper.
- Penunjukan keeper tetap memeriksa `User::isAdmin()` di controller, sehingga
  supervisor (akses penuh tetapi hanya baca) tidak bisa mengubah keeper.
- Route form token berada di luar middleware panel dan tidak butuh login.

## Berkas utama

- `database/migrations/2026_11_20_0000*_create_tool_*_table.php`
- `database/migrations/2026_11_20_000006_add_is_tool_keeper_to_technicians_table.php`
- `app/Models/ToolType.php`, `ToolGroup.php`, `ToolLocation.php`,
  `ToolRack.php`, `Tool.php`, `ToolLoan.php`, `ToolToken.php`
- `app/Services/PencarianToolService.php`, `ToolLendingService.php`,
  `ToolTokenService.php`, `ToolLoanNotifier.php`, `ToolTelegramService.php`
- `app/Http/Controllers/ToolController.php`, `ToolLoanController.php`,
  `ToolKeeperController.php`, `ToolFormController.php`, `AlatSayaController.php`,
  `ToolSearchController.php`
- `resources/views/tools/**` (termasuk `partials/_kartu-ringkas.blade.php`,
  `partials/_collapse-script.blade.php`, `partials/_card-klik-script.blade.php`,
  `tools/katalog.blade.php`, `tools/returns.blade.php`),
  `resources/views/alat/**`
- `app/Models/ToolLoan.php` (durasi peminjaman & status pengembalian)
- `config/pencarian_tools.php`
- `app/Services/TelegramService.php` (sendPhoto, editMessageText,
  answerCallbackQuery, menu command)
- `app/Console/Commands/Traits/TelegramMessageHandlerTrait.php`
- `app/Console/Commands/PollTelegramUpdates.php`
- `app/Http/Controllers/TelegramWebhookController.php`
- `config/akses.php`, `routes/web.php`, `resources/views/layouts/app.blade.php`
- `tests/Feature/ManagementToolsTest.php`, `ToolLendingTest.php`,
  `ToolFormTest.php`, `ToolTelegramTest.php`, `AlatSayaTest.php`,
  `ToolCatalogSearchTest.php`, `ToolReturnApprovalTest.php`

## Uji

- `ManagementToolsTest`, `ToolLendingTest`, `ToolFormTest`, `ToolTelegramTest`,
  `AlatSayaTest`, `ToolCatalogSearchTest`, `ToolReturnApprovalTest`,
  `TelegramMenuTest` hijau.
- Seluruh suite: 894 test hijau, 4.616 assertion.
- `php artisan view:cache` sukses dan `npm run build` sukses.

## Checklist deploy

1. Deploy lewat `Deploy-Laporan.bat`; migrasi baru berjalan otomatis.
2. Sinkronkan menu bot (`telegram:set-commands` atau tombol di halaman Bot
   Telegram) supaya `/pakai`, `/pinjam`, dan `/selesai` muncul.
3. Beri akses modul **Management Tools** pada halaman Hak Akses Bagian bila
   akun non-administrator perlu melihat dashboard.
4. Buat **Jenis** dan **Kelompok**, lalu pilih kelompok pada setiap unit alat.
   Data lama otomatis mendapat kelompok dari nama alat dan jenis **Umum**.
5. Tandai satu/lebih teknisi aktif sebagai **Tool Keeper** di tab Tool Keeper.
6. Pastikan `storage:link` ada dan `APP_URL` PROD memakai domain publik supaya
   foto/link form dapat dibuka dari Telegram.
7. Uji `/pinjam`, `/pakai`, dan `/selesai` dari satu keeper dan satu teknisi.
8. Uji pengembalian: teknisi menekan Kembalikan, keeper menerima lewat
   notifikasi Telegram atau halaman Barang Kembali.
9. Untuk pemakaian web: buka `/alat` dengan akun teknisi (pinjam/kembali) dan
   akun keeper (pinjamkan/menerima). Pastikan akun keeper terhubung ke teknisi
   yang masih ditandai keeper.

## Batasan versi ini

- Satu unit per submit pada form; untuk beberapa alat ulangi aksi (token keeper
  tetap berlaku, teknisi meminta tautan baru lewat `/pakai`).
- Belum ada tenggat pengembalian/penanda terlambat.
- Peminjaman self-service langsung tercatat tanpa persetujuan keeper.
- Pengembalian oleh teknisi **wajib diterima keeper/administrator**; alat tetap
  `dipakai` selama menunggu penerimaan.
- Tautan dashboard keeper dibuat per notifikasi; notifikasi pengembalian baru
  dapat membatalkan tautan keeper lama yang belum dibuka.
- Tombol inline hanya berfungsi pada jalur polling; jalur webhook memakai
  tautan form.
- Bot belum mendukung alur khusus grup Telegram; dipakai di chat pribadi.
