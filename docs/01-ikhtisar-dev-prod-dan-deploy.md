# Ikhtisar DEV/PROD, Deploy, dan Service Latar

> Bagian dari dokumentasi proyek di folder `docs`.
> Peta dokumen: `AGENTS.md` di akar repo.

## PENTING — DUA COPY PROJECT DI KOMPUTER INI (DEV & PROD)

Sejak 28/09/2026 project ini SENGAJA ada dalam DUA copy di satu komputer
(diputuskan user: agar pengeditan tidak langsung terlihat di server):

| Peran | Path | Keterangan |
|---|---|---|
| **DEV** (tempat kerja agent) | `C:\laragon\www\oleochemicalReport` | SEMUA perubahan kode dikerjakan di sini. Database `oleochemical_dev`. Diuji lokal di `http://oleochemicalReport.test` (Apache, vhost lama). |
| **PROD** (yang disajikan) | `C:\laragon\www\oleochemicalReport-prod` | Hasil deploy. Disajikan Apache di `http://oleo-prod.lvh.me` + `http://localhost/`, dan `artisan serve` di **`http://192.168.41.74:8000`** (alamat yang dipakai orang di jaringan kantor); nanti domain publik. Database `oleochemical` (data asli) dan pemegang `storage/app` data asli (foto/laporan). **JANGAN pernah mengedit file di sini.** |

- **Kanal deploy (diputuskan user 13/10/2026): GitHub = sumber resmi.**
  Repo: `https://github.com/rikchodam-glitch/oleochemicalReport.git` (PUBLIK;
  kalau nanti dijadikan PRIVAT, copy PROD WAJIB punya kredensial tersimpan
  karena deploy tidak pernah meminta masukan manusia). DEV punya remote
  **`origin`** ke GitHub, dan copy PROD diarahkan ke `origin` = GitHub oleh
  `tools\deploy-prod.ps1` sendiri, jadi `Deploy-Laporan.bat` selalu menarik
  commit dari GitHub.
- Repo hub lokal `C:\laragon\repo\oleochemicalReport.git` (bare, remote
  **`hub`** di DEV) DIPERTAHANKAN hanya sebagai **cadangan/offline**: DEV tetap
  push ke sana SESUDAH push GitHub (kegagalannya cuma peringatan), dan PROD
  menarik dari sana sebagai cadangan bila GitHub tidak bisa dihubungi.
- Vhost produksi: `C:\laragon\etc\apache2\sites-enabled\oleo-prod.conf`
  (DocumentRoot ke copy PROD, `ServerName oleo-prod.lvh.me` + `ServerAlias localhost`).
- Alur perubahan yang WAJIB diikuti (aturan lengkap: bagian "ALUR KERJA BANYAK
  TEKNISI" di atas): edit & uji di DEV **di cabang tugas sendiri** -> **commit di
  DEV SEGERA setelah uji lulus** (lihat `docs/13-nama-sistem-kode-alur-sesi-kebersihan.md`
  bagian "ALUR KERJA SETIAP SESI BARU" poin 7,
  jangan menumpuk perubahan tanpa commit) -> unggah cabang tugas dengan
  `tools\unggah-tugas.ps1` lalu buka **Pull Request** -> integrator menekan
  Merge di GitHub -> klik **`Deploy-Laporan.bat`** di komputer integrator
  (backup database PROD, pull dari GitHub di PROD, dependensi, migrasi, cache,
  restart service). JANGAN mengunggah langsung ke `main`, dan JANGAN mengedit
  atau menjalankan migrasi manual di PROD.
- **Deploy satu klik (sejak 01/10/2026, keputusan user):** pintasan Desktop
  `Deploy-Laporan.bat` dan `Backup-Database-Laporan.bat` hanya MEMANGGIL
  `PROD\tools\deploy-laporan-prod.ps1` supaya tidak ada dua versi logika.
  Dijalankan MANUSIA (skripnya minta konfirmasi `y` sebelum mengubah PROD).
  Urutan kerja skrip itu: (1) backup database PROD lebih dulu dengan
  `mysqldump` (biner `C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysqldump.exe`)
  ke `C:\laragon\backup-oleochemical\db\oleochemical-<stamp>.sql`, 10 berkas
  terbaru disimpan, catatan di `C:\laragon\backup-oleochemical\backup-db.log`;
  **backup gagal = deploy dibatalkan** (jangan pernah melewati ini saat ada
  perubahan database) -> (2) penjaga alur kerja banyak teknisi
  (`tools\penjaga-main-dev.ps1`): copy DEV WAJIB berada pada commit cabang `main`
  di GitHub — hanya tertinggal = dimajukan otomatis (fast-forward), sedangkan
  berbeda/bercabang = deploy DIBATALKAN. Langkah ini TIDAK mengunggah apa pun,
  karena `main` hanya boleh diisi lewat Pull Request -> (3)
  ringkasan: commit PROD sekarang, commit yang ADA di GitHub, migrasi
  tertunda (`php artisan migrate:status --pending`), dan berkas DEV yang belum
  di-commit -> (4) konfirmasi manusia -> (5) `tools\deploy-prod.ps1 -LewatiDev
  -LewatiComposer -LewatiService -BangunAset` (pull, `migrate --force`,
  `config:cache` + `view:cache`, build aset) -> (6) `artisan up` sebagai jaring
  pengaman -> (7) restart SEMUA service lewat
  `tools\hentikan-service-prod.ps1 -BiarkanJadwal` lalu
  `tools\jaga-service-prod.ps1` (queue:work, schedule:work, telegram:poll,
  `serve` port 8000, tunnel Cloudflare) -> (8) status akhir
  `tools\cek-status-prod.ps1`. Opsi: `-HanyaBackup`, `-LewatiBackup`,
  `-LewatiBangunAset`, `-LewatiService`, `-TanpaKonfirmasi` (pengujian
  otomatis saja) dan `-SimpanBackup <n>`. Memulihkan database dari backup:
  hentikan service dulu (`stop-report.bat`), jalankan
  `mysql -u root oleochemical < <berkas>.sql`, lalu `start-report.bat`.
  Skrip itu memanggil skrip lain lewat PROSES TERPISAH (`powershell.exe -File`)
  supaya masalah stderr git pada `deploy-prod.ps1` (lihat catatan di bawah)
  tidak menular.

- Service latar (`queue:work`, `schedule:work`, `telegram:poll`) dan web
  `artisan serve` port 8000 HANYA jalan di PROD; dua poller Telegram sekaligus =
  bot mati total (HTTP 409, insiden #21). Di DEV tidak perlu service apa pun —
  Apache sudah menyajikan DEV di `http://oleochemicalReport.test`. Karena itu
  import hasil sync Google Sheets di DEV dijalankan LANGSUNG di proses sync
  (mode 'inline', lihat `cm.google_sheets.import_mode` / insiden #45), bukan
  lewat queue: job yang menumpuk di tabel `jobs` tidak akan pernah diambil.
  Untuk memaksa mode itu di mesin mana pun: `php artisan cm:sync-sheets --langsung`.
- Service PROD dijaga otomatis oleh `tools\jaga-service-prod.ps1`, dipasang
  sebagai tugas Windows **`oleo-jaga-service-prod`** (berjalan tiap 5 menit).
  Tugasnya SUDAH ADA di komputer ini (kini berstatus Disabled) sehingga tidak
  ada yang perlu dipasang lagi. Skrip pemasang lamanya
  (`tools\pasang-jadwal-jaga-service.ps1`) DIHAPUS 13/10/2026 karena isinya
  menunjuk watchdog copy DEV dan memakai `powershell.exe` langsung, melanggar
  aturan wscript di bawah — JANGAN dibuat ulang dalam bentuk itu. Watchdog
  menyalakan ulang keempat proses (queue:work, schedule:work, telegram:poll,
  serve:8000)
  yang mati dan membuang proses duplikat; catatannya ada di
  `PROD\storage\logs\jaga-service.log`. Kalau ada service yang tidak jalan,
  jalankan `tools\jaga-service-prod.ps1` lebih dulu sebelum menyimpulkan bug.
  **Tugas ini memanggil `wscript.exe` + `tools\jalankan-tersembunyi.vbs`**
  (bukan `powershell.exe` langsung) supaya tidak ada jendela PowerShell yang
  muncul-tutup setiap 5 menit — jangan diubah kembali ke `powershell.exe`.
  Diperiksa 13/10/2026: tindakan tugas itu memang berbunyi
  `wscript.exe "C:\laragon\www\oleochemicalReport\tools\jalankan-tersembunyi.vbs"`.
  CATATAN terbuka: VBS itu (DEV maupun PROD) masih menunjuk berkas skrip di
  COPY DEV (`...\oleochemicalReport\tools\jaga-service-prod.ps1`); skripnya
  sendiri bekerja pada copy PROD (`$ProdPath`), jadi perilakunya benar, tetapi
  bila copy DEV dipindah/dihapus tugas ini akan gagal — arahkan `jalurSkrip`
  ke copy PROD bila penjagaan otomatis dihidupkan lagi.
  Watchdog juga memverifikasi port 8000 benar-benar menyajikan copy PROD
  (via `GET /debug-login`: 404 = PROD, 302 = DEV) dan menggantinya bila salah.
- Dua tugas lama dari sesi sebelumnya — **`Oleo Serve`** dan **`Tele Poll`** —
  menjalankan `php.exe artisan serve` dan `artisan telegram:poll` dengan
  working directory **copy DEV** saat boot. Keduanya TIDAK bisa diubah lagi
  tanpa password akun/admin (schtasks meminta password; `Set-ScheduledTask`
  ditolak "Access is denied"). Dampaknya sudah dinetralkan: (a) perintah
  `telegram:poll` kini MENOLAK jalan bila bukan `APP_ENV=production`
  (opsi `--force` untuk pengujian khusus), dan (b) watchdog mengganti serve
  port 8000 yang bukan dari copy PROD. Idealnya kedua tugas itu dinonaktifkan
  manual lewat Task Scheduler (butuh hak admin).
- **SEJAK 30/09/2026 SEMUA SERVICE LATAR PROD DIJALANKAN MANUAL (keputusan
  user).** Tugas Windows `oleo-jaga-service-prod` sudah DINONAKTIFKAN
  (`Disable-ScheduledTask`) sehingga tidak ada lagi yang menyalakan ulang
  service tiap 5 menit. Konsekuensi penting untuk sesi berikutnya: kalau bot
  Telegram diam, queue tidak memproses, `/login` di port 8000 atau domain
  publik (`https://oleomtc.com`, cadangan `https://app.saranapendidik.com`)
  tidak jalan, JANGAN langsung menyimpulkan
  bug — periksa dulu apakah service-nya memang sengaja tidak dinyalakan.
  - Menyalakan (semua: queue, schedule, telegram:poll, serve:8000, tunnel):
    klik dua kali `start-report.bat` (ada di copy DEV dan PROD) atau jalankan
    `tools\jaga-service-prod.ps1`.
  - Menghentikan (sekaligus menonaktifkan jadwal watchdog):
    `stop-report.bat` atau `tools\hentikan-service-prod.ps1`
    (opsi `-BiarkanJadwal` bila jadwal tidak ingin diubah).
  - Mengaktifkan kembali penjagaan otomatis:
    `Enable-ScheduledTask -TaskName oleo-jaga-service-prod` (tugasnya sudah ada
    di komputer ini). Skrip pemasang lamanya sudah DIHAPUS 13/10/2026, jadi
    bila suatu saat tugas itu hilang, buat ulang dengan `schtasks /create`
    beraksi `wscript.exe` + VBS copy PROD (lihat aturan wscript di atas),
    JANGAN memakai `powershell.exe` langsung.
  - Pintasan Desktop untuk pengguna awam (di luar repo, jadi tidak dilacak
    git). Keadaan per 14/10/2026: SEMBILAN peluncur. Tujuh di antaranya hanya
    MEMANGGIL file resmi copy PROD supaya tidak ada dua versi logika; kalau file
    di copy PROD tidak ditemukan, pintasan berhenti dengan pesan jelas. Dua
    peluncur BARU (nomor 8-9) SENGAJA menunjuk copy DEV karena urusan cabang
    tugas dan Pull Request hanya ada di copy DEV.
    1. `Deploy-Laporan.bat` -> `PROD\tools\deploy-laporan-prod.ps1` (deploy 1 klik).
    2. `Backup-Database-Laporan.bat` -> backup database PROD.
    3. `Cek-Status-Laporan.bat` -> `PROD\tools\cek-status-prod.ps1`.
    4. `Pasang-Token-Tunnel.bat` -> `PROD\tools\pasang-token-tunnel-prod.ps1`
       (INTERAKTIF: Read-Host untuk menempel token baru — JANGAN dijalankan
       lewat pipe/tanpa manusia).
    5. `Cek-Tunnel-Laporan.bat` -> `PROD\tools\cek-tunnel-prod.ps1` (BARU 13/10/2026).
    6. `Set-Domain-Laporan.bat` -> `PROD\tools\set-domain-prod.ps1` (BARU 13/10/2026;
       menanyakan domain lalu memanggilnya dengan/tanpa `-Paksa`; domain kosong
       dibatalkan tanpa menyentuh PROD).
    7. `Siapkan-Tunnel-Laporan.bat` -> `PROD\tools\siapkan-tunnel-prod.ps1` (BARU 13/10/2026).
    8. `Buat-PR-Laporan.bat` -> `DEV\tools\buat-pr-tugas.ps1` (BARU 14/10/2026).
       Menyiapkan Pull Request cabang tugas: membuktikan commit cabang sudah
       sampai ke GitHub (dibaca lewat `git ls-remote`), menyusun isi PR dari
       berkas `tugas\pr-<ringkas cabang>.md` ditambah daftar commit/berkas dari
       git, menyalin isi itu ke papan klip, lalu membuka peramban pada halaman
       "Compare & pull request" (`quick_pull=1&title=...`). Ingat: GitHub TIDAK
       PERNAH membuat PR sendiri — tombol Merge baru muncul sesudah MANUSIA
       menekan "Create pull request".
    9. `Kembali-Ke-Main-Laporan.bat` -> `DEV\tools\kembalikan-ke-main.ps1`
       (BARU 14/10/2026). Menjalankan `git checkout main` + `git pull --ff-only`
       di copy DEV, memberi tahu apakah commit cabang tugas sudah masuk `main`,
       lalu menjalankan `tools\penjaga-main-dev.ps1` sebagai verifikasi dan
       MENAWARKAN deploy sesudahnya. Inilah langkah wajib sesudah Pull Request
       di-merge; tanpa itu penjaga membatalkan deploy karena copy DEV masih
       berdiri di cabang tugas.
    Ditambah dua SALINAN APA ADANYA (bukan peluncur) `start-report.bat` dan
    `stop-report.bat` — lihat butir berikutnya. Kelima peluncur BARU (nomor 5-9)
    punya mode `uji` (`<berkas>.bat uji`): hanya mencetak perintah PowerShell
    yang akan dijalankan, tidak menyentuh PROD — itulah cara aman mengujinya.
  - **Pintasan DUPLIKAT/PENGGANGGU yang DIBUANG 13/10/2026** (arsip lengkap:
    `C:\laragon\backup-oleochemical\desktop-tools-lama-20261013-101528` +
    `CATATAN.txt` di dalamnya): `Jalankan-Service-Laporan.bat` dan
    `Hentikan-Service-Laporan.bat` (hanya memanggil
    `PROD\start-report.bat`/`stop-report.bat`, jadi duplikat murni), dan
    `terminal.bat` (menyalakan `telegram:poll` dari copy DEV — DILARANG, dua
    poller = bot mati total 409, Riwayat Insiden #21). JANGAN dihidupkan lagi.
    Di folder `Downloads` dibuang `diagnose-server.bat` + `capture-traffic.bat`
    (versi lama, sudah digantikan `-v2`) dan `update-project.bat` (duplikat dari
    `C:\laragon\www\oleochemicalReport\update-project.bat` yang masih ada);
    `diagnose-server-v2.bat` + `capture-traffic-v2.bat` hanya DIPINDAHKAN ke
    Desktop (tidak dihapus) supaya semua perkakas ada di satu tempat.
    Pada 13/10/2026 (instruksi user "yang melanggar hapus saja") turut DIBUANG:
    peluncur BARU `Pasang-Jadwal-Jaga-Laporan.bat` beserta skrip pemasang yang
    dipanggilnya, `tools\pasang-jadwal-jaga-service.ps1` (dihapus dari repo,
    jadi hilang juga di copy PROD pada deploy berikutnya).
  - **Salinan LANGSUNG di Desktop (diminta user, 08/10/2026):** selain peluncur
    di atas, ada dua SALINAN APA ADANYA dari berkas copy DEV, yaitu
    `C:\Users\sapusr\Desktop\start-report.bat` dan
    `C:\Users\sapusr\Desktop\stop-report.bat` (byte-identik dengan DEV; dibuktikan
    `Get-FileHash` SHA256 sama dan CRLF utuh: start 174 / stop 161, LF-saja 0).
    Gunanya: versi TERBARU (pra-syarat + opsi `paksa` + opsi `tunnel`) bisa
    diklik dari Desktop tanpa menunggu `Deploy-Laporan.bat`. Keduanya tetap
    bekerja pada copy PROD karena jalur di dalam berkasnya ABSOLUT
    (`set "PROD=C:\laragon\www\oleochemicalReport-prod"`) dan TIDAK memakai
    `%~dp0`, jadi aman dijalankan dari Desktop.
    Konsekuensinya: salinan itu TIDAK ikut berubah saat berkas DEV/PROD
    diperbarui, jadi setiap kali `start-report.bat`/`stop-report.bat` diubah,
    SALIN ULANG
    (`Copy-Item -LiteralPath C:\laragon\www\oleochemicalReport\<berkas> -Destination C:\Users\sapusr\Desktop -Force`)
    lalu pastikan hash/akhiran barisnya tetap sama. Uji aman TANPA menyalakan
    atau mematikan service: `start-report.bat uji nopause` dan
    `stop-report.bat uji nopause`.
  - Memeriksa status service TANPA mengubah apa pun: klik
    `Cek-Status-Laporan.bat`, atau
    `powershell -NoProfile -ExecutionPolicy Bypass -File tools\cek-status-prod.ps1`
    (ada di DEV dan PROD). Menampilkan status tugas Windows, jumlah proses
    queue/schedule/poll/serve/tunnel, kode HTTP `/login` (port 8000, Apache,
    domain publik), dan 6 baris terakhir log watchdog.
  - **Jangan menguji `.bat` ini lewat pipe terminal** (`cmd /c "<file>.bat" | Select-Object`):
    proses service yang baru dinyalakan mewarisi handle stdout sehingga
    perintah tampak menggantung tanpa akhir padahal service sudah jalan.
    Kalau perlu menangkap keluarannya, alihkan ke FILE
    (`cmd /c "<file>.bat < NUL > %TEMP%\out.txt 2>&1`), lalu baca file itu.
  - Saat semua service mati, aplikasi TETAP bisa dibuka lewat Apache (vhost
    PROD: `http://localhost/` dan `http://oleo-prod.lvh.me`); yang mati hanya
    port 8000 dan domain publik. Dua tugas lama `Oleo Serve`/`Tele Poll`
    masih berstatus Ready dan TIDAK bisa dinonaktifkan tanpa hak admin, jadi
    setelah komputer reboot/login keduanya bisa menyalakan serve dari copy DEV
    di port 8000 — matikan lagi dengan `stop-report.bat`.
- `.env` PROD BOLEH diedit langsung (tidak dilacak git, memang beda per copy),
  tetapi WAJIB diikuti `php artisan config:clear` + `config:cache` di PROD.
  `APP_URL` PROD harus alamat yang bisa dibuka pengguna, karena dipakai
  membentuk link "Detail web" pada pesan Telegram
  (`app\Services\Telegram\AssetInfoService.php`). Nilainya per 30/09/2026
  14:4x WIB sudah `https://oleomtc.com` (sebelumnya `https://app.saranapendidik.com`,
  dan sebelum tunnel aktif sempat `http://192.168.41.74:8000`). Menggantinya
  JANGAN manual satu-satu langkah: pakai `tools\set-domain-prod.ps1 -Domain <domain>`
  (normalisasi + cek DNS + tulis .env + `config:clear`/`config:cache` + uji).
- Storage & database terpisah: DEV tidak memuat foto asli (gambar akan tampak
  rusak di dev; itu normal, bukan bug).
- `php artisan optimize` JANGAN dipakai — `routes/web.php` memakai closure
  route (`/`) sehingga `route:cache` gagal. Pakai `config:cache` + `view:cache`.

Setiap kali menyebutkan kondisi suatu file ("file X sudah ada", "kolom Y
begini"), WAJIB sertakan path lengkap absolut yang benar-benar dibaca saat
itu juga, dan sebutkan apakah yang dibaca itu copy DEV atau PROD. Jangan
mengandalkan laporan dari sesi sebelumnya tanpa verifikasi ulang.
