# Papan Klaim: erik

Diperbarui: 2026-10-14 11:36 WIB

- Cabang aktif: `tugas/erik/catat-setelan-penguncian-cabang-main-github`
- Mulai: 2026-10-13 11:00
- Status: SELESAI (menunggu Merge Pull Request #7)
- Modul/berkas yang disentuh:
  - `AGENTS.md` (bagian 7 "Setelan GitHub yang harus dipasang integrator")
  - `tugas/erik.md` (papan klaim)
  - `tugas/pr-catat-setelan-penguncian-cabang-main-github.md` (BARU: isi Pull Request)
- Catatan: hanya dokumentasi; tidak menyentuh kode aplikasi, rute, maupun
  migrasi. Keputusan user 13/10/2026: repo tetap PUBLIK, penguncian `main`
  memakai `Required approvals = 1`, dan penyimpanannya tetap manual lewat web
  (belum tersimpan per pemeriksaan `api.github.com/.../branches/main` ->
  `"protected": false`).
- Catatan konflik 14/10/2026: berkas ini berkonflik DUA KALI saat rebase ke
  `main` yang bergerak cepat (sekali karena PR #5, sekali karena PR #6 — kedua
  PR itu juga menyunting papan klaim ini). Keduanya diselesaikan dengan
  MENGAMBIL KEDUA SISI sesuai butir 6 bagian "ALUR KERJA BANYAK TEKNISI":
  klaim cabang lain dipindahkan ke bagian "Cabang Sebelumnya" dan riwayat kedua
  sisi digabung, tidak ada bagian yang dibuang.
- Cara menyelesaikan, urutannya jangan dibalik:
  1. Pull Request #7 cabang ini SUDAH dibuat di GitHub.
  2. integrator menekan "Merge pull request".
  3. kembalikan copy DEV ke cabang `main`.

## Cabang Sebelumnya (sudah di-merge)
- `tugas/erik/hapus-keterangan-periode-default`
- Mulai: 2026-10-14 10:25
- Status: SELESAI - SUDAH DI-MERGE lewat Pull Request #6 (merge commit `9781ff8`, 14/10/2026)
- Modul/berkas yang disentuh:
  - `resources/views/cm/overview.blade.php` (hapus keterangan "(bulan terakhir yang ada datanya)" pada baris Periode)
  - `tests/Feature/CmOverviewPeriodeTest.php` (BARU: penjaga baris Periode)
  - `tugas/erik.md` (papan klaim ini)
  - `tugas/pr-hapus-keterangan-periode-default.md` (isi Pull Request)

- `tugas/erik/aturan-wajib-perbarui-dev-lalu-buka-pr`
- Mulai: 2026-10-14 10:54
- Status: SELESAI - SUDAH DI-MERGE lewat Pull Request #5 (merge commit `1990be8`, 14/10/2026)
- Modul/berkas yang disentuh:
  - `AGENTS.md` (aturan baru di ujung berkas + blok penunjuk pada "ALUR KERJA
    SETIAP SESI BARU" poin 7c)
  - `tugas/erik.md` (papan klaim ini)
  - `tugas/pr-aturan-wajib-perbarui-dev-lalu-buka-pr.md` (isi Pull Request)
- Catatan: murni aturan/perkakas alur kerja; TIDAK menyentuh kode aplikasi,
  rute, blade, test, migrasi, `.env`, maupun `.bat`, jadi PROD tidak terpengaruh.

- `tugas/erik/siapkan-deploy-aman-saat-berkas-belum-di-commit`
- Mulai: 2026-10-14 09:57
- Status: SELESAI - SUDAH DI-MERGE lewat Pull Request #4 (merge commit `df5cec9`, 14/10/2026)
- Modul/berkas yang disentuh:
  - `tools/siapkan-deploy.ps1` (BARU: menyeragamkan copy DEV walau ada berkas belum di-commit)
  - `tools/penjaga-main-dev.ps1` + `tools/kembalikan-ke-main.ps1` (menyebut nama berkas penghalang `PENGHALANG=`)
  - `tools/deploy-laporan-prod.ps1` (petunjuk lama yang menyesatkan dihapus)
  - `tests/Feature/SiapkanDeployTest.php` (BARU: 10 test)
  - `AGENTS.md` (bagian 6b + Riwayat Insiden 46 + daftar peluncur Desktop)

- `tugas/erik/sync-cm-impor-langsung-di-dev`
- Mulai: 2026-10-13
- Status: SELESAI - SUDAH DI-MERGE lewat Pull Request #3 (merge commit `ec1154d`, 14/10/2026)
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
- tugas/erik/sync-cm-impor-langsung-di-dev (mulai 2026-10-13) -> di-merge lewat PR #3 (commit ec1154d)
- tugas/erik/alur-kerja-banyak-teknisi (mulai 2026-10-13 10:44) -> di-merge ke `main`
- tugas/erik/catat-setelan-penguncian-cabang-main-github (mulai 2026-10-13 11:00) -> SELESAI, menunggu Merge Pull Request #7
- tugas/erik/siapkan-deploy-aman-saat-berkas-belum-di-commit (mulai 2026-10-14 09:57) -> di-merge lewat PR #4 (commit df5cec9)
- tugas/erik/aturan-wajib-perbarui-dev-lalu-buka-pr (mulai 2026-10-14 10:54) -> di-merge lewat PR #5 (commit 1990be8)
- tugas/erik/hapus-keterangan-periode-default (mulai 2026-10-14 10:25) -> di-merge lewat PR #6 (commit 9781ff8)

