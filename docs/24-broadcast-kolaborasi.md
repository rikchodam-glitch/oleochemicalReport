# Broadcast Kolaborasi Laporan

Dokumen ini menjelaskan broadcast Telegram ke teknisi kolaborator
(20/10/2026).

## Kapan dikirim

1. **Saat laporan disimpan dari wizard Telegram** dan memiliki kolaborator:
   satu job broadcast dikirim ke semua kolaborator laporan induk.
2. **Saat kolaborator ditambahkan lewat halaman edit web**: job broadcast
   dikirim hanya ke kolaborator baru. Kolaborator lama tidak dikirimi ulang
   agar tidak spam.
3. Laporan induk tanpa kolaborator tidak mengirim broadcast.
4. Edit detail laporan dan penghapusan kolaborator tidak mengirim broadcast
   ulang (belum termasuk).

## Penerima

- Teknisi pemilik baris salinan `collaborator_of` laporan induk.
- Hanya yang punya `telegram_id`.
- Pelaksana utama dikecualikan (dia sudah menerima pesan sukses wizard).
- Nama seluruh kolaborator tetap dicantumkan di pesan, termasuk kolaborator
  yang belum punya Telegram.
- Broadcast dikirim sebagai DM per teknisi, bukan pesan grup.

## Isi pesan

- Judul **BROADCAST KOLABORASI**
- Kode laporan, pelaksana, tanggal
- Klasifikasi dan jenis pekerjaan
- Lokasi/equipment
- Deskripsi pekerjaan
- Durasi
- Root cause dan temuan
- Daftar seluruh kolaborator
- Tautan halaman detail laporan (`reports.show`, perlu login)

Nilai dinamis di-escape karena `TelegramService` memakai parse mode HTML.
Pratinjau tautan dimatikan.

## Teknis

- `App\Services\ReportCollaborationNotifier` menyusun dan mengirim pesan.
- `App\Jobs\KirimBroadcastKolaborasi` menjalankan pengiriman lewat queue
  (`tries=3`, `backoff=30` detik).
- Pemanggil:
  - `WizardReportSaverTrait::saveReport()` setelah salinan kolaborasi dibuat.
  - `ReportCollaborationController::store()` setelah salinan kolaborator baru
    dibuat.
- Job hanya memproses laporan induk; salinan kolaborasi/multi alat tidak
  memicu broadcast.
- Queue worker PROD harus berjalan (`queue:work`). Jika worker mati, pesan
  menunggu di tabel `jobs` dan tidak hilang.

## Bukti

- `ReportCollaborationBroadcastTest`: 3 test hijau, 11 assertion.
  - Semua kolaborator menerima detail + nama rekan lain.
  - Pelaksana tidak menerima broadcast.
  - Kolaborator tanpa Telegram dilewati.
  - Penambahan lewat web mengirim job untuk teknisi baru.
- Seluruh suite dijalankan lewat gerbang pre-push.

## Berkas

- `app/Services/ReportCollaborationNotifier.php`
- `app/Jobs/KirimBroadcastKolaborasi.php`
- `app/Services/Telegram/Traits/WizardReportSaverTrait.php`
- `app/Http/Controllers/ReportCollaborationController.php`
- `tests/Feature/ReportCollaborationBroadcastTest.php`
