# Dashboard Utama — Indikator Lintas Modul

Dokumen ini menjelaskan dashboard di `/dashboard` setelah direkonstruksi
19/10/2026. Tujuannya: satu halaman untuk melihat indikator utama semua modul
tanpa membuka setiap halaman, dengan ukuran grafik sesuai kepentingan fitur.

## Prinsip

- **Hanya modul yang boleh diakses.** `DashboardController` memeriksa
  `User::punyaAkses()` untuk assets, technicians, reports, cm, reliability,
  sparepart, it_assets, panel, work_orders, ai_providers, dan bot. Modul yang
  tidak diizinkan tidak di-query dan tidak dirender.
- **Periode tunggal.** Parameter `?periode=7|30|90` (default 30) dipakai untuk
  laporan, CM, reliability, dan tabel aktivitas. Nilai lain kembali ke 30.
- **Snapshot vs periode.** Aset, teknisi, sparepart, IT, panel, work order, AI,
  dan bot memakai keadaan terkini; laporan/CM/reliability memakai periode.
- **Chart.js dari CDN** (`chart.js@4.4.4`) dimuat lewat `@push('scripts')` hanya
  di halaman dashboard. Layout utama hanya memuat Alpine.
- **Setelah menambah kelas Tailwind baru, jalankan `npm run build`.** Blade saja
  tidak cukup karena CSS produksi memakai hasil Vite.

## Isi halaman

| Bagian | Indikator | Sumber data |
|---|---|---|
| KPI | Asset aktif, teknisi aktif, laporan periode, perlu review, temuan CM terbuka, sparepart perlu tindakan, perangkat IT online, ruang panel normal | `assets`, `technicians`, `reports`, `cm_findings`, `sp_material`, `it_assets`, `panel_ruangan` |
| Tren laporan | Harian: selesai / perlu review / dilanjutkan / lainnya | `reports.report_date`, `reports.status` |
| Status laporan | Komposisi status periode | `reports.status` |
| Jenis pekerjaan | Top 8 `jenis_pekerjaan` | `reports.jenis_pekerjaan` |
| Teknisi paling aktif | Jumlah laporan + jam kerja | `reports.technician_id`, `work_duration_minutes` |
| Aset per departemen | Top 8 departemen | `assets.area_id` → `areas.department_id` → `departments.name` |
| Condition Monitoring | Good/alarm/danger/visual bad, temuan terbuka, severity, equipment danger terbaru | `cm_readings`, `cm_findings`, `cm_equipment` |
| Sparepart | Jumlah material, REORDER, PR/PO terbuka, sebaran aksi | `sp_material`, `sp_impor` |
| Perangkat IT | Online/offline/belum diping, pemakaian IP per VLAN, notifikasi baru | `it_assets`, `ip_addresses`, `ip_networks`, `it_notifications` |
| Monitoring panel | Suhu/kelembapan 24 pembacaan terakhir, status ruang, ambang | `panel_ruangan`, `panel_pembacaan` |
| Reliability | Fleet MTBF, defer success, undetected failure, rasio CM:dCM:PM | `reports` (CM/dCM/PM), `cm_equipment` |
| Work Order | Terbuka/selesai dan jenis pekerjaan; empty state bila belum ada | `work_orders` |
| AI Provider | Status sehat, token hari/bulan, request 24 jam, success rate log periode | `ai_providers`, `ai_usage_logs` |
| Bot Telegram | Pending, disetujui, aset tak dikenal | `bot_registrations`, `bot_unknown_assets` |
| Perlu perhatian | Temuan CM, sparepart REORDER, IT offline, panel alert, pendaftaran bot, AI rendah, work order terbuka | gabungan indikator di atas |
| Laporan terbaru | 8 laporan terakhir | `reports` + relasi teknisi/area |

## Aturan pengembangan

1. Modul baru: tambahkan kunci di `config/akses.php`, lalu di
   `DashboardController@index` tambahkan `$akses['...']`, method data, KPI bila
   perlu, kartu/grafik, dan item "Perlu perhatian".
2. Jangan menaruh query berat di Blade. Semua agregasi di controller; Blade hanya
   merender array.
3. Gunakan `@json()` untuk data Chart.js. Jangan menyisipkan data mentah ke
   atribut HTML.
4. Jangan memakai `YEAR()`/`MONTH()` di query dashboard; SQLite (test) tidak
   mendukung. Gunakan `DATE()` atau agregasi di PHP.
5. Jangan menampilkan angka modul yang tidak boleh diakses akun. Uji dengan akun
   Bagian yang hanya punya modul `dashboard`.
6. Setelah mengubah kelas Tailwind, jalankan `npm run build`; setelah mengubah
   Blade, jalankan `php artisan view:cache`.

## Bukti 19/10/2026

- `php artisan test`: 672 test hijau, 3.296 assertion.
- `php artisan view:cache`: sukses.
- `npm run build`: sukses; kelas `xl:col-span-2/3/4` ikut terkompilasi.
- Screenshot headless 1600 px: dashboard memuat 8 KPI, 8 grafik/panel indikator,
  daftar perhatian, dan tabel laporan terbaru tanpa error JavaScript.
- Akun Bagian yang hanya diberi modul `dashboard`: halaman 200 dan seluruh kartu
  modul lain tidak dirender (diuji di `DashboardRingkasanTest`).
