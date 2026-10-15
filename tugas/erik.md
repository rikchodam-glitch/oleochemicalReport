# Papan Klaim: erik

Diperbarui: 2026-10-15 13:35 WIB

- Cabang aktif: `tugas/erik/popup-kalender-tidak-terbuka-sendiri`
- Mulai: 2026-10-15 13:35
- Status: SEDANG DIKERJAKAN
- Modul/berkas yang disentuh:
  - `resources/views/technicians/index.blade.php` (popup hanya dibuka bila URL ditandai `buka_kalender=1`; tautan bulan menambahkan penanda; penanda dibuang dari address bar setelah dipakai)
  - `app/Http/Controllers/TechnicianController.php` (tautan paginasi tidak lagi membawa `jadwal_bulan`/`buka_kalender`, hanya filter daftar)
  - `tests/Feature/TechnicianKalenderAutoBukaTest.php` (BARU)
- Catatan: cabang dibuat dari main terbaru (PR #16 kalender & #17 tautan laporan sudah di-merge). Tanpa migrasi/perubahan data.

## Riwayat
- tugas/erik/tautan-laporan-mengikuti-grup (mulai 2026-10-15 13:15) -> dilanjutkan 2026-10-15 13:35
