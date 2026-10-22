# Tampilan CM gaya apple.com dan sinkronisasi equipment dari Master Equipment

## Ringkasan perubahan

Dua perubahan yang saling melengkapi dalam satu cabang:

1. **Tampilan CM dan Reliability Dashboard diseragamkan** memakai partial baru
   `resources/views/partials/_apple-ui.blade.php` (gaya apple.com: tipografi,
   kartu, tabel, tab, dan toolbar). Halaman yang berubah: Overview, Monitoring,
   Status Equipment, Findings, Report Analysis, dan Reliability Dashboard.
2. **Daftar equipment CM mengikuti sheet `Master Equipment`** setiap kali
   import/sync. Tag yang dihapus dari sheet berhenti dimonitoring tanpa
   menghapus histori reading/finding.

### Perilaku sinkronisasi

- `ProcessCmExcelImport` (upload & Google Sheets) dan `cm:import-readings`
  memanggil `CmMasterEquipmentSyncService` setelah data dibaca.
- Tag di master: dibuat/diperbarui (`pt_location`, `plant`, `in_master=true`,
  `is_active=true`); tag yang sebelumnya ada di master lalu hilang dari sheet
  dinonaktifkan (`is_active=false`, `deactivated_at` diisi).
- Equipment yang dibuat otomatis dari `Data AppSheet`/Finding CM tetapi belum
  ada di master ditandai `in_master=false` dan TIDAK ikut dinonaktifkan.
- Perbandingan tag case-insensitive + spasi dirapikan
  (`SC-Rework A` = `SC-REWORK A`).
- Bila baris master < 10 (sheet sedang diisi/terpotong), deaktivasi dilewati.
- Sheet `Monitoring Bulanan` sengaja BUKAN patokan deaktivasi karena isinya
  bisa lebih sedikit dari master.
- Semua daftar/hitungan halaman CM (Overview, Monitoring, Status Equipment,
  Report Analysis, export monitoring, rebuild tracking bulanan) memakai scope
  `CmEquipment::active()`.

## Checklist wajib (agenda integrator)

- [x] `php artisan test` HIJAU: 728 passed (3571 assertions).
- [x] `php artisan view:cache` sukses (blade disentuh).
- [x] Perubahan HANYA pada berkas yang relevan (bukan `git add -A`).
- [x] Tidak ada berkas sampah (skrip `tmp-*`, `.bak`, hasil export sekali pakai).
- [x] Migrasi baru memakai stempel waktu `2026_10_21_000001`.
- [x] Sudah `git rebase origin/main` (bukan merge).
- [x] `AGENTS.md` (peta dokumen) dan `docs/04-keputusan-arsitektur-01-10.md`
      diperbarui karena aturan daftar equipment berubah.

## Bukti uji

```
php artisan test tests/Feature/CmMasterEquipmentSyncTest.php
Tests: 6 passed (31 assertions)

php artisan test
Tests: 728 passed (3571 assertions)
Duration: 228.37s

php artisan view:cache
INFO  Blade templates cached successfully.
```

## Berkas yang disentuh

```
1.  resources/views/partials/_apple-ui.blade.php      - partial gaya apple.com (baru)
2.  resources/views/cm/overview.blade.php             - tampilan overview
3.  resources/views/cm/monitoring.blade.php           - tampilan monitoring per PT
4.  resources/views/cm/equipment-status.blade.php     - tampilan status equipment
5.  resources/views/cm/findings.blade.php             - tampilan findings
6.  resources/views/cm/report-analysis.blade.php      - tampilan report analysis
7.  resources/views/cm/_overview-cards.blade.php      - kartu ringkasan
8.  resources/views/cm/_tabs.blade.php                - tab halaman CM
9.  resources/views/cm/_findings-list.blade.php       - daftar findings
10. resources/views/reliability/index.blade.php       - Reliability Dashboard
11. app/Services/CmMasterEquipmentSyncService.php     - sinkronisasi Master Equipment (baru)
12. app/Console/Commands/CmImportReadings.php         - panggil sync + penanda in_master
13. app/Console/Commands/CmImportFindings.php         - equipment baru findings in_master=false
14. app/Jobs/ProcessCmExcelImport.php                 - panggil sync setelah import
15. app/Models/CmEquipment.php                        - fillable/casts + scope active/inactive
16. app/Exports/CmMonitoringExport.php                - export hanya equipment aktif
17. app/Http/Controllers/CmController.php             - filter active() + monitoring bulan berjalan
18. database/migrations/2026_10_21_000001_add_active_status_to_cm_equipment.php - kolom status (baru)
19. tests/Feature/CmMasterEquipmentSyncTest.php       - 6 test sinkronisasi (baru)
20. docs/04-keputusan-arsitektur-01-10.md             - aturan Master Equipment sebagai sumber kebenaran
21. AGENTS.md                                         - peta dokumen
22. tugas/pr-tampilan-cm-gaya-apple-dan-sync-master-equipment.md - catatan PR ini
```

## Risiko / hal yang perlu diperhatikan integrator

- **Migrasi baru wajib jalan di PROD** (`Deploy-Laporan.bat` sudah menjalankan
  `migrate --force`). Tanpa migrasi, halaman CM akan error karena kolom
  `is_active` belum ada.
- Nilai bawaan kolom `is_active`/`in_master` adalah `true`, jadi setelah deploy
  semua equipment lama masih tampil sampai **import/sync pertama** dijalankan.
  Import pertama itulah yang menonaktifkan tag yang sudah tidak ada di sheet
  `Master Equipment` (di DEV: 13 tag nonaktif, termasuk CT PUMP 1 & 2).
- Deaktivasi bersifat lunak: reading, finding, dan tracking lama tetap ada;
  yang berubah hanya daftar/hitungan halaman CM.
- Bila sheet `Master Equipment` tidak ditemukan atau kolomnya berubah, sync
  mencatat error di `ImportLog` dan import reading tetap berjalan (tidak
  membatalkan seluruh import).
- Tidak ada perubahan `.env` maupun build aset khusus untuk partial baru.
