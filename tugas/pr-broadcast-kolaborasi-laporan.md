# Broadcast kolaborasi laporan ke teknisi rekan

## Ringkasan

- Saat laporan disimpan dari wizard dengan kolaborator A/B/C, setiap kolaborator
  mendapat DM Telegram berisi detail pekerjaan dan daftar seluruh kolaborator.
- Pelaksana utama tidak dikirimi broadcast (sudah menerima pesan sukses wizard).
- Kolaborator tanpa `telegram_id` dilewati.
- Saat kolaborator baru ditambahkan lewat halaman edit web, broadcast dikirim
  hanya ke teknisi baru; kolaborator lama tidak di-spam.
- Pengiriman memakai queue `KirimBroadcastKolaborasi` (`tries=3`,
  `backoff=30`) agar wizard tidak tertahan.

## Isi broadcast

Kode laporan, pelaksana, tanggal, klasifikasi/jenis, lokasi/equipment,
deskripsi, durasi, root cause, temuan, daftar semua kolaborator, dan tautan
detail laporan.

## Batasan

- Broadcast hanya pada pembuatan laporan dan penambahan kolaborator baru.
- Edit detail laporan/penghapusan kolaborator belum mengirim broadcast ulang.
- Laporan lama tidak di-broadcast retroaktif.
- Queue worker PROD harus berjalan; jika mati, job menunggu di tabel `jobs`.

## Berkas

- `app/Services/ReportCollaborationNotifier.php` (baru)
- `app/Jobs/KirimBroadcastKolaborasi.php` (baru)
- `app/Services/Telegram/Traits/WizardReportSaverTrait.php`
- `app/Http/Controllers/ReportCollaborationController.php`
- `tests/Feature/ReportCollaborationBroadcastTest.php` (baru)
- `docs/24-broadcast-kolaborasi.md`, `AGENTS.md`
- `tugas/erik.md`

## Uji

- `php artisan test --filter=ReportCollaborationBroadcastTest`: 3 test hijau,
  11 assertion.
- Seluruh suite: 697 test hijau, 3.435 assertion.
- Tidak ada migrasi baru.
