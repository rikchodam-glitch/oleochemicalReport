# Alur Kerja Banyak Teknisi (Wajib)

> Bagian dari dokumentasi proyek di folder `docs`.
> Peta dokumen: `AGENTS.md` di akar repo.

## ALUR KERJA BANYAK TEKNISI (WAJIB — SEMUA AGENT AI)

Sejak 13/10/2026 project ini dikerjakan **beberapa teknisi sekaligus, masing-masing
memakai asisten AI di komputernya sendiri**, dan setiap teknisi meng-clone repo
dari GitHub. Karena `Deploy-Laporan.bat` memasang ke PROD apa pun yang ada di
cabang `main` GitHub, tanpa aturan ketat pekerjaan satu orang bisa terpasang ke
produksi tanpa di-review, atau saling menimpa. Aturan di bawah ini WAJIB dipatuhi
tanpa perlu ditanya lagi.

**Aturan pokoknya satu kalimat: JANGAN pernah bekerja atau mengunggah di cabang
`main`; kerjakan di cabang `tugas/<nama>/<ringkas>`, uji dulu, unggah lewat
`tools\unggah-tugas.ps1`, lalu tunggu integrator meng-merge Pull Request.**

### 1. Peran

| Peran | Tugas |
|---|---|
| **Teknisi** | Mengerjakan perubahan di cabang tugas sendiri, uji, lalu membuka Pull Request. Termasuk USER sendiri: cara kerjanya SAMA, tidak ada pengecualian. |
| **Integrator** (`rikchodam-glitch`, pemilik repo) | Memeriksa Pull Request lalu menekan Merge. HANYA komputer integrator yang menjalankan `Deploy-Laporan.bat`. |

### 2. Cabang yang dipakai

| Cabang | Fungsi | Boleh diisi lewat |
|---|---|---|
| `main` | Produksi; sumber commit yang dipasang `Deploy-Laporan.bat` | HANYA Merge Pull Request |
| `tugas/<nama>/<ringkas>` | Pekerjaan satu orang (mis. `tugas/vicky/pompa-vibrasi`) | pemiliknya sendiri |

**DILARANG** (semuanya akan diblokir penjaga/hook kalau dicoba):

- `git push origin main` (push langsung ke produksi) — `tools\unggah-tugas.ps1`
  menolak jalan bila cabang aktifnya `main`.
- commit di cabang `main` di komputer mana pun.
- commit di cabang milik orang lain.
- `git add -A` / `git add .` — commit hanya berkas yang relevan (aturan lama
  "ALUR KERJA SETIAP SESI BARU" poin 7b tetap berlaku).

### 3. Alur wajib: mulai -> edit -> uji -> baru unggah

1. **SEKALI per clone**: `powershell -ExecutionPolicy Bypass -File tools\siapkan-clone.ps1`
   - menunjuk `core.hooksPath` ke `.githooks` (tanpa ini hook tidak aktif, karena
     folder `.git/hooks` tidak ikut ter-clone),
   - memeriksa php/composer/node (ketiganya TIDAK selalu ada di PATH),
   - membuat `.env` dari `.env.example` bila belum ada.
2. **Mulai tugas**:
   `powershell -ExecutionPolicy Bypass -File tools\mulai-tugas.ps1 -Nama vicky -Tugas "perbaiki man hour kolaborasi"`
   - `git fetch` lalu `main` dimajukan (fast-forward),
   - cabang `tugas/vicky/...` dibuat/dibuka,
   - papan klaim `tugas/vicky.md` diperbarui,
   - **menampilkan pekerjaan teknisi lain yang sedang berjalan**.
3. **Isi papan klaim** (`tugas/<nama>.md`) bagian `Modul/berkas yang disentuh`
   dengan daftar berkas/modul yang akan diubah. Ini pemberitahuan resmi ke
   rekan lain; tanpa ini orang lain tidak tahu bagian mana yang sedang dipakai.
4. **Kerjakan perubahan** mengikuti seluruh aturan yang sudah ada di berkas ini
   (Aturan Wajib Edit File, Verifikasi Dulu Baru Koding, dst).
5. **Uji dulu**: `php artisan test` WAJIB hijau. Bila menyentuh blade:
   `php artisan view:cache` juga harus sukses.
6. **Commit** segera setelah uji lulus: satu perubahan = satu commit, pesan Bahasa
   Indonesia, hanya berkas terkait yang di-`git add`.
7. **Unggah**: `powershell -ExecutionPolicy Bypass -File tools\unggah-tugas.ps1`
   - menolak jalan bila berada di `main` atau ada berkas ter-track belum commit,
   - `git fetch origin main` lalu `git rebase origin/main` (konflik -> rebase
     dibatalkan otomatis dan diberi petunjuk),
   - menjalankan `tools\cek-sebelum-push.ps1` (php artisan test),
   - push cabang tugas lalu mencetak tautan **Buat Pull Request**.
8. **Buka Pull Request** lewat tautan itu di peramban, isi template yang tersedia
   (`docs\pull_request_template.md`), lalu kirim.
9. **Integrator** me-review, menekan **Merge**, lalu barulah
   **`Deploy-Laporan.bat`** dijalankan di komputer integrator.

**Penegakan otomatis (bukan sekadar imbauan).** Hook `.githooks/pre-push`
memanggil `tools\cek-sebelum-push.ps1`, sehingga **push DIBLOKIR** bila
`php artisan test` merah atau masih ada berkas ter-track yang belum di-commit
(yang diuji harus sama dengan yang diunggah). Melewatinya hanya untuk keadaan
darurat dan wajib dilaporkan ke integrator: `git push --no-verify`.

**Catatan keadaan copy DEV yang kotor.** Komputer integrator hampir selalu
menyimpan pekerjaan sesi lain yang belum di-commit. Karena itu `mulai-tugas.ps1`
dan `unggah-tugas.ps1` akan MENOLAK jalan dalam keadaan itu (itu memang
disengaja). Pilihan yang benar: `-PindahkanPerubahan` (perubahan disimpan ke
`git stash` lalu dipulihkan dengan `git stash pop`) atau `-AbaikanKotor`
(dilanjutkan sambil diperingatkan). JANGAN memakai `git add -A` untuk
"membersihkan" keadaan — itu melanggar aturan poin 7b dan bisa menyapu berkas
sesi lain ke dalam commit.

### 4. Cara mencegah konflik (mekanismenya, bukan sekadar harapan)

1. **Cabang per orang.** Selama tidak ada dua orang menulis di cabang yang sama,
   `main` tidak pernah "bertabrakan"; yang perlu diselesaikan hanya perbedaan
   antara cabang tugas dan `main`.
2. **Rebase otomatis sebelum unggah.** `tools\unggah-tugas.ps1` SELALU
   `git fetch` lalu `git rebase origin/main`. Jadi perbedaan ketahuan di komputer
   PENULIS saat perubahannya masih kecil dan masih diingat, bukan di GitHub
   berjam-jam kemudian.
3. **Papan klaim** `tugas/<nama>.md`. Kasus paling mahal adalah dua orang
   menyunting berkas yang sama tanpa saling tahu. Karena itu SETIAP tugas wajib
   menulis daftar berkas/modulnya di papan klaim, dan `tools\mulai-tugas.ps1`
   selalu menampilkan isi papan klaim orang lain sebelum bekerja.
   Karena satu orang = satu berkas, **papan klaim itu sendiri tidak pernah
   konflik** (berbeda dengan satu berkas "daftar tugas" bersama).
4. **Perubahan kecil, commit cepat.** Sesuai "ALUR KERJA SETIAP SESI BARU" poin 7:
   satu perubahan = satu commit, segera setelah uji lulus. Rebase atas commit
   kecil hampir selalu selesai sendiri.
5. **Berkas yang paling sering bertabrakan di project ini** (perlakukan dengan
   hati-hati, beri tahu lewat papan klaim lebih dulu): `AGENTS.md`, `docs/*.md`,
   `routes/web.php`, `config/akses.php`, `resources/views/layouts/app.blade.php`,
   `app/Http/Controllers/ReportController.php`, `resources/views/reports/*`,
   dan nama berkas migrasi `database/migrations/YYYY_MM_DD_HHMMSS_*`.
6. **Dilarang**: `git merge origin/main` ke dalam cabang tugas (pakai rebase,
   supaya riwayat lurus), `git push --force` ke `main`, dan `git rebase` pada
   cabang milik orang lain.

### 5. Kalau ternyata masih terjadi konflik

`tools\unggah-tugas.ps1` akan membatalkan rebase otomatis dan berhenti. Langkah
penyelesaiannya:

1. `git status` untuk melihat berkas yang bertanda konflik (`both modified`).
2. Perbaiki berkasnya DI KOMPUTER SENDIRI (jangan pernah menyelesaikan konflik di
   `main` atau memaksa push).
3. `git add <berkas>` lalu `git rebase --continue`. Bila ruwet: `git rebase --abort`
   mengembalikan keadaan seperti sebelum rebase.
4. Jalankan lagi `php artisan test`, lalu `git push origin <cabang-tugas>` dan
   lanjutkan Pull Request.

Aturan khusus per jenis berkas (mengikuti `docs/09-aturan-wajib-edit-file.md`):

| Berkas | Cara menyelesaikan konflik |
|---|---|
| `AGENTS.md` dan `docs/*.md` | **Ambil KEDUA sisi** (blok lama dan blok baru) — dokumen ini bersifat *append-only*, jadi tidak ada bagian yang boleh dibuang. Nomor "Riwayat Insiden" diurutkan ulang agar tidak ada nomor kembar, lalu pastikan dokumen baru tercantum di peta `AGENTS.md`. |
| `database/migrations/*` | JANGAN menyatukan dua migrasi berbeda. Nama berkas memakai stempel waktu `php artisan make:migration`; bila dua nama bentrok, salah satu dibuat ulang dengan waktu baru. |
| `routes/web.php`, `config/akses.php`, `layouts/app.blade.php` | Ambil KEDUA sisipan (biasanya satu baris masing-masing). JANGAN menulis ulang berkas besar ini saat menyelesaikan konflik. |
| `.bat` / `.cmd` | Setelah konflik selesai, PASTIKAN akhiran barisnya tetap CRLF (Riwayat Insiden #39) dengan menghitung byte berkasnya. |
| Berkas lain | Selesaikan dengan membaca kedua versi; kalau ragu, tanyakan ke integrator daripada memilih secara asal. |

Bila konfliknya menyangkut berkas yang juga sedang disentuh orang lain, hubungi
orang itu dan sepakati urutan pengerjaan lebih dulu.

### 6. Penjaga deploy: `Deploy-Laporan.bat` TIDAK lagi mengunggah apa pun

Sejak 13/10/2026, `tools\deploy-laporan-prod.ps1` menjalankan
`tools\penjaga-main-dev.ps1` lebih dulu. Penjaga itu membandingkan commit `main`
di copy DEV dengan commit `main` di GitHub:

| Keadaan copy DEV | Akibatnya |
|---|---|
| Sama dengan GitHub | Deploy lanjut seperti biasa. |
| Hanya tertinggal (tidak punya commit sendiri) | Copy DEV dimajukan otomatis dengan *fast-forward* (aman, tidak ada commit yang hilang), lalu deploy lanjut. Repo hub lokal (cadangan) ikut disamakan dengan commit GitHub. |
| Bercabang / punya commit sendiri, atau sedang berada di cabang tugas | **Deploy DIBATALKAN** dengan pesan jelas. Pekerjaan yang belum di-merge TIDAK PERNAH sampai ke produksi. |
| GitHub tidak terbaca (internet mati) | Penjaga dilewati dengan peringatan, deploy tetap boleh lanjut memakai commit yang sudah ada. |

`tools\deploy-prod.ps1` juga tidak lagi mengunggah apa pun; pada jalur tanpa
`-LewatiDev` ia memakai penjaga yang sama dalam bentuk pemeriksaan commit.

Konsekuensi penting: **hanya komputer integrator yang berada di cabang `main`
yang bisa men-deploy.** Komputer teknisi yang sedang ada di cabang tugas akan
ditolak penjaga — ini memang disengaja.

### 6b. Bila fast-forward terhalang berkas yang belum di-commit (14/10/2026)

Kejadian nyata 14/10/2026: PR #3 di-merge, tetapi `main` copy DEV masih di commit
lama DAN ada **pekerjaan sesi lain yang belum di-commit** (`.env.example`) pada
berkas yang IKUT diubah PR itu. Git menolak menimpa berkas itu
(`Please commit your changes or stash them before you merge`), sehingga penjaga
membatalkan deploy walau isi kodenya tidak salah. Yang salah hanyalah
petunjuknya: pesan lama menyuruh `git checkout main` + `git pull --ff-only origin
main`, padahal KEDUA perintah itu gagal dengan sebab yang SAMA.

Sejak 14/10/2026 alatnya ada di `tools\siapkan-deploy.ps1`:

- Mencari sendiri berkas yang menghalangi: `git diff --name-only` antara `HEAD`
  dan commit tujuan, dipotong dengan daftar `git status --porcelain`.
- Berhenti bila copy DEV berada di cabang tugas atau cabang utama lokal punya
  commit sendiri (itu urusan Pull Request, bukan penyeragaman commit).
- Mencadangkan berkas penghalang ke
  `C:\laragon\backup-oleochemical\siapkan-deploy\dev-<cap waktu>\` (beserta
  `CATATAN.txt`), memindahkannya sementara ke `git stash`, memajukan copy DEV
  dengan fast-forward, lalu **mengembalikan perubahan itu sebagai perubahan yang
  tetap belum di-commit** (tidak dibuatkan commit atas nama orang lain).
- Bila pengembaliannya bentrok (dua sisi menambah di tempat berdekatan), KEDUA
  isi digabung otomatis dan baris penanda bentrok dibuang. Opsi `-JanganGabung`
  mematikan penggabungan itu; `-Kering` hanya melaporkan tanpa mengubah apa pun.

`tools\penjaga-main-dev.ps1` dan `tools\kembalikan-ke-main.ps1` sekarang
menyebut nama berkas penghalang (`PENGHALANG=...`) dan menunjuk skrip itu,
sehingga deploy berikutnya tidak perlu ditolong agent. Urutan kerjanya tetap:
Merge Pull Request -> `Kembali-Ke-Main-Laporan.bat` (bila copy DEV masih di
cabang tugas) -> `tools\siapkan-deploy.ps1` (bila penjaga menolak karena berkas
belum di-commit) -> `Deploy-Laporan.bat`.

### 6c. Jangan menambah commit ke cabang tugas setelah PR di-merge (20/10/2026)

Kejadian 20/10/2026: fitur laporan kolaborasi shift sudah di-merge lewat PR #25
(`82830c6`), tetapi di cabang tugas masih dibuat satu commit tambahan
(`350a24c`) yang hanya mengganti nama catatan PR. `Deploy-Laporan.bat`
kemudian dijalankan dari cabang tugas itu, sehingga penjaga membatalkan deploy
walau SEMUA kode fitur sudah ada di `main`.

Aturan yang berlaku sekarang:

1. **Semua berkas PR — termasuk `tugas/pr-*.md` — di-commit sebelum PR dibuat
   atau di-merge.** Jangan menambah commit ke cabang tugas setelah PR-nya
   di-merge.
2. **Deploy hanya dari `main`.** Bila copy DEV masih di cabang tugas, jalankan
   `Kembali-Ke-Main-Laporan.bat` / `tools\kembalikan-ke-main.ps1` lebih dulu.
3. Bila `kembalikan-ke-main.ps1` mencetak `KEMBALI_MAIN=BELUM` tetapi penjaga
   mencetak `SEJAJAR=1`, periksa commit yang tertinggal:
   `git log --oneline origin/main -5` dan
   `git merge-base --is-ancestor <commit-fitur> origin/main`.
   Bila yang tertinggal hanya commit administratif (catatan PR), jangan
   reset/hapus branch; penjaga `SEJAJAR=1` adalah penentu siap deploy.
4. Berkas kotor sesi lain tidak ikut di-deploy; deploy memakai commit `main`,
   bukan working tree.

### 6d. Jangan menyarankan deploy saat copy DEV masih di cabang tugas (24/10/2026)

Kejadian 24/10/2026: PR #58 (foto Assets/CM) sudah di-merge menjadi `8154eb3`,
tetapi copy DEV masih berada di `tugas/erik/rapikan-foto-asset-kelola-fullsize`
dengan commit tambahan `9533c47` (hanya koreksi jumlah assertion pada dokumen)
yang belum masuk `main`. `Deploy-Laporan.bat` dibatalkan penjaga dengan
`SEJAJAR=0`: "copy DEV TIDAK berada pada commit main GitHub".

Aturan:

1. Sebelum menyarankan deploy, pastikan PR sudah BENAR-BENAR dibuat dan
   di-merge. `tools\buat-pr-tugas.ps1` hanya menyiapkan judul/isi dan membuka
   peramban; PR baru sah sesudah manusia menekan "Create pull request" dan
   "Merge pull request".
2. Jangan menambah commit ke cabang tugas setelah PR-nya di-merge. Semua berkas
   PR, termasuk `tugas/pr-*.md`, WAJIB di-commit sebelum PR dibuat.
3. Sesudah merge, kembalikan copy DEV ke `main` dengan
   `tools\kembalikan-ke-main.ps1`, lalu pastikan penjaga mencetak `SEJAJAR=1`
   dan `CARA=dilanjutkan` sebelum deploy.
4. Bila yang tertinggal hanya commit administratif/dokumentasi (mis. koreksi
   catatan uji), jangan menganggap fitur belum di-merge. Selesaikan commit itu
   lewat PR terpisah; deploy tetap hanya memakai `main` yang sejajar GitHub.
5. Agent DILARANG menyimpulkan "sudah siap deploy" hanya karena
   `unggah-tugas.ps1`/`buat-pr-tugas.ps1` selesai, atau karena PR sudah
   di-merge, tanpa memeriksa cabang dan `SEJAJAR=1` di copy DEV.

### 7. Setelan GitHub yang harus dipasang integrator (sekali, manual di web)

Repo ini PUBLIK (dibuktikan `git ls-remote` tanpa kredensial, 13/10/2026), jadi
penguncian cabang **gratis** pada GitHub Free. Di
`https://github.com/rikchodam-glitch/oleochemicalReport`:

Aturan yang DIPUTUSKAN USER 13/10/2026: repo tetap **PUBLIK** dan penguncian
memakai **Required approvals = 1**. Publik hanya berarti *terbaca* (siapa pun
boleh clone/fork/mengirim PR), BUKAN boleh menulis: hak menulis ditentukan peran
collaborator, dan selama penguncian aktif tidak ada seorang pun yang bisa push
langsung ke `main`.

1. **Settings -> Branches -> Add branch protection rule**, pola nama `main`.
2. Biarkan **Restrict deletions** tercentang (`main` tidak bisa dihapus).
   **JANGAN** mencentang **Restrict updates** -- itu memblokir SELURUH pembaruan
   ref `main`, termasuk Merge Pull Request, sehingga alurnya beku. **Restrict
   creations** tidak perlu (pola `main` tidak dipakai untuk cabang tugas).
3. Centang **Require a pull request before merging**, lalu isi
   **Required approvals = 1**. Dengan 0, setiap akun berhak tulis bisa meng-Merge
   PR-nya sendiri tanpa review sehingga aturan "integrator me-review dulu"
   kehilangan artinya.
   - KONSEKUENSI yang harus disadari: GitHub melarang penulis PR menyetujui
     PR-nya sendiri, jadi PR yang dibuat integrator sendiri perlu persetujuan
     teknisi lain. Karena itu pilihan di langkah 6 menentukan: penegakan
     maksimal (butuh teknisi lain) atau kemudahan integrator (admin masih bisa
     menembus).
4. **Require branches to be up to date before merging** TIDAK berada di bagian
   Pull Request: ia sub-opsi **di dalam** **Require status checks to pass**
   (centang "Require status checks to pass" lebih dulu, barulah opsi itu muncul;
   boleh tanpa memilih check apa pun). Repo ini belum punya CI, jadi ini
   pengerasan tambahan -- kesegaran cabang sudah dijaga `tools\unggah-tugas.ps1`
   yang selalu `git rebase origin/main` sebelum mengunggah.
5. Centang **Block force pushes** (tanpa itu penulisan ulang riwayat `main` masih
   boleh, padahal penjaga deploy membandingkan commit `main` DEV dengan `main`
   GitHub).
6. Bila ingin penegakan maksimal, centang juga **Do not allow bypassing the above
   settings** (letaknya di bagian bawah halaman, di bawah pilihan-pilihan lain).
   Bila TIDAK dicentang, aturan tetap berlaku bagi akun berhak tulis tetapi
   admin/integrator masih bisa menembus -- dipilih hanya bila integrator perlu
   bisa meng-Merge PR buatannya sendiri tanpa bantuan teknisi.
7. Klik **Create** / **Save changes**.

Cara membuktikan aturannya SUDAH aktif (tanpa login, hanya membaca):
`curl.exe -s https://api.github.com/repos/rikchodam-glitch/oleochemicalReport/branches/main`
lalu cari `"protected"`. Diperiksa 13/10/2026 setelah formulir dibuka: nilainya
masih `false` (belum disimpan), sedangkan `private: false` /
`visibility: public` / `default_branch: main` sudah sesuai rencana.

Cara memberi akses ke teknisi: **Settings -> Collaborators and teams**, peran
**Write** saja (bukan Maintain/Admin) supaya mereka bisa mendorong cabang tugas
tetapi tidak bisa mengubah setelan repo maupun penguncian cabang.

Pemeriksaan isi repo saat masih publik (13/10/2026, 556 berkas terlacak di copy
DEV `C:\laragon\www\oleochemicalReport`): `.env` TIDAK terlacak (dilindungi
`.gitignore` baris 9), nol berkas `.sql`/`.xlsx`/`.sqlite`/`pem`/`key` terlacak,
nol temuan pola rahasia (Groq `gsk_`, OpenAI `sk-`, Google `AIza`, token bot
Telegram). Yang tetap terbaca publik hanya kode + informasi internal (IP
`192.168.41.x`, domain, dokumen rancangan) -- tanpa kredensial, sehingga tidak
memberi akses ke sistem. Bila ingin lebih tertutup: matikan **Allow forking**
dan **Issues** di Settings (mengurangi, bukan menghapus kemampuan orang menyalin
kode publik).

Dengan setelan itu, walau seseorang memaksa `git push origin main`, GitHub akan
menolaknya. Jadi penegakan aturan ini punya DUA lapis: hook lokal (cepat, memberi
tahu lebih awal) dan penguncian GitHub (tidak bisa dilewati).

Catatan: bila repo ini nanti dijadikan PRIVAT, penguncian cabang menjadi fitur
berbayar, DAN copy PROD wajib menyimpan kredensial GitHub karena deploy tidak
pernah meminta masukan manusia.

### 8. Ringkasan perintah (hafalkan yang ini saja)

| Keperluan | Perintah (dari folder repo) |
|---|---|
| Sekali per clone | `powershell -ExecutionPolicy Bypass -File tools\siapkan-clone.ps1` |
| Mulai tugas baru | `powershell -ExecutionPolicy Bypass -File tools\mulai-tugas.ps1 -Nama <nama> -Tugas "<ringkas>"` |
| Cek sebelum mengunggah | `powershell -ExecutionPolicy Bypass -File tools\cek-sebelum-push.ps1` |
| Unggah cabang tugas + tautan PR | `powershell -ExecutionPolicy Bypass -File tools\unggah-tugas.ps1` |
| Cek kesesuaian copy DEV dengan GitHub | `powershell -ExecutionPolicy Bypass -File tools\penjaga-main-dev.ps1` |
| Siapkan copy DEV agar deploy lolos (bila penjaga menyebut berkas belum di-commit) | `powershell -ExecutionPolicy Bypass -File tools\siapkan-deploy.ps1` |
| Mencoba dulu tanpa mengubah apa pun | tambahkan `-Kering` (laporan saja) atau `-JanganGabung` (bentrok dibiarkan) |
| Deploy ke PROD (HANYA integrator, setelah Merge) | klik `Deploy-Laporan.bat` |
