# Perbaiki kolaborasi multi-report web

## Ringkasan

- Server sekarang mengikuti pilihan checkbox kolaborator **per laporan**.
  Sebelumnya `autoCollaboratorsFor()` selalu digabung ulang, sehingga laporan
  yang sengaja tanpa kolaborator tetap mendapat salinan dari group.
- Dua laporan dengan kolaborator dan alat berbeda diuji: salinan kolaborator
  tidak tercampur dan masing-masing tetap terhubung ke laporan induknya.
- Salinan multi-alat sekarang memakai `equipment_tag` alat tambahan, bukan
  `equipment_tag` alat utama.
- Kolaborator otomatis group tetap tercentang di form; teknisi bisa
  melepasnya dan server menghormati pilihan itu.

## Bukti

- Test baru:
  - report tanpa pilihan kolaborator tidak membuat salinan walau ada group auto;
  - dua report dengan collaborator/alat berbeda tidak tercampur;
  - multi-alat memakai equipment_tag alat kedua.
- `ReportFormTest`: 17 test hijau, 69 assertion.
- Seluruh suite: 730 test hijau, 3.581 assertion.

## Berkas

- `app/Services/ReportFormService.php`
- `app/Services/ReportCopyService.php`
- `tests/Feature/ReportFormTest.php`
- `docs/25-form-laporan-teknisi.md`
- `tugas/erik.md`
