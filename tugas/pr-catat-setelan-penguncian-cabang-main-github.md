# Catat setelan penguncian cabang main GitHub di AGENTS.md bagian 7

## Ringkasan perubahan

Pencatatan hasil penyetelan 13/10/2026 tentang PENGUNCIAN cabang `main` di
GitHub, supaya langkahnya tidak perlu ditemukan ulang dari nol dan jebakan yang
mudah terlewat tidak terulang.

Murni dokumentasi: TIDAK ada kode aplikasi, rute, blade, test, migrasi, `.env`,
maupun `.bat` yang berubah, sehingga PROD tidak terpengaruh.

1. `AGENTS.md` bagian 7 "Setelan GitHub yang harus dipasang integrator":
   - keputusan user 13/10/2026 dicatat eksplisit: repo tetap PUBLIK dan
     penguncian memakai **Required approvals = 1**,
   - langkahnya diurai menjadi 1-7 dengan tiga jebakan yang mudah terlewat:
     **Restrict updates** JANGAN dicentang (memblokir SELURUH pembaruan ref
     `main`, termasuk Merge Pull Request, sehingga alurnya beku),
     **Require branches to be up to date** letaknya DI DALAM **Require status
     checks to pass**, dan **Do not allow bypassing the above settings** berada
     di bagian bawah halaman,
   - cara membuktikan penguncian sudah aktif tanpa login (baca
     `api.github.com/.../branches/main` lalu cari `"protected"`),
   - cara memberi akses teknisi: **Settings -> Collaborators and teams** dengan
     peran **Write** saja.
2. `tugas/erik.md`: papan klaim (modul yang disentuh + status).
3. `tugas/pr-catat-setelan-penguncian-cabang-main-github.md`: berkas catatan ini.

## Checklist wajib (agenda integrator)

- [x] `php artisan test` HIJAU (seluruh suite; angkanya pada bagian "Bukti uji").
- [x] Perubahan HANYA pada berkas yang relevan (bukan `git add -A`); pekerjaan
      sesi lain di copy DEV TIDAK ikut di-commit: `.env.example`, `.gitignore`,
      `RENCANA_MODUL_IT_ASSETS.md` + `RENCANA_MODUL_RUANG_PANEL.md` (terhapus),
      `app/Jobs/ProcessCmFindingsImport.php`,
      `resources/views/reports/partials/_temuan.blade.php`,
      `tests/Feature/SiapkanDeployTest.php`, `.kilo/`,
      `app/Console/Commands/CmDriveCheck.php`.
- [x] TIDAK ada migrasi baru, tidak ada perubahan `.env`/`.bat`, dan TIDAK ada
      blade yang disentuh (karena itu `php artisan view:cache` tidak perlu).
- [x] Sudah `git rebase origin/main` (bukan merge); cabang berdiri tepat di atas
      `9781ff8` (main SESUDAH PR #6 di-merge, 14/10/2026). Rebase dikerjakan DUA
      KALI karena `main` bergerak saat PR diperiksa (PR #5 lalu PR #6 — keduanya
      juga menyunting papan klaim ini). Konflik
      `tugas/erik.md` diselesaikan dengan "ambil KEDUA sisi" sesuai butir 6
      bagian "ALUR KERJA BANYAK TEKNISI": klaim cabang
      `aturan-wajib-perbarui-dev-lalu-buka-pr` dipindahkan ke bagian "Cabang
      Sebelumnya" dan riwayat kedua sisi digabung, tidak ada yang dibuang.

## Bukti uji

```
php artisan test        (seluruh suite, 14/10/2026, copy DEV
                         C:\laragon\www\oleochemicalReport)
  Tests:    591 passed (2821 assertions)
  Duration: 145.12s
  EXIT_TEST=0

git --no-pager diff --stat origin/main...HEAD
  AGENTS.md                                          |  59 +++++++++++-
  tugas/erik.md                                      |  67 ++++++-------
  ...-catat-setelan-penguncian-cabang-main-github.md | 104 +++++++++++++++++++++
  3 files changed, 189 insertions(+), 41 deletions(-)

pemeriksaan penanda konflik pada papan klaim
(^(<<<<<<<|=======|>>>>>>>)):
  0 kemunculan

pemeriksaan hasil auto-merge AGENTS.md bagian 7:
  langkah 1-7 utuh, "Restrict updates" tetap disertai peringatan JANGAN
  dicentang, tidak ada langkah yang dobel
```

Catatan: uji dijalankan pada copy DEV yang berdiri di `main` karena cabang ini
HANYA mengubah berkas dokumentasi (`AGENTS.md`, `tugas/erik.md`) — isi kode yang
diuji identik dengan isi cabang, jadi hasilnya sah untuk cabang ini. Angka 591
ini juga cocok dengan cabang yang di-merge lewat PR #6 (`main` sekarang memuat
`CmOverviewPeriodeTest` berisi 2 test baru).

## Berkas yang disentuh

```
1. AGENTS.md     - bagian 7 "Setelan GitHub yang harus dipasang integrator": langkah 1-7
                   + keputusan user 13/10/2026 + cara memeriksa "protected" + akses Write
2. tugas/erik.md - papan klaim (modul yang disentuh + status + riwayat)
3. tugas/pr-catat-setelan-penguncian-cabang-main-github.md - berkas catatan PR ini
```

## Risiko / hal yang perlu diperhatikan integrator

- **Hanya dokumentasi.** Tidak ada perubahan query, route, config, blade, maupun
  struktur database; tidak ada `.env` yang perlu diubah dan tidak perlu build
  aset. Deploy cukup lewat `Deploy-Laporan.bat` seperti biasa.
- Catatan di `AGENTS.md` masih memuat hasil pemeriksaan 13/10/2026
  (`"protected": false`, setelan belum disimpan). Bila integrator SUDAH
  menyimpan penguncian itu, kalimatnya boleh diperbarui pada commit berikutnya —
  bukan penghalang merge, karena inti catatannya adalah cara menyetel, bukan
  status sesaat itu.
- Nama cabang ikut disebut di papan klaim, jadi `tugas/erik.md` memang sengaja
  diperbarui di cabang ini.
- Berkas `tugas/erik.md` sempat KONFLIK saat rebase ke `main` (karena `main`
  sudah memuat klaim cabang `aturan-wajib-perbarui-dev-lalu-buka-pr`). Sesuai
  aturan repo, konflik diselesaikan dengan "ambil KEDUA sisi": isi `main` dan
  isi cabang sama-sama dipertahankan.

      `AGENTS.md` ter-merge otomatis tanpa konflik (diperiksa: langkah 1-7 utuh,
      tidak ada langkah yang dobel).
- [x] Tidak ada berkas sampah baru (tidak ada skrip sementara yang ditinggal).
