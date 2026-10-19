# Papan Klaim: erik

Diperbarui: 2026-10-19 15:50 WIB (di-rebase ke `main` 2026-10-20)

- Cabang aktif: `tugas/erik/perbaiki-penautan-laporan-equipment-cm`
- Mulai: 2026-10-19 15:50
- Status: SELESAI DIUJI, menunggu Pull Request (#32)
- Modul/berkas yang disentuh:
  - `app/Http/Controllers/CmController.php`
  - `app/Models/Report.php`
  - `app/Providers/AppServiceProvider.php`
  - `app/Services/EquipmentMentionService.php` (baru)
  - `app/Services/Telegram/AssetInfoService.php`
  - `app/Services/Telegram/Traits/WizardReportSaverTrait.php`
  - `app/Console/Commands/ReportsTagEquipment.php` (baru)
  - `resources/views/cm/equipment-show.blade.php`
  - `tests/Feature/ReportEquipmentTagTest.php` (baru)
  - `tests/Feature/CmEquipmentLaporanTeknisiTest.php`
  - `docs/19-laporan-teknisi-equipment-cm.md`,
    `docs/12-riwayat-insiden-33-51.md` (insiden #51)
- Catatan: laporan teknisi tidak muncul di detail equipment CM karena
  `reports.equipment_tag` tidak pernah diisi Tech Ident No. Deploy memakai
  `php artisan reports:tag-equipment --apply` (tanpa migrasi).

## Pekerjaan lain di papan ini
- `tugas/erik/cuti-otomatis-dan-halaman-cuti-saya` (mulai 2026-10-20 10:07,
  sudah di-merge lewat PR #31)
  - Modul/berkas: `app/Services/CutiService.php`,
    `app/Http/Controllers/CutiController.php`,
    `app/Http/Controllers/CutiSayaController.php` (baru), `routes/web.php`,
    `config/akses.php`, `resources/views/cuti/*`,
    `resources/views/layouts/app.blade.php`,
    `resources/views/layouts/partials/sidebar-pengaturan.blade.php`,
    `tests/Feature/CutiTest.php`, `tests/Feature/CutiSayaTest.php`,
    `docs/23-fitur-cuti.md`, `AGENTS.md`,
    `tugas/pr-cuti-otomatis-dan-halaman-cuti-saya.md`
  - Catatan: cuti otomatis disetujui; halaman Cuti Saya untuk akun teknisi
    mengedit/menghapus cuti sendiri.
- `tugas/erik/laporan-kolaborasi-shift-tampil-di-tab-shift` (mulai 2026-10-20 08:30,
  sudah di-merge lewat PR #25)
  - Modul/berkas: `app/Http/Controllers/ReportController.php`,
    `resources/views/reports/index.blade.php`,
    `tests/Feature/ReportGroupKolaborasiShiftTest.php`,
    `docs/22-laporan-kolaborasi-shift.md`, `AGENTS.md` (peta dokumen)
  - Catatan: laporan non-shift yang berkolaborasi dengan shift/staff grub tampil di
    Shift saja.

## Riwayat
- tugas/erik/cuti-otomatis-dan-halaman-cuti-saya (mulai 2026-10-20 10:07) -> sudah di-merge lewat PR #31
- tugas/erik/perbaiki-tautan-cuti-pratinjau-telegram (mulai 2026-10-20 09:55) -> sudah di-merge lewat PR #30
- tugas/erik/laporan-kolaborasi-shift-tampil-di-tab-shift (mulai 2026-10-20 08:30) -> sudah di-merge lewat PR #25
- tugas/erik/rekonstruksi-dashboard-utama (mulai 2026-10-19 11:35) -> dilanjutkan 2026-10-20 08:30
- tugas/erik/perbaiki-penautan-laporan-equipment-cm (mulai 2026-10-19 15:50) -> menunggu Pull Request #32
- tugas/erik/perbaiki-dashboard-sparepart-pr-dan-mrp (mulai 2026-10-19 09:37) -> dilanjutkan 2026-10-19 11:35