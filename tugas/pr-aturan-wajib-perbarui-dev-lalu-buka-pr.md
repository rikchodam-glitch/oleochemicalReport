# Aturan wajib: perbarui copy DEV dulu, lalu langsung buka Pull Request (berlaku di semua PC Dev)

## Ringkasan perubahan

Permintaan user 14/10/2026: setiap kali AI mengerjakan editing di project ini, AI
WAJIB (1) memperbarui copy DEV lebih dulu, lalu (2) langsung menjalankan
Pull Request supaya user bisa memeriksa halaman GitHub saat itu juga. Aturan itu
berlaku untuk semua PC Dev, bukan hanya komputer integrator.

Perubahan ini MURNI aturan/perkakas alur kerja: TIDAK ada kode aplikasi, rute,
blade, test, migrasi, `.env`, maupun `.bat` yang berubah, sehingga PROD tidak
terpengaruh sampai aturan ini di-merge dan deploy dijalankan seperti biasa.

1. **`AGENTS.md`**: bagian BARU di ujung berkas, "ATURAN WAJIB (14/10/2026):
   PERBARUI COPY DEV DULU, LALU LANGSUNG BUKA PULL REQUEST (BERLAKU DI SEMUA PC
   DEV)". Isinya:
   - urutan perintah baku SEBELUM menyunting (`tools\mulai-tugas.ps1`; opsi
     `-AbaikanKotor`/`-PindahkanPerubahan` untuk pekerjaan sesi lain yang belum
     di-commit),
   - jebakan nyata 14/10/2026: `mulai-tugas.ps1` berhenti "Your local changes to
     the following files would be overwritten by checkout" karena `main` LOKAL
     masih tertinggal; penanganannya `git fetch origin main:main` (fast-forward,
     bukan commit) lalu ulangi — kejadian ini benar-benar dialami saat mengerjakan
     PR ini, jadi bukan dugaan,
   - urutan baku SESUDAH uji hijau + commit (`tools\unggah-tugas.ps1` lalu
     `tools\buat-pr-tugas.ps1`) pada giliran yang sama,
   - daftar yang WAJIB dilaporkan AI di akhir sesi (cabang, hash, tautan PR,
     status PR, berkas kotor sesi lain yang tidak ikut di-commit),
   - larangan yang tidak berubah (push/commit di `main`, `git add -A`, menunda
     commit, mengunggah yang merah, deploy di luar komputer integrator, dan fakta
     bahwa GitHub tidak pernah membuat PR sendiri),
   - tabel ringkasan tiga perintah untuk semua PC Dev.
2. **`AGENTS.md`**: blok penunjuk pada "ALUR KERJA SETIAP SESI BARU" poin 7c
   supaya aturan baru itu terbaca langsung dari aturan commit yang sudah ada.
3. **`tugas/erik.md`**: papan klaim (berkas yang disentuh + status).
4. **`tugas/pr-aturan-wajib-perbarui-dev-lalu-buka-pr.md`**: berkas catatan ini,
   dipakai `buat-pr-tugas.ps1` sebagai badan Pull Request.

## Checklist wajib (agenda integrator)

- [x] `php artisan test` HIJAU (589 test, 2815 assertions, 122,48 detik).
- [x] Perubahan HANYA pada berkas yang relevan (bukan `git add -A`); pekerjaan
      sesi lain di copy DEV TIDAK ikut di-commit: `.env.example`, `.gitignore`,
      `RENCANA_MODUL_IT_ASSETS.md` + `RENCANA_MODUL_RUANG_PANEL.md` (terhapus),
      `app/Jobs/ProcessCmFindingsImport.php`,
      `resources/views/reports/partials/_temuan.blade.php`,
      `tests/Feature/SiapkanDeployTest.php`, `.kilo/`,
      `app/Console/Commands/CmDriveCheck.php`.
- [x] Tidak ada migrasi baru, tidak ada perubahan `.env`/`.bat`, tidak ada blade.
- [x] `AGENTS.md` diperbarui (aturan baru + penunjuk poin 7c).
- [x] Sudah `git rebase origin/main` lewat `tools\unggah-tugas.ps1`; cabang berdiri
      di atas `df5cec9` (merge PR #4).
- [x] Tidak ada berkas sampah baru (tidak ada skrip sementara yang ditinggal).

## Bukti uji

```
php artisan test        (seluruh suite, 14/10/2026)
  Tests:    589 passed (2815 assertions)
  Duration: 122.48s

tools\mulai-tugas.ps1 -Nama erik -Tugas "aturan wajib perbarui dev lalu buka pr" -AbaikanKotor
  [mulai] PERINGATAN: 7 berkas ter-track belum di-commit (diizinkan lewat -AbaikanKotor).
  [mulai] main sudah sejajar dengan origin/main.
  [mulai] Cabang tugas dibuat: tugas/erik/aturan-wajib-perbarui-dev-lalu-buka-pr
  [mulai] papan klaim diperbarui: tugas\erik.md
  CABANG_TUGAS=tugas/erik/aturan-wajib-perbarui-dev-lalu-buka-pr

Percobaan pertama SEBELUM ref main lokal dimajukan (bukti jebakan di atas):
  [mulai] GAGAL: tidak bisa berpindah ke cabang main.
         error: Your local changes to the following files would be overwritten by checkout:
         tests/Feature/SiapkanDeployTest.php
         Please commit your changes or stash them before you switch branches.
  -> diperbaiki dengan `git fetch origin main:main` (ec1154d..df5cec9, fast-forward),
     berkas sesi lain TIDAK diubah dan TIDAK di-commit.
```

## Berkas yang disentuh

```
1. AGENTS.md                                          - aturan wajib baru di ujung berkas + penunjuk poin 7c
2. tugas/erik.md                                      - papan klaim
3. tugas/pr-aturan-wajib-perbarui-dev-lalu-buka-pr.md - isi Pull Request ini
```

## Risiko / hal yang perlu diperhatikan integrator

- Murni dokumentasi/aturan: penggabungannya tidak mengubah perilaku aplikasi sama
  sekali, tidak ada langkah deploy khusus (tidak ada migrasi, `.env`, `.bat`,
  maupun build aset). `Deploy-Laporan.bat` biasa sudah cukup bila ingin
  memasangkannya ke PROD.
- Aturan baru ini hanya "hidup" kalau dibaca: karena itu `AGENTS.md` diminta
  dibaca penuh setiap sesi baru, dan penunjuknya juga ditaruh pada aturan commit
  yang sudah ada (poin 7c) supaya tidak terlewat.
- Tidak ada perubahan pada `.bat`, jadi tidak ada urusan akhiran baris CRLF
  (Riwayat Insiden #39) yang perlu diperiksa.
