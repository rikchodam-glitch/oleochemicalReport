# Form laporan web lengkap berbasis `/laporan`

## Ringkasan

- `/laporan` atau `/laporan <deskripsi>` membuat token sekali pakai 30 menit
  dan membalas tautan form; deskripsi menjadi prefill.
- Form tanpa login, identitas dari token; token lama yang belum dibuka
  dibatalkan, preview Telegram dimatikan.
- Klasifikasi ZPM → jenis pekerjaan saling mengunci di UI dan server.
- Hierarki PT → Dept → Area → Section; tipe equipment wajib sebelum alat;
  pencarian alat memakai prefix/LIKE + skor kemiripan.
- Tanpa alat = pekerjaan area; dengan alat = perbaikan equipment.
- `[+Tambah]` untuk multi-alat/lokasi; dibuat salinan `multi_of`.
- Durasi memakai jam mulai/selesai dan dihitung server.
- Kolaborator otomatis group + pencarian manual per Bagian.
- Foto dokumentasi/hygiene multi-baris dengan tombol Tambah foto; setiap baris
  bisa memilih beberapa file dan semua dipreview.
- Status on/off = completed/continue.
- **Tambah laporan** + **Kirim** mendukung maksimal 5 laporan dalam satu POST
  atomik; token dikunci saat transaksi agar tidak ada double-submit.

## Perbaikan UI setelah uji

- Memilih tipe equipment langsung menampilkan daftar alat pada tipe/lokasi tersebut.
- Dokumentasi/hygiene memakai beberapa baris input + tombol **Tambah foto**;
  setiap baris bisa memilih beberapa file dan semua dipreview, tanpa DataTransfer.

## Data & teknis

- Tabel baru `report_tokens`; model `ReportToken`.
- Service baru `ReportFormService` menangani token, validasi, penyimpanan,
  salinan kolaborator/multi-alat, foto, cleanup, dan broadcast.
- Route: `/laporan/form/{token}`, `/laporan/{token}/tipe-alat`,
  `/laporan/{token}/cari-alat`, `/laporan/selesai`.
- Route login `/laporan-saya/buat` sekarang membuat token lalu redirect ke form.
- `/laporan` ditangani di poller dan webhook.

## Batasan

- Belum ada AI classification/klarifikasi bertahap.
- Belum ada wizard lanjutan laporan (`continue` dari laporan lama).
- Maksimal 5 laporan dan 5 item/laporan per sesi.

## Berkas

- `database/migrations/2026_10_21_000002_create_report_tokens_table.php`
- `app/Models/ReportToken.php`
- `app/Services/ReportFormService.php`
- `app/Http/Controllers/ReportFormController.php`
- `app/Console/Commands/Traits/TelegramMessageHandlerTrait.php`
- `app/Http/Controllers/TelegramWebhookController.php`
- `resources/views/reports/form-teknisi.blade.php`,
  `form-selesai.blade.php`, `form-kadaluarsa.blade.php`
- `routes/web.php`, `config/akses.php`
- `tests/Feature/ReportFormTest.php`
- `docs/25-form-laporan-teknisi.md`, `AGENTS.md`
- `tugas/erik.md`

## Uji

- `ReportFormTest`: 13 test hijau, 50 assertion.
- Seluruh suite: 711 test hijau, 3.498 assertion.
- `view:cache` sukses.
