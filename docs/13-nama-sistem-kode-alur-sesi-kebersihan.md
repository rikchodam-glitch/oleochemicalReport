# Nama Sistem, Penulisan Kode, Alur Sesi Baru, dan Kebersihan Repo

> Bagian dari dokumentasi proyek di folder `docs`.
> Peta dokumen: `AGENTS.md` di akar repo.

## NAMA SISTEM (BRANDING) — WAJIB DIKETAHUI SEBELUM MENGUBAH TEKS TAMPILAN

Nama resmi sistem per **30/09/2026** adalah **Oleochemical Maintenance**
(sebelumnya "Oleochemical Pro"; diganti atas instruksi user). Nama ini
**TIDAK** diambil dari `config('app.name')` — ia ditulis HARCODE di beberapa
tempat, jadi mengubah satu tempat saja membuat tampilan tidak seragam:

- `resources/views/auth/login.blade.php` baris 6 (judul tab), 16 (H1), 72 (footer).
- `resources/views/layouts/app.blade.php` baris 6 (judul tab), 229 (nama di
  sidebar), dan baris 227 penanda logo sidebar (kini **OM**, sebelumnya OP).
- `app/Http/Controllers/Traits/AssetExportTrait.php` baris 72 (keterangan
  export Excel) dan 197 (keterangan export CSV).
- Penjaga otomatis yang ikut menyebut nama: `tests/Feature/AssetImportUpdateTest.php`
  baris 383 dan 455 (harapan test) serta `tools/cek-tunnel-prod.ps1` baris 164
  (pengenal "halaman login aplikasi tampil"). Kalau nama diubah lagi, KEDUA
  berkas itu WAJIB ikut diubah, kalau tidak test gagal / cek tunnel berbunyi
  salah. Jalankan `php artisan test --filter='AssetImportUpdateTest|PageSmokeTest'`
  setelah menyentuh nama.
- Tagline "Maintenance Management System" (login) dan "Maintenance System"
  (sidebar) sengaja TIDAK diubah saat penggantian nama — jangan dianggap sisa
  nama lama.

Catatan penting: `APP_NAME` di `.env` copy DEV maupun PROD masih bernilai
`DailyReport`, dan `.env.example` bernilai `Oleochemical Report`. Nilai itu
TIDAK memengaruhi judul/teks yang tampak (kecuali halaman bawaan
`welcome.blade.php`), tetapi dipakai membentuk **nama cookie sesi**, prefiks
cache/session, dan `MAIL_FROM_NAME`. Karena itu JANGAN mengubah `APP_NAME`
tanpa instruksi user: akibatnya semua pengguna ter-logout (harus login ulang).
Nama perusahaan pada dokumen `docs/PROMPT.md` baris 5 (brief awal project) juga
dibiarkan apa adanya, sengaja, karena itu nama perusahaan bukan nama aplikasi.

## ATURAN PENULISAN KODE

- Kode rapi, konsisten gaya yang sudah ada.
- Tidak ada emoji di kode, komentar, atau string apapun.
- Komentar dalam Bahasa Indonesia.
- Setiap method baru wajib docblock (parameter + return).
- Early return untuk hindari nesting dalam.
- Hapus kode tidak terpakai, jangan di-comment out tanpa alasan.
- Null-safe operator (`?->`) atau fallback (`??`) WAJIB dipakai untuk akses
  relasi/property yang datanya tidak dijamin selalu ada (equipment tanpa
  plant, tanpa reading, dst) — akses tanpa null-check bisa menyebabkan
  halaman tampak KOSONG/PUTIH di production (`APP_DEBUG=false`) tanpa pesan
  error jelas, sering disalahartikan sebagai "data tidak ada".

## ALUR KERJA SETIAP SESI BARU

1. Baca ulang peta `AGENTS.md` dan dokumen `docs/` yang relevan sebelum mulai
   kerja apapun.
2. Sebutkan file apa saja yang akan disentuh sebelum mulai edit.
3. Untuk fitur baru/kompleks ATAU untuk bug fix yang menyentuh definisi
   filter/agregasi data: baca dan laporkan dulu kondisi file terkait DAN
   definisi business logic yang dipahami, TUNGGU konfirmasi manusia sebelum
   menulis kode (lihat Riwayat Insiden #8).
4. Setiap file yang diubah ditulis ulang lengkap (kecuali perubahan kecil,
   lihat `docs/09-aturan-wajib-edit-file.md` poin 2).
5. Jika file dibutuhkan tapi belum jelas isinya, baca dulu, jangan mengarang.
6. Setelah klaim "sudah selesai": sertakan bukti konkret (screenshot,
   output test, angka yang bisa diverifikasi), bukan asumsi. Kalau tidak
   bisa verifikasi sendiri (misal tidak bisa login/akses browser), katakan
   itu secara eksplisit, jangan simpulkan "seharusnya sudah benar".
7. **COMMIT SEGERA SETELAH UJI LULUS — wajib, bukan pilihan.** Begitu satu
   perubahan dinyatakan lulus uji (test otomatis hijau, atau uji langsung yang
   hasilnya bisa ditunjukkan), perubahan itu WAJIB langsung di-commit saat itu
   juga. JANGAN menunggu akhir sesi, JANGAN menunda sampai "nanti setelah
   fitur lain selesai", dan JANGAN menggabungkan beberapa perubahan berbeda
   dalam satu commit. Aturannya:
   a. Satu perubahan/perbaikan = satu commit, pesan Bahasa Indonesia yang
      menyebut apa yang berubah dan kenapa.
   b. Hanya berkas yang terkait perubahan itu yang di-`git add` (tulis
      path-nya satu per satu). JANGAN `git add -A` / `git add .` — berkas
      yang sudah kotor dari sesi lain tidak boleh ikut ter-commit (contoh nyata
      12/10/2026: `app/Jobs/ProcessCmFindingsImport.php`, `.env.example`,
      `.gitignore`, `app/Console/Commands/CmDriveCheck.php`). Daftar kotor itu
      SELALU diperiksa lebih dulu dengan `git status --short`, jangan memakai
      contoh di sini sebagai daftar tetap.
   c. Setelah commit: unggah lewat `tools\unggah-tugas.ps1` (push ke cabang tugas
      `tugas/<nama>/<ringkas>`, BUKAN ke `main`) lalu buka **Pull Request**.
      `main` hanya terisi lewat Merge Pull Request, dan repo hub lokal (cadangan)
      disamakan otomatis oleh `tools\penjaga-main-dev.ps1` saat deploy. Pekerjaan
      dianggap "selesai" hanya setelah hash commit-nya dilaporkan ke user.
      Aturan lengkap: bagian "ALUR KERJA BANYAK TEKNISI" di atas, DITAMBAH aturan
      WAJIB 14/10/2026 di AKHIR berkas ini: copy DEV WAJIB diperbarui lebih dulu
      SEBELUM menyunting (`tools\mulai-tugas.ps1`), lalu sesudah uji hijau
      LANGSUNG `tools\unggah-tugas.ps1` + `tools\buat-pr-tugas.ps1` supaya Pull
      Request siap diperiksa di GitHub pada giliran yang sama.
   d. Yang BELUM lulus uji JANGAN di-commit (jangan pernah commit kode setengah
      jalan/rusak). Kalau memang harus menyimpan pekerjaan darurat, buat
      commit terpisah berawalan `WIP:` dan sebutkan di laporan bahwa commit itu
      belum lulus uji.
   e. Deploy ke PROD TETAP manual lewat pintasan `Deploy-Laporan.bat`
      (dijalankan manusia, ada konfirmasi `y`) — commit/push TIDAK mengubah
      PROD sedikit pun. Setelah push, bandingkan commit PROD
      (`git -C 'C:\laragon\www\oleochemicalReport-prod' log --oneline -1`)
      dengan commit GitHub (`git ls-remote origin refs/heads/main`) untuk tahu
      PROD masih tertinggal atau tidak, lalu laporkan.
   f. Alasan aturan ini: kalau nanti file corrupt dan perlu `git checkout`,
      kerugian yang hilang hanya sebatas perubahan sejak commit terakhir,
      bukan seluruh sesi kerja.

## ATURAN WAJIB: JANGAN TINGGALKAN SAMPAH BERKAS (kebersihan repo)

Diminta user 12/10/2026 sesudah ditemukan 19 berkas/folder sampah tertinggal di
copy DEV (skrip uji sementara, cadangan `.bak`, partial yatim, berkas hasil
export sekali pakai, `__pycache__`, dan 184 view terkompilasi yang sudah yatim).
Aturan ini berlaku untuk SEMUA sesi berikutnya.

1. **Skrip/pengujian sementara** (`tmp-cek-*.php`, `tmp-*.php`, `_cek_*.php`,
   `compare-*.php`) hanya boleh hidup selama dipakai. Selesai dipakai = HAPUS
   pada giliran itu juga, jangan menunggu "nanti". Kalau masih diperlukan untuk
   melanjutkan pekerjaan, taruh di folder `tmp-oleo\` (folder itu boleh ada,
   isinya TIDAK boleh di-commit).
2. **Berkas cadangan** (`.bak`, `.old`, `.orig`, `*~`, `*_lama.*`) JANGAN
   dibiarkan di dalam repo. Untuk berkas terlacak, salinan amannya sudah ada di
   commit terakhir; untuk berkas tak terlacak, salinannya ditaruh di luar repo
   (`C:\laragon\backup-oleochemical\`).
3. **Berkas hasil sekali pakai** (CSV/TXT hasil export atau laporan uji di
   `storage/app/`) dihapus sesudah angkanya dilaporkan. Bila perlu disimpan
   untuk rujukan laporan, pindahkan ke `C:\laragon\backup-oleochemical\`
   (di luar repo), bukan dibiarkan di dalam repo.
4. **Partial/view yatim** (sudah tidak dirujuk `@include`/`view()` mana pun)
   WAJIB dihapus, bukan dibiarkan "barangkali nanti dipakai". Buktikan yatim
   dulu dengan `search_codebase`, jangan menebak.
5. **Setelah menghapus berkas blade, WAJIB jalankan `php artisan view:clear`**
   (bukan hanya `view:cache`): view lama di `storage/framework/views` tetap
   tersimpan dan tetap bisa melayani rute walau sumbernya sudah dihapus
   (kejadian 12/10/2026: 184 view yatim). Sesudah `view:cache`, bandingkan
   jumlah berkas terkompilasi dengan jumlah blade sumber; selisihnya dilaporkan.
6. **Sebelum menutup sesi**, jalankan pemeriksaan sampah berikut (hanya
   membaca; perintahnya sudah diuji 12/10/2026 atas 16.553 berkas di repo dan
   mengembalikan nol temuan pada repo yang sudah bersih):
   a. `git status --short` -- perhatikan berkas baru (`??`) yang bukan bagian
      fitur yang sedang dikerjakan.
   b. `Get-ChildItem -Recurse -Force -File | Where-Object { $_.Name -like '*.bak'
      -or $_.Name -like '*.old' -or $_.Name -like '*~' -or $_.Name -like '_tmp_*'
      -or $_.Name -like 'tmp-*' }` -- bila lambat, saring dulu folder
      `vendor` dan `node_modules`.
   c. `Get-ChildItem -Recurse -Force -Directory | Where-Object { $_.Name -eq
      '__pycache__' }`
   d. `(Get-ChildItem -LiteralPath storage\framework\views -File).Count`
   Hasilnya dilaporkan ke user, dan sampahnya dihapus pada sesi itu.
7. **Copy PROD**: JANGAN menghapus atau menyunting apa pun di
   `C:\laragon\www\oleochemicalReport-prod`. Berkas sampah yang tampak di sana
   adalah temuan untuk dilaporkan (dibersihkan lewat commit di DEV lalu deploy),
   bukan untuk dibersihkan langsung.
8. **Pola sampah yang sudah dikecualikan git**: DEV memakai
   `C:\laragon\www\oleochemicalReport\.git\info\exclude` (bagian "Sampah kerja
   agent"), diisi 12/10/2026. Berkas itu ada DI DALAM `.git` sehingga tidak ikut
   ter-commit dan hanya berlaku di DEV. Pola yang sama masih perlu ditambahkan
   ke `.gitignore` (kolom "Debug artifacts") saat berkas itu boleh disentuh
   lagi; pemisahan ini sengaja supaya penyuntingan `.gitignore` tidak mencampur
   pekerjaan sesi lain yang belum di-commit.
