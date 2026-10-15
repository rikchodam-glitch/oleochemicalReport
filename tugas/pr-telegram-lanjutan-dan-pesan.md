# PR: Pesan & alur Telegram untuk laporan lanjutan

Cabang: `tugas/erik/telegram-lanjutan-dan-pesan`

## Ringkasan

Diminta user 15/10/2026:

1. Bug: saat teknisi membuat laporan lanjutan dengan menulis `KODE + lanjut`,
   data laporan asal tidak diwarisi (kode equipment/lokasi hilang).
2. Khusus laporan lanjutan, tawarkan input tanggal; bila dilewati memakai
   tanggal hari ini (tanggal input).
3. Perbaiki kata-kata pesan agar lebih profesional, sederhana, dan mudah dipahami.
4. Pesan akhir saat laporan diterima harus memuat deskripsi pekerjaan dan tautan
   halaman detail laporan.

## Akar masalah (#1, dibuktikan dengan uji)

`ReportWizardService::startContinuation()` mewarisi lokasi HANYA lewat aset
(`lengkapiStateLokasi($state, null, $asal->asset)`). Untuk laporan AREA/lokasi
yang tidak punya aset, `funcloc_id`/`area_id` tidak pernah diisi (bahkan kunci
`funcloc_id` tidak ada di state). Kolom `finding` juga tidak diwarisi.

Reproduksi: uji `TelegramLanjutanPesanTest` gagal sebelum perbaikan ("Undefined
array key funcloc_id", `finding` = null) dan hijau sesudahnya.

## Perubahan

- **Warisan lengkap**: `startContinuation()` menyalin `funcloc_id`, `area_id`,
  `funcloc_code`/`funcloc_name`/`area_code`, equipment, dan `finding` langsung
  dari laporan asal; resolver lokasi hanya menyempurnakan yang masih kosong.
- **Tawaran tanggal lanjutan** (step baru `STEP_CONTINUATION_DATE`): pesan
  menampilkan alat/lokasi warisan lalu meminta tanggal; tombol **Pakai Hari Ini**
  melewati isian. Tanggal valid dipakai; tanggal tidak terbaca/di masa
  depan/terlalu lama membuat bot menjelaskan dan bertanya ulang.
- **Perapian kata**: catatan "Lanjutan dari" pada prompt durasi & alat tambahan,
  serta ringkasan konfirmasi, ditulis ulang lebih ringkas dan jelas.
- **Pesan akhir diperkaya**: menampilkan `Deskripsi` pekerjaan dan baris
  `Detail laporan : [buka halaman laporan](URL)`. Teks dinamis di-escape
  (`escapeMarkdown`) agar parse_mode Markdown tidak rusak.

## Bukti uji

- `tests/Feature/TelegramLanjutanPesanTest.php` (6 test): warisan alat & lokasi
  (aset dan area), tombol Pakai Hari Ini, tanggal diketik, tanggal tak terbaca,
  pesan akhir (deskripsi + tautan `/reports/`).
- `tests/Feature/ReportContinueStatusTest.php` disesuaikan ke alur baru
  (langkah pertama = `STEP_CONTINUATION_DATE`).
- `php artisan test` **652 test hijau (3180 assertions)**.
- Uji mutasi: mengabaikan tanggal hasil parsing membuat uji "lanjutan bisa
  memakai tanggal yang diketik teknisi" GAGAL, lalu dikembalikan.

## Berkas yang disentuh

- `app/Services/Telegram/ReportWizardService.php`
- `app/Services/Telegram/Traits/WizardStepHandlerTrait.php`
- `app/Services/Telegram/Traits/WizardCallbackHandlerTrait.php`
- `app/Services/Telegram/Traits/WizardReportSaverTrait.php`
- `tests/Feature/TelegramLanjutanPesanTest.php`
- `tests/Feature/ReportContinueStatusTest.php`
- `tugas/erik.md`

## Checklist

- [x] Uji otomatis hijau
- [x] Tidak menambah migrasi / tidak mengubah data
- [x] Data laporan asal (alat, lokasi, temuan, root cause, foto, kolaborator) diwarisi
- [x] Tanggal lanjutan bisa diisi atau dilewati (default hari ini)
