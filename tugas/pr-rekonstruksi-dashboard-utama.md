# Rekonstruksi dashboard utama lintas modul

## Ringkasan

- `/dashboard` dirombak menjadi ringkasan operasional semua modul: 8 KPI, tren
  laporan, status laporan, jenis pekerjaan, teknisi paling aktif, aset per
  departemen, Condition Monitoring, Reliability, Sparepart, IT, panel suhu,
  Work Order, AI Provider, Bot Telegram, daftar perhatian, dan laporan terbaru.
- Ukuran tiap bagian disesuaikan: tren laporan dan monitoring panel lebih besar;
  reliabilitas, work order, AI, dan bot dibuat kompak.
- Grafik memakai Chart.js 4.4.4 dari CDN (tanpa menambah dependency npm).
- Filter periode `7/30/90 hari` (default 30). Nilai lain kembali ke 30.
- Dashboard mematuhi hak akses per Bagian: modul yang tidak diizinkan tidak
  di-query dan tidak dirender (diuji dengan akun yang hanya punya `dashboard`).

## Data yang ditampilkan

- Laporan: total periode, hari ini, selesai/perlu review/dilanjutkan, jam kerja,
  tren harian, jenis pekerjaan, top teknisi.
- CM: kondisi good/alarm/danger/visual bad, temuan terbuka, severity, kategori,
  equipment danger terbaru.
- Reliability: fleet MTBF, defer success, undetected failure, rasio CM:dCM:PM.
- Sparepart: total material, REORDER, PR/PO terbuka, sebaran aksi, import terakhir.
- IT: online/offline/belum diping, pemakaian IP per VLAN, notifikasi baru.
- Panel: 24 pembacaan terakhir (suhu/kelembapan), status ruang, ambang.
- Work Order: terbuka/selesai + empty state bila belum ada data.
- AI/Bot: status provider, token, request, success rate; pendaftaran dan aset
  tak dikenal.

## Berkas

- `app/Http/Controllers/DashboardController.php`
- `resources/views/dashboard/index.blade.php`
- `tests/Feature/DashboardRingkasanTest.php` (baru)
- `docs/21-dashboard-utama.md` (baru), `AGENTS.md` (peta dokumen)
- `tugas/erik.md`

## Uji

- `php artisan test`: **672 test hijau, 3.298 assertion**.
- `php artisan view:cache`: sukses.
- `npm run build`: sukses; kelas Tailwind baru (`xl:col-span-2/3/4`) terkompilasi.
- `/dashboard?periode=7`, `30`, dan `90`: HTTP 200.
- Screenshot headless 1600 px: seluruh KPI/grafik/panel tampil, tidak ada error JS.

## Catatan data DEV

Work Order masih 0 dan Reliability menampilkan 0 karena data DEV belum memakai
klasifikasi CM/dCM/PM. Keduanya sudah memakai empty state/penjelasan, bukan
error. PROD akan menampilkan angka sebenarnya setelah data tersedia.
