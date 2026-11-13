# Papan Klaim: erik

Diperbarui: 2026-11-13 10:20 WIB

- Cabang aktif: `tugas/erik/perbaiki-tampilan-riwayat-pembacaan-dan-temuan-equipment-cm`
- Mulai: 2026-10-27 12:12
- Status: SEDANG DIKERJAKAN (lanjutan halaman Finding CM + Overview)
- Modul/berkas yang disentuh:
  - `resources/views/cm/finding-show.blade.php` — detail finding lengkap, foto, PIC, modal edit
  - `resources/views/cm/_findings-list.blade.php` — kolom PIC dan baris bisa diklik
  - `resources/views/cm/findings.blade.php` — filter PIC, beban open per PIC, fokus tindak lanjut
  - `resources/views/cm/_modal-edit-finding.blade.php` — dukungan redirect setelah edit
  - `resources/views/cm/overview.blade.php` — hero visual bad dan panel tindak lanjut finding
  - `resources/views/cm/_overview-cards.blade.php` — kartu baik mencakup visual bad
  - `app/Http/Controllers/CmController.php` — filter PIC, redirect detail, agregasi visual bad, ringkasan finding
  - `app/Exports/CmFindingsExport.php` — filter PIC pada export
  - `tests/Feature/CmFindingPagesTest.php` — penjaga filter PIC, navigasi, detail, beban PIC
  - `tests/Feature/CmOverviewVisualBadTest.php` — penjaga persentase visual bad
  - `docs/32-tampilan-riwayat-pembacaan-dan-temuan.md`
- Catatan: `php artisan test` 799 passed (4038 assertions); `view:cache` sukses. Filter PIC memakai nilai `cm_findings.pic` dari sheet Finding CM; pilihan "Tanpa PIC" untuk finding yang belum diisi PIC. Sesuai keputusan user, `visual_bad` dihitung sebagai kondisi baik di persentase Overview dan tetap tampil sebagai catatan terpisah.

## Riwayat
- tugas/erik/aset-js-lokal-tanpa-cdn (mulai 2026-11-04 09:53) -> dilanjutkan 2026-11-06 08:51
- tugas/erik/filter-bagian-request-sparepart (mulai 2026-10-27 08:11) -> dilanjutkan 2026-10-27 12:12
- tugas/erik/perbaiki-tampilan-riwayat-pembacaan-dan-temuan-equipment-cm (lanjutan halaman Finding CM) -> 2026-11-13 09:30
