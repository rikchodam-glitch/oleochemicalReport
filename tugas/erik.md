# Papan Klaim: erik

Diperbarui: 2026-11-13 16:10 WIB

- Cabang aktif: `tugas/erik/perbaiki-tampilan-riwayat-pembacaan-dan-temuan-equipment-cm`
- Mulai: 2026-10-27 12:12
- Status: SEDANG DIKERJAKAN (perbaikan sync Google Sheets 419 + keep-alive sesi)
- Modul/berkas yang disentuh:
  - `resources/views/cm/finding-show.blade.php` — detail finding lengkap, foto, PIC, modal edit
  - `resources/views/cm/_findings-list.blade.php` — kolom PIC dan baris bisa diklik
  - `resources/views/cm/findings.blade.php` — filter PIC, beban open per PIC, fokus tindak lanjut
  - `resources/views/cm/_modal-edit-finding.blade.php` — dukungan redirect setelah edit
  - `resources/views/cm/overview.blade.php` — hero visual bad, panel tindak lanjut, penanganan 419
  - `resources/views/cm/_overview-cards.blade.php` — kartu baik mencakup visual bad
  - `resources/views/layouts/app.blade.php` — keep-alive sesi
  - `app/Http/Controllers/CmController.php` — filter PIC, redirect detail, agregasi visual bad, filter equipment aktif
  - `app/Exports/CmFindingsExport.php` — filter PIC pada export
  - `app/Exports/CmReadingsExport.php` — hanya equipment aktif
  - `app/Jobs/ProcessCmExcelImport.php` — urutan fallback kondisi sesuai formula sheet
  - `routes/web.php` — route keep-alive
  - `config/akses.php` — keep-alive bebas modul
  - `tests/Feature/CmFindingPagesTest.php` — penjaga filter PIC, navigasi, detail, beban PIC
  - `tests/Feature/CmOverviewVisualBadTest.php` — penjaga persentase visual bad dan exclusion equipment nonaktif
  - `tests/Feature/CmSyncImportModeTest.php` — penjaga keep-alive dan penanganan sesi
  - `docs/32-tampilan-riwayat-pembacaan-dan-temuan.md`
- Catatan: `php artisan test` 802 passed (4045 assertions); `view:cache` sukses. Akar sync gagal 13/11/2026: POST `/cm/sync-google-sheets` 15:46 kena HTTP 419 karena sesi 120 menit kedaluwarsa (log Apache), bukan URL Google Sheets. Sync manual 15:48 sukses (log #96/#97). Perbaikan: keep-alive 10 menit + penanganan 419/redirect login di tombol Sync.

## Riwayat
- tugas/erik/aset-js-lokal-tanpa-cdn (mulai 2026-11-04 09:53) -> dilanjutkan 2026-11-06 08:51
- tugas/erik/filter-bagian-request-sparepart (mulai 2026-10-27 08:11) -> dilanjutkan 2026-10-27 12:12
- tugas/erik/perbaiki-tampilan-riwayat-pembacaan-dan-temuan-equipment-cm (lanjutan halaman Finding CM) -> 2026-11-13 09:30
