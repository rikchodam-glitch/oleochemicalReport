# Notifikasi laporan web diterima dan broadcast kolaborasi

## Ringkasan

- Setelah laporan web tersimpan, job `KirimNotifikasiLaporanDiterima` dikirim
  via queue ke Telegram pelapor (`/start`/`/laporan`).
- Broadcast kolaborasi tetap dikirim via `KirimBroadcastKolaborasi` untuk
  setiap laporan induk.
- Request form tidak menunggu pengiriman Telegram.
- Foto lebih dari 1,5 MB dikompres di browser (maks. 1600 px, JPEG 0.82)
  sebelum upload untuk mengurangi waktu kirim.
- Tombol Kirim berubah menjadi "Mengirim..." dan mencegah klik ganda.

## Penyebab lambat

Pengiriman sebelumnya dapat tertahan oleh upload foto besar dan proses
pascasimpan. Notifikasi sekarang queue; upload foto dikecilkan di browser.
Jika masih lambat, penyebab berikutnya adalah peladen PROD yang memakai
`artisan serve`/`php -S` (satu proses) atau jaringan; sebaiknya gunakan Apache
Laragon untuk PROD.

## Bukti

- Job notifikasi diuji mengirim pesan "Laporan diterima" + kode + tautan detail
  ke `telegram_id` pelapor.
- `ReportFormTest`: 15 test hijau, 59 assertion.
- Seluruh suite: 716 test hijau, 3.514 assertion.

## Berkas

- `app/Jobs/KirimNotifikasiLaporanDiterima.php` (baru)
- `app/Services/ReportFormService.php`
- `resources/views/reports/form-teknisi.blade.php`
- `tests/Feature/ReportFormTest.php`
- `docs/25-form-laporan-teknisi.md`
- `tugas/erik.md`
