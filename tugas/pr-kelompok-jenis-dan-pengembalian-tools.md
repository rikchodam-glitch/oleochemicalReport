# Management Tools: kelompok/jenis alat, card kompak, pencarian pintar, dan penerimaan pengembalian

## Ringkasan

1. **Kelompok alat** - ditambahkan master `tool_groups`; BRBT001/BRBT002 masuk
   ke kelompok Bor Batrai. Unit alat kini wajib memilih kelompok; nama unit
   mengikuti nama kelompok.
2. **Card kompak** - card diubah menjadi thumbnail kecil + teks (kelompok, kode,
   jenis, status, peminjam) sehingga desktop dapat menampilkan sekitar 9 card
   dalam 3 baris dan mobile tetap ringkas. Buka/tutup semua panel tetap tersedia.
3. **Pengembalian harus diterima keeper**:
   - teknisi menekan Kembalikan/Ajukan Kembali -> `return_requested_at` diisi,
     alat tetap `dipakai`;
   - keeper/administrator menerima lewat halaman **Barang Kembali** atau token
     keeper;
   - semua keeper mendapat notifikasi Telegram dengan tombol inline **Terima**
     dan tombol **Buka Dashboard**;
   - notifikasi penerimaan dikirim ke peminjam setelah alat benar-benar
     diterima.
4. **Pencarian pintar** - kotak pencarian memakai pola Asset/Reports
   (`PencarianKataKunci`, saran dropdown, skor relevansi). Mencakup kelompok,
   kode unit, jenis, lokasi, rak, dan catatan. Filter Lokasi, Rak, Jenis,
   Kelompok, dan status tetap dipertahankan.
5. **Jenis & Kelompok** - tab baru untuk CRUD jenis dan kelompok, dengan aturan
   hapus: jenis tidak boleh dihapus bila dipakai kelompok; kelompok tidak boleh
   dihapus bila masih berisi unit.

## Perubahan data

- Tabel baru: `tool_types`, `tool_groups`.
- `tools.tool_group_id` (backfill dari nama alat lama; jenis awal **Umum**).
- `tool_loans`: `return_requested_at`,
  `return_requested_by_type`, `return_requested_by_technician_id`,
  `return_requested_by_user_id`.
- Status pinjaman: `Dipinjam`, `Menunggu diterima`, `Dikembalikan`.

## Halaman/tab

- Dashboard Tools (card kompak + pencarian pintar + filter).
- Lokasi & Rak.
- **Jenis & Kelompok** (baru).
- **Barang Kembali** (baru, tombol Terima).
- Riwayat Peminjaman.
- Tool Keeper.
- `/alat` teknisi/keeper: pencarian pintar, Pinjaman Saya, Pinjaman Aktif,
  tautan Barang Kembali, dan aksi pengajuan/penerimaan.

## Telegram

- `/selesai` teknisi sekarang **mengajukan** pengembalian; bukan final.
- Keeper menerima notifikasi permintaan kembali dengan tombol **Terima** dan
  tautan dashboard token.
- `/selesai` keeper membuka halaman Barang Kembali (pending + pinjaman aktif).
- Callback `alat:terima:<id>` hanya dilayani keeper aktif.

## Uji

- Seluruh suite: **894 test hijau, 4.616 assertions**.
- `view:cache` dan `npm run build` sukses.
- Test baru: `ToolCatalogSearchTest`, `ToolReturnApprovalTest`; test lama
  diperbarui untuk alur pengajuan/penerimaan.

## Berkas utama

- Migration `2026_11_23_000002` sampai `000005`.
- Model `ToolType`, `ToolGroup`; perubahan `Tool`, `ToolLoan`.
- Service `PencarianToolService`, perubahan `ToolLendingService`,
  `ToolLoanNotifier`, `ToolTelegramService`.
- Controller `ToolController`, `AlatSayaController`, `ToolFormController`,
  `ToolSearchController`.
- View `resources/views/tools/**`, `resources/views/alat/**`,
  `resources/views/partials/_pencarian-entitas.blade.php`.
- `config/pencarian_tools.php`, `config/akses.php`, `routes/web.php`.
- Test `ToolCatalogSearchTest`, `ToolReturnApprovalTest`, dan update test lama.
- Dokumentasi `docs/33-management-tools.md`, `docs/27`, `AGENTS.md`.

## Catatan deploy

- Deploy lewat `Deploy-Laporan.bat`; lima migrasi baru berjalan otomatis.
- Data alat lama otomatis masuk kelompok berdasarkan nama dan jenis **Umum**.
- Buat jenis/kelompok yang diinginkan, lalu sesuaikan kelompok tiap unit.
- Pastikan keeper aktif memiliki Telegram dan terhubung ke teknisi keeper.
- Uji alur: teknisi ajukan kembali -> keeper menerima lewat notifikasi/halaman
  Barang Kembali -> alat kembali tersedia.
- Berkas kotor sesi lain di copy DEV tidak ikut di-commit.
