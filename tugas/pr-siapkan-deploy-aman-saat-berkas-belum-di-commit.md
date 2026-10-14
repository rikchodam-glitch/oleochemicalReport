# Deploy tidak lagi macet karena berkas belum di-commit: alat BARU `tools\siapkan-deploy.ps1`

## Ringkasan perubahan

Melengkapi alur banyak teknisi setelah kejadian nyata 14/10/2026 pagi:
`Deploy-Laporan.bat` DIBATALKAN penjaga walaupun copy DEV hanya tertinggal satu
commit. Sebabnya: `main` GitHub sudah `ec1154d` (PR #3), `main` copy DEV masih
`f9b65c9`, DAN `.env.example` yang diubah sesi lain di copy DEV juga diubah PR
itu, sehingga `git merge --ff-only` menolak menimpanya ("Please commit your
changes or stash them before you merge"). Petunjuk penjaga saat itu menyesatkan:
ia menyuruh `git checkout main` + `git pull --ff-only origin main` - DUA perintah
yang gagal dengan sebab yang SAMA.

Tidak ada migrasi baru, tidak ada berkas blade yang disentuh, dan tidak ada
perilaku aplikasi yang berubah: seluruh perubahan ada di perkakas alur kerja.

1. **Alat BARU `tools/siapkan-deploy.ps1`.** Mencari sendiri berkas yang
   menghalangi (`git diff --name-only` antara `HEAD` dan commit tujuan, dipotong
   dengan daftar `git status --porcelain`), mencadangkannya ke
   `C:\laragon\backup-oleochemical\siapkan-deploy\dev-<cap waktu>\` (beserta
   `CATATAN.txt`), memindahkannya sementara ke `git stash`, memajukan copy DEV
   dengan fast-forward, lalu MENGEMBALIKAN perubahan itu sebagai perubahan yang
   tetap BELUM di-commit (tidak dibuatkan commit atas nama orang lain).
   Bila pengembaliannya bentrok (dua sisi menambah di tempat berdekatan), KEDUA
   isi digabung otomatis dan baris penanda bentrok dibuang. Opsi `-Kering` hanya
   melaporkan, `-JanganGabung` berhenti tanpa menggabungkan. Alat ini BERHENTI
   bila copy DEV berada di cabang tugas atau cabang utama lokal punya commit
   sendiri (itu urusan Pull Request, bukan penyeragaman commit).
2. **`tools/penjaga-main-dev.ps1`** menyebut nama berkas penghalang
   (`PENGHALANG=...`) dan menunjuk `tools\siapkan-deploy.ps1`, bukan lagi
   menyuruh perintah manual yang gagal dengan sebab yang sama. Kasus berbeda
   (bercabang / bukan di cabang main) juga diberi petunjuk yang tepat.
3. **`tools/kembalikan-ke-main.ps1`** membedakan DUA sebab kegagalan
   fast-forward: berkas belum di-commit (menunjuk `siapkan-deploy.ps1`) versus
   cabang utama lokal punya commit sendiri. Sebelumnya keduanya dijelaskan
   sebagai "punya commit sendiri", yang menyesatkan.
4. **`tools/deploy-laporan-prod.ps1`** tidak lagi menyarankan `git checkout main`
   + `git pull --ff-only origin main` saat penjaga membatalkan deploy.
5. **`AGENTS.md`**: bagian baru "6b" (cara kerja alat + urutan kerja), 2 baris
   pada tabel ringkasan perintah, dan Riwayat Insiden 46.
6. **Uji otomatis `tests/Feature/SiapkanDeployTest.php`** (10 test) memakai repo
   git TIRUAN di folder sementara; copy DEV dan PROD yang sebenarnya tidak
   pernah disentuh.

## Checklist wajib (agenda integrator)

- [x] `php artisan test` HIJAU (589 test, 2815 assertions, 150,03 detik).
- [x] `php artisan view:cache` dijalankan sebagai pemeriksaan: sukses (tidak ada
      berkas blade yang disentuh).
- [x] Perubahan HANYA pada berkas yang relevan (bukan `git add -A`); pekerjaan
      sesi lain di copy DEV (`.env.example`, `.gitignore`, `RENCANA_MODUL_*.md`
      yang terhapus, `ProcessCmFindingsImport.php`, `_temuan.blade.php`,
      `CmDriveCheck.php`, `.kilo/`) TIDAK ikut di-commit.
- [x] Tidak ada berkas sampah (skrip uji sementara di folder `%TEMP%` sudah
      dihapus; repo git tiruan uji dibersihkan `afterEach`).
- [x] TIDAK ada migrasi baru.
- [x] Sudah `git rebase origin/main` lewat `tools\unggah-tugas.ps1` (cabang
      berdiri di atas `ec1154d`, main sesudah PR #3).
- [x] `AGENTS.md` diperbarui + Riwayat Insiden 46 ditambahkan.

## Bukti uji

```
php artisan test --filter=SiapkanDeployTest
  Tests:    10 passed (60 assertions)   Duration: 61,76s

php artisan test        (seluruh suite)
  Tests:    589 passed (2815 assertions)   Duration: 150,03s

Uji mutasi 14/10/2026 (dua mutasi di tools/siapkan-deploy.ps1 sekaligus:
membiarkan $penghalang kosong + mematikan penggabungan bentrok)
  Tests:    3 failed, 1 passed (12 assertions)   Duration: 28,20s
  -> sesudah mutasi dikembalikan, 10 test hijau lagi
```

Bukti uji nyata ke repo git tiruan SEBELUM uji otomatis ditulis (folder
sementara, copy DEV/PROD tidak disentuh):

```
[siapkan] Fast-forward terhalang 1 berkas yang belum di-commit (pekerjaan sesi lain):
   - catatan.txt
[siapkan] Bentrok digabung: isi perubahan sesi lain DAN isi commit tujuan sama-sama dipertahankan.
GABUNG_BENTROK=ya
SIAPKAN_DEPLOY=SIAP
isi catatan.txt : baris awal / BLOK-DARI-REMOTE / BLOK-DARI-SESI-LAIN
status          : " M catatan.txt" (tetap BELUM di-commit), jumlah commit tetap 2, stash kosong
```

## Berkas yang disentuh

```
1. tools/siapkan-deploy.ps1                      - BARU: penyeragam commit DEV yang aman
2. tools/penjaga-main-dev.ps1                    - pesan + daftar berkas penghalang (PENGHALANG=...)
3. tools/kembalikan-ke-main.ps1                  - pesan yang sama untuk jalur kembali ke main
4. tools/deploy-laporan-prod.ps1                 - kalimat saran saat penjaga membatalkan deploy
5. tests/Feature/SiapkanDeployTest.php           - BARU: 10 test memakai repo git tiruan
6. AGENTS.md                                     - bagian 6b, tabel perintah, Riwayat Insiden 46
7. tugas/erik.md                                 - papan klaim (diperbarui mulai-tugas.ps1)
8. tugas/pr-siapkan-deploy-aman-saat-berkas-belum-di-commit.md - isi Pull Request ini
```

## Risiko / hal yang perlu diperhatikan integrator

- Alat baru ini MEMINDAHKAN SEMENTARA berkas yang belum di-commit milik sesi lain.
  Karena itu jalankan saat tidak ada sesi lain sedang menulis berkas itu. Tidak
  ada pekerjaan yang hilang: berkas dicadangkan ke
  `C:\laragon\backup-oleochemical\siapkan-deploy\dev-<cap waktu>\` DAN disimpan di
  `git stash`, lalu dikembalikan sebagai perubahan belum di-commit (tanpa commit
  baru, tanpa `git reset --hard`, tanpa menyentuh berkas lain). Bila penggabungan
  bentrok tidak diinginkan, pakai `-JanganGabung` supaya berhenti dengan penanda
  bentrok dibiarkan seperti sebelumnya.
- Tidak ada langkah deploy khusus: tidak ada migrasi, tidak ada perubahan `.env`,
  tidak ada perubahan `.bat`, tidak ada build aset. `Deploy-Laporan.bat` biasa
  sudah cukup.
- Alat ini HANYA menyentuh copy DEV; copy PROD tidak pernah disentuh.
- Sifatnya perkakas: kalau di kemudian hari perilaku git berubah, uji
  `SiapkanDeployTest` akan menangkapnya karena seluruh skenario dijalankan pada
  repo git tiruan yang nyata, bukan tiruan palsu di tingkat PHP.
