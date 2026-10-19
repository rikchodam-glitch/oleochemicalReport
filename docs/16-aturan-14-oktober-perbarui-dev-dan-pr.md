# Aturan 14/10/2026: Perbarui DEV dan PR

> Bagian dari dokumentasi proyek di folder `docs`.
> Peta dokumen: `AGENTS.md` di akar repo.

## ATURAN WAJIB (14/10/2026): PERBARUI COPY DEV DULU, LALU LANGSUNG BUKA PULL REQUEST (BERLAKU DI SEMUA PC DEV)

Permintaan user 14/10/2026: "setiap melakukan editing buat agar AI-nya update dulu
project DEV-nya lalu setelah itu jalankan PR, agar saya bisa langsung cek di
halaman GitHub. Ini berlaku untuk project di semua PC Dev."

Aturan ini MELENGKAPI bagian "ALUR KERJA BANYAK TEKNISI" dan "ALUR KERJA SETIAP
SESI BARU" poin 7 (bukan menggantinya), dan dikerjakan TANPA perlu ditanya lagi.

### 1. SEBELUM menyunting: copy DEV WAJIB diperbarui lebih dulu

Satu perintah, dijalankan di AWAL sesi (sebelum berkas apa pun diubah):

```
powershell -ExecutionPolicy Bypass -File tools\mulai-tugas.ps1 -Nama <nama> -Tugas "<ringkas>"
```

Yang dikerjakannya: `git fetch origin main` -> `main` dimajukan (fast-forward) ->
cabang tugas `tugas/<nama>/<ringkas>` dibuat/dibuka -> papan klaim
`tugas/<nama>.md` diperbarui.

- JANGAN menyunting apa pun sebelum langkah ini selesai. Kalau pekerjaan dimulai
  dari `main` yang basi, seluruh perbedaan baru ketahuan saat
  `tools\unggah-tugas.ps1` me-rebase cabang ke `main` terbaru - justru pada saat
  perubahannya sudah banyak dan sudah tidak diingat lagi.
- Copy DEV yang menyimpan pekerjaan sesi lain yang belum di-commit adalah keadaan
  NORMAL di komputer integrator. Tambahkan SALAH SATU opsi, dan JANGAN
  meng-"bersihkan" keadaan itu dengan `git add -A`:
  `-AbaikanKotor` (dilanjutkan sambil diperingatkan; pekerjaan sesi lain
  dibiarkan apa adanya) atau `-PindahkanPerubahan` (disimpan ke `git stash` lalu
  dipulihkan dengan `git stash pop`).
- Bila `mulai-tugas.ps1` berhenti dengan "Your local changes to the following
  files would be overwritten by checkout" padahal isi `HEAD` sudah sama dengan
  `main`, penyebabnya `main` LOKAL yang masih tertinggal. Majukan ref-nya tanpa
  checkout: `git fetch origin main:main` (fast-forward, BUKAN commit baru), lalu
  ulangi `mulai-tugas.ps1`. Kejadian nyata 14/10/2026: `main` lokal masih sebelum
  PR terakhir sehingga `git checkout main` mau menimpa
  `tests/Feature/SiapkanDeployTest.php` yang sedang diubah sesi lain.
- Cabang tugas yang BELUM di-merge: jangan menumpuk tugas baru di atasnya
  diam-diam. Lanjutkan di cabang itu hanya bila tugasnya masih sama (rebase ke
  `main` terbaru lewat `unggah-tugas.ps1`); untuk tugas yang berbeda, buat cabang
  baru, dan sebutkan keadaan PR yang masih menggantung pada laporan ke user.

### 2. SESUDAH uji hijau + commit: LANGSUNG unggah dan siapkan Pull Request

Dua perintah berikut dikerjakan pada giliran yang SAMA, tanpa menunggu diminta
dan tanpa menunda ke sesi lain:

```
1. powershell -ExecutionPolicy Bypass -File tools\unggah-tugas.ps1
   (rebase ke main terbaru -> php artisan test -> push cabang tugas -> tautan PR)
2. powershell -ExecutionPolicy Bypass -File tools\buat-pr-tugas.ps1
   (menyusun isi PR dari tugas\pr-<ringkas cabang>.md + daftar commit/berkas
    dari git, menyalin isi ke papan klip, membuka peramban pada halaman
    "Compare & pull request" dengan judul sudah terisi)
```

- Bila masih ada pekerjaan sesi lain yang belum di-commit, jalankan
  `unggah-tugas.ps1 -AbaikanKotor`. Uji otomatis TETAP dijalankan; yang dilewati
  hanya hook `pre-push` (push memakai `--no-verify`), dan keadaan itu WAJIB
  disebutkan pada laporan ke user.
- Sertakan selalu berkas catatan `tugas\pr-<ringkas cabang>.md` (ringkasan +
  checklist + bukti uji + berkas yang disentuh) supaya isi PR yang disusun
  `buat-pr-tugas.ps1` langsung lengkap dan bisa di-review.

### 3. Yang WAJIB dilaporkan AI pada akhir sesi

1. Nama cabang tugas + hash commit.
2. Tautan Pull Request (penanda `PR_TAUTAN=...` dari `buat-pr-tugas.ps1`), atau
   penanda `PR_STATUS=...` bila PR belum bisa disiapkan (mis. belum diunggah).
3. Status PR: belum dibuat / siap diperiksa / sudah di-merge.
4. Daftar berkas kotor milik sesi lain yang SENGAJA tidak ikut di-commit.
5. Bila ada langkah yang tidak bisa dikerjakan agent (mis. menekan tombol di
   peramban), katakan apa adanya - jangan menyimpulkan "seharusnya sudah benar".

### 4. Larangan yang TIDAK berubah

1. `git push origin main` dan commit di cabang `main` - tetap DILARANG.
2. `git add -A` / `git add .` - tetap DILARANG (commit hanya berkas relevan).
3. Menunda commit, atau menggabungkan beberapa perubahan berbeda dalam satu
   commit - tetap DILARANG.
4. Mengunggah pekerjaan yang uji otomatisnya MERAH - tetap DILARANG.
5. Deploy ke PROD tetap HANYA di komputer integrator, manual lewat
   `Deploy-Laporan.bat` (ada konfirmasi `y`), dan tetap SESUDAH Merge.
6. GitHub TIDAK PERNAH membuat Pull Request sendiri: `buat-pr-tugas.ps1` hanya
   MENYIAPKAN judul + isi + peramban. Menekan "Create pull request" dan
   "Merge pull request" tetap pekerjaan manusia (lihat kepala berkas
   `tools\buat-pr-tugas.ps1`).

### 5. Aturan ini berlaku di SEMUA PC Dev

Setiap copy DEV di komputer mana pun (komputer integrator maupun komputer
teknisi lain) mengikuti urutan yang sama: perbarui dulu -> kerjakan di cabang
tugas -> commit -> unggah -> siapkan Pull Request. Perbedaannya HANYA satu dan
memang disengaja: yang menekan Merge dan menjalankan `Deploy-Laporan.bat` tetap
hanya komputer integrator (`rikchodam-glitch`), karena penjaga deploy memeriksa
copy DEV di komputer itu sendiri (bagian "6. Penjaga deploy").

Ringkasan perintah (dijalankan dari folder repo, di PC Dev mana pun):

| Keperluan | Perintah |
|---|---|
| Perbarui DEV + buka cabang tugas (SEBELUM menyunting) | `powershell -ExecutionPolicy Bypass -File tools\mulai-tugas.ps1 -Nama <nama> -Tugas "<ringkas>"` |
| Unggah cabang tugas + tautan PR (SESUDAH commit) | `powershell -ExecutionPolicy Bypass -File tools\unggah-tugas.ps1` |
| Siapkan isi PR + buka halaman GitHub | `powershell -ExecutionPolicy Bypass -File tools\buat-pr-tugas.ps1` |

Bukti alur ini sudah berjalan (diperiksa 14/10/2026 lewat GitHub API): PR #1
(`tugas/erik/potong-istirahat-laporan-non-shift`), PR #2
(`tugas/erik/lembur-tanpa-jam-istirahat-man-hour-tidak-dobel`), PR #3
(`tugas/erik/sync-cm-impor-langsung-di-dev`), dan PR #4
(`tugas/erik/siapkan-deploy-aman-saat-berkas-belum-di-commit`) semuanya masuk ke
`main` lewat Merge Pull Request; tidak ada satu pun push langsung ke `main`.


