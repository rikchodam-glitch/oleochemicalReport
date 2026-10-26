# Request Spare Part (Bot Telegram)

> Bagian dari dokumentasi proyek di folder `docs`.
> Peta dokumen: `AGENTS.md` di akar repo.

## Ringkasan

Fitur request spare part memungkinkan seluruh teknisi aktif (reguler maupun
shift) mengajukan permintaan lewat bot Telegram dengan perintah `/req`.
Staff CM/PM memproses request di tab **Request Spare Part** pada halaman
Sparepart. Fitur ini TERPISAH dari modul Integrasi PR/PO yang mengimpor berkas
stok/PR/PO (tabel `sp_material`); request disimpan di tabel `sp_requests`.

## Alur status

| Status | Arti | Aksi berikutnya |
|---|---|---|
| `menunggu` | Request baru dari teknisi | Staff CM/PM menyetujui atau menolak |
| `disetujui` | Disetujui, menunggu nomor PR | Staff CM/PM mengisi nomor PR |
| `kejar_po` | Nomor PR sudah ada | Staff CM/PM mengisi nomor PO |
| `kejar_delivery` | Nomor PO sudah ada | Staff CM/PM menandai barang diterima |
| `selesai` | Barang sudah diterima | Arsip |
| `ditolak` | Request ditolak | Arsip, alasan wajib diisi |

Satu baris request hanya punya satu nomor PR dan satu nomor PO. Perpindahan
status memakai syarat status asal sehingga request tidak bisa melompat tahap.

## Isian request

- Keterangan (wajib)
- Deskripsi/ukuran (opsional)
- Jumlah (wajib, angka > 0)
- Satuan (opsional)
- Foto (opsional, satu foto)

Kode request dibuat dengan format `REQ-YYYYMMDD-XXXX` (nomor urut harian),
mengikuti pola `Report::generateReportCode()`.

## Perintah Telegram `/req`

1. Teknisi mengirim `/req <keterangan>`; jika keterangan kosong, bot meminta
   keterangan lebih dulu.
2. Bot menanyakan deskripsi ukuran (opsional, ketik `-` untuk lewati).
3. Bot menanyakan jumlah (wajib, angka).
4. Bot menanyakan satuan (opsional, ketik `-` untuk lewati).
5. Bot meminta foto (opsional; kirim foto atau ketik `-`).
6. Bot menampilkan ringkasan dan tombol **Kembali** / **Kirim** / **Batalkan**.
   Teks `KEMBALI`, `KIRIM`, atau `BATAL` juga dikenali sebagai fallback jalur
   webhook.
7. Setelah dikonfirmasi, request disimpan dengan status `menunggu`.

### Navigasi dan edit isian

- Setiap langkah setelah keterangan menampilkan tombol **Kembali** dan
  **Batalkan**.
- Tombol **Kembali** mengembalikan teknisi ke langkah sebelumnya TANPA
  menghapus isian yang sudah ada, sehingga keterangan, ukuran, jumlah, satuan,
  atau foto bisa diubah lalu dilanjutkan kembali.
- Prompt langkah yang dibuka ulang menampilkan nilai saat ini agar teknisi tahu
  isian yang akan diubah.
- Kembali dari konfirmasi membuka langkah foto. Jika teknisi memilih melewati
  foto, foto lama yang tersimpan di sesi ikut dihapus.
- Teks `KEMBALI`, `BACK`, atau `UBAH` juga berfungsi sebagai tombol kembali.

State wizard disimpan di cache per chat (`sparepart_request_wizard:<chat_id>`,
TTL 1 jam) dan dibatalkan otomatis setelah 30 menit tanpa jawaban. `/reset`
membatalkan sesi request (dan sesi laporan bila ada). `/req` ditolak sementara
bila wizard laporan sedang aktif.

Foto request disimpan di disk `telegram_photos` pada folder
`sparepart-requests/...`. Foto yang belum tersimpan dihapus saat request
dibatalkan atau sesi kedaluwarsa.

## Halaman web

Tab tingkat atas halaman Sparepart: **Stok & PR/PO** dan **Request Sparepart**.
Tab Request hanya tampil untuk pengguna yang punya modul `sparepart_request`.

Sub-tab:

- **Pengajuan**: filter Menunggu/Disetujui/Ditolak/Semua. Request menunggu
  punya tombol Setujui/Tolak; request disetujui punya input nomor PR; request
  yang belum punya PR punya tombol hapus.
- **Kejar PO**: tabel collapsible **Daftar PR Menunggu PO** di bagian atas
  (input nomor PO per baris), lalu daftar lengkap dengan pencarian/pagination.
- **Kejar Delivery**: daftar PO menunggu barang dan tombol **Barang diterima**.
- **Selesai**: arsip request yang sudah diterima.

### Hapus request

- Request boleh dihapus selama belum punya nomor PR, yaitu status `menunggu`,
  `disetujui`, atau `ditolak`.
- Request yang sudah masuk `kejar_po`, `kejar_delivery`, atau `selesai` tidak
  bisa dihapus karena sudah menjadi riwayat pengadaan.
- Penghapusan hanya bisa dilakukan Staff CM/PM (atau Administrator/Supervisor)
  dan ikut menghapus berkas foto request.
- Tombol hapus berupa ikon tempat sampah di kolom Aksi tab Pengajuan dan
  meminta konfirmasi sebelum menghapus.

Pencarian mencakup kode, keterangan, nama/NIK teknisi, nomor PR, dan nomor PO.
Filter tanggal memakai `created_at`.

## Hak akses

- Modul baru `sparepart_request` (pola route `sparepart.request.*`) mengatur
  akses halaman. Tab Request hanya tampil bila modul ini dimiliki.
- Penyetuju dan pemroses: pengguna dengan role Bagian `cm_pm` dan Group
  `reguler_staff` (di form user tampil sebagai CM/PM - Reguler Staff).
- Administrator dan Supervisor tetap boleh karena akses penuh.
- Kemampuan tulis `sparepart_request` ditambahkan ke Group `reguler_staff` di
  `config/akses.php`. Controller tetap memeriksa Bagian `cm_pm`, sehingga
  Reguler Staff dari Bagian lain tidak bisa menyetujui/mengisi PR/PO.
- Staff CM/PM juga perlu modul `sparepart` (halaman stok) agar bisa membuka
  halaman Sparepart dan melihat tab Request.
- Pengguna yang hanya diberi modul `sparepart_request` bisa melihat daftar,
  tetapi form aksi tetap disembunyikan dan ditolak server bila bukan pemroses.

## Notifikasi Telegram

- Pemohon menerima notifikasi saat request diajukan, disetujui/ditolak, PR
  dicatat, PO dicatat, dan barang diterima.
- Staff CM/PM menerima notifikasi saat request baru masuk, berisi kode request,
  nama/NIK pemohon, keterangan, ukuran, jumlah/satuan, dan status foto.
- Penerima Staff CM/PM: teknisi aktif `section = cm_pm`,
  `group = reguler_staff`, dan punya `telegram_id`. Pemohon dikecualikan.
- Kegagalan kirim Telegram hanya dicatat di log; perubahan data tidak dibatalkan.

## Tabel `sp_requests`

Kolom utama: `kode` (unik), `technician_id`, `keterangan`,
`deskripsi_ukuran`, `jumlah`, `satuan`, `foto_path`, `status`,
`approved_by/approved_at`, `rejected_by/rejected_at/alasan_tolak`,
`pr_no/pr_by/pr_at`, `po_no/po_by/po_at`, `received_by/received_at`, `catatan`,
timestamp.

Relasi ke `technicians` dan `users` memakai `nullOnDelete` agar riwayat tetap
ada bila akun/teknisi dihapus.

## Berkas

- `database/migrations/2026_10_24_000001_create_sp_requests_table.php`
- `app/Models/SpRequest.php`
- `app/Services/Sparepart/SparepartRequestService.php`
- `app/Services/Sparepart/SparepartRequestNotifier.php`
- `app/Services/Telegram/SparepartRequestWizardService.php`
- `app/Http/Controllers/SparepartRequestController.php`
- `resources/views/sparepart/request/index.blade.php` + partial
- `resources/views/sparepart/partials/_tabs.blade.php`
- `config/akses.php`, `app/Models/User.php`
- `app/Console/Commands/PollTelegramUpdates.php`,
  `app/Console/Commands/Traits/TelegramMessageHandlerTrait.php`
- `app/Http/Controllers/TelegramWebhookController.php`
- `app/Services/TelegramService.php`,
  `app/Services/Telegram/PhotoStorageService.php`
- `routes/web.php`
- `resources/views/layouts/partials/mode-hanya-baca.blade.php`
- `tests/Feature/SparepartRequestTelegramTest.php`
- `tests/Feature/SparepartRequestWebTest.php`

## Uji

- `php artisan test --filter=SparepartRequest`: **28 test hijau,
  103 assertions**.
- Seluruh suite: **766 test hijau, 3.780 assertions**.
- `php artisan view:cache` sukses.
- Migrasi DEV `2026_10_24_000001_create_sp_requests_table` sudah dijalankan.

## Checklist deploy

1. Jalankan migrasi (`php artisan migrate`).
2. Sinkronkan menu bot (`telegram:set-commands` atau tombol di halaman Bot)
   supaya `/req` muncul di menu.
3. Di **Hak Akses Bagian**, centang modul **Integrasi PR/PO Spare Part** dan
   **Request Spare Part** untuk CM/PM - Reguler Staff.
4. Pastikan akun Staff CM/PM terhubung ke teknisi Bagian CM/PM dengan Group
   Reguler Staff dan punya `telegram_id` bila ingin menerima notifikasi.
5. Setelah deploy, uji `/req` dari satu teknisi lalu proses di tab Request.

## Batasan versi ini

- Belum ada edit request atau pembatalan oleh teknisi; hapus hanya oleh
  Staff CM/PM dan hanya selama request belum punya PR.
- Belum ada partial delivery; satu tombol Barang diterima menutup request.
- Satu request hanya satu foto dan satu nomor PR/PO.
- Belum terhubung ke kode material/daftar pantau atau equipment.
- Tombol inline pada wizard membutuhkan jalur polling; jalur webhook memakai
  teks KIRIM/BATAL sebagai fallback.
