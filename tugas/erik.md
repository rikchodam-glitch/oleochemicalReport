# Papan Klaim: erik

Diperbarui: 2026-11-13 11:45 WIB

- Cabang aktif: `tugas/erik/perbaiki-tampilan-riwayat-pembacaan-dan-temuan-equipment-cm`
- Mulai: 2026-10-27 12:12
- Status: SEDANG DIKERJAKAN (audit data CM + pembersihan stale)
- Modul/berkas yang disentuh:
  - `resources/views/cm/finding-show.blade.php` — detail finding lengkap, foto, PIC, modal edit
  - `resources/views/cm/_findings-list.blade.php` — kolom PIC dan baris bisa diklik
  - `resources/views/cm/findings.blade.php` — filter PIC, beban open per PIC, fokus tindak lanjut
  - `resources/views/cm/_modal-edit-finding.blade.php` — dukungan redirect setelah edit
  - `resources/views/cm/overview.blade.php` — hero visual bad dan panel tindak lanjut finding
  - `resources/views/cm/_overview-cards.blade.php` — kartu baik mencakup visual bad
  - `app/Http/Controllers/CmController.php` — filter PIC, redirect detail, agregasi visual bad, ringkasan finding, filter equipment aktif
  - `app/Exports/CmFindingsExport.php` — filter PIC pada export
  - `app/Exports/CmReadingsExport.php` — hanya equipment aktif
  - `app/Jobs/ProcessCmExcelImport.php` — urutan fallback kondisi sesuai formula sheet
  - `tests/Feature/CmFindingPagesTest.php` — penjaga filter PIC, navigasi, detail, beban PIC
  - `tests/Feature/CmOverviewVisualBadTest.php` — penjaga persentase visual bad dan exclusion equipment nonaktif
  - `docs/32-tampilan-riwayat-pembacaan-dan-temuan.md`
- Catatan: `php artisan test` 799 passed (4038 assertions); `view:cache` sukses. Audit 13/11/2026: 3.721 key reading sheet cocok, 434 master tag cocok, 587 finding cocok, tracking aktif 0 selisih. Cleanup menghapus 8 finding open lama dan 9 reading stale; sisa reading 4.458. Query Overview/Report/Analysis/Export kini hanya equipment aktif.

## Riwayat
- tugas/erik/aset-js-lokal-tanpa-cdn (mulai 2026-11-04 09:53) -> dilanjutkan 2026-11-06 08:51
- tugas/erik/filter-bagian-request-sparepart (mulai 2026-10-27 08:11) -> dilanjutkan 2026-10-27 12:12
- tugas/erik/perbaiki-tampilan-riwayat-pembacaan-dan-temuan-equipment-cm (lanjutan halaman Finding CM) -> 2026-11-13 09:30
