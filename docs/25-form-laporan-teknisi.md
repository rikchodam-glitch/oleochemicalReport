# Form Laporan Web untuk Teknisi

Dokumen ini menjelaskan alternatif MVP pembuatan laporan tanpa bot Telegram
(21/10/2026).

## Akses

- Route `/laporan-saya/buat` (GET/POST), hanya untuk akun login yang punya
  `users.technician_id` dan teknisi berstatus `active`.
- Route berada di luar middleware hanya-baca dan di luar pemetaan modul;
  controller memeriksa pemilik teknisi.
- Menu **Buat Laporan** muncul di sidebar untuk akun teknisi.

## Isi form (MVP)

- Tanggal laporan, tipe laporan: equipment repair / area work / umum.
- Klasifikasi ZPM dan jenis pekerjaan (CM, dCM, PM, EI, Mekanik, CM/PM,
  Fabrikasi, Sipil, IT, Project, UMUM).
- Sesuai rencana (wajib untuk dCM), tanggal kejadian.
- Area dan pencarian alat/equipment lintas area (mengisi `asset_id`,
  `area_id`, `equipment_tag`).
- Deskripsi pekerjaan, durasi menit, root cause, temuan.
- Status selesai (`completed`) atau lanjut (`continue`).
- Foto dokumentasi dan hygiene clearance.
- Kolaborator (teknisi aktif selain pembuat).

## Perilaku simpan

1. Laporan induk dibuat dengan `technician_id` = akun login, `creator_id` =
   akun login, kode laporan baru, `submitted_at` sekarang, dan status sesuai
   pilihan.
2. Jika equipment repair: `asset_id` wajib; area/funcloc/equipment tag
   mengikuti data aset.
3. Jika area work: area wajib.
4. dCM wajib memiliki `sesuai_rencana`.
5. Foto disimpan ke disk `telegram.photo_disk` di folder
   `reports/YYYY/MM/DD/{report_id}`.
6. Setiap kolaborator dibuatkan salinan `collaborator_of` melalui
   `ReportCopyService`, sama seperti jalur Telegram.
7. Jika modul broadcast kolaborasi sudah tersedia, job
   `KirimBroadcastKolaborasi` dikirim setelah salinan dibuat. Saat ini
   pemanggilan memakai `class_exists` agar tetap kompatibel sebelum PR
   broadcast di-merge.
8. Pengguna diarahkan ke halaman detail laporan.

## Perbedaan dengan wizard Telegram

- Belum ada AI classification/klarifikasi bertahap.
- Belum ada wizard multi-equipment dan lanjutan laporan berbasis state.
- Kolaborator dipilih manual; belum menggabungkan auto-group kolaborasi.
- Foto diunggah langsung, bukan lewat alur foto Telegram.
- Kolom klasifikasi/jenis diisi manual, bukan hasil AI.

## Berkas

- `app/Http/Controllers/ReportFormController.php`
- `resources/views/reports/form-teknisi.blade.php`
- `routes/web.php`, `config/akses.php`
- `resources/views/layouts/app.blade.php`
- `tests/Feature/ReportFormTest.php`

## Uji

- `ReportFormTest` 6 test hijau: akses form, simpan area + salinan kolaborator,
  validasi equipment, validasi dCM, foto, akun tanpa teknisi.
- Seluruh suite: 699 test hijau, 3.419 assertion.
- `view:cache` sukses.
