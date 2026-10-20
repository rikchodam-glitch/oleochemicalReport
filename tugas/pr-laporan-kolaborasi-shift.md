# Laporan Reguler yang berkolaborasi shift tampil di tab Shift

## Ringkasan

- Laporan dengan pelapor Reguler/Reguler Staff (atau group kosong) yang
  mencantumkan kolaborator **Grub A-D** atau **Staff Grub A-D** sekarang tampil
  di tab **Shift**.
- Laporan itu **tidak** tampil lagi di tab Reguler umum, sehingga tetap satu
  baris dan tidak muncul di dua tab.
- Yang ditampilkan adalah laporan induk (nama pelapor asli), bukan salinan
  kolaborator. Lencana baris menjadi **Kolaborasi Grub B / Staff Grub B /
  Shift**.
- Filter Grub B/C/D hanya memuat laporan bila kolaboratornya berada di grub
  tersebut (termasuk Staff Grub-nya).
- Laporan shift yang berkolaborasi dengan Reguler tetap di Shift; laporan
  Reguler yang hanya berkolaborasi dengan Reguler/Reguler Staff tetap di Reguler.

## Bukti DEV 20/10/2026

- 10 laporan induk non-shift memiliki kolaborator shift/staff:
  7 pelapor Reguler, 3 Reguler Staff; 4 kolaborator Grub B, 1 Grub C,
  5 Grub D.
- 24/09/2026: sebelum perubahan Reguler 11 / Shift 0; sesudah
  Reguler 9 / Shift 2. Laporan #603 dan #611 masing-masing muncul tepat satu
  kali di Shift dan tidak muncul di Reguler.
- Uji otomatis juga memuat kasus dua kolaborator shift pada satu laporan:
  baris tetap satu (tidak duplikat).

## Pengecualian yang disengaja

Saat daftar difilter ke **satu teknisi** (`technician_id`), laporan tetap
tampil pada grup teknisi yang difilter. Ini menjaga jumlah laporan di halaman
teknisi dan Rincian Man Hour Harian tetap konsisten. Aturan pindah tab berlaku
pada daftar umum.

## Dampak

- Tab Reguler/Shift, kartu status/jenis, total pekerjaan, chip tanggal,
  pagination, dan export (`reguler`/`shift`/`semua`) memakai query yang sama,
  jadi otomatis mengikuti.
- Tidak ada perubahan skema/data. Man hour tidak berubah: laporan induk tetap
  milik pelapor, salinan tetap milik kolaborator.

## Berkas

- `app/Http/Controllers/ReportController.php`
- `resources/views/reports/index.blade.php`
- `tests/Feature/ReportGroupKolaborasiShiftTest.php` (baru)
- `tests/Feature/ReportIndexViewTest.php`,
  `tests/Feature/ReportIndexRingkasanTest.php` (penyesuaian ekspektasi)
- `docs/22-laporan-kolaborasi-shift.md`, `AGENTS.md`
- `tugas/erik.md`

## Uji

- `php artisan test`: **676 test hijau, 3.340 assertion**.
- `php artisan view:cache`: sukses.
- Tidak ada migrasi baru; deploy hanya kode.
