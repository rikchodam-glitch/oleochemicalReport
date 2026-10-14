# Papan Klaim: erik

Diperbarui: 2026-10-14 WIB

- Cabang aktif: `tugas/erik/lembur-tanpa-jam-istirahat-man-hour-tidak-dobel`
- Mulai: 2026-10-13 15:33
- Status: SELESAI - MENUNGGU PULL REQUEST DIBUAT LALU DI-MERGE
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
- Cara menyelesaikan, urutannya jangan dibalik:
  1. klik `Buat-PR-Laporan.bat` di Desktop, lalu tekan "Create pull request" di GitHub.
  2. integrator menekan "Merge pull request".
  3. klik `Kembali-Ke-Main-Laporan.bat`; kalau layarnya berbunyi SIAP, deploy boleh lanjut.

## Riwayat
- tugas/erik/potong-istirahat-laporan-non-shift (mulai 2026-10-13 11:34) -> di-merge lewat PR #1 (commit cf43b2a)
- tugas/erik/lembur-tanpa-jam-istirahat-man-hour-tidak-dobel (mulai 2026-10-13 15:33)

