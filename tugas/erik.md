# Papan Klaim: erik

Diperbarui: 2026-10-14 WIB

- Cabang aktif: `tugas/erik/sync-cm-impor-langsung-di-dev`
- Mulai: 2026-10-13
- Status: SELESAI - MENUNGGU PULL REQUEST DIBUAT LALU DI-MERGE
- Modul/berkas yang disentuh:
  - `config/cm.php` (kunci baru `google_sheets.import_mode`)
  - `.env.example` (contoh `CM_SYNC_IMPORT_MODE`, dikomentari)
  - `app/Jobs/SyncGoogleSheetsJob.php` (mode inline + jaring pengaman `import_logs`)
  - `app/Console/Commands/SyncGoogleSheetsCommand.php` (opsi `--langsung`)
  - `tests/Feature/CmSyncImportModeTest.php` (BARU: penjaga mode inline vs queue)
  - `AGENTS.md` (Riwayat Insiden 45 + catatan service DEV pada bagian Service)
  - `tugas/pr-sync-cm-impor-langsung-di-dev.md` (BARU: isi Pull Request)
- Catatan: TIDAK ada migrasi baru. DEV tidak menjalankan service `queue:work`,
  jadi sync Google Sheets dahulu hanya menumpuk job di tabel `jobs` dan baris
  `import_logs` tertinggal 'processing' (baris 13/10/2026 tidak pernah masuk
  aplikasi). Kini DEV mengimpor LANGSUNG (mode 'inline'), sedangkan PROD tetap
  'queue' sehingga perilaku PROD TIDAK berubah.
- Catatan penomoran: cabang `tugas/erik/lembur-tanpa-jam-istirahat-man-hour-tidak-dobel`
  sudah DI-MERGE lebih dulu lewat Pull Request #2 (merge commit `f9b65c9`,
  14/10/2026), jadi Riwayat Insiden 44 resmi milik `main`; entri insiden di
  cabang ini memakai 45 supaya tidak ada nomor kembar. Konflik `AGENTS.md` dan
  berkas ini diselesaikan saat rebase 14/10/2026 dengan aturan "ambil KEDUA
  sisi" (butir 6 bagian ALUR KERJA BANYAK TEKNISI), bukan dengan membuang satu
  sisi.
- Cara menyelesaikan, urutannya jangan dibalik:
  1. buat Pull Request cabang ini di GitHub (judul + isi sudah disiapkan pada
     `tugas\pr-sync-cm-impor-langsung-di-dev.md`), lalu tekan "Create pull request".
  2. integrator menekan "Merge pull request".
  3. kembalikan copy DEV ke cabang `main`; deploy HANYA kalau integrator memutuskan begitu.

## Cabang Sebelumnya (sudah di-merge)
- `tugas/erik/lembur-tanpa-jam-istirahat-man-hour-tidak-dobel`
- Mulai: 2026-10-13 15:33
- Status: SELESAI - SUDAH DI-MERGE lewat Pull Request #2 (merge commit `f9b65c9`)
- Modul/berkas yang disentuh:
  - `app/Services/Shift/JamKerjaService.php` (rumus: istirahat bukan lembur)
  - `app/Services/Shift/ManHourService.php` (man hour dihitung dari jam laporan; `menitPerTeknisi`, `lemburPerTanggal`)
  - `app/Models/Report.php` (penghapusan laporan: foto bersama + baris salinan)
  - `app/Console/Commands/ReportPangkasIstirahatCommand.php` (keterangan perintah)
  - `resources/views/reports/partials/_waktu-kerja.blade.php` (angka tampilan + skrip rumus baru)
  - `resources/views/reports/partials/_waktu-kerja-lihat.blade.php` (BARU: mode lihat)
  - `resources/views/reports/show.blade.php` (blok durasi mode lihat jadi 1 baris @include)
  - `resources/views/technicians/partials/_modal-lembur.blade.php` (kalimat definisi lembur)
  - `tests/Feature/ReportLemburTanpaIstirahatTest.php` (BARU, 7 test)
  - `tests/Feature/ReportHapusSalinanAmanTest.php` (BARU, 5 test)
  - `tests/Feature/TechnicianLemburTest.php` (disesuaikan dengan aturan baru)
  - `tools/buat-pr-tugas.ps1` (BARU 14/10: menyiapkan Pull Request satu klik)
  - `tools/kembalikan-ke-main.ps1` (BARU 14/10: `git checkout main` + verifikasi penjaga)
  - `tugas/pr-lembur-tanpa-jam-istirahat-man-hour-tidak-dobel.md` (BARU: isi PR)
  - peluncur Desktop `Buat-PR-Laporan.bat` + `Kembali-Ke-Main-Laporan.bat` (di luar repo)
  - `AGENTS.md` (butir 32 + Riwayat Insiden 44 + daftar peluncur Desktop)
- Catatan: TIDAK ada migrasi baru. Perubahan menyentuh perhitungan man hour (definisi lembur/istirahat) dan keamanan penghapusan laporan; halaman yang tampil berubah: detail laporan (durasi + lembur) dan profil teknisi (penanda lembur).

## Riwayat
- tugas/erik/potong-istirahat-laporan-non-shift (mulai 2026-10-13 11:34) -> di-merge lewat PR #1 (commit cf43b2a)
- tugas/erik/lembur-tanpa-jam-istirahat-man-hour-tidak-dobel (mulai 2026-10-13 15:33) -> di-merge lewat PR #2 (commit f9b65c9)
- tugas/erik/sync-cm-impor-langsung-di-dev (mulai 2026-10-13)


