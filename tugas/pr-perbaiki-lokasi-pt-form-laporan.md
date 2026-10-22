# Perbaiki pekerjaan area sampai level PT

## Ringkasan

- Form laporan tidak lagi mewajibkan Section. Pengguna boleh berhenti di
  PT, Dept, Area, atau Section.
- `funcloc_id` diisi dengan level terdalam yang dipilih; jika tidak ada alat,
  laporan disimpan sebagai pekerjaan area pada level tersebut.
- Tipe/equipment tetap difilter dari subtree lokasi yang dipilih.
- Server sudah menerima `funcloc_id` level PT/Dept/Area dan menyimpan
  `report_type=area_work`.

## Bukti

- Test baru: laporan dengan hanya PT tersimpan sebagai `area_work`,
  `funcloc_id` = PT, dan `asset_id` null.
- `ReportFormTest`: 14 test hijau, 55 assertion.
- Seluruh suite: 715 test hijau, 3.510 assertion.
- `view:cache` sukses.

## Berkas

- `resources/views/reports/form-teknisi.blade.php`
- `tests/Feature/ReportFormTest.php`
- `docs/25-form-laporan-teknisi.md`
- `tugas/erik.md`
