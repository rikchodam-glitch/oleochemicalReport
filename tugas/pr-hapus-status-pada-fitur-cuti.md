# Hapus status pada fitur cuti

## Ringkasan

- Cuti tidak lagi memiliki status pengajuan/persetujuan.
- Semua cuti yang dicatat Admin Reguler langsung efektif dan memengaruhi man hour.
- Kolom `status` pada `cuti_teknisis` dibuang lewat migrasi, termasuk index
  `cuti_status_tanggal_idx`.
- UI kelola cuti dan Cuti Saya tidak lagi menampilkan status.
- Validasi bentrok memeriksa semua cuti (tidak ada filter status).
- `ManHourService` membaca seluruh cuti yang tersimpan.

## Dampak

- Deploy akan menjalankan migrasi
  `2026_10_21_000001_drop_status_from_cuti_teknisis`.
- Semua cuti lama tetap berlaku, termasuk yang sebelumnya berstatus pending
  (tidak ada lagi konsep pending).
- Tidak ada perubahan pada data tanggal/jenis/alasan.

## Berkas

- `app/Models/CutiTeknisi.php`
- `app/Services/CutiService.php`
- `app/Services/Shift/ManHourService.php`
- `resources/views/cuti/kelola.blade.php`, `kelola-edit.blade.php`,
  `saya-index.blade.php`
- `database/migrations/2026_10_21_000001_drop_status_from_cuti_teknisis.php`
- `tests/Feature/CutiAdminTest.php`, `tests/Feature/CutiSayaTest.php`
- `docs/23-fitur-cuti.md`, `AGENTS.md`
- `tugas/erik.md`

## Uji

- `CutiAdminTest` + `CutiSayaTest`: 7 test hijau, 33 assertion.
- Seluruh suite: 699 test hijau, 3.418 assertion.
- `view:cache` sukses.

## Catatan deploy

Migrasi menghapus kolom `status`; backup DB otomatis dilakukan
`Deploy-Laporan.bat`. Tidak ada perubahan pada tabel `cuti_tokens` (tetap tidak
dipakai).
