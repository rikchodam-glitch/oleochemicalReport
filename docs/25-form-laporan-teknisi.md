# Form Laporan Web Teknisi

Dokumen ini menjelaskan form laporan web berbasis token Telegram (21/10/2026).

## Alur `/laporan`

1. Teknisi mengirim `/laporan` atau `/laporan <deskripsi>`.
2. Bot membuat token 30 menit, sekali pakai, terikat `technician_id` dan
   `telegram_id`; deskripsi disimpan sebagai prefill.
3. Bot membalas tautan form dengan preview dimatikan.
4. Form terbuka tanpa login; identitas dari token.
5. Teknisi mengisi satu atau beberapa laporan lalu menekan **Kirim semua**.
6. Semua laporan disimpan dalam satu transaksi; token ditandai terpakai.

Perintah `/laporan` ditangani di dua jalur: poller
(`TelegramMessageHandlerTrait`) dan `TelegramWebhookController`. Jalur login
`/laporan-saya/buat` membuat token lalu mengarahkan ke form yang sama.

## Form

- Klasifikasi wajib lebih dulu; pilihan jenis mengikuti klasifikasi:
  - ZPM 1/6 → EI, Mekanik, IT
  - ZPM 3 → CM/PM
  - ZPM 5 → Fabrikasi
  - ZPM 7 → Sipil
  - ZPM 8 → Project
  - UMUM → UMUM
- Server memvalidasi ulang pasangan klasifikasi-jenis.
- Lokasi: PT → Dept → Area → Section (dari `functional_locations` aktif).
  Pengguna boleh berhenti di level mana pun; tanpa alat dianggap pekerjaan area.
- Equipment type wajib dipilih sebelum alat; tipe berasal dari
  `assets.equipment_type` pada subtree lokasi. Memilih tipe langsung
  menampilkan daftar alat pada tipe/lokasi tersebut.
- Pencarian alat memakai exact/prefix/LIKE + skor `similar_text`.
- Tanpa alat = pekerjaan area; dengan alat = perbaikan equipment.
- `[+Tambah]` menambah alat/lokasi tambahan; dibuat salinan `multi_of`.
- Durasi memakai jam mulai/selesai dan dihitung server menjadi menit.
- Kolaborator otomatis dari group + pencarian manual per Bagian.
- Foto dokumentasi/hygiene memakai beberapa baris input; tombol **+ Tambah
  foto** menambah baris, dan setiap baris bisa memilih beberapa file sekaligus.
  Semua file dipreview sebelum kirim.
- Status on/off: on = `completed`, off = `continue`.
- **Tambah laporan** membuat kartu baru; **Kirim** mengirim maksimal 5 laporan
  dalam satu POST atomik.

## Keamanan dan konsistensi

- Token hanya disimpan sebagai hash SHA-256.
- Tautan lama yang belum dibuka dibatalkan saat token baru dibuat; tautan yang
  sudah dibuka tetap berlaku sampai kedaluwarsa.
- Token dikunci selama transaksi agar double-submit tidak membuat laporan ganda.
- Validasi server memeriksa: jenis sesuai klasifikasi, alat berada dalam subtree
  lokasi, tipe alat sesuai, jam selesai > mulai, minimal satu item, maksimal
  item/laporan, dan file image.
- Foto disimpan ke disk/folder yang sama dengan Telegram; file dibersihkan bila
  transaksi gagal.
- Salinan kolaborator dan multi-alat memakai `ReportCopyService`; man hour hanya
  menghitung laporan induk (baris `multi_of` dikecualikan).
- Setelah commit, `KirimNotifikasiLaporanDiterima` dan
  `KirimBroadcastKolaborasi` masuk queue. Response form tidak menunggu Telegram.
- Foto lebih dari 1,5 MB dikompres di browser sebelum upload; tombol Kirim
  berubah menjadi "Mengirim..." dan mencegah klik ganda.

## Batasan

- Belum ada AI classification/klarifikasi bertahap.
- Belum ada wizard lanjutan laporan (`continue` dari laporan lama).
- Multi-item memakai pilihan lokasi/alat manual; belum ada pencarian global
  lintas PT.
- Maksimal 5 laporan dan 5 item per laporan per sesi.

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

## Uji

- `ReportFormTest`: 15 test hijau, 59 assertion.
- Seluruh suite: 716 test hijau, 3.514 assertion.
- `view:cache` sukses.
