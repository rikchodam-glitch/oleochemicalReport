# Papan Klaim: erik

Diperbarui: 2026-10-14 11:18 WIB

- Cabang aktif: `tugas/erik/hapus-keterangan-periode-default`
- Mulai: 2026-10-14 10:25
- Status: SELESAI (menunggu Pull Request #6 diperiksa integrator)
- Modul/berkas yang disentuh:
  - `resources/views/cm/overview.blade.php` (hapus keterangan "(bulan terakhir yang ada datanya)" pada baris Periode)
  - `tests/Feature/CmOverviewPeriodeTest.php` (BARU: penjaga baris Periode)
  - `tugas/erik.md` (papan klaim ini)
  - `tugas/pr-hapus-keterangan-periode-default.md` (BARU: isi Pull Request)
- Catatan: permintaan user 14/10/2026 lewat halaman DEV
  `http://oleochemicalreport.test/cm/overview?tahun=2026&bulan=10` — keterangan
  "(bulan terakhir yang ada datanya)" di samping nama bulan DIHAPUS. Baris
  `Periode: <bulan> <tahun>` TETAP ada (masih menandai periode aktif), dan
  perilaku default bulan (controller memilih bulan terakhir yang punya data bila
  `?bulan` tidak dikirim) TIDAK diubah. TIDAK ada migrasi baru.
- Catatan konflik 14/10/2026: saat rebase ke `main` terbaru (sesudah PR #5
  di-merge) berkas ini berkonflik karena `main` sudah memuat klaim cabang
  `aturan-wajib-perbarui-dev-lalu-buka-pr`. Diselesaikan dengan MENGAMBIL KEDUA
  SISI sesuai butir 6 bagian "ALUR KERJA BANYAK TEKNISI": klaim cabang
  `aturan-wajib-perbarui-dev-lalu-buka-pr` dan `siapkan-deploy-aman-saat-berkas-
  belum-di-commit` dipindahkan ke bagian "Cabang Sebelumnya", riwayat kedua sisi
  digabung, dan tidak ada bagian yang dibuang.
- Cara menyelesaikan, urutannya jangan dibalik:
  1. Pull Request #6 cabang ini SUDAH dibuat di GitHub (judul + isi disiapkan
     pada `tugas\pr-hapus-keterangan-periode-default.md`).
  2. integrator menekan "Merge pull request".
  3. kembalikan copy DEV ke cabang `main`.

## Cabang Sebelumnya (sudah di-merge)
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
- Catatan: TIDAK ada migrasi baru dan tidak menyentuh kode aplikasi.


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
- Catatan penomoran: dua cabang sudah DI-MERGE lebih dulu — lembur lewat Pull
  Request #2 (merge commit `f9b65c9`) dan sync CM lewat Pull Request #3 (merge
  commit `ec1154d`), keduanya 14/10/2026 — jadi Riwayat Insiden 44 dan 45 resmi
  milik `main`; cabang baru ini tidak menambah nomor insiden. Konflik `AGENTS.md`
  dan berkas ini diselesaikan saat rebase 14/10/2026 dengan aturan "ambil KEDUA
  sisi" (butir 6 bagian ALUR KERJA BANYAK TEKNISI), bukan dengan membuang satu
  sisi.
- Cara menyelesaikan (SUDAH SELESAI): Pull Request #3 dibuat, lalu DI-MERGE
  14/10/2026 (merge commit `ec1154d`).

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
- tugas/erik/siapkan-deploy-aman-saat-berkas-belum-di-commit (mulai 2026-10-14 09:57) -> di-merge lewat PR #4 (commit df5cec9)
- tugas/erik/aturan-wajib-perbarui-dev-lalu-buka-pr (mulai 2026-10-14 10:54) -> di-merge lewat PR #5 (commit 1990be8)
- tugas/erik/hapus-keterangan-periode-default (mulai 2026-10-14 10:25) -> SELESAI, menunggu Merge Pull Request #6

