# Laporan Reguler yang Berkolaborasi dengan Shift

Dokumen ini mencatat aturan tampilan tab **Reguler/Shift** di halaman Laporan
setelah perubahan 20/10/2026.

## Aturan

1. Laporan induk dengan pelapor **shift** (Grub A-D) atau **Staff Grub A-D**
   tetap tampil di tab Shift seperti sebelumnya.
2. Laporan induk dengan pelapor **Reguler**, **Reguler Staff**, atau group kosong
   (data lama) dipindahkan ke tab **Shift** bila laporan itu mencantumkan
   minimal satu kolaborator dari:
   - Grub A-D, atau
   - Staff Grub A-D.
3. Laporan yang dipindahkan **tidak ditampilkan lagi di tab Reguler umum**.
   Yang tampil tetap **satu baris laporan induk**, bukan salinan kolaborator.
4. Filter Grub B/C/D hanya memuat laporan itu bila kolaborator shift/staff-nya
   berada di grub tersebut. Contoh: kolaborator Staff Grub B ikut muncul pada
   filter Grub B.
5. Lencana pada baris Shift menampilkan **Kolaborasi Grub B**,
   **Kolaborasi Staff Grub B**, atau **Kolaborasi Shift** bila ada beberapa
   grub. Nama pelapor tetap nama asli (Reguler/Reguler Staff).

## Pengecualian filter satu teknisi

Bila daftar disaring ke **satu teknisi** (`technician_id`), laporan tetap
tampil pada grup teknisi yang difilter. Tujuannya agar jumlah laporan pada
halaman teknisi dan Rincian Man Hour Harian tidak berubah. Aturan pindah tab
di atas berlaku pada daftar umum (tanpa filter teknisi).

## Implementasi

- `ReportController::buildBaseQuery()` meneruskan
  `$request->filled('technician_id')` ke `applyGroupFilter()`.
- `ReportController::applyGroupFilter()`:
  - Shift: `whereHas('technician', grup shift/staff)` **ATAU**
    (`pelapor` non-shift/null **DAN** `whereHas('collaboratorReports.technician',
    grup shift/staff)`).
  - Reguler: pelapor non-shift/null **DAN**
    `whereDoesntHave('collaboratorReports.technician', grup shift/staff)`
    kecuali sedang memfilter satu teknisi.
- Karena memakai query yang sama, kartu status/jenis, total pekerjaan, chip
  tanggal, pagination, dan export (`tampilan=reguler|shift|semua`) otomatis
  mengikuti. Export `semua` tetap memuat semuanya tanpa duplikasi.
- Man hour tidak diubah: laporan induk tetap milik pelapor, salinan kolaborator
  tetap milik kolaborator.

## Bukti DEV 20/10/2026

- 10 laporan induk non-shift memiliki kolaborator shift/staff:
  7 pelapor Reguler, 3 Reguler Staff; kolaborator 4 Grub B, 1 Grub C, 5 Grub D.
- Tanggal terdampak: 20/08 (2), 22/08, 10/09, 11/09, 18/09, 23/09, 24/09 (2),
  25/09.
- Contoh 24/09/2026: sebelum perubahan Reguler 11 / Shift 0; sesudah
  Reguler 9 / Shift 2. Laporan #603 dan #611 masing-masing muncul tepat satu
  kali di Shift dan tidak muncul di Reguler.
- Uji: `php artisan test` 676 hijau, 3.340 assertion; `view:cache` sukses.

## Catatan

Kolaborator otomatis dari kelompok shift juga dihitung. Saat ini 10 laporan
tersebut berasal dari kolaborator yang dipilih langsung (pelapornya tidak
menjadi anggota kelompok kolaborasi shift). Bila suatu saat pelapor non-shift
dimasukkan ke kelompok shift, laporan otomatisnya juga akan pindah ke Shift.
