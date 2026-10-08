# AGENTS.md — oleochemicalReport

## PENTING — DUA COPY PROJECT DI KOMPUTER INI (DEV & PROD)

Sejak 28/09/2026 project ini SENGAJA ada dalam DUA copy di satu komputer
(diputuskan user: agar pengeditan tidak langsung terlihat di server):

| Peran | Path | Keterangan |
|---|---|---|
| **DEV** (tempat kerja agent) | `C:\laragon\www\oleochemicalReport` | SEMUA perubahan kode dikerjakan di sini. Database `oleochemical_dev`. Diuji lokal di `http://oleochemicalReport.test` (Apache, vhost lama). |
| **PROD** (yang disajikan) | `C:\laragon\www\oleochemicalReport-prod` | Hasil deploy. Disajikan Apache di `http://oleo-prod.lvh.me` + `http://localhost/`, dan `artisan serve` di **`http://192.168.41.74:8000`** (alamat yang dipakai orang di jaringan kantor); nanti domain publik. Database `oleochemical` (data asli) dan pemegang `storage/app` data asli (foto/laporan). **JANGAN pernah mengedit file di sini.** |

- Repo hub lokal (perantara deploy): `C:\laragon\repo\oleochemicalReport.git`
  (bare). DEV punya remote **`hub`** ke sana; PROD punya remote **`origin`**
  ke sana. Remote `origin` di DEV = GitHub (cadangan, sering tertinggal).
- Vhost produksi: `C:\laragon\etc\apache2\sites-enabled\oleo-prod.conf`
  (DocumentRoot ke copy PROD, `ServerName oleo-prod.lvh.me` + `ServerAlias localhost`).
- Alur perubahan yang WAJIB diikuti: edit & uji di DEV -> **commit di DEV
  SEGERA setelah uji lulus** (lihat "ALUR KERJA SETIAP SESI BARU" poin 7,
  jangan menumpuk perubahan tanpa commit) -> `git push hub main` -> jalankan
  `tools\deploy-prod.ps1` (pull di PROD + dependensi + migrasi + cache +
  restart service). JANGAN mengedit atau menjalankan migrasi manual di PROD.
- **Deploy satu klik (sejak 01/10/2026, keputusan user):** pintasan Desktop
  `Deploy-Laporan.bat` dan `Backup-Database-Laporan.bat` hanya MEMANGGIL
  `PROD\tools\deploy-laporan-prod.ps1` supaya tidak ada dua versi logika.
  Dijalankan MANUSIA (skripnya minta konfirmasi `y` sebelum mengubah PROD).
  Urutan kerja skrip itu: (1) backup database PROD lebih dulu dengan
  `mysqldump` (biner `C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysqldump.exe`)
  ke `C:\laragon\backup-oleochemical\db\oleochemical-<stamp>.sql`, 10 berkas
  terbaru disimpan, catatan di `C:\laragon\backup-oleochemical\backup-db.log`;
  **backup gagal = deploy dibatalkan** (jangan pernah melewati ini saat ada
  perubahan database) -> (2) `git push hub main` dari DEV TANPA membuat commit
  baru, jadi berkas DEV yang belum di-commit tidak ikut terkirim — karena itu
  WAJIB commit dulu di DEV (aturan poin 7), kalau tidak yang terpasang ke PROD
  hanya commit terakhir yang ADA di repo hub -> (3)
  ringkasan: commit PROD sekarang, commit yang ADA di repo hub, migrasi
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
  Apache sudah menyajikan DEV di `http://oleochemicalReport.test`.
- Service PROD dijaga otomatis oleh `tools\jaga-service-prod.ps1`, dipasang
  sebagai tugas Windows **`oleo-jaga-service-prod`** (berjalan tiap 5 menit,
  dibuat oleh `tools\pasang-jadwal-jaga-service.ps1`). Skrip itu menyalakan
  ulang keempat proses (queue:work, schedule:work, telegram:poll, serve:8000)
  yang mati dan membuang proses duplikat; catatannya ada di
  `PROD\storage\logs\jaga-service.log`. Kalau ada service yang tidak jalan,
  jalankan `tools\jaga-service-prod.ps1` lebih dulu sebelum menyimpulkan bug.
  **Tugas ini memanggil `wscript.exe` + `tools\jalankan-tersembunyi.vbs`**
  (bukan `powershell.exe` langsung) supaya tidak ada jendela PowerShell yang
  muncul-tutup setiap 5 menit — jangan diubah kembali ke `powershell.exe`.
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
    `Enable-ScheduledTask -TaskName oleo-jaga-service-prod` atau
    `tools\pasang-jadwal-jaga-service.ps1`.
  - Pintasan Desktop untuk pengguna awam (dibuat 30/09/2026, di luar repo
    jadi tidak dilacak git): `C:\Users\sapusr\Desktop\Jalankan-Service-Laporan.bat`,
    `C:\Users\sapusr\Desktop\Hentikan-Service-Laporan.bat`,
    `C:\Users\sapusr\Desktop\Cek-Status-Laporan.bat`, dan
    `C:\Users\sapusr\Desktop\Pasang-Token-Tunnel.bat`. Semuanya hanya MEMANGGIL
    file resmi copy PROD (`start-report.bat`, `stop-report.bat`,
    `tools\cek-status-prod.ps1`, `tools\pasang-token-tunnel-prod.ps1`) supaya
    tidak ada dua versi logika; kalau file
    di copy PROD tidak ditemukan, pintasan berhenti dengan pesan jelas.
    `Pasang-Token-Tunnel.bat` bersifat INTERAKTIF (Read-Host untuk menempel
    token tunnel baru), jadi JANGAN dijalankan lewat pipe/tanpa manusia.
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

## DOMAIN PUBLIK (CLOUDFLARE TUNNEL) — STATUS PERSIAPAN

Metode yang dipilih user: **Cloudflare Tunnel** (tanpa port forwarding, tetap
berjalan di belakang CGNAT, HTTPS otomatis dari Cloudflare). Yang SUDAH
disiapkan di komputer ini (terverifikasi 28/09/2026):

- Biner cloudflared versi **2026.9.3** ada DUA salinan di komputer ini, keduanya
  versi sama:
  1. `C:\Program Files (x86)\cloudflared\cloudflared.exe` — hasil
     `winget install --id Cloudflare.cloudflared` (terdaftar di winget:
     `winget list` menampilkan `cloudflared | Cloudflare.cloudflared | 2026.9.3`).
     Folder `C:\Program Files (x86)\cloudflared\` ada di PATH MESIN, jadi
     perintah `cloudflared --version` bisa dipanggil dari terminal BARU.
  2. `C:\laragon\bin\cloudflared\cloudflared.exe` — unduhan manual saat
     persiapan awal; `tools\siapkan-tunnel-prod.ps1` sengaja memakai path
     lengkap ini supaya tidak bergantung pada PATH.
  Catatan: kalau `cloudflared` "tidak ditemukan" di terminal yang sedang
  terbuka, itu karena PATH sesi itu disusun sebelum instalasi — buka terminal
  baru, bukan berarti paketnya tidak terpasang (lihat insiden #27).
- Konfigurasi: `C:\laragon\cloudflared\config-prod.yml` — ingress ke
  `http://localhost:80` dengan `httpHostHeader: oleo-prod.lvh.me` sehingga
  permintaan domain publik dilayani vhost PROD (tanpa menyentuh vhost DEV).
  **Kenapa 80 (Apache) dan bukan 8000 (`artisan serve`):** `artisan serve`
  bawaan PHP melayani satu permintaan sekaligus (satu proses) sehingga rentan
  lambat/timeout untuk pengguna dari internet; Apache multiproses dan sudah
  dipakai vhost PROD.
- Panduan: `tools\siapkan-tunnel-prod.ps1`.
- Vhost PROD: `C:\laragon\etc\apache2\sites-enabled\oleo-prod.conf`.
- **STATUS 28/09/2026 — TUNNEL SUDAH JALAN.** `https://app.saranapendidik.com`
  melayani copy PROD. Terverifikasi dari PC: `GET /login` → 200, `GET /` → 302
  ke `https://app.saranapendidik.com/dashboard`, aset CSS memakai domain itu
  (`/build/assets/app-*.css`), dan login admin end-to-end lewat domain publik →
  302 ke `/dashboard` lalu 200.
  Tunnel berjalan dalam **mode token dashboard** (dibuat dari dashboard
  Cloudflare, bukan CLI): token disimpan di
  `C:\laragon\cloudflared\token-prod.txt` dan diteruskan ke proses lewat
  variabel lingkungan `TUNNEL_TOKEN` (tidak muncul di daftar proses).
  `config-prod.yml` (mode config CLI) BELUM dipakai — baru berguna kalau
  `cloudflared tunnel login` diselesaikan dan tunnel dibuat lewat CLI.
  Aturan ingress (Public Hostname) untuk tunnel ini ada di DASHBOARD:
  `app.saranapendidik.com` DAN `oleomtc.com` → HTTP → `localhost:80` + HTTP
  Host Header `oleo-prod.lvh.me` (keduanya terbaca di snapshot konfigurasi yang
  diterima cloudflared 30/09/2026). Cloudflared mengambil perubahan ingress dari
  dashboard SECARA OTOMATIS (log: `Updated to new configuration`), jadi
  menambah/mengubah hostname TIDAK perlu me-restart proses tunnel.
  Catatan: token itu sempat terkirim di chat, jadi
  idealnya dirotasi (hapus + buat ulang tunnel) atau pindah ke jalur CLI.
- Pengaman akses publik **BELUM aktif** per 28/09/2026 — halaman login masih
  bisa dibuka siapa saja yang tahu URL-nya. Langkah berikutnya: Cloudflare
  Access (Zero Trust, tim mis. `saranapendidik`) atau Basic Auth Apache pada
  vhost PROD. JANGAN bagikan URL itu sebelum salah satu pengaman aktif.
- Nama tunnel: **`oleo-report`**, Tunnel ID
  **`3deca11e-4c25-42e0-bb0c-6469051ffd6d`** (dibaca dari
  `PROD\storage\logs\tunnel.err.log` baris `Starting tunnel tunnelID=...`).
  Mode token TIDAK menyimpan berkas kredensial: folder `C:\Users\sapusr\.cloudflared`
  memang KOSONG, jadi JANGAN mengandalkan isi folder itu.
- **DOMAIN PUBLIK BERGANTI (30/09/2026):** hostname utama sekarang
  **`https://oleomtc.com`** (apex, ditambahkan user sebagai Public Hostname);
  `app.saranapendidik.com` DIPERTAHANKAN sebagai cadangan.
  Status terverifikasi 30/09/2026 pukul 14:1x WIB: **zona `oleomtc.com` sudah
  aktif di Cloudflare** (NS `zeus.ns.cloudflare.com` /
  `sureena.ns.cloudflare.com`, SOA sudah dijawab sureena), TETAPI
  **belum ada record A/CNAME untuk apex `oleomtc.com`** — jawaban otoritatif NS
  hanya SOA, `www.oleomtc.com` punya record tetapi handshake TLS-nya gagal —
  sehingga `https://oleomtc.com/login` masih gagal (curl code 000).
  Tindakan tertinggal di sisi Cloudflare (MANUSIA, tidak bisa dari sini):
  zona `oleomtc.com` > DNS > Records > Add record, Type `CNAME`, Name `@`,
  Target `3deca11e-4c25-42e0-bb0c-6469051ffd6d.cfargotunnel.com`, Proxy
  `Proxied`. Cara termudah: buka ulang editor Public Hostname `oleomtc.com`
  pada tunnel `oleo-report` lalu Simpan, Cloudflare akan membuatkan recordnya.
  Alternatif yang lebih mulus: pakai subdomain `app.oleomtc.com` sebagai
  Public Hostname (Cloudflare otomatis membuat CNAME-nya).
  Pengalihan `APP_URL` PROD + `config:clear`/`config:cache` ada di
  `tools\set-domain-prod.ps1 -Domain oleomtc.com` (menolak jalan bila DNS
  belum ada; tambahkan `-Paksa` untuk tetap lanjut). Jangan alihkan `APP_URL`
  sebelum record DNS ada kalau tidak ingin tautan "Detail web" pada pesan
  Telegram menunjuk domain yang tidak bisa dibuka.
- `tools\cek-tunnel-prod.ps1` dan `tools\cek-status-prod.ps1` TIDAK lagi
  mematok domain: hostname publik dibaca dari `APP_URL` di `.env` copy PROD,
  jadi penggantian domain berikutnya tidak perlu menyunting skrip apa pun.
- **ROTASI TUNNEL SEDANG BERJALAN (30/09/2026).** User membuat tunnel BARU
  dengan ID **`0ce4f072-7ed2-42db-b440-6d0b3904963d`** — tujuannya mengganti
  token lama yang pernah bocor di chat. Status terverifikasi saat itu:
  connector yang JALAN masih memakai token LAMA
  (`C:\laragon\cloudflared\token-prod.txt` memuat token untuk tunnel
  `3deca11e-4c25-42e0-bb0c-6469051ffd6d`, berkasnya belum berubah sejak
  28/09/2026 11:41), dan tunnel lama itu memang sudah memuat hostname
  `app.saranapendidik.com` + `oleomtc.com`.
  Agar rotasi tuntas, tiga hal ini harus selesai:
  1. Di DASHBOARD tunnel BARU, tambahkan Public Hostname `oleomtc.com` DAN
     `app.saranapendidik.com`, masing-masing service HTTP `localhost:80` +
     HTTP Host Header `oleo-prod.lvh.me`. Menyimpan hostname pada editor itu
     sekaligus membuat/mengarahkan record DNS-nya ke tunnel baru
     (`0ce4f072-7ed2-42db-b440-6d0b3904963d.cfargotunnel.com`, Proxied) —
     inilah langkah yang sampai 30/09/2026 14:1x WIB masih KURANG untuk
     `oleomtc.com` (zona aktif, tetapi belum ada record A/CNAME).
  1a. STATUS 30/09/2026 14:4x WIB — ROTASI TUNTAS, DOMAIN SUDAH PINDAH:
     token tunnel BARU (`0ce4f072-7ed2-42db-b440-6d0b3904963d`) SUDAH
     terpasang dan `https://oleomtc.com/login` = 200 (diverifikasi 3x curl;
     aset HTML memakai host `https://oleomtc.com`). `APP_URL` copy PROD kini
     `https://oleomtc.com` lewat `tools\set-domain-prod.ps1 -Domain oleomtc.com`
     (config:clear + config:cache sudah ikut dijalankan).
     Isi folder `C:\laragon\cloudflared` sekarang: `token-prod.txt` = token
     BARU (aktif), `token-prod.txt.lama-20260930-143824.txt` = token LAMA,
     `token-prod.txt.lama-20260930-143307.txt` = salinan token BARU.
     SEMENTARA ada DUA proses connector cloudflared (`tunnel : 2` pada
     `tools\cek-status-prod.ps1`) dan itu MEMANG DISENGAJA: proses kedua
     memakai token tunnel LAMA sebagai cadangan supaya
     `https://app.saranapendidik.com/login` tetap 200, karena route hostname
     itu belum dipindah ke tunnel baru (DNS-nya masih menunjuk tunnel lama).
     Log proses cadangan: `PROD\storage\logs\tunnel-cadangan.out.log` dan
     `tunnel-cadangan.err.log`. Setelah hostname `app.saranapendidik.com`
     ditambahkan pada tunnel baru di dashboard, hentikan proses cadangan itu
     (Stop-Process pada cloudflared yang command line-nya memuat `tunnel run`).
     Ingat: `tools\pasang-token-tunnel-prod.ps1` menghentikan SEMUA connector
     saat memasang token, jadi proses cadangan itupun ikut mati — nyalakan
     ulang secara manual kalau masih diperlukan.
     Sisa pekerjaan di dashboard: hostname `app.saranapendidik.com` pada
     tunnel baru, dan `www.oleomtc.com` (sekarang dijawab 404 oleh tunnel
     karena belum didaftarkan sebagai Public Hostname).
  2a. Catatan cara pakai (30/09/2026): token TIDAK perlu ditempel di jendela
     hitam — skrip membuka Notepad dan membaca berkas
     `C:\laragon\cloudflared\token-baru.txt`. Menempel di jendela hitam pernah
     TERPOTONG sehingga ditolak dengan pesan FromBase64String (insiden #30);
     pesan galat skrip kini memuat panjang token dan sisa bagi 4 tanpa
     membocorkan isinya. Opsi `-TanpaNotepad` hanya untuk pengujian otomatis.
  2. Pasang token tunnel baru memakai
     `tools\pasang-token-tunnel-prod.ps1` (cara pakai di kepala berkas itu;
     token dibaca dari berkas atau isian tersembunyi — JANGAN menempelkan
     token ke chat). Skrip itu menyalakan ulang hanya proses connector.
  3. Kalau record DNS `app` pada zona `saranapendidik.com` masih menunjuk
     tunnel LAMA, arahkan ke target tunnel baru; kalau tidak, domain cadangan
     itu akan error 1033 begitu connector pindah ke tunnel baru.
- **Trusted proxy SUDAH dipasang** di `bootstrap/app.php`
  (`$middleware->trustProxies(at: ['127.0.0.1', '::1'], headers: ...X_FORWARDED_*)`)
  dengan pengujian di `tests/Feature/TrustedProxyTest.php`. JANGAN dihapus:
  tanpa ini aplikasi menganggap skema `http` dan host `oleo-prod.lvh.me`,
  sehingga halaman publik tampil tanpa CSS dan bisa terjadi redirect loop.
  Daftar `at:` itu BENAR selama cloudflared berjalan di komputer yang sama
  (Apache melihat koneksi datang dari 127.0.0.1). Kalau cloudflared nanti
  dipindah ke mesin lain, daftar `at:` WAJIB disesuaikan dengan IP mesin itu.
- Audit endpoint yang bisa dipanggil TANPA login (28/09/2026): hanya
  `POST /telegram/webhook` — selain itu `/` (redirect), `/login`, `/logout`,
  dan `/up`. Bot Telegram saat ini memakai **polling keluar** (`telegram:poll`)
  dan pemeriksaan `getWebhookInfo` membuktikan webhook-nya KOSONG, jadi
  **Cloudflare Access boleh diaktifkan untuk seluruh hostname tanpa
  pengecualian**. Kalau nanti bot diubah ke mode webhook (tombol "Set Webhook"
  di halaman Bot Telegram), WAJIB tambahkan policy **Bypass** khusus path
  `/telegram/webhook`, karena server Telegram tidak bisa melewati halaman login
  Cloudflare Access.
- Tunnel dijalankan otomatis oleh watchdog (`tools\jaga-service-prod.ps1`,
  kini menjaga 5 proses: queue:work, schedule:work, telegram:poll, serve:8000,
  tunnel) setelah `config-prod.yml` diisi (bukan lagi placeholder `TUNNEL-ID`);
  lognya di `PROD\storage\logs\tunnel.out.log` dan `tunnel.err.log`.
- Saran keamanan (belum dikerjakan): aktifkan **Cloudflare Access (Zero Trust)**
  untuk `oleomtc.com` DAN `app.saranapendidik.com` supaya hanya email tertentu
  yang bisa membuka halaman login; login Laravel tetap jadi lapis kedua.

Langkah yang MASIH perlu dijalankan user (jangan diasumsikan sudah selesai):
1. `cloudflared tunnel login` (butuh akun Cloudflare + browser).
2. `cloudflared tunnel create oleo-report` (catat TUNNEL ID).
3. `cloudflared tunnel route dns oleo-report <domain>`.
4. Isi TUNNEL ID + domain di `config-prod.yml` (atau pakai parameter
   `-Domain`/`-TunnelId` pada `tools\siapkan-tunnel-prod.ps1`).
5. Jalankan tunnel dari copy PROD.
6. Ubah `.env` PROD: `APP_URL=https://<domain>` lalu
   `php artisan config:clear` + `php artisan config:cache`.
7. Tunnel permanen: `cloudflared service install` (butuh hak admin).

Batasan penting lingkungan ini:
- Akun Windows **TIDAK punya hak admin**: file `hosts` tidak bisa diubah dan
  vhost Apache BARU tidak aktif sebelum Laragon di-reload
  (Laragon > Apache > Reload). Selama vhost belum aktif, uji dengan
  `curl.exe -H "Host: oleo-prod.lvh.me" http://127.0.0.1/login`.
- Karena entri `hosts` tidak bisa ditambah, hostname uji lokal memakai
  `localhost` dan `*.lvh.me` (DNS publik yang selalu menunjuk 127.0.0.1).
- Sebelum dipublikasikan, pastikan `.env` PROD sudah `APP_ENV=production` dan
  `APP_DEBUG=false` (ini juga mematikan route `debug-login`).

## KEPUTUSAN ARSITEKTUR FINAL (JANGAN TANYA ULANG, SUDAH DIPUTUSKAN)

1. Modul utama: **Condition Monitoring (CM)** — singkatan "CM" di project ini
   berarti **Condition Monitoring**, BUKAN Corrective Maintenance. Kalau ada
   kebutuhan mencatat pekerjaan perbaikan (CM/dCM/PM dalam arti maintenance),
   itu masuk konsep **"Corrective Maintenance"** yang harus ditulis lengkap
   (jangan disingkat "CM" juga) supaya tidak bentrok istilah dengan modul
   Condition Monitoring yang sudah ada.
2. Struktur data lokasi: **PT Location → Plant → Equipment Tag**. Project ini
   TIDAK punya konsep Area/Functional Location/TechIdentNo seperti project
   lain (mss-project) — jangan bawa konsep itu ke sini.
3. Equipment/reading (sesuaikan/cek ulang nama pasti via `php artisan
   model:show` — daftar berikut adalah nama yang PERNAH dipakai, WAJIB
   diverifikasi ulang sebelum dipakai lagi karena ada indikasi inkonsistensi
   penamaan antar sesi sebelumnya):
   - Equipment: tabel `cm_equipment`, kolom `equipment_tag`, `pt_location`,
     `plant`.
   - Reading: tabel `cm_readings`, kolom yang PERNAH disebut: `kondisi`
     (status ALARM/DANGER/GOOD/VISUAL BAD), `tanggal`, `max_vibration`,
     `max_temp`, `analysis`, `cm_equipment_id`. **VERIFIKASI ULANG nama-nama
     ini via `php artisan model:show CmReading` sebelum menulis query baru
     — pernah ada dugaan campur aduk dengan nama alternatif seperti
     `status_condition`/`date`, belum dipastikan mana yang benar-benar dipakai
     di database aktual.**
4. Sheet sumber data Excel (`Data_CM.xlsx`) — 5 sheet dengan tujuan berbeda:
   - `Data AppSheet` — data mentah per titik ukur, SUMBER UTAMA (bukan
     formula, angka asli).
   - `Status CM` — HASIL FORMULA (VLOOKUP + MAX + IF) yang merujuk ke
     `Data AppSheet`. Formula ini PUNYA cached value tersimpan di file
     (terverifikasi bisa dibaca dengan `setReadDataOnly(true)` di
     PhpSpreadsheet) — TIDAK PERLU dihitung ulang manual di PHP kecuali
     terbukti (lewat test terisolasi) cached value-nya benar-benar tidak
     terbaca.
   - `Master Equipment` — referensi daftar equipment per PT/Plant, dipakai
     untuk validasi (equipment belum terdaftar = warning, bukan reject).
   - `Monitoring Bulanan` — compliance tracking (Sudah/Belum) per equipment
     per bulan, header 3 baris dengan merged cells.
   - `Tabel Mon. Bulanan` — rekap agregat, bisa dihitung ulang otomatis dari
     sheet lain, tidak wajib diimport manual.
5. Threshold vibrasi tinggi: **> 4.5 mm/s**, WAJIB disimpan sebagai config
   (`config('cm.high_vibration.threshold')`), jangan hardcode di banyak
   tempat — nilai ini bisa direvisi sesuai ISO 10816 atau kebijakan internal.
6. Definisi bisnis untuk section **"Equipment Vibrasi Tinggi per PT"**
   (breakdown per kategori Analysis, card per PT) — **SUDAH DIPUTUSKAN,
   JANGAN DIUBAH TANPA INSTRUKSI EKSPLISIT USER**:
   - Pakai **LATEST READING PER EQUIPMENT** saja (1 entry per equipment,
     mewakili status TERKINI).
   - Latest = GOOD/normal → equipment TIDAK ditampilkan sama sekali.
   - Latest = ALARM/DANGER → tampilkan status TERBARU itu saja. Histori
     lama (status sebelumnya yang sudah "kalah" oleh reading lebih baru)
     TIDAK ikut ditampilkan.
   - Section **"Ranking Analisa Bulanan"** (tabel trend per bulan) —
     kemungkinan besar TETAP pakai SEMUA histori kejadian (bukan
     latest-only), supaya trend dari bulan ke bulan tetap valid untuk
     analisa historis. **INI HARUS DIKONFIRMASI ULANG ke user sebelum
     implementasi apapun yang menyentuh section ini — jangan diasumsikan.**
7. Tema visual WAJIB: warna aksen **teal `#0E9E8E`**. Vanilla JS (BUKAN
   Alpine.js `x-data`). Sidebar dua level: grup "MENU UTAMA" dan grup
   "RELIABILITY & MAINTENANCE" (berisi: Condition Monitoring, Riwayat
   Import, Reliability Dashboard).
8. Import Excel jalan sebagai **queued job** (`ProcessCmExcelImport`), BUKAN
   sinkron di request HTTP. Baca file pakai PhpSpreadsheet dengan
   `setReadDataOnly(true)` + `setLoadSheetsOnly()` per sheet (load 1 sheet
   per waktu, bukan 5 sheet sekaligus), `unset()` + `gc_collect_cycles()`
   setelah tiap sheet selesai diproses.
9. Setiap baris Excel yang diproses saat import WAJIB berakhir di salah
   satu kategori tercatat di `ImportLog`: insert / update / gagal /
   skip-duplikat / skip-kosong / unregistered. Total baris dibaca HARUS
   SAMA DENGAN jumlah semua kategori tersebut — log warning kalau tidak
   balance.

10. **Jadwal shift & man hour teknisi (sudah diputuskan 26/09/2026):**
   - Sumber: file Excel "Schedule Shift MTC" per periode **26-25 tiap bulan**.
     Blok `NAME STAF`/`NAME INTERNAL`/`NAME OS` = reguler, blok `NAME SHIFT` =
     Group A/B/C/D dengan kode `x` = OFF, `1` = 08.00-16.00,
     `2` = 16.00-24.00, `3` = 00.00-08.00.
   - Import lewat tombol di halaman Teknisi (POST `technicians.shift-schedule`)
     atau `php artisan shifts:import <file> [--execute]`; riwayat di tabel
     `shift_schedules`, detail harian di `technician_shifts`. Pencocokan
     teknisi memakai NIK dulu, baru nama (dicatat sebagai "dicocokkan lewat
     nama"); yang tetap tidak ketemu tetap tersimpan dan dilaporkan, tidak
     ditolak.
   - Target man hour: **480 menit per hari kerja** (`config('cm.man_hour')`),
     hari OFF target 0, Minggu target 0 untuk teknisi reguler. Realisasi =
     jumlah `work_duration_minutes` laporan milik teknisi (`technician_id`)
     pada tanggal itu; **baris salinan multi alat (`multi_of`) TIDAK dihitung**
     supaya satu pekerjaan tidak terhitung berkali-kali.
   - Anomali yang ditandai: bekerja pada hari OFF dan laporan yang
     `start_time`-nya di luar jendela shift hari itu (toleransi 60 menit).
   - Rentang tanggal di halaman Teknisi = periode jadwal terakhir (fallback:
     bulan berjalan). Perubahan aturan ini wajib dikonfirmasi ulang ke user.
   - **Pola rotasi (dikonfirmasi 26/09/2026):** semua group memakai siklus 20
     hari yang SAMA, hanya fasenya berbeda:
     `5 hari Shift 1 -> 2 OFF -> 5 hari Shift 3 -> 2 OFF -> 5 hari Shift 2 -> 1 OFF`.
     Rumus: `kode = siklus[(jarak_hari(tanggal, acuan) + offset[group]) mod 20]`
     dengan acuan `2026-06-26` dan offset A=2, B=12, C=17, D=7 (semuanya di
     `config('cm.shift_pattern')`). Verifikasi: 976 dari 976 hari-orang pada 4
     file jadwal (26 Juni - 25 Oktober 2026) cocok tanpa selisih. Karena itu
     jadwal periode berikutnya TIDAK perlu diupload: sistem memproyeksi sendiri
     (`ShiftPatternService`), dan bisa dipermanenkan lewat
     `php artisan shifts:generate --execute` atau tombol "Buat Jadwal Otomatis"
     di halaman Teknisi. Baris hasil upload selalu menang atas hasil proyeksi
     (untuk koreksi manual).
   - **Group non-shift ada dua (26/09/2026):**
     `reguler` = Senin-Kamis 08.00-16.00, Jumat 08.00-16.00 dengan istirahat
     2 jam (11.00-13.00), Sabtu 08.00-14.00, Minggu libur; istirahat hari biasa
     12.00-13.00.
     `reguler_staff` = Senin-Jumat 08.00-17.00, Jumat istirahat 2 jam,
     Sabtu/Minggu libur.
     Target harian = (jam selesai - jam mulai) dikurangi istirahat, hasilnya:
     reguler 420/420/420/420/360/300/0 menit dan reguler staff
     480/480/480/480/420/0/0 menit (Senin s.d. Minggu) — lihat
     `config('cm.man_hour.jam_kerja')`.
     Pekerja shift **tanpa potong istirahat** (8 jam penuh = 480 menit per hari
     kerja, dikonfirmasi 26/09/2026), jadi total mingguannya lebih besar
     (±42 jam) bukan karena salah hitung: reguler/reguler staff 39 jam/minggu,
     sedangkan pekerja shift rata-rata 15 hari kerja per 20 hari (5,25 hari/minggu).

11. **Status laporan `draft` TIDAK dipakai lagi (diputuskan 26/09/2026).**
   Laporan hanya berstatus `completed` (Done) atau `continue` (belum selesai),
   plus `needs_review` bila admin meminta pemeriksaan. Semua draft lama
   (700 laporan) sudah dipindahkan ke `completed` lewat migration
   `2026_09_26_000005` beserta `completed_at`-nya, dan nilai bawaan kolom
   `status` di database kini `completed`. Tombol "Tandai Draft", opsi filter
   Draft, dan kartu "Draft" di halaman Teknisi (diganti "Continue") sudah
   dihapus — jangan dihidupkan lagi tanpa instruksi user.

12. **Manajemen user berbasis Bagian (diputuskan 27/09/2026, sudah ada
   kodenya — jangan diubah tanpa instruksi user):**
   - Login memakai **username**; untuk akun teknisi username = **NIK** dan
     password awal = **NIK huruf kecil** (`mb_strtolower`). Akun lama tetap
     bisa login memakai email (deteksi bentuk isian ada di
     `app/Http/Controllers/LoginController.php`).
   - Role akun berisi **kode Bagian** (kolom `section` teknisi: mekanik, ei,
     it, sipil, fabrikasi, general, cm_pm, lainnya) atau `admin`/`supervisor`.
     Kolom `role` di tabel `users` karena itu diubah dari enum menjadi
     varchar(50) — **nilai bawaannya tetap `admin`** supaya akun lama dan
     akun buatan `UserFactory` tidak berubah perilakunya (perubahan default
     ini pernah hampir merusak seluruh pengujian).
   - Hak akses halaman diatur per Bagian **DAN per Group teknisi** di tabel
     `bagian_accesses` (`bagian` + `grup` unik bersama, `permissions` json);
     daftar modulnya di `config/akses.php` (label, grup menu, pola nama
     route, nama route menu sidebar, penanda `khusus_admin`). Akun
     `admin`/`supervisor` SELALU akses penuh dan tidak bisa dibatasi; modul
     bertanda `khusus_admin` (mis. Manajemen User) tidak bisa diberikan ke
     Bagian maupun Group mana pun.
   - Group sebuah akun diambil dari data teknisi yang terhubung
     (`users.technician_id` -> `technicians.group`), BUKAN dari kolom baru di
     `users` (keputusan user 01/10/2026, supaya group selalu ikut data teknisi
     dan tidak perlu dijaga manual). Urutan pencarian izin:
     (1) baris Bagian + Group, (2) baris Bagian dengan `grup` NULL sebagai
     pengaturan bawaan, (3) seluruh modul diizinkan. Jadi akun tanpa teknisi
     dan Group yang belum diatur memakai bawaan Bagian, dan baris lama dari
     migrasi `2026_09_27_000002` otomatis menjadi bawaan itu — tidak ada
     akses pengguna yang berubah saat migrasi
     `2026_10_01_000002_add_grup_to_bagian_accesses_table.php` dijalankan.
   - Layar "Hak Akses Bagian" (`resources/views/akses/index.blade.php`) punya
     DUA baris tab: tab Bagian, lalu tab Group (Bawaan Bagian + Reguler,
     Reguler Staff, Grub A-D) lengkap dengan jumlah akun/teknisi per
     kombinasi; Group yang belum diatur sendiri ditandai "mengikuti bawaan".
     `BagianAccess::untukBagian()` menerima parameter grup,
     `BagianAccess::tersimpan()` membaca baris apa adanya (untuk status
     "belum diatur" dan pengujian), dan `User::grupBagian()` /
     `User::labelBagian()` menampilkan label seperti "Mekanik - Reguler Staff"
     di daftar akun serta sidebar. Hak akses per modul di-cache per instance
     `User` (`$cacheDaftarAkses`) supaya pemeriksaan 15 modul untuk
     menyembunyikan menu tidak menanyakan database berkali-kali. Uji:
     `tests/Feature/AksesBagianGrupTest.php` (10 test; uji mutasi: membuat
     `User::grupBagian()` selalu mengembalikan null membuat 4 test GAGAL).
   - Penegakan izin ada di `App\Http\Middleware\AksesMiddleware` (alias
     `akses`, dipasang bersama `admin` pada grup route di `routes/web.php`).
     Route yang tidak terpetakan ke modul mana pun dilewatkan, karena itu
     ada test penjaga "semua route bernama punya modul hak akses" yang WAJIB
     tetap hijau setiap kali menambah route baru.
   - Sidebar menyembunyikan menu tanpa hak akses lewat partial
     `resources/views/layouts/partials/sidebar-akses.blade.php` (dipanggil
     dengan SATU baris dari `layouts/app.blade.php`, sengaja tidak mengubah
     547 baris layout). Penyembunyian ini hanya tampilan; pengamannya tetap
     AksesMiddleware di sisi server.
   - Kolom `users.email` dibuat nullable (akun teknisi tidak punya email);
     index unik `users_email_unique`, `users_username_unique`, dan
     `users_technician_id_unique` tetap dipertahankan.

13. **Mode hanya baca untuk akun non-administrator + relokasi menu sidebar
   (diputuskan 28/09/2026, sudah ada kodenya):**
   - `config('akses.role_penulis')` = `['admin']`: HANYA administrator yang
     boleh mengubah data. Semua akun lain (termasuk `supervisor` dan semua
     Bagian) hanya bisa membaca; permintaan POST/PUT/PATCH/DELETE ditolak
     `App\Http\Middleware\HanyaBacaMiddleware` (alias `hanya_baca`, dipasang
     pada grup route bersama `admin` dan `akses`). Route yang dikecualikan
     (mis. `logout` dan penanda notifikasi dibaca) ada di
     `config('akses.route_hanya_baca_bebas')`. Tambahkan `supervisor` ke
     `role_penulis` kalau supervisor juga perlu menulis — hanya itu yang
     perlu diubah.
   - Tampilan akun hanya baca: partial
     `resources/views/layouts/partials/mode-hanya-baca.blade.php` (dipanggil
     SATU baris sebelum `<main>` di `layouts/app.blade.php`) menampilkan
     penanda "Mode hanya baca" dan menyembunyikan form non-GET, tautan
     tambah/ubah/impor, dan tombol modal "Tambah...". Penyembunyian ini hanya
     tampilan; pengamannya tetap middleware.
   - **Relokasi menu sidebar JANGAN dilakukan dengan menulis ulang
     `layouts/app.blade.php` (547 baris — lihat riwayat insiden #3/#10/#12).**
     Pola yang dipakai: render menu di partial + buang tautan lama dengan
     skrip. Kolaborasi dirender `partials/sidebar-menu-utama.blade.php`
     (di-include setelah menu Laporan), Manajemen User + Hak Akses Bagian +
     Storage dirender `partials/sidebar-pengaturan.blade.php` (di-include
     sebelum judul "Sistem"), lalu `partials/sidebar-akses.blade.php`
     membuang tautan Storage/Kolaborasi posisi lama dan judul grup "Sistem"
     yang jadi kosong. Tautan baru diberi kelas `menu-pindahan` supaya tidak
     ikut terbuang. Ikuti pola ini untuk pemindahan menu berikutnya.
   - Ikon menu Kolaborasi memakai ikon "share" supaya tidak sama dengan menu
     Teknisi; Manajemen User memakai ikon kunci, Hak Akses memakai ikon
     perisai.

14. **Step Temuan (finding) + format daftar alat (diputuskan 01/10/2026;
   URUTANNYA DIBALIK 06/10/2026 atas instruksi user — jangan diubah lagi
   tanpa instruksi user):**
   - Wizard Telegram sekarang menjalankan **temuan lebih dulu (Step 5a) baru
     root cause (Step 5b)**: `ReportWizardService::STEP_FINDING` +
     `buildFindingPrompt()`, `handleFindingInput()`, `lewatiTemuan()` di
     `WizardStepHandlerTrait`. Alurnya: durasi (Step 4) -> TEMUAN (5a) ->
     ROOT CAUSE (5b) -> foto dokumentasi (Step 6). Jadi `duration_ok` menuju
     STEP_FINDING, temuan (teks atau tombol lewati) menuju STEP_ROOT_CAUSE,
     dan root cause (teks, tombol lewati, atau `rootcause_ok` dari deteksi AI)
     baru menuju STEP_PHOTO_DOCUMENTATION. Urutan lama (root cause lalu
     temuan) JANGAN dihidupkan kembali tanpa instruksi user.
     Root cause DAN temuan sama-sama **opsional**: tombol `Lewati Temuan`
     (`wizard:confirm:finding_skip`) dan `Lewati Root Cause`
     (`wizard:confirm:rootcause_skip`); teks `-`, `skip`, `lewati`, `tidak`,
     `tidak ada` juga diperlakukan sebagai lewati
     (`teksMenandakanLewati()`).
   - Uji penjaga urutan: `tests/Feature/ReportFindingStepTest.php` (18 test).
     Uji mutasi 06/10/2026: mengembalikan transisi durasi ke STEP_ROOT_CAUSE
     dan transisi temuan ke STEP_PHOTO_DOCUMENTATION membuat 3 test GAGAL.
   - Temuan disimpan di kolom BARU **`reports.finding`** (text nullable;
     migration `2026_10_01_000001_add_finding_to_reports_table.php`) dan ikut
     tersalin ke baris salinan multi alat karena dikirim lewat `$dataLaporan`
     di `WizardReportSaverTrait::saveReport()`. `root_cause` di titik itu
     memakai `?? null` supaya laporan tanpa root cause tetap bisa disimpan.
   - Form web laporan memakai partial agar blade besar tidak perlu ditulis
     ulang: `resources/views/reports/partials/_temuan.blade.php` (create/edit)
     dan `_temuan-kartu.blade.php` (detail laporan), masing-masing dipanggil
     SATU baris dari `create.blade.php`, `edit.blade.php`, `show.blade.php`.
   - Daftar alat pada prompt Step 8b dan di SEMUA layar hirarki mode multi
     memakai satu helper: `WizardUtilityTrait::daftarEquipmentState()` →
     blok `*Equipment :*` (alat utama: `KODE — Deskripsi`) lalu
     `*Equipment Tambahan :*` bernomor `1. KODE — Deskripsi`. State
     `equipment_desc` diisi saat alat dikunci (`lockEquipmentAndAdvance()`,
     `finalizeClarificationSelection()`, `startWizardForAsset()`) dengan
     fallback `Asset::find()` di `equipmentDesc()`.
   - **Label tombol daftar alat = kode alat + deskripsi SAJA**
     (`WizardUtilityTrait::tombolAlatLabel()`, dipotong 64 karakter). Kode
     Functional Location SENGAJA tidak disertakan: pada data DEV 1.185 dari
     4.252 label terpotong karena batas 64 karakter tombol Telegram (tanpa
     kode lokasi hanya 39) sehingga deskripsi alat terpotong. Jangan
     menambahkan `functional_loc` kembali ke label tombol alat, dan jangan
     menyalin logika label ini — `ClarificationKeyboardBuilderTrait` punya
     duplikat kecil (`labelTombolAlat()`) karena dipakai ClarificationService,
     bukan ReportWizardService.
   - Uji: `tests/Feature/ReportFindingStepTest.php` (14 test). Uji mutasi sudah
     dijalankan: menetralkan (a) penyimpanan temuan, (b) urutan blok daftar
     alat, (c) penghapusan kode funcloc pada label kandidat membuat 3 test
     GAGAL.

15. **Perbaikan deskripsi aset dari berkas "Master List" (diputuskan 03/10/2026,
   sudah ada kodenya):**
   - Perkakas: `php artisan assets:perbarui-deskripsi {berkas} {--sheet=}
     {--pt=} {--kode-saja} {--perbarui-kode-alat} {--laporan=} {--execute}` di
     `app\Console\Commands\AssetUpdateDescriptionCommand.php`. Kolom berkas
     dikenali dari BARIS JUDUL ("Kode Alat" dan "Description"), bukan dari
     urutan kolom, supaya revisi berkas berikutnya boleh menggeser posisi
     kolom. Tanpa `--execute` hanya simulasi; `--laporan=<path>` menulis CSV
     berisi SELURUH baris yang dibaca beserta statusnya (diperbarui / sama /
     ambigu / tidak_ketemu / kode_kosong / gagal) ditambah kolom
     `kode_alat_baru` (Kode Alat sesudah disamakan).
   - Aturan pencocokan: KODE ALAT = `assets.tech_ident_no` (huruf besar, spasi
     tepi dibuang). Bila satu Kode Alat dipakai lebih dari satu baris DI DALAM
     berkas, atau lebih dari satu aset di database, Equipment No. dipakai
     sebagai PEMUTUS (kolom `assets.equipment_no` berindeks unik). Bila Kode
     Alat di berkas tidak ada di database, baris dicocokkan lewat Equipment No.
     sebagai CADANGAN; opsi `--kode-saja` mematikan cadangan itu sehingga hanya
     Kode Alat persis yang dihitung. Pencocokan longgar lewat AKHIRAN kode alat
     DILARANG: "ESKALCY01" dan "MALCY01" sama-sama berakhiran "ALCY01" padahal
     dua mesin berbeda di lokasi yang sama.
   - Opsi `--perbarui-kode-alat` (dibuat 03/10/2026) menyamakan kolom
     `assets.tech_ident_no` dengan tulisan Kode Alat di berkas (database
     "ESKALCY01" menjadi "ALCY01"). Perubahan DIBATALKAN bila kode tujuan sudah
     dipakai aset LAIN di PT yang sama, karena `tech_ident_no` TIDAK berindeks
     unik (`database\migrations\0001_01_01_000007_create_assets_table.php` baris
     15) sehingga dua aset bisa berkode sama dan pencarian alat jadi ambigu.
     Simulasi DAN eksekusi 03/10/2026 pada database PROD `oleochemical`: 27 Kode
     Alat disamakan (aset id 3724-3751 selain 3740; mis. "ESKALCY01" -> "ALCY01",
     "ESKH05A" -> "SVH05A"), 1 dibatalkan (aset id 3740 "ESKSC04" tetap apa
     adanya karena "SC04" sudah dipakai aset id 3895), 0 gagal; total aset tetap
     4253 dan Kode Alat ganda di PT NPA tetap NIHIL. Bukti: simulasi
     `C:\laragon\backup-oleochemical\simulasi-kode-alat-npa.csv`, hasil eksekusi
     `C:\laragon\backup-oleochemical\hasil-samakan-kode-alat-npa.csv`, backup
     tabel `assets` sebelum menulis
     `C:\laragon\backup-oleochemical\assets-oleochemical-sebelum-samakan-kode-20261003-143341.sql`.
     Jebakan yang WAJIB diingat: `petaAset()` harus ikut membaca kolom
     `company_id`, kalau tidak `$aset->company_id` null dan penjaga bentrok
     tidak pernah aktif (bug ini nyata terjadi dan ketahuan dari test, bukan
     dari peninjauan kode). Database DEV `oleochemical_dev` BELUM dijalankan
     (masih 28 aset berawalan "ESK", id 3724-3751) - penyeragaman di DEV
     menunggu keputusan user.
   - Bukti pemakaian pertama: berkas `Master List NPA rev 2.xlsx` (287 baris)
     dipakai 03/10/2026 untuk PT NPA di database PROD `oleochemical`: 256 baris
     cocok lewat Kode Alat + 31 baris lewat Equipment No. (kode di berkas
     ditulis tanpa awalan "ESK"/"M"), 287 deskripsi diperbarui, 0 gagal.
     Berkas itu juga memuat Kode Alat ganda: "SC04" dipakai dua mesin berbeda
     (baris 66 = MP01-7831/70000101, baris 221 = RS01-7851/70000247) dan
     "AGT02A"/"AGT02B" masing-masing dua baris — inilah alasan pemutus
     Equipment No. WAJIB ada, bukan pilihan.
   - Cakupan berkas rev 2 DIPERIKSA 03/10/2026 memakai skrip sementara yang
     membandingkan laporan CSV hasil eksekusi dengan seluruh aset
     `assets.company_id` PT NPA (440 aset) di database PROD `oleochemical`:
     285 aset dikenali berkas, **155 aset TIDAK ada di berkas** — 86 aset
     pabrik/operasional (Line 2: `NPA-PROD-RS02-7852` 62, `MP02-7832` 12,
     `CAOT-7822` 8, forklift `WH01-7052` 4; plus 1 aset tanpa Kode Alat:
     id 4027 "Valve main line PFAD") dan 69 perangkat ICT (CCTV 35,
     workstation `WS-NPA-*` 13, radio 7, switch 6, card/face reader 5,
     SCADA 2, tablet 1). Artinya berkas rev 2 hanya mencakup Line 1; aset
     Line 2 sengaja dibiarkan (deskripsi lamanya TIDAK ikut diperbarui).
     Daftar lengkap: `C:\laragon\backup-oleochemical\daftar-aset-npa-luar-berkas-rev2-20261003.csv`
     (semua baris berkas + statusnya juga tersimpan di
     `daftar-selisih-npa-20261003.txt`, `daftar-kode-cocok-lewat-equipment-no-20261003.csv`,
     dan `daftar-baris-berkas-tanpa-aset-20261003.csv` — berkas terakhir KOSONG,
     bukti tidak ada baris berkas yang gagal ditemukan asetnya).
   - PENGINGAT: `app\Services\ImportService.php` mencocokkan aset lewat Tech
     Ident No dan mengambil `description` dari kolom Description berkas. Import
     ulang berkas master SAP yang kolom Description-nya masih berisi nama plant
     section (BLENDING, REACTIONSYSTEM, AIRSUPPLYSYSTEM, dst.) akan
     MENGEMBALIKAN deskripsi lama — perbaiki berkas sumbernya lebih dulu bila
     import ulang akan dilakukan.
   - Uji: `tests/Feature/AssetUpdateDescriptionCommandTest.php` (16 test; uji
     mutasi: memaksa penulisan saat mode simulasi membuat 1 test GAGAL,
     menetralkan penjaga bentrok membuat test "Kode Alat dibatalkan bila kode
     itu sudah dipakai aset lain" GAGAL, dan mengabaikan opsi
     `--perbarui-kode-alat` membuat test "tanpa opsi perbarui-kode-alat Kode
     Alat di database tidak diubah" GAGAL). Seluruh suite 305 test hijau
     (03/10/2026).

16. **Batas diam (idle) sesi wizard Telegram (diputuskan 04/10/2026, sudah ada
   kodenya):**
   - Latar: kejadian nyata 04/10/2026 — state wizard milik Vicky (chat
     `8827242872`, teknisi id 6) MENGANGGUR di step `work_classification` sejak
     pukul 10:01 padahal sudah hampir 2 jam, dan Dian Adi Kurniawan (chat
     `6146322700`, teknisi id 32) mengetik deskripsi pekerjaan yang sama tiga
     kali karena teks barunya selalu dibaca sebagai jawaban langkah LAMA. Batas
     keras `ReportWizardService::CACHE_TTL` = 7200 detik (2 jam) tidak pernah
     memeriksa apakah teknisi masih menjawab. (Identitas kedua teknisi
     diverifikasi langsung di database DEV `oleochemical_dev` tabel
     `technicians`, 04/10/2026.)
   - Aturan yang dipilih user: sesi yang menganggur melewati
     `config('cm.wizard.idle_timeout_menit')` (bawaan **10 menit**) dibatalkan
     otomatis **HANYA saat pesan TEKS baru datang**. Foto dan tombol SENGAJA
     TIDAK membatalkan apa pun supaya progres teknisi yang kembali dari
     lapangan (mengirim foto dokumentasi/hygiene terlambat) tidak hilang;
     keduanya tetap tunduk pada batas keras 2 jam. Isi 0 = mematikan
     pembatalan otomatis.
   - Wujud kode: `ReportWizardService::batasIdleMenit()`,
     `sesiSudahBasi(array $state)` (acuan `last_activity_at`, cadangan
     `created_at`; state tanpa kedua penanda dianggap BELUM basi demi
     melindungi sesi yang sedang berjalan saat fitur ini dipasang), dan
     `batalkanSesiBasi(string $chatId): bool` (menghapus state basi + mencatat
     log `WizardService: sesi basi dibatalkan otomatis`). `saveState()` mengisi
     `last_activity_at` pada setiap penyimpanan, dan `createInitialState()`
     ikut mengisinya. `hasActiveWizard()` TETAP memakai `Cache::has` tanpa
     pemeriksaan idle supaya jalur foto/tombol tidak berubah perilakunya.
     Pemanggil: `TelegramMessageHandlerTrait::processUpdate()` (tepat sebelum
     routing teks) yang juga mengirim pesan "Sesi laporan sebelumnya
     dibatalkan otomatis karena tidak ada jawaban lebih dari N menit.".
     Untuk tombol, pesan "Sesi laporan ini sudah tidak aktif..." yang sudah ada
     di `PollTelegramUpdates::processCallbackQuery()` tetap dipakai.
   - JANGAN memindahkan pemeriksaan idle ke `hasActiveWizard()` atau ke jalur
     foto (`handlePhotoMessage`/`handlePhotoInput`): akibatnya foto susulan
     memulai laporan BARU dan seluruh progres teknisi hilang — justru inilah
     alasan aturan "teks saja" dipilih.
   - Uji: `tests/Feature/ReportWizardIdleTimeoutTest.php` (9 test; uji mutasi:
     menetralkan pemeriksaan `sesiSudahBasi()` di `batalkanSesiBasi()` membuat
     3 test GAGAL, dan menghapus cadangan `created_at` membuat test "sesi tanpa
     penanda aktivitas memakai created_at sebagai cadangan" GAGAL). Seluruh
     suite 314 test hijau (04/10/2026).
   - **Perintah teks `/reset` (ditambahkan 05/10/2026).** Tombol "Batalkan
     Laporan" menempel pada pesan wizard, sehingga ia ikut hilang bila layar
     lama digambar ulang atau pesannya sudah tergulung jauh di riwayat chat —
     teknisi jadi tidak punya jalan keluar selain menunggu sesi basi
     dibatalkan otomatis. Karena itu `TelegramMessageHandlerTrait::processUpdate()`
     mengenali `/reset` (termasuk bentuk `/reset@namabot`) dan memanggil
     `handleResetCommand()` yang membuang state lewat
     `ReportWizardService::destroyWizard()` lalu membalas "Sesi laporan
     dibatalkan."; pesan berikutnya diproses sebagai laporan BARU, bukan
     jawaban langkah lama. Tidak ada laporan setengah jalan yang tersimpan
     karena laporan dibuat hanya setelah konfirmasi di Step 8. Letak
     pemeriksaannya SETELAH validasi teknisi aktif (bersama `/info`), dan
     hanya membuang sesi wizard chat pengirim. Uji:
     `tests/Feature/TelegramResetCommandTest.php` (4 test; uji mutasi:
     mematikan routing `/reset` membuat keempat test GAGAL). Seluruh suite 318
     test hijau (05/10/2026).

17. **Kecocokan daftar Laporan dengan Rincian Man Hour Harian (diputuskan
   05/10/2026, sudah ada kodenya):**
   - Keluhan asal (05/10/2026): "di bagian Rincian Man Hour Harian saat diklik
     harinya seharusnya menampilkan laporan kolaborasi juga". Penyebabnya:
     baris harian itu menaut ke
     `reports.index?technician_id=..&tanggal=..`, sedangkan
     `ReportController::buildBaseQuery()` SELALU membuang baris kolaborasi
     (`whereNull('collaborator_of')`) padahal `ManHourService::harian()`
     MENGHITUNG baris itu sebagai pekerjaan teknisi kolaborator — jadi kolom
     "Pekerjaan"/"Realisasi" terisi tetapi daftar yang dibuka kosong.
   - Aturan yang dipakai sekarang (**Opsi A, dipilih user**): salinan kolaborasi
     (`collaborator_of` terisi) ikut tampil HANYA bila daftar disaring ke satu
     teknisi (`technician_id` terisi). Daftar umum tetap konsolidasi (hanya
     laporan induk + keterangan "Berkolaborasi dengan: ...") supaya satu
     pekerjaan tidak berulang untuk tiap peserta. Salinan multi alat
     (`multi_of`) TIDAK PERNAH tampil di daftar, sama seperti di man hour.
     Jangan ubah definisi ini tanpa instruksi user (pelajaran insiden #8).
   - Bukti data DEV `oleochemical_dev` (05/10/2026): 129 pasangan
     teknisi-tanggal punya laporan kolaborasi; aturan lama cocok=0/beda=129,
     aturan baru cocok=129/beda=0 (skrip sementara `tmp-cek-kolaborasi.php`,
     dihapus lagi setelah dipakai, tidak masuk repo).
   - Uji: `tests/Feature/ReportTeknisiKolaborasiTest.php` (4 test). Uji mutasi:
     memaksa konsolidasi (selalu `whereNull('collaborator_of')`) membuat 2 test
     GAGAL; membalik pengecualian (konsolidasi hanya saat ada filter teknisi)
     membuat 3 test GAGAL. Test lama
     `tests/Feature/ReportIndexViewTest.php` "laporan kolaborasi tampil satu
     baris dengan daftar kolaborator" tetap hijau dan menjaga jalur
     konsolidasi. Seluruh suite 322 test hijau (05/10/2026).
   - CATATAN BELUM DIKERJAKAN (perlu keputusan user sebelum diubah): tautan
     "N laporan" di `resources/views/technicians/show.blade.php` baris 340-342
     TIDAK mengirim parameter `tampilan`, sehingga daftar selalu dibuka pada
     tab Reguler. Untuk teknisi pekerja SHIFT (grub A-D) laporannya bisa tetap
     kosong di sana karena `applyGroupFilter('reguler')` hanya memuat grup
     non-shift.
   - Lencana kolaborasi di halaman detail teknisi (05/10/2026): baris salinan
     kolaborasi pada tabel "Pekerjaan Harian"
     (`resources/views/technicians/show.blade.php`) menampilkan
     `KOLABORASI : <nama pembuat>` lewat accessor `Report::nama_pembuat`
     (dibaca dari `creator_id`; data lama yang `creator_id`-nya kosong
     ditelusuri dari laporan induk -- creator, lalu teknisi pemilik induk --
     supaya kolaborator TIDAK pernah disebut sebagai pembuat). Karena accessor
     itu dipanggil per baris, `TechnicianController::show()` memuat relasi
     `creator` + `parentReport.creator` + `parentReport.technician` agar tidak
     terjadi query berulang (N+1). Bukti data DEV `oleochemical_dev`
     (05/10/2026): 254 salinan kolaborasi, `creator_id` terisi semua (0 NULL).
     Uji: `tests/Feature/TechnicianKolaborasiBadgeTest.php` (4 test; uji
     mutasi: mengembalikan lencana menjadi "KOLABORASI" saja membuat 2 test
     GAGAL, dan memakai teknisi pemilik baris salinan sebagai cadangan membuat
     1 test GAGAL). Seluruh suite 376 test hijau (1643 assertions, 05/10/2026).

18. **Halaman EDIT laporan dimaksimalkan (diputuskan 05/10/2026, sudah ada
   kodenya — jangan diubah tanpa instruksi user).** Semua perubahan ada di copy
   DEV `C:\laragon\www\oleochemicalReport`; 6 commit berurutan `623cf34`,
   `bca2b66`, `65c9f55`, `dd24d9d`, `af2afe7`, `103d768` (semuanya sudah di
   `hub/main`).
   - **Jam kerja:** form edit kini punya kolom `start_time` & `end_time`
     (regex `^([01]?\d|2[0-3]):[0-5]\d$`, pesan galat Bahasa Indonesia). Durasi
     dihitung otomatis dari rentang jam bila kolom durasi dikosongkan
     (`Report::durasiDariJam()`); **jam selesai lebih kecil dari jam mulai
     dianggap melewati tengah malam dan ditambah 24 jam**
     (`Report::lintasTengahMalam()`, mis. 22:00-06:00 = 480 menit). Durasi yang
     diketik manual TIDAK ditimpa; durasi kosong + jam kosong = nilai lama
     DIPERTAHANKAN (kolom dikeluarkan dari data update) supaya angka man hour
     tidak hilang karena kolom terlewat; isi 0 bila memang ingin dinolkan.
     **Form edit TIDAK punya isian durasi (diputuskan 05/10/2026): hanya
     meminta Jam Mulai & Jam Selesai**, jadi form tidak mengirim kolom
     durasi sama sekali dan angkanya selalu dihitung ulang server dari rentang
     jam; durasi tersimpan hanya dipertahankan bila kedua jam dikosongkan.
     Partial `resources/views/reports/partials/_waktu-kerja.blade.php` berisi
     dua kolom jam + tombol "Kosongkan Jam" + peringatan lintas tengah malam
     (tanpa kotak durasi apa pun); KEDUA inputnya memakai atribut
     `form="form-edit-laporan"` karena dipasang di luar elemen `<form>` pada
     halaman detail (lihat poin 19). Halaman `create.blade.php` masih memakai
     isian durasi manual dan belum diseragamkan.
   - **Pencarian lokasi/alat:** `App\Http\Controllers\Traits\ReportLocationTrait`
     menerima parameter `q` pada `getFuncLocsByArea()`/`getAssetsByArea()`
     (hasil dibatasi `BATAS_CARI_LOKASI` = 50 baris saat `q` diisi) dan method
     baru `cariAsset()` (rute `reports.locations.cari-alat`) untuk pencarian
     ALAT LINTAS AREA (minimal 2 huruf; kode alat/nomor equipment/deskripsi/
     kode lokasi; membawa `area_id` + `funcloc_id` + `area_label`). Kotak
     pencarian memilih hasil lewat method Alpine baru
     `terapkanAset()`/`kosongkanLokasi()` pada komponen `reportDetail()` halaman
     detail (partial `partials/_cari-alat.blade.php`; skripnya mencari komponen
     lewat `[x-data="reportLocationManager()"], [x-data="reportDetail()"]`).
   - **Kolaborasi:** `App\Http\Controllers\ReportCollaborationController` +
     rute `reports.collaborators.store|destroy`; baris salinan dibuat oleh
     `App\Services\ReportCopyService::buatSalinanKolaborasi()` (detail pekerjaan
     meniru jalur wizard, kode laporan baru, `creator_id` = pembuat laporan
     induk). Hanya laporan INDUK yang boleh diubah
     (`collaborator_of`/`multi_of` harus NULL), pelaksana sendiri & teknisi
     kembar ditolak, batas `CollaborationService::MAX_KOLABORATOR` (5).
   - **Jalan masuk pengelolaan (diperbarui 05/10/2026):** tombol "Edit Laporan"
     pada kartu Aksi Admin `reports/show.blade.php` mengaktifkan mode edit, dan
     kartu Kolaborasi Teknisi + Alat/Lokasi Tambahan hanya tampak pada mode itu.
     Partial `_tombol-edit-lengkap.blade.php` dan halaman edit terpisah SUDAH
     DIHAPUS (lihat poin 19).
   - **Alat/lokasi tambahan (multi item):**
     `App\Http\Controllers\ReportMultiItemController` + rute
     `reports.multi-items.store|destroy`, memakai
     `ReportCopyService::buatSalinanMulti()` (area dihitung dari alat/node
     lokasi lewat `areaUntukItem()`, `report_type` equipment_repair/area_work,
     `multi_of` = induk). Alat induk / item kembar ditolak; baris salinan multi
     TIDAK dihitung di man hour. Label item memakai accessor
     `Report::multi_item_label` supaya tampilan server & balasan JSON sama.
   - **Foto:** `ReportController::addPhoto()` menerima BANYAK berkas
     (`photos[]`, selain bentuk lama `photo` tunggal yang tetap didukung) dan
     membalas JSON berisi daftar URL terbaru; `deletePhoto()` tetap seperti
     semula. UI foto ada di `reports/show.blade.php` (grid Alpine
     `photoGroups` + `unggahFoto()`/`deletePhoto()`, lihat poin 19).
     **Unggah/hapus foto HANYA boleh dari laporan INDUK** (`penjagaFotoInduk()`
     di `ReportController`) karena baris salinan memakai PATH FOTO YANG SAMA —
     menghapus dari salinan akan ikut menghapus berkas milik induk.
   - **Tipe & status:** form edit menambah `report_type` & `status`
     (`partials/_pelengkap.blade.php`). Keduanya nullable supaya pemanggil lama
     tetap kompatibel; `status` diisi -> `completed_at` ikut disetel (completed =
     `completed_at ?? now()`, selain itu NULL); `status` kosong -> kolom itu
     tidak disentuh.
   - **PENTING (bootstrap/app.php):** `shouldRenderJsonWhen()` dulu HANYA
     `api/*`, sehingga galat VALIDASI pada endpoint fetch halaman laporan
     berbalas 302 + HTML (JavaScript-nya bingung). Kini diperluas menjadi
     `api/*` ATAU (`expectsJson()` DAN `reports/*`).
   - Uji: `ReportEditJamKerjaTest` (11), `ReportEditCariAlatTest` (8),
     `ReportEditKolaborasiTest` (11), `ReportEditAlatTambahanTest` (8),
     `ReportEditFotoTest` (7), `ReportEditFieldPelengkapTest` (5) — semuanya
     diuji lewat `GET /reports/{id}` (mode edit halaman detail), bukan lagi
     halaman edit. Uji mutasi dijalankan per tahap: mematikan
     `durasiDariJam()` -> 3 test GAGAL; mematikan penjaga 2 huruf + batas 50 ->
     2 test GAGAL; `collaborator_of` diisi NULL -> 5 test GAGAL; mematikan
     `sudahAda()` + `areaUntukItem()` -> 3 test GAGAL; mematikan dukungan
     `photos[]` -> 3 test GAGAL; mematikan penyelarasan `completed_at` -> 2
     test GAGAL. Seluruh suite **372 test hijau** (1631 assertions) dan
     `php artisan view:cache` sukses (05/10/2026).
   - Semua AJAX memakai `fetch` + `X-CSRF-TOKEN` supaya isian form yang belum
     disimpan tidak hilang saat kolaborator/item/foto ditambah atau dihapus.

19. **Halaman DETAIL laporan menjadi SATU-SATUNYA tempat mengedit laporan
    (diputuskan 05/10/2026, sudah ada kodenya — jangan diubah tanpa instruksi
    user).** Halaman terpisah `reports.edit` SUDAH DIHAPUS supaya pengeditan
    tidak tersebar di dua tempat: semua perubahan laporan dikerjakan di
    `resources/views/reports/show.blade.php` pada mode edit (Alpine
    `reportDetail()`, tombol "Edit Laporan" pada kartu Aksi Admin). Semua
    berkas yang disebut di bawah ini ada di copy DEV
    `C:\laragon\www\oleochemicalReport`.
    - **Rute & controller:** `routes/web.php` memakai
      `Route::resource('reports', ...)->except(['edit'])`, jadi
      `GET /reports/{id}/edit` berbalas 404. `ReportController::edit()` diganti
      method privat `dataPengelolaanLaporan(Report): array` (kunci:
      `laporanInduk`, `kolaborator`, `teknisiPilihan`, `alatTambahan`) yang
      datanya digabungkan ke `view('reports.show', ...)` oleh `show()`.
    - **Berkas yang DIHAPUS:** `resources/views/reports/edit.blade.php`,
      `resources/views/reports/partials/_tombol-edit-lengkap.blade.php`, dan
      `resources/views/reports/partials/_foto.blade.php` (UI foto khusus
      halaman edit; fungsinya pindah ke halaman detail, jangan dihidupkan
      kembali).
    - **Partial yang dipakai halaman detail:** `_pelengkap` (ZPM, jenis
      pekerjaan, `sesuai_rencana`, `tanggal_kejadian`, `equipment_tag`, tipe
      laporan, status), `_waktu-kerja` (jam mulai/selesai), `_cari-alat`,
      `_kolaborasi`, `_alat-tambahan` — semuanya di-include dari
      `show.blade.php`. Karena inputnya berada DI LUAR elemen `<form>`, semua
      isian wajib memakai atribut `form="form-edit-laporan"` (form tersembunyi
      `PUT` ke `reports.update` di kolom kanan halaman). Kolom `finding`
      (temuan) pada halaman detail TETAP memakai partial `_temuan-kartu` yang
      sudah ada (kartu tersendiri: tampilan + mode edit); partial `_temuan`
      hanya dipakai halaman `create.blade.php`.
    - **Foto:** kotak unggah halaman detail mengirim BANYAK berkas
      (`photos[]`; id `foto_berkas`/`foto_unggah`, hygiene
      `foto_berkas_hygiene`/`foto_unggah_hygiene`) lewat `fetch` (method Alpine
      `unggahFoto()`), lalu daftar foto diperbarui dari balasan JSON
      `ReportController::addPhoto()` tanpa reload; hapus foto tetap
      `deletePhoto()`. Kotak unggah DAN tombol hapus hanya dirender untuk
      laporan INDUK (`$laporanInduk->id === $report->id`, lihat
      `dataPengelolaanLaporan()`) karena baris salinan memakai berkas foto yang
      sama; pengaman sisi server tetap `penjagaFotoInduk()`.
    - **Tautan baris alat tambahan TIDAK menuju halaman baris salinan:**
      `partials/_alat-tambahan.blade.php` dan
      `ReportMultiItemController::daftarItem()` menautkan ke
      `assets.show`/`locations.show` (kode baris salinan tetap ditampilkan
      sebagai keterangan). Ini menjaga aturan lama di
      `tests/Feature/ReportMultiEquipmentTest.php` ("halaman detail laporan
      induk tidak menautkan baris salinan", satu pekerjaan bersama tetap satu
      laporan) — jangan dikembalikan ke `reports.show` tanpa mengubah aturan
      itu lebih dulu.
    - **Uji:** semua test `tests/Feature/ReportEdit*Test.php`,
      `ReportProjectWorkTypeTest`, `ExampleTest`, dan `PageSmokeTest` dialihkan
      ke `GET /reports/{id}` (test `GET /reports/{id}/edit` di PageSmokeTest
      kini mengharapkan 404). Dua test "Edit Lengkap" pada
      `ReportEditKolaborasiTest` diganti menjadi "halaman detail laporan
      menjadi satu-satunya tempat pengelolaan laporan" dan "laporan salinan
      kolaborasi mengarahkan pengelolaan ke laporan INDUKNYA". Seluruh suite
      **372 test hijau (1631 assertions)** dan `php artisan view:cache` sukses
      (05/10/2026).

20. **Ruang panel: dukung sketsa dashboard (JSON TCP + HTML), bukan hanya
    firmware resmi (diputuskan 06/10/2026, sudah ada kodenya).** Semua perubahan
    ada di copy DEV `C:\laragon\www\oleochemicalReport`.
    - Latar: perangkat nyata yang dipakai user (`192.168.41.240`) menjalankan
      **sketsa sendiri** — port 80 menyajikan dashboard HTML (`Refresh: 3`, teks
      `Suhu: xx.xx` dan `Kelembaban: xx.xx`) dan port **TCP 5000** menyajikan
      satu baris JSON
      `{"device":"ESP32_W5500","ip":"...","temperature":..,"humidity":..,"uptime_sec":..}`.
      Sketsa itu TIDAK punya `/api/status` maupun buffer, sehingga modul panel
      lama (yang hanya membaca JSON API firmware) selalu menilai perangkat
      offline. Diverifikasi LANGSUNG ke perangkat, bukan asumsi:
      `GET /api/status` menjawab HTML (jadi `Http::json()` null), dan TCP 5000
      baru membalas SETELAH klien mengirim satu byte (lihat `handleTCPClients()`
      pada sketsa) — karena itu `PanelPerangkatReader::bacaJsonTcp()` menulis
      `"\n"` lebih dulu.
    - Kolom BARU `panel_ruangan.protokol` (migration
      `2026_10_06_000001_add_protokol_to_panel_ruangan_table.php`, bawaan
      `otomatis`): `otomatis` | `api-json` | `tcp-json` | `html`. Daftarnya di
      `PanelRuangan::PROTOKOL`, label tampilan lewat `labelProtokol()`.
      Urutan `otomatis` = JSON API (paling informatif) -> JSON TCP -> dashboard
      HTML. Ruangan lama tidak perlu diubah karena bawaannya `otomatis`.
    - Pembaca mentah dipisah ke **`App\Services\PanelPerangkatReader`**
      (`bacaJsonHttp`, `bacaJsonTcp`, `bacaDashboardHtml`) supaya bisa diganti
      tiruan saat pengujian — jangan mengembalikan HTTP/TCP langsung ke dalam
      `PanelSensorService`.
    - `PanelSensorService` bercabang pada penanda `sumber` jawaban. Jalur lama
      (`json-api`) tetap memakai buffer + `id_perangkat` milik perangkat.
      Perangkat tanpa buffer (`tcp-json`/`html`) memakai jalur BARU
      `tarikDariNilaiTerkini()`: `waktu` = waktu penarikan server, `sesi_boot`
      tetap `'sk'`, `id_perangkat` naik sendiri (`max(id_perangkat)+1`).
      **Nilai yang tidak berubah hanya disimpan ulang setelah
      `panel.sketch.jeda_simpan_detik` (bawaan 60 detik); nilai yang berubah
      selalu langsung disimpan.** Port TCP diatur `panel.sketch.port_tcp`
      (bawaan 5000).
    - **Jebakan yang harus diingat:** penanda `sumber` WAJIB ikut disisipkan ke
      dalam array status (`$mentah['sumber'] = 'json-api'`), bukan hanya di
      pembungkus hasil. Versi pertama hanya menaruhnya di pembungkus sehingga
      perangkat firmware resmi salah dianggap perangkat sketsa (tersimpan 0
      pembacaan). Ketahuan dari test, bukan dari peninjauan kode.
    - **Penjaga nilai nol:** sketsa mengirim `0,00/0,00` selama sensor belum
      pernah berhasil membaca (variabelnya diawali nol, lihat `buildJSONData()`),
      jadi pasangan suhu DAN kelembapan nol dianggap "sensor belum membaca":
      ruangan ditandai online + `catatan` berisi petunjuk wiring, TANPA menyimpan
      baris pembacaan supaya grafik tidak tertipu angka nol. Penentunya
      `PanelSensorService::sensorMembaca()`.
    - Tampilan: pilihan "Cara baca perangkat" di form ruangan, nama protokol di
      kartu ruangan (`protokol_label` dari `/panel/data`), dan baris "Sumber
      jawaban" pada modal Uji Koneksi. Pesan uji koneksi perangkat tanpa buffer
      memakai cabang sendiri di `PanelRuanganController::pesanUjiKoneksi()`
      (tidak menyebut W5500/firmware karena sketsa tidak melaporkannya).
    - Uji: `tests/Feature/PanelProtokolSketchTest.php` (12 test, 45 assertions;
      memakai tiruan `PanelPerangkatReader` + `ItPingService` sehingga tidak
      membuka soket nyata). Seluruh suite **388 test hijau (1688 assertions)**
      dan `php artisan view:cache` sukses (06/10/2026). Bukti uji nyata ke
      perangkat user: `sumber=tcp-json`, ping `online` 3 ms, status ruangan
      `online`, catatan "sensor belum mengirim pembacaan", TIDAK ada baris palsu
      tersimpan, dan `bacaDashboardHtml()` membaca angka asli dari dashboard
      port 80 perangkat.
    - Naik ke PROD lewat `Deploy-Laporan.bat` (migrasi `protokol` ikut jalan).
    - **Wiring perangkat nyata (diselaraskan 06/10/2026):** blok `KONFIGURASI PIN`
      di `sensor-suhu-esp32\firmware\ethernet_dht11\ethernet_dht11.ino` kini
      `PIN_DHT 27` + `#define TIPE_SENSOR DHT22` + `PIN_W5500_RST 17`
      (`PAKAI_RESET_W5500 1`) supaya cocok dengan papan yang sudah disolder user
      dan dengan sketsa dashboard (`sketch_dashboard.ino`: DHTPIN 27, DHTTYPE
      DHT22, W5500 CS=5/SCK=18/MISO=19/MOSI=23/RST=17). Bagian W5500 memang
      sudah sama sejak awal; yang DULU berbeda hanya pin sensor (GPIO4) dan tipe
      sensor (DHT11). Pin GPIO2 tetap dipakai sebagai LED status (LED bawaan
      board). Sketsa bantu `firmware\uji_sensor_dht\uji_sensor_dht.ino` (ikut
      di-commit) mencoba beberapa pin x DHT11/DHT22 untuk memastikan kombinasi
      yang benar; JANGAN mengompilasi/mengunggah apa pun tanpa perintah user. Catatan:
      salah `TIPE_SENSOR` membuat library menolak data (format/checksum berbeda)
      sehingga pembacaan selalu gagal.


21. **Pengaturan lengkap ruang panel + ruang panel menjadi aset penuh
    (diputuskan 06/10/2026, sudah ada kodenya).** Semua perubahan ada di copy
    DEV `C:\laragon\www\oleochemicalReport`. Pemicunya: user melaporkan suhu
    sudah muncul di dashboard (dibuktikan langsung di database DEV
    `oleochemical_dev`: 110 baris `panel_pembacaan` untuk ruangan id 3 "Ruang
    Panel EPE 1", suhu 24,90-25,00 C, `sesi_boot='sk'`, `sumber='pull'`).
    - **Halaman baru**: `resources/views/panel/ruangan/form.blade.php` (tambah +
      ubah, satu berkas) dan `resources/views/panel/ruangan/show.blade.php`
      (detail + tombol aksi). Rute baru di `routes/web.php`:
      `panel.ruangan.create`, `panel.ruangan.edit`, `panel.ruangan.show`,
      `panel.ruangan.sinkron-aset`. Route `create` WAJIB didaftarkan SEBELUM
      `/panel/ruangan/{panelRuangan}` supaya kata "create" tidak terbaca
      sebagai id.
    - **Form memisahkan dua jenis sumber data secara eksplisit**: kartu
      "Dashboard Arduino (sketsa sendiri)" (`tcp-json` dengan port TCP per
      ruangan, `html`) dan kartu "Firmware proyek (JSON API)" (`api-json`),
      semuanya radio `name="protokol"`. Label `PanelRuangan::PROTOKOL` TIDAK
      diubah karena test lama memeriksanya di halaman `/panel`.
    - **Kolom baru** (migration
      `2026_10_06_000002_sambungkan_panel_ruangan_ke_assets.php`):
      `panel_ruangan.funcloc_id` (FK nullOnDelete), `kode_alat`,
      `equipment_type`, `port_tcp`; `assets.panel_ruangan_id` (FK cascade);
      ENUM `assets.data_source` ditambah `panel_sync` (perubahan ENUM dijaga
      `if (DB::getDriverName() === 'mysql')` seperti migration IT).
    - **Kode alat manual + nomor equipment otomatis** (KEPUTUSAN USER
      06/10/2026): `assets.tech_ident_no` = `panel_ruangan.kode_alat` (diketik
      manual pada form), sedangkan `assets.equipment_no` dibuat 8 digit lewat
      `AssetNumberService::generateForFuncLoc()` mengikuti seri nomor PT lokasi
      itu. Ruangan tanpa lokasi ATAU tanpa kode alat TIDAK dipaksa masuk: baris
      cerminnya dibuang dan alasan dilewatkannya ditampilkan di halaman
      form/detail.
    - **`App\Services\PanelAssetSyncService`** (pola sama dengan
      `ItAssetSyncService`): `syncOne`, `syncAll`, `hapusCermin`,
      `removeStaleMirrors`, `buildMirrorPayload`, `alasanLewatSinkron`,
      `asetCermin`. Dipanggil otomatis pada `store`/`update`, tombol
      "Sinkronkan Aset", saat `destroy`, dan perintah
      `php artisan panel:sync-assets [--dry-run]`
      (`app\Console\Commands\PanelSyncAssetsCommand.php`). Baris aset lama
      bertipe impor/manual dengan kode alat sama DIADOPSI (nomor equipment
      lamanya dipertahankan); baris cermin modul IT (`it_sync`) TIDAK boleh
      diadopsi.
    - **Satu sumber aturan lokasi**: `App\Services\AssetLocationService`
      (company_id/department_id/area_id/sub_area_id + `kolomLokasi()`);
      `ItAssetSyncService` kini mendelegasikan `resolveCompanyId()` dan
      `resolveDepartmentId()` ke service itu (import `Company`/`Department` di
      sana dihapus). Daftar Functional Location untuk form dipusatkan di
      `App\Services\FunclocOptionService::grupPerPt()` dan
      `ItAssetController::funclocOptionsGroups()` memakai service yang sama.
    - **Port TCP per ruangan**: `PanelSensorService` memakai
      `PanelRuangan::portTcpEfektif()` (kolom `port_tcp`, cadangan
      `config('panel.sketch.port_tcp')`), tidak lagi satu nilai global.
    - **Penjaga `kode_alat`**: menolak kode yang sudah dipakai baris cermin
      modul lain atau ruangan panel lain, TETAPI baris cermin milik ruangan itu
      sendiri dikecualikan. Jebakan yang sudah kena sekali: penyaring
      pengecualian WAJIB memakai `whereNull('panel_ruangan_id') orWhere(...)`,
      karena `where('panel_ruangan_id', '!=', $id)` membuang baris NULL di SQL
      sehingga cermin milik perangkat IT lolos. Bug pertama malah menolak
      menyimpan perubahan pada kode alat yang sama; ketahuan dari test, bukan
      dari peninjauan kode.
    - **Import/export aset**: `data_source` `panel_sync` diperlakukan sama
      seperti `it_sync` (dilewati `ImportService` pada tiga titik pemeriksaan,
      tidak ikut diexport di `AssetExportTrait`), dan label data_source di
      `resources/views/assets/show.blade.php` ditambah "Sinkron Ruang Panel".
    - **Dashboard `/panel` TIDAK ditulis ulang** (1.052 baris): modal lama tetap
      dipakai sebagai "tambah cepat" (setelah simpan, pengguna diarahkan ke
      halaman detail ruangan untuk melengkapi lokasi/kode alat), nama ruangan
      pada kartu menaut ke halaman detail, dan tombol Edit mengarah ke halaman
      pengaturan lewat `window.location.href`. **Modal lama BELUM dihapus**;
      kalau nanti ingin dihapus, lakukan overwrite penuh berkas itu sekali
      jalan, jangan tambal sebagian.
    - Uji: `tests/Feature/PanelPengaturanRuanganTest.php` (14 test, 73 assertions;
      mencakup port TCP per ruangan, adopsi baris lama, penolakan kode bentrok
      ruangan lain/perangkat IT, hapus ruangan menghapus cermin, dan perintah
      `panel:sync-assets --dry-run`). Test lama
      `PanelProtokolSketchTest` "ruangan panel dapat disimpan dengan protokol
      tcp-json" disesuaikan: pengalihan setelah simpan kini ke halaman detail
      ruangan, bukan `/panel`. Seluruh suite **403 test hijau (1767 assertions)**
      dan `php artisan view:cache` sukses (06/10/2026).
    - Catatan lingkungan: `php` TIDAK ada di PATH sesi terminal agent; pakai
      `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`. Saat memanggil
      `php artisan tinker --execute` dari PowerShell, tanda kutip GANDA di dalam
      argumen terpotong (muncul "Undefined constant") — pakai kutip tunggal
      ganda (`''teks''`) atau, untuk pemeriksaan rumit, skrip sementara
      `tmp-cek-*.php` yang dijalankan langsung lalu dihapus lagi.

22. **Dashboard panel diringkas, penanggung jawab panel, halaman detail
    (grafik realtime + riwayat harian + ekspor Excel), dan pengingat Telegram
    harian (diputuskan 06/10/2026, sudah ada kodenya).** Semua perubahan ada di
    copy DEV `C:\laragon\www\oleochemicalReport`; 4 commit berurutan
    **`eafd7d`** (penanggung jawab), **`c16070d`** (dashboard ringkas),
    **`8685bef`** (detail: grafik + riwayat harian + ekspor), **`3a4c4ec`**
    (pengingat Telegram); semuanya sudah di `hub/main`.
    - **Dashboard `/panel` TIDAK lagi menampilkan grafik besar dan tabel
      riwayat.** `resources/views/panel/index.blade.php` ditulis ulang: panel
      `#panelGrafik`/`#panelRiwayat` beserta seluruh JavaScript pendukungnya
      DIHAPUS (jangan dihidupkan lagi). Setiap kartu sekarang memuat: nilai
      terkini, **kurva singkat** (canvas `kurva-<id>`, Chart.js tanpa
      sumbu/label/legenda dari data `grafik` yang sudah dikirim `/panel/data`),
      **lencana kondisi** (`TERLALU PANAS` / `TERLALU DINGIN` / `SUHU NORMAL` /
      `BELUM ADA DATA` berdasar `PanelRuangan::kondisiSuhu()`), nama
      **penanggung jawab**, ambang suhu, dan tombol Detail/Uji/Tarik/Edit/Hapus.
      Kurva lama di-`destroy()` sebelum kartu digambar ulang
      (`bersihkanKurva()`) supaya objek Chart tidak menumpuk di memori.
    - **Penanggung jawab (PIC) panel**: tabel baru **`panel_penanggung_jawab`**
      (pasangan unik `panel_ruangan_id` + `technician_id`, keduanya cascade)
      lewat migration `2026_10_06_000003_tambah_penanggung_jawab_panel.php`
      yang sekaligus membuat **`panel_notifikasi_terkirim`** (unik
      `technician_id` + `tanggal`, kolom `jam_kerja`, `jumlah_ruangan`).
      Relasi: `PanelRuangan::penanggungJawab()` (belongsToMany) dan
      `Technician::panelYangDitangani()`; helper `namaPenanggungJawab()`
      mengembalikan "belum ditentukan" bila kosong. Dipilih dari data teknisi
      (dikelompokkan per Bagian; yang tanpa Telegram ID diberi tanda
      "- tanpa Telegram") pada kartu baru form ruangan
      `resources/views/panel/ruangan/partials/_penanggung-jawab.blade.php`
      (di-include satu baris dari `form.blade.php` supaya form 340 baris tidak
      perlu ditulis ulang). Validasi `penanggung_jawab[]` (array id teknisi
      yang ada) + `simpanPenanggungJawab()` (sync; daftar kosong = menghapus
      semua).

    - **Halaman detail ruangan** (`resources/views/panel/ruangan/show.blade.php`,
      ditulis ulang): kartu ringkasan (lencana kondisi ikut diperbarui
      JavaScript), **grafik realtime** Chart.js dari endpoint BARU
      `panel.ruangan.data` (polling tiap `panel.tampilan.interval_polling_detik`),
      **tabel riwayat harian** per bulan (`?bulan=YYYY-MM`, bawaan bulan
      berjalan; isian tidak sah dikembalikan ke bulan berjalan), tombol
      **Ekspor Excel** (`panel.ruangan.export-harian`, nama berkas dari nama
      ruangan + cap waktu), dan tombol **Kirim Pengingat**
      (`panel.ruangan.kirim-pengingat`, uji kirim manual oleh admin).
    - **Rekap harian**: `App\Services\PanelRiwayatService::harian()` menghitung
      MIN/MAX/AVG suhu & kelembapan serta jumlah pembacaan di luar ambang dengan
      agregasi SQL (`DATE(waktu)` + `CASE WHEN`), dibatasi
      `startOfDay()`/`endOfDay()` (jangan tanggal polos — lihat insiden #23).
      Ekspor Excel-nya di trait baru
      `App\Http\Controllers\Traits\PanelExportTrait` (gaya sama dengan
      `AssetExportTrait`: blok judul/keterangan, header teal, autofilter,
      `setCellValueExplicit`).
    - **Pengingat Telegram harian**: `App\Services\PanelNotifikasiService`
      menentukan jam masuk kerja tiap PIC (`mulaiKerja()`): non-shift memakai
      `JamKerjaService::hari()` (Sabtu/Minggu libur otomatis), pekerja shift
      memakai kode pola rotasi `ShiftPatternService::kode()` (kode `x` = OFF =
      tidak dikirimi). Pesan memuat kondisi terkini tiap ruangan yang ditangani
      (suhu, kelembapan, lencana kondisi, online/offline, tautan detail dan
      dashboard; teks dinamis di-escape `htmlspecialchars` karena Telegram
      memakai parse_mode HTML). Pengiriman hanya **sekali per hari per teknisi**
      (dijaga `panel_notifikasi_terkirim`) dan hanya di dalam **jendela 15 menit
      setelah jam masuk kerja** (`config('panel.notifikasi.jendela_menit')`,
      toleransi bila penjadwal sempat mati beberapa menit). Perintah
      `php artisan panel:kirim-pengingat` dijadwalkan `everyMinute`
      (`routes/console.php`, saklar `PANEL_NOTIFIKASI_ENABLED`), dengan opsi
      `--uji-kirim` dan `--teknisi=` untuk pengujian/manual. **Jebakan yang
      sudah kena sekali:** mode uji kirim TIDAK boleh menuntut jadwal kerja
      (teknisi yang sedang OFF tetap harus bisa dikirimi saat diuji) — karena
      itu `kirim()` punya parameter `$wajibJadwal` dan `$idTerpilih`; teknisi di
      luar daftar terpilih TIDAK dihitung pada "dilewati" supaya pesan
      ringkasannya tidak menyesatkan.
    - Uji: `PanelPenanggungJawabTest` (7), `PanelDashboardRingkasTest` (2),
      `PanelRiwayatHarianTest` (5), `PanelPengingatTelegramTest` (7). Uji mutasi
      yang sudah dijalankan: memaksa mode uji kirim tetap menuntut jam kerja
      membuat 1 test GAGAL, dan filter `$idTerpilih` yang ikut menghitung
      "dilewati" membuat pesan ringkasan salah (ketahuan dari test tombol kirim).
      Seluruh suite **424 test hijau (1860 assertions)** dan
      `php artisan view:cache` sukses (06/10/2026).
    - Dua berkas blade besar (`panel/index.blade.php` 1055 baris dan
      `panel/ruangan/show.blade.php` 325 baris) ditulis ulang dengan pola: salin
      berkas lama ke `%TEMP%`, hapus berkas aslinya, lalu tulis versi baru
      bertahap dari baris terakhir — cara ini dipakai karena
      `find_and_replace` pada berkas blade besar berulang kali meleset dan
      titik sambungannya bergeser (lihat Aturan Wajib Edit File poin 6 dan
      Riwayat Insiden #12). SETELAH menulis bertahap WAJIB memeriksa: titik
      sambungan, jumlah `<div>`/`</div>`, `<script>`/`</script>`,
      `@push`/`@endpush`, `@forelse`/`@endforelse`, `php artisan view:cache`,
      lalu mengekstrak blok `<script>` dan menjalankannya lewat
      `C:\laragon\bin\nodejs\node-v22\node.exe --check` (echo blade diganti `0`
      lebih dulu) supaya kesalahan JavaScript ketahuan tanpa membuka browser.
    - Catatan alat: `read_files` dengan rentang baris atas berkas yang SUDAH
      pernah dibaca utuh bisa dijawab `[outdated - see the latest file
      content]` tanpa isi. Jalan pintas yang dipakai saat itu: cetak rentang
      barisnya lewat PowerShell (`(Get-Content <berkas>)[a..b]` + nomor baris),
      HANYA untuk membaca — penulisan tetap memakai tool editor.
    - **Perbaikan 06/10/2026: kartu suhu dashboard menampilkan `24.4NaN`, bukan
      `24.4 C`.** Penyebabnya satu tanda plus BERLEBIH pada rangkaian
      penggabungan teks penyusun kartu (`resources/views/panel/index.blade.php`
      baris 453 sebelum diperbaiki berbunyi
      `+          + '<span class="text-sm font-semibold text-slate-400"> C</span>'`):
      tanda plus kedua adalah unary plus, sehingga JavaScript mengubah teks
      span menjadi `NaN` dan satuan C ikut hilang. Halaman detail ruangan tidak
      terpengaruh karena nilainya dirender server (`number_format`). Perbaikan:
      hapus tanda plus kedua. Bukti render browser nyata (Chrome
      `--headless=new --dump-dom` atas `http://oleochemicalReport.test/panel`):
      sebelum = `>24.5NaN`, sesudah =
      `24.4<span class="text-sm font-semibold text-slate-400"> C</span>` dan
      tidak ada `NaN` (pencarian peka huruf besar-kecil). Uji penjaga: 2 test
      baru di `tests/Feature/PanelDashboardRingkasTest.php` (uji mutasi:
      mengembalikan tanda plus ganda membuat KEDUA test GAGAL). Lihat Riwayat
      Insiden #34.
    - Catatan lingkungan 06/10/2026: copy DEV `oleochemical_dev` ternyata BELUM
      menjalankan migrasi `2026_10_06_000003_tambah_penanggung_jawab_panel`,
      sehingga `/panel/data` di DEV berbalas HTTP 500 (relasi `penanggungJawab`
      dibaca lebih dulu padahal tabel `panel_penanggung_jawab` belum ada) —
      padahal `tarikBilaJedaLewat()` sudah menarik data perangkat SEBELUM galat
      terjadi, jadi angka pembacaan tetap bertambah walau kartu halaman tampak
      gagal. Sudah diperbaiki dengan `php artisan migrate --force` di DEV. PROD
      sudah memiliki kedua tabel itu.

23. **Import/Export asset berpindah kunci ke Equipment No (diputuskan 06/10/2026, sudah
    ada kodenya).** Semua perubahan ada di copy DEV
    `C:\laragon\www\oleochemicalReport`; PROD baru ikut setelah `Deploy-Laporan.bat`
    dijalankan manusia (TIDAK ada migrasi baru, jadi deploy aman).
    - Keluhan asal: admin ingin MENGUBAH Kode Alat di file hasil export, tetapi import
      lama mencocokkan HANYA lewat Tech Ident No sehingga baris ber-kode-baru dibuat
      sebagai aset BARU (duplikat).
    - **Export** (`app\Http\Controllers\Traits\AssetExportTrait.php`): kolom
      **`Equipment No` ditambahkan di kolom B** (setelah `No`) untuk xlsx DAN csv,
      nilainya ditulis sebagai TEKS (`setCellValueExplicit`) supaya angka nol di depan
      tidak hilang. Kolom lain bergeser satu: C Description, D Tech Ident No, E
      Equipment Type, F Functional Loc, ... O Data Source (`$kolomTerakhir` = A..O).
    - **Import** (`app\Services\ImportService.php`): urutan pencocokan =
      **(1) Equipment No** (kolom unik; header `Equipment No`/`Equipment` sudah dikenali
      `buildColMap()` sejak lama, tetapi nilainya dulu dibuang/`''` di `kumpulkanBaris()`),
      **(2) Tech Ident No** sebagai cadangan bila nomor di file kosong atau belum ada di
      database, **(3) aset baru** + nomor dibuat `AssetNumberService`. Urutan ini adalah
      PILIHAN USER saat ditanya 06/10/2026 ("file lama tetap jalan tanpa perubahan").
      Baris hasil cocok diberi penanda `dicocokkan_lewat`
      (`equipment_no`/`tech_ident_no`).
    - Penjaga yang WAJIB ada (jangan dilepas):
      (a) **Kode Alat baru yang sudah dipakai aset LAIN** walau nomornya cocok -> masuk
      bucket `ambigu` (Perlu Diperiksa) dan TIDAK ditulis, karena `tech_ident_no` tidak
      berindeks unik (Riwayat Insiden #15);
      (b) **Equipment No yang muncul >1 kali di file** -> `ambigu`;
      (c) nilai Kode Alat yang sudah rusak karena konversi spreadsheet TIDAK menimpa
      database (`kodeAlatTerpotong()`: "61530" vs "6153E1") -- sebelumnya kasus ini hanya
      tertangani di jalur Tech Ident No, sekarang ikut di jalur Equipment No (ketahuan
      dari test lama "Tech Ident No angka yang terpotong Excel...");
      (d) baris **tanpa Kode Alat tetap masuk bucket `no_equip`** (tidak diproses) supaya
      sel Kode Alat kosong tidak menghapus nilai yang sudah ada;
      (e) nomor equipment aset lama TIDAK PERNAH diubah oleh import, dan nomor pada baris
      BARU tetap diabaikan (dibuat sistem).
    - **Jebakan yang sudah kena sekali:** penghitung identitas membaca
      `$baris['equipment_no']`, padahal nilai itu ditaruh di
      `$baris['data']['equipment_no']` oleh `kumpulkanBaris()`. Karena ada `?? ''` tidak
      muncul error apa pun; hanya penjaga "nomor dobel di file" yang diam-diam tidak
      pernah aktif (ketahuan dari test, BUKAN dari peninjauan kode). Karena itu kunci
      `equipment_no` kini ditulis di LEVEL ATAS array baris (sejajar
      `tech_ident_no`/`functional_loc`).
    - Berkas lain yang disesuaikan: `app\Http\Controllers\ImportController.php` (template
      Excel + lembar Petunjuk memuat kolom `Equipment No` dan urutan pencocokannya;
      `getStyle('A1:I1')`), `resources\views\assets\import.blade.php` (teks penjelasan
      pencocokan di halaman import), dan `PROMPT.md` bagian "Export & Import Aset".
    - Uji: `tests\Feature\AssetImportUpdateTest.php` (32 test; 5 test baru: kode alat
      diubah tetap dicocokkan lewat Equipment No tanpa aset baru, fallback ke Kode Alat,
      Kode Alat milik aset lain ditandai perlu diperiksa, Equipment No dobel di file, dan
      export menulis Equipment No sebagai teks di kolom B). Test lama yang mengunci
      perilaku "Equipment No tidak diekspor" serta indeks kolom export (B7/C7/E7, C8,
      indeks 4/6/7/8 pada CSV) ikut disesuaikan. Seluruh suite **433 test hijau (1909
      assertions)** dan `php artisan view:cache` sukses (06/10/2026).
    - Bukti uji nyata ke database DEV `oleochemical_dev` (aset id 4302, Equipment No
      70000292, Kode Alat FBN04): file tanpa mengubah Kode Alat ->
      `duplicate=1, clean=0, dicocokkan_lewat=equipment_no, perubahan=[]`; file dengan
      Kode Alat diubah menjadi `FBN04-UJI` -> tetap `duplicate=1, clean=0,
      dicocokkan_lewat=equipment_no` dengan
      `perubahan=[{Tech Ident No: FBN04 -> FBN04-UJI}]`. Jumlah aset tidak berubah
      (4256) karena analisa tidak menulis apa pun.

24. **Tata letak halaman DETAIL laporan disesuaikan (diputuskan 06/10/2026, sudah ada
    kodenya).** Semua perubahan ada di copy DEV
    `C:\laragon\www\oleochemicalReport`, berkas
    `resources/views/reports/show.blade.php` (1.258 baris setelah perubahan).
    - **Temuan dipindah KE ATAS Root Cause** (include
      `reports.partials._temuan-kartu` di baris 372, di atas kartu Root Cause),
      mengikuti urutan wizard Step 5a temuan lalu 5b root cause (lihat item 14).
      Jangan dikembalikan ke bawah tanpa instruksi user.
    - **Analisa AI dan Timeline TIDAK lagi tampil langsung di halaman.** Kedua kartu
      lama (yang dulu berada di ujung kolom kiri) kini ada di dalam popup: satu kartu
      baru "Analisa AI dan Timeline" berisi dua tombol
      (`@click="bukaPopup('analisa')"` dan `@click="bukaPopup('timeline')"`), dan dua
      blok `<template x-teleport="body">` memuat kartu lamanya. Popup ditutup lewat
      tombol "Tutup", klik luar (`@click.self`), atau tombol Escape
      (`@keydown.escape.window`); overlaynya `z-50` (sidebar layout `z-40`) sehingga
      tidak tertutup sidebar. State Alpine `popup` (`null` | `'analisa'` |
      `'timeline'`) beserta `bukaPopup()` dan `tutupPopup()` ada di `reportDetail()`.
      Isi popup Analisa AI tetap memakai
      `@if($report->is_manually_edited) ... @elseif($report->ai_analyzed)`, ditambah
      pemberitahuan "Belum ada analisa AI untuk laporan ini." bila keduanya tidak
      berlaku (sebelumnya bagian itu kosong).
    - **Kartu Aksi Admin tidak lagi punya tombol pintas status**: tombol "Tandai
      Selesai" dan "Tandai Perlu Review" DIHAPUS 06/10/2026; status laporan diubah
      lewat pilihan "Status Pekerjaan" pada mode edit (`partials/_pelengkap`). Route
      `reports.update-status` dan test `tests/Feature/ReportDraftRemovalTest.php`
      tetap ada; test itu kini memastikan KEDUA label tombol TIDAK muncul lagi dan
      `Status Pekerjaan` tetap ada sebagai penggantinya.
    - Cara menyunting berkas 1.181 baris ini TANPA menulis ulang seluruhnya: semua
      perubahan dikerjakan sebagai SISIPAN (`insert_line`) dari BAWAH ke ATAS (baris
      775, lalu 733, lalu 678, terakhir 372) supaya nomor baris di atasnya tidak
      bergeser, ditambah dua `find_and_replace` pada string unik satu/beberapa baris
      (include `_temuan-kartu` lama, dan blok 3 baris `enableEditMode()`). Kartu lama
      TIDAK dihapus: pembungkus popup disisipkan mengelilinginya. Sisa alami: satu
      include diganti komentar penanda dan ada dua baris kosong ganda (tidak
      berpengaruh ke tampilan).
    - Uji: `tests/Feature/ReportDetailTampilanTest.php` (4 test: urutan Temuan -> Root
      Cause lewat `assertSeeInOrder`, tombol popup + `x-show="popup === ..."` + tepat
      2 `x-teleport="body"`, pemberitahuan "belum ada analisa AI", dan kartu Aksi
      Admin tanpa tombol status). Uji mutasi 06/10/2026: mematikan include temuan dan
      mengubah nama popup analisa membuat 2 test GAGAL. Seluruh suite **438 test hijau
      (1932 assertions)**, `php artisan view:cache` sukses, dan blok `<script>` hasil
      ekstraksi lolos `node --check`.

## ATURAN WAJIB EDIT FILE (supaya tidak gagal apply / macet)

1. File BARU atau perubahan >30% isi file: overwrite penuh (create_file
   atau tulis manual di editor), JANGAN find_and_replace/diff parsial.
2. Perubahan KECIL (<30%): find_and_replace dengan target pencarian PENDEK
   (maksimal 5-10 baris), dan target harus UNIK di file (kalau ragu suatu
   string muncul lebih dari 1 kali, sertakan konteks baris sebelum/sesudah
   yang lebih panjang supaya match presisi ke satu lokasi saja).
3. Satu file, satu bagian besar per panggilan tool. Pecah jadi beberapa
   panggilan berurutan untuk file besar (>250-300 baris), laporkan hasil
   tiap panggilan sebelum lanjut.
4. Baca ulang file dari disk LANGSUNG SEBELUM melakukan perubahan apapun
   pada file itu — baik `find_and_replace` MAUPUN overwrite penuh, tidak
   terkecuali. Ini termasuk file yang "baru saja" ditulis di giliran atau
   panggilan tool sebelumnya dalam sesi yang sama — JANGAN andalkan isi
   file yang diingat dari konteks/percakapan, karena isi di disk bisa
   sudah berubah (oleh proses lain, oleh revert, atau oleh panggilan tool
   sebelumnya yang gagal sebagian).
5. Jika tool edit GAGAL, STOP — jangan coba lagi dengan variasi teks
   berkali-kali, dan JANGAN eskalasi ke PowerShell. Laporkan: nama file,
   potongan teks yang dicari, dugaan penyebab gagal. Tunggu instruksi
   lanjutan — opsi teraman adalah manusia menulis manual langsung di editor.
6. File `.blade.php`: WAJIB overwrite penuh sebagai DEFAULT MUTLAK, BUKAN
   sekadar anjuran. `find_and_replace` parsial HANYA boleh untuk perubahan
   1 baris tunggal yang benar-benar unik di seluruh file — di luar itu
   DILARANG. **Trigger otomatis berhenti:** begitu SATU KALI panggilan
   `find_and_replace` pada file `.blade.php` gagal (string tidak
   ditemukan/tidak unik), itu adalah SINYAL WAJIB untuk langsung pindah ke
   overwrite penuh pada percobaan berikutnya — JANGAN coba variasi target
   pencarian lain, JANGAN coba replace bagian lain dulu dengan asumsi
   filenya masih baik-baik saja. Satu kegagalan find_and_replace di file
   blade = anggap file berpotensi sudah tidak sinkron dengan asumsi kamu;
   baca ulang dari disk, lalu overwrite penuh.
7. JANGAN PERNAH gunakan terminal/PowerShell/`php -r` untuk menulis isi
   file `.php` atau `.blade.php` — selalu pakai tool file bawaan (create
   file / edit file), atau tulis manual di editor kalau tool bermasalah.
8. Baca file lewat tool baca file (`view`/`read_file`) langsung, JANGAN
   verifikasi keberadaan/isi file lewat command PowerShell dengan `php -r`
   atau `file_exists()` di terminal. Untuk file kritis, verifikasi ukuran
   file (byte) sebagai pengecekan tambahan, jangan hanya percaya isi yang
   ditampilkan tool.
9. Setelah overwrite file blade/PHP, WAJIB hitung ulang kesesuaian tag
   pembuka/penutup (`<div>` vs `</div>`, `@foreach` vs `@endforeach`,
   `@push` vs `@endpush`, kurung kurawal `{` `}`) — jangan asumsikan
   berhasil hanya karena tidak ada error saat menulis.
10. **Recovery via `git checkout`/`git reset` pada satu file** (mis. file
    blade yang terbukti corrupt akibat find_and_replace gagal berulang):
    boleh dipakai sebagai langkah darurat SEKALI untuk mengembalikan file
    ke versi bersih terakhir yang ter-commit. Setelah checkout berhasil,
    WAJIB:
    - Baca ulang file itu dari disk untuk konfirmasi isinya memang bersih
      (jangan asumsikan checkout otomatis berarti bersih dan sesuai
      ekspektasi — verifikasi isi asli).
    - SISA perubahan yang belum selesai pada file itu WAJIB dilanjutkan
      dengan overwrite penuh, BUKAN kembali ke find_and_replace parsial —
      karena find_and_replace parsial yang berulang adalah PENYEBAB file
      itu corrupt di awal. Kembali ke metode yang sama akan mengulang
      kegagalan yang sama.
    - `git checkout HEAD -- <file>` mengembalikan ke commit TERAKHIR, bukan
      ke commit tertentu di masa lalu. JANGAN checkout ke commit hash lama
      dari Riwayat Insiden manapun di bawah — hash-hash itu catatan sejarah
      untuk kejadian saat itu saja, BUKAN prosedur baku untuk diulang (lihat
      catatan di Riwayat Insiden #3).

## RIWAYAT INSIDEN (pelajaran, jangan diulang)

1. File `resources/views/cm/_tabs.blade.php` sempat CORRUPT — isi HTML tab
   navigasi yang benar tersambung dengan konten mentah `routes/web.php`.
   Root cause pasti tidak pernah dikonfirmasi 100%, dugaan kuat: proses
   edit sebelumnya gagal separuh jalan dan menyisipkan konten file lain ke
   file ini. Solusi yang berhasil: overwrite penuh file dengan konten yang
   benar-benar bersih, verifikasi dengan `grep -c "Route::"` untuk pastikan
   tidak ada sisa teks route di file blade manapun.

2. Halaman Overview Condition Monitoring sempat menampilkan RAW ROUTE FILE
   sebagai konten halaman (bocoran struktur backend ke user) — akibat dari
   insiden #1 di atas (compiled view mencerminkan file sumber yang corrupt).

3. `overview.blade.php` sempat corrupt KEDUA KALINYA — kali ini bukan
   karena PowerShell, tapi karena `find_and_replace` yang salah target/tidak
   unik saat menambahkan modal Import Excel + script, menyebabkan `<script>`
   dan `@push('scripts')` terduplikasi tidak rapi. Solusi: `git checkout`
   ke commit bersih terakhir (`69f216d`), lalu tambahkan fitur baru sebagai
   SATU KALI overwrite penuh, bukan tambal berkali-kali.
   **Catatan: hash commit `69f216d` di atas adalah snapshot SEJARAH untuk
   insiden itu SAJA, BUKAN instruksi baku yang harus diulang. JANGAN pernah
   `git checkout` ke hash lama ini lagi di sesi mana pun — kalau ada file
   corrupt baru, gunakan `git checkout HEAD -- <file>` (commit terakhir,
   bukan hash lama ini) atau overwrite penuh file yang corrupt saja,
   JANGAN revert seluruh repo ke titik waktu lama.**

4. Penulisan file Blade besar lewat PowerShell double-quote here-string
   (`@" ... "@`) menyebabkan SEMUA `$variable` dan `{{ $x }}` ter-strip
   hilang (PowerShell tetap melakukan variable expansion di double-quote
   here-string). Solusi: pakai single-quote here-string (`@' ... '@`) kalau
   terpaksa lewat shell, tapi LEBIH DIUTAMAKAN hindari shell sama sekali
   untuk menulis konten file — pakai tool file bawaan.

5. Proses import Excel sempat menyebabkan PROSES PHP GHOST (150MB per
   proses, tidak selesai-selesai) — root cause: `IOFactory::load()` memuat
   SELURUH file (5 sheet) sekaligus TERMASUK parsing formula kompleks
   (SUMPRODUCT, array formula di sheet "Monitoring Bulanan"). Solusi:
   `setReadDataOnly(true)` + `setLoadSheetsOnly()` per sheet, `unset()` +
   `gc_collect_cycles()` di antara proses tiap sheet, dan pastikan tidak
   ada proses PHP ghost tersisa (`Get-Process php`) sebelum menyimpulkan
   proses berhasil.

6. Sempat disimpulkan (SALAH) bahwa "PhpSpreadsheet dengan
   `setReadDataOnly(true)` tidak bisa membaca cached value formula" pada
   sheet "Status CM", sehingga direncanakan rewrite besar untuk menghitung
   ulang semua nilai (Max Vibration, Max Temp, Status Condition) dari sheet
   "Data AppSheet" secara manual di PHP. Setelah diverifikasi LANGSUNG ke
   file asli (dibaca dengan mode cached-value), TERBUKTI cached value-nya
   ADA dan bisa dibaca dengan normal — kesalahan diagnosis kemungkinan
   besar karena salah pakai method (`getValue()` vs cached-value reader)
   di kode, BUKAN keterbatasan library. **Pelajaran: WAJIB test terisolasi
   minimal dulu sebelum menyimpulkan "library tidak bisa X", jangan langsung
   rencanakan rewrite besar berdasarkan asumsi.**

7. Hasil import sempat menunjukkan angka tidak balance (Total Baris 4.340,
   tapi Insert+Update+Gagal+Unreg cuma 3.917 — selisih 423 baris "hilang").
   Root cause: ada `continue`/skip di tengah loop import yang tidak diikuti
   pencatatan kategori (misal baris duplikat dalam file yang sama di-skip
   diam-diam). Solusi: setiap baris WAJIB berakhir di kategori tercatat
   (lihat Keputusan Arsitektur poin 9), tambahkan assertion balance di
   akhir proses.

8. **Kesalahan diagnosis business logic 2x berturut-turut (kasus paling
   mahal)** — untuk section "Equipment Vibrasi Tinggi per PT": awalnya
   diasumsikan bug adalah "cuma ambil reading terakhir, seharusnya semua
   histori kejadian" (Fix 1, diimplementasi sebagai rewrite besar). Setelah
   itu ternyata definisi yang BENAR justru sebaliknya: **latest per
   equipment SAJA** yang harus tampil (1 entry mewakili status terkini,
   bukan riwayat lama). Assumption pertama menyebabkan bug baru: equipment
   yang sama (misal `6821MX01`) tampil DUA KALI di kategori yang sama
   (Mar-26 ALARM dan Mei-26 DANGER), padahal harusnya cuma tampil SEKALI
   (Mei-26 DANGER, karena itu status terbarunya). **Pelajaran: definisi
   filter/agregasi data adalah KEPUTUSAN BISNIS, bukan keputusan teknis —
   WAJIB dikonfirmasi definisi persis ke user (idealnya minta 2-3 contoh
   kasus konkret) SEBELUM menulis kode, terutama kalau ada beberapa
   section/halaman berbeda yang berpotensi butuh definisi berbeda pula
   (lihat Keputusan Arsitektur poin 6).**

9. Bug tampilan card nested — kategori 2/3/4 (Bearing Defect, Structure
   Looseness, Cavitation) tampil ter-indent ke dalam card kategori 1
   (Misalignment) alih-alih sejajar sebagai kategori terpisah dalam 1 card
   PT yang sama. Root cause: `<div>` pembuka per-kategori di dalam
   `@foreach` tidak ditutup `</div>` dengan benar sebelum kategori
   berikutnya dirender, sehingga menumpuk nested ke iterasi berikutnya.
   **Pelajaran: setelah edit blade yang melibatkan `@foreach` bersarang
   dengan banyak `<div>`, WAJIB hitung manual jumlah tag buka vs tutup
   sebelum menganggap selesai.**

10. **Pelanggaran sadar terhadap aturan yang sudah jelas** — pada
    `overview.blade.php`, sudah tahu aturan "blade default overwrite
    penuh" (poin 6 di atas), tapi tetap memilih bolak-balik
    `find_and_replace` parsial (mengubah "Temp Motor" jadi "Temp Motor +
    Temp Mesin") karena dianggap "lebih cepat". Hasilnya: karakter escape
    literal (`\"`, `\\\"`) berceceran di file, file corrupt. Recovery
    dilakukan dengan `git checkout HEAD -- resources/views/cm/overview.blade.php`
    (commit terakhir, bukan hash lama), lalu berhasil 3 replace pendek,
    tapi replace ke-4 gagal lagi (string tidak ditemukan) — tanda file
    sudah tidak sinkron dengan asumsi, seharusnya saat itu juga langsung
    pindah ke overwrite penuh, bukan lanjut mencoba replace lain.
    **Pelajaran: mengetahui aturan tidak cukup — begitu satu
    find_and_replace di file blade gagal, itu HARUS langsung jadi trigger
    pindah ke overwrite penuh untuk SISA perubahan di file itu, bukan
    alasan untuk mencoba variasi replace lain dulu. "Lebih cepat" secara
    subjektif justru menghasilkan lebih banyak putaran gagal dan waktu
    yang hilang. Lihat Aturan Wajib Edit File poin 6 dan 10.**

11. **Penyebab gagal `find_and_replace` di blade bisa sepele: indentasi salah
    baca dari output `Select-String`.** Pada `resources/views/reports/edit.blade.php`
    (23/09/2026), target pencarian disusun dari output `Select-String` yang
    menampilkan baris sudah di-*trim* (spasi awal hilang), sehingga string
    12-spasi tidak ketemu saat file aslinya memakai 8 spasi. Aturan poin 6
    langsung berlaku (pindah ke overwrite penuh), dan menghentikan perubahan di
    file itu adalah keputusan yang benar. **Pelajaran: untuk menyusun target
    `find_and_replace`, JANGAN pakai output `Select-String` (ter-trim) — pakai
    hasil baca file apa adanya, atau cetak ulang barisnya dengan panjang
    karakter untuk memastikan indentasinya.**

12. **Overwrite penuh file blade besar secara bertahap (`insert_line`) rawan
    digeser sambungannya.** Saat menulis ulang `reports/edit.blade.php` (307
    baris) dengan cara menambah potongan per `insert_line`, urutan baris di titik
    sambungan bergeser (baris atribut `class` jatuh setelah blok berikutnya) dan
    muncul baris kosong sisa. **Pelajaran: kalau memang harus overwrite penuh
    file besar, tulis ke file BARU secara berurutan, verifikasi jumlah baris dan
    titik sambungan TIAP potongan dengan `git diff --no-index` sebelum
    menggantikan file asli — kalau prosesnya sudah dua kali meleset, HENTIKAN
    dan laporkan ke user (biar diedit manual) daripada lanjut menebak.**

13. **Diulanginya pelanggaran aturan 7: menulis file `.blade.php` lewat
    PowerShell `(Get-Content -Raw) -replace ... | Set-Content`.** Pada
    `resources/views/it-assets/partials/_location-select.blade.php`
    (23/09/2026), penambahan nilai ke daftar tipe perangkat dilakukan lewat
    PowerShell karena dianggap cepat. Akibatnya: pola pengganti memuat `\\]`
    sehingga hasilnya menyisipkan backslash liar
    (`'ACCESS_DOOR'\], true)`) dan blade itu RUSAK sampai diperbaiki manual
    dengan tool editor. Ini persis pola pelanggaran yang sudah dilarang di
    Aturan Wajib Edit File poin 7. **Pelajaran: larangan menulis isi file
    `.php`/`.blade.php` lewat terminal bersifat MUTLAK — tidak ada pengecualian
    "karena cuma satu pola sederhana". Untuk penambahan nilai pada satu baris,
    pakai tool editor (`find_and_replace` satu baris unik) atau masukkan logika
    ke partial baru yang seluruhnya ditulis dengan tool editor. Selalu jalankan
    `php artisan view:cache` setelah menyentuh blade untuk memastikan tidak ada
    sisa karakter rusak.**

14. **`insert_line` pada file blade berisi `<script>` menyisipkan di TENGAH
    blok script, bukan di akhir file.** Pada
    `resources/views/it-assets/partials/_cctv-channel-slots.blade.php`
    (23/09/2026), nomor baris disusun dari perkiraan (file dianggap sekitar 140
    baris) padahal file sebenarnya 154 baris; akibatnya `<script>` baru masuk di
    tengah IIFE script pertama sehingga JavaScript-nya rusak. Pemulihan dilakukan
    dengan `git checkout HEAD -- <file>` (versi bersih terakhir), lalu penyisipan
    diulang pada baris `jumlah baris + 1` setelah menghitung ulang jumlah baris.
    **Pelajaran: SEBELUM memakai `insert_line`, hitung jumlah baris file saat itu
    (`(Get-Content file).Count`) dan pastikan titik sisip = jumlah baris + 1
    untuk menambah di akhir file. JANGAN mengandalkan perkiraan jumlah baris.
    Setelah menyisipkan, baca ulang sekitar titik sambungan dan periksa pasangan
    `<script>`/`</script>` supaya tetap seimbang.**



15. **AI diam karena dua sebab berbeda yang keduanya tersembunyi (nama model
    invalid + kuota harian tidak pernah direset otomatis).** Pada 24/09/2026,
    gejala "AI tidak jalan" ternyata punya dua akar masalah terpisah:
    (a) `ai_providers.model` berisi `groq/compound` yang TIDAK ADA di akun
    (Groq membalas HTTP 404 "model does not exist") sehingga setiap panggilan
    gagal dan provider ditandai `error`; (b) `tokens_used_today` pada kedua
    provider sudah melewati `daily_token_limit` (114.096/100.000 dan
    100.553/100.000), dan `getBestProvider()` HANYA memilih provider berstatus
    `healthy` dengan sisa kuota — akibatnya AI dilewati sama sekali dan sistem
    diam-diam memakai fallback keyword tanpa pesan apa pun ke pengguna.
    Penghitung harian itu sebelumnya TIDAK punya penjadwal reset (hanya bisa
    direset manual dari panel), jadi sekali terlampaui, AI mati permanen.
    **Pelajaran: (1) pesan "No provider available" di log adalah gejala
    kuota/status, bukan bukti library/framework bermasalah — cek dulu kolom
    `status`, `tokens_used_today`, dan `daily_token_limit` sebelum mendiagnosis
    lebih jauh; (2) nilai yang bisa berubah di sisi vendor (nama model) JANGAN
    pernah dipatok di kode/dokumentasi sebagai teks bebas — ambil dari API
    (`GET /models`, lihat `App\Services\AiModelDiscoveryService`) dan sediakan
    koreksi otomatis saat pemanggilan gagal; (3) setiap penghitung kuota
    (harian/bulanan) WAJIB punya penjadwal reset (`ai:reset-quota` di
    `routes/console.php`), jangan hanya tombol manual.**

16. **Migration ber-SQL mentah MySQL membuat SELURUH suite test tidak bisa
    jalan, dan itu tidak ketahuan karena suite dianggap "sudah pernah hijau".**
    Empat migration memakai sintaks khusus MySQL tanpa penjaga driver:
    `ALTER TABLE ... MODIFY ... ENUM(...)` (2026_08_28_000002,
    2026_09_23_000001, 2026_09_23_000003) dan `information_schema.COLUMNS` +
    `DATE_ADD` (2026_09_02_000001). Karena test memakai SQLite in-memory
    (`phpunit.xml`), `RefreshDatabase` gagal di migration dan SEMUA test
    dilaporkan gagal (bukan hanya test baru). **Pelajaran: setiap SQL mentah
    yang khas MySQL WAJIB dibungkus penjaga driver
    (`if (DB::getDriverName() !== 'mysql') { return; }`, mengikuti pola
    `2026_08_21_000001_expand_report_work_types.php`). Jalankan
    `php artisan test` secara berkala — jangan menganggap suite masih hijau
    hanya karena tidak pernah dijalankan.**



17. **"Deteksi kuota otomatis" TIDAK bisa lewat endpoint — hanya lewat header
    respons; klaim ini dibuktikan dulu sebelum menulis kode.** Pada 24/09/2026
    diuji langsung ke API Groq memakai API key asli: `GET /openai/v1/usage`,
    `/limits`, `/rate_limits`, `/dashboard/usage`, dan `/organizations/usage`
    semuanya **404**. Satu-satunya data kuota nyata ada di **header respons
    chat** (`x-ratelimit-limit-tokens`, `x-ratelimit-remaining-tokens`,
    `x-ratelimit-reset-tokens`, `x-ratelimit-limit-requests`,
    `x-ratelimit-remaining-requests`, `x-ratelimit-reset-requests`) dan HTTP
    **429** (dengan `retry-after`). `GET /models` hanya memberi metadata model
    (`context_window`, `max_completion_tokens`, `pricing`), BUKAN kuota.
    Nilai header itu per MENIT (contoh nyata: 8.000 token/menit, 1.000
    permintaan/menit), bukan per hari — jadi jangan dipakai untuk mengisi
    `daily_token_limit`. **Pelajaran: (1) kuota yang benar diambil dari header
    tiap respons (lihat `App\Services\AiQuotaService`), bukan dari angka tebakan
    di kolom limit aplikasi; (2) kolom `daily_token_limit`/`monthly_token_limit`
    HANYA boleh jadi penjaga tambahan yang opt-in
    (`config('ai.quota.enforce_local_limit')`, bawaan false) — kalau dipakai
    sebagai pemblokir utama, AI akan berhenti diam-diam (lihat insiden #15);
    (3) 429 WAJIB diperlakukan sebagai masa tunggu (cooldown dari `retry-after`),
    bukan status mati sampai direset manual; (4) jangan pernah menyimpulkan
    "API tidak menyediakan X" tanpa memanggil endpoint-nya sendiri dan mencatat
    kode HTTP-nya.**



18. **Mengedit provider AI tanpa mengetik ulang API key MENGHAPUS key yang
    tersimpan (bug senyap lama, ketahuan dari test).** Pada 24/09/2026,
    `AiProviderController::update()` meneruskan `$validated['api_key_encrypted']`
    apa adanya ke `$aiProvider->update()`. Karena form menyuruh "kosongkan jika
    tidak ingin mengganti", request berisi string kosong (menjadi null lewat
    `ConvertEmptyStringsToNull`) sehingga kolom key ditimpa NULL — provider
    langsung kehilangan kredensialnya tanpa pesan apa pun (gejalanya: provider
    berubah jadi `error`/tidak bisa dipakai sesudah diedit). Perbaikannya:
    `if (empty($validated['api_key_encrypted'])) { unset($validated['api_key_encrypted']); }`.
    **Pelajaran: untuk field rahasia bertipe "isi hanya bila ingin mengganti",
    nilai kosong WAJIB dibuang dari array update — jangan pernah diteruskan apa
    adanya ke `update()`. Semua bug ini ditemukan karena test memalsukan
    (fake) respons API lalu memeriksa kolom database, bukan karena membaca kode.**



19. **Menulis `\\n` pada `new_text` menghasilkan teks LITERAL `\n` di file PHP
    (bukan baris baru), dan itu TIDAK terdeteksi oleh test.** Pada 24/09/2026,
    penulisan `App\Services\Telegram\AssetInfoService.php` memakai `\\n` di dalam
    string PHP; file yang tersimpan berisi `\\n` (dua backslash) sehingga pesan
    Telegram yang dikirim berisi tulisan "\n" sebagai teks, bukan baris baru.
    Semua test unit/feature tetap HIJAU karena hanya memeriksa `toContain()`
    penggalan kalimat, dan `php -l` juga lolos. Yang menemukan: **uji nyata
    memakai data produksi** (memanggil service lalu mencetak pesan apa adanya).
    **Pelajaran: (1) di tool editor, tulis `\n` (satu backslash) untuk baris baru
    di dalam string PHP — `\\n` akan tersimpan sebagai teks literal; (2) setelah
    menulis pesan/teks dinamis, WAJIB jalankan uji cetak nyata (bukan hanya test
    yang mengecek `toContain`) supaya karakter escape yang salah ketahuan;
    (3) periksa dengan mencetak baris lalu mengganti `\` menjadi penanda
    (`(Get-Content file)[n].Replace('\','<BS>')`) untuk memastikan jumlah backslash.**

20. **Kode fallback pada pengelompokan harus dipakai JUGA pada penyaringan, jika
    tidak, hasil filter selalu kosong.** `FuncLocParser::groupBySectionAndType()`
    memakai penanda `'0000'` (section tidak terurai) dan `'?'` (tipe tidak
    terurai). Versi pertama `AssetInfoService` mengelompokkan dengan penanda itu
    tetapi menyaring dengan `extractSectionCode() === $section` (nilai null),
    sehingga tombol "Section 0000" selalu membuka daftar KOSONG — padahal 431
    alat terdaftar di sana. **Pelajaran: setiap nilai fallback ('0000', '?')
    wajib diterapkan identik di kedua sisi (pengelompokan dan penyaringan), dan
    tombol yang menampilkan jumlah besar (431 alat) harus diuji benar-benar
    membuka daftar alat, bukan hanya diuji lewat unit test.**



21. **Restart bot Telegram sempat meninggalkan DUA proses polling sekaligus (HTTP 409).**
    Pada 26/09/2026, saat menjalankan ulang bot setelah perubahan kode wizard:
    file `storage/app/telegram_poll.stop` dibuat dan **memang terhapus dalam 5 detik**
    sehingga terlihat seolah loop lama sudah berhenti — TETAPI proses lama
    (`php artisan telegram:poll`) masih hidup. Proses baru yang dijalankan
    berikutnya bertabrakan dengannya, dan log produksi berulang kali memuat
    `409 Conflict: terminated by other getUpdates request; make sure that only one
    bot instance is running` — artinya bot justru tidak melayani update sama sekali
    walau terlihat "ada proses yang jalan".
    Perbaikan: matikan semua proses dengan `Stop-Process` berdasarkan daftar
    `Get-CimInstance Win32_Process -Filter "Name='php.exe'"` yang command line-nya
    memuat `telegram:poll`, pastikan **tidak ada sisa** sebelum menyalakan satu
    proses baru, lalu verifikasi (a) jumlah proses polling tepat 1, (b) log
    `storage/logs/telegram-poll.out.log` TIDAK memuat `Conflict` setelah ~60 detik,
    (c) `storage/app/telegram_offset.txt` bergerak naik.
    **Pelajaran: sinyal stop yang "terkonsumsi" BUKAN bukti proses berhenti —
    verifikasi lewat daftar proses (PID/command line), bukan lewat ada/tidaknya
    file penanda. Jangan pernah menyalakan poller baru sebelum daftar proses
    dipastikan bersih, karena dua poller = bot mati total (409) tanpa pesan error
    ke pengguna.**

22. **Mode picker baru ("multi") hanya diuji di level terdalam (Section), sehingga
    di level Area/Sub Area picker malah MENGUNCI lokasi utama laporan.** Pada
    26/09/2026, laporan RPT-20260926-0020 tidak bisa menambah alat/lokasi
    kolaborasi: begitu teknisi memilih Area/Sub Area pada "Pilih Hirarki", picker
    menjalankan jalur `lockFuncLocAndAdvance()` (perilaku mode 'area') sehingga
    lokasi itu dikunci sebagai lokasi utama laporan, bukan ditambahkan sebagai
    item pekerjaan. Penyebab: `buildFuncLocScreen()` menggantungkan daftar
    equipment pada `$mode === 'equipment'` dan pada cabang "tidak ada anak/tidak
    ada equipment" langsung mengunci lokasi; mode 'multi' tidak masuk ke
    keduanya, dan `handleFuncLocPick()` juga hanya mengecualikan 'equipment' dari
    penguncian di level Section. Perbaikan: mode 'multi' diperlakukan sama seperti
    'equipment' (pakai `in_array($mode, ['equipment', 'multi'], true)`) untuk
    daftar equipment, cabang otomatis memanggil `selesaikanPickerMulti()` alih-alih
    mengunci, dan pemilihan alat (`funcloc_asset_pick`) menambah item bukan
    mengganti alat utama. **Pelajaran: (1) setiap mode baru pada picker WAJIB diuji
    di SEMUA level hirarki (PT/Departemen/Area/Section), bukan hanya level
    terdalam yang dipakai saat menulis fitur; (2) jangan mengandalkan
    `$mode === 'equipment'` sebagai satu-satunya penanda "tampilkan equipment" —
    mode yang berperilaku serupa harus dikelompokkan eksplisit; (3) cabang
    "auto-lock" pada picker adalah tempat paling rawan: pastikan cabang itu tidak
    pernah dijalankan oleh mode yang niatnya hanya "menambah item".**



23. **Rentang tanggal pada kolom `date` WAJIB memakai batas jam, jika tidak hari
    terakhir rentang selalu hilang tanpa error apa pun.** Pada 26/09/2026,
    perhitungan man hour memakai
    `whereBetween('report_date', ['2026-09-26', '2026-09-26'])`. Di MySQL
    (kolom DATE) itu benar, tetapi di SQLite (dipakai test) Eloquent menyimpan
    cast `date` sebagai `2026-09-26 00:00:00`, sehingga baris pada hari
    terakhir rentang GAGAL cocok (`'2026-09-26 00:00:00' > '2026-09-26'`).
    Akibatnya produksi akan selalu melaporkan "hari ini 0 menit" dan catatan
    anomali hari terakhir hilang — ketahuan dari test man hour, bukan dari
    halaman web. Perbaikan: batas rentang memakai jam
    (`[$mulai . ' 00:00:00', $selesai . ' 23:59:59']`, lihat
    `TechnicianShift::scopeAntara()` dan `App\Services\Shift\ManHourService`).
    **Pelajaran: setiap filter rentang pada kolom tanggal WAJIB memakai batas
    jam 00:00:00/23:59:59 (atau `whereDate()`) supaya perilakunya sama di MySQL
    dan SQLite; jangan pernah memakai `whereBetween` dengan tanggal polos.**


24. **Menentukan "pekerja shift" dengan pola `GROUPS kecuali reguler` membuat
    Reguler Staff ikut dianggap shift.** Pada 26/09/2026 ditambahkan group
    `reguler_staff`, tetapi `ReportController` masih memakai
    `collect(Technician::GROUPS)->except(['reguler'])` untuk dua hal: isi
    tampilan "Shift" dan pilihan Grub. Akibatnya laporan teknisi Reguler Staff
    muncul di tampilan **Shift** dan hilang dari tampilan **Reguler**, padahal
    Reguler Staff bukan pekerja shift. Perbaikan: konstanta eksplisit
    `Technician::GRUP_SHIFT` dan `Technician::GRUP_NON_SHIFT` (+ helper
    `grupShift()`) dipakai di filter tampilan, dan tampilan Reguler memuat kedua
    group non-shift. **Pelajaran: daftar "semua kecuali X" harus ditulis
    eksplisit; setiap kali menambah anggota baru pada sekumpulan nilai
    (group/status/kategori), cari dulu semua tempat yang memakai pola
    "kecuali X"/"selain X" dan ujilah — penambahan anggota baru mengubah arti
    daftar itu tanpa error apa pun.**


25. **Test penolakan 403 pada route ber-parameter bisa salah dibaca sebagai bug
    middleware, padahal route model binding yang jalan lebih dulu.** Pada
    28/09/2026, test "akun hanya baca" memakai `DELETE /assets/1` dan gagal
    dengan 404 (bukan 403). Penyebabnya: `SubstituteBindings` (bagian grup
    `web`) menyelesaikan `{asset}` SEBELUM middleware route berjalan, sehingga
    record yang tidak ada menghasilkan 404 lebih dulu. **Pelajaran: untuk
    membuktikan penolakan 403, pakai route tulis tanpa parameter model
    (mis. `POST /assets`, `POST /technicians`) atau buat dulu record-nya;
    jangan memakai id yang tidak ada.**


26. **Clone baru tidak punya folder `storage/framework/views`, dan itu membuat
    `config:cache` menyimpan path view KOSONG.** Pada 28/09/2026, copy produksi
    hasil `git clone` langsung gagal `HTTP 500` dengan pesan "Please provide a
    valid cache path". Dua sebab berantai: (a) repo tidak pernah menyimpan
    `storage/framework/views/.gitignore` sehingga folder itu tidak ada di clone;
    (b) `config/view.php` memakai
    `realpath(storage_path('framework/views'))` yang mengembalikan `false` saat
    folder belum ada, jadi `config:cache` menyimpan path kosong dan `view:cache`
    menolak dengan "View path not found". Perbaikan: `storage/framework/views/.gitignore`
    ditambahkan ke repo, dan `tools/deploy-prod.ps1` membuat folder storage wajib
    SEBELUM `config:cache`. **Pelajaran: kalau folder/data wajib belum ada saat
    `config:cache` dijalankan, gejalanya muncul belakangan di tempat lain (di
    sini: view compiler) — buat folder wajib dulu, baru cache config.**


27. **PATH sesi terminal yang usang membuat instalasi yang BERHASIL disimpulkan
    gagal.** Pada 28/09/2026, `winget install --id Cloudflare.cloudflared`
    dijalankan dan setelahnya `Get-Command cloudflared` menjawab "tidak ada",
    sehingga disimpulkan winget gagal dan binernya diunduh manual ke
    `C:\laragon\bin\cloudflared\`. Ternyata winget SUDAH berhasil memasangnya di
    `C:\Program Files (x86)\cloudflared\cloudflared.exe` (dengan folder itu di
    PATH MESIN) — yang usang hanya PATH proses terminal yang dipakai memeriksa,
    karena sesi itu dimulai sebelum instalasi. Ketahuan setelah memeriksa
    `where cloudflared` dengan PATH yang disusun ulang dari registry. **Pelajaran:
    (1) hasil "perintah tidak ditemukan" setelah instalasi BUKAN bukti paket
    gagal — cek dulu folder instalasi, `winget list`, dan PATH mesin
    (`[Environment]::GetEnvironmentVariable('Path','Machine')`); (2) pakai
    `where.exe` dengan PATH yang dibangun ulang dari registry untuk memeriksa
    perintah baru dalam sesi yang sama.**


28. **Jaring pengaman yang menggabungkan ulang data otomatis saat menyimpan
    membatalkan penghapusan manual pengguna.** Pada 30/09/2026 dilaporkan dari
    produksi: teknisi menghapus kolaborator yang dicantumkan otomatis (menu
    "Sesuaikan" pada Step 8a wizard Telegram) lalu menambahkan orang lain;
    hasil akhir laporan tetap memuat nama yang sudah dihapus, sementara nama
    penggantinya masuk. Penyebab: `WizardReportSaverTrait::saveReport()`
    memanggil `CollaborationService::gabungKolaborator()` sekali lagi tepat
    sebelum membuat salinan kolaborasi (jaring pengaman agar rekan kelompok
    ikut andil walau state belum terisi), dan penggabungan itu membaca ulang
    anggota kelompok dari database (`autoCollaboratorsFor()`) tanpa
    memperhatikan nama yang sudah dihapus teknisi sehingga nama itu ditambahkan
    kembali. Bug ini lolos pengujian karena test lama hanya memeriksa state dan
    prompt wizard ("nama tidak muncul lagi di prompt"), bukan laporan yang
    benar-benar tersimpan. Perbaikan: state wizard menyimpan daftar
    `collaborator_dikecualikan` (diisi `hapusKolaboratorIndex()` dan tombol
    "Lewati"), dan `gabungKolaborator()` menerima parameter `$dikecualikan`
    sehingga rekan kelompok yang sudah dihapus tidak dimasukkan kembali.
    **Pelajaran: (1) setiap proses yang menggabungkan ulang data otomatis saat
    menyimpan (safety net) WAJIB menghormati keputusan manual pengguna yang
    sudah tercatat di state, jangan menimpanya dengan data dari database;
    (2) test fitur penghapusan WAJIB memeriksa HASIL AKHIR yang tersimpan
    (baris database), bukan hanya tampilan/prompt, karena penggabungan ulang
    terjadi di lapisan paling akhir; (3) pastikan test baru memang menangkap
    bug dengan uji mutasi: netralkan perbaikannya, test harus GAGAL (di sini
    tepat 2 test baru gagal saat parameter pengecualian dikosongkan).**


29. **`.bat` menutup seketika saat diklik: tanda kurung di dalam baris `echo`
    pada blok `if (...)` memutus parsing `cmd.exe`.** Pada 30/09/2026 pintasan
    baru `C:\Users\sapusr\Desktop\Pasang-Token-Tunnel.bat` dilaporkan user
    "jendela CLI langsung menutup". Uji ulang dengan
    `cmd.exe /c "\"<berkas>.bat\" < NUL > %TEMP%\out.txt 2>&1"` menghasilkan
    `. was unexpected at this time.` dan exit 255 TANPA teks apa pun, padahal
    berkas yang diperiksa ada sehingga semestinya hanya mencetak pesan.
    Penyebabnya satu baris `echo Copy PROD mungkin dipindah atau belum siap
    (jalankan tools\deploy-prod.ps1).` di dalam blok `if not exist "%X%" (`.
    Tanda kurung tutup di dalam `echo` ditafsirkan sebagai penutup blok,
    sehingga cmd menemukan sisa token dan MENGAKHIRI batch sebelum `pause`
    → jendela menutup seketika. Perbaikan: struktur `goto`/label (tanpa blok)
    dan tidak ada tanda kurung pada baris `echo`; tanda `>` pada baris `REM`
    juga dihindari karena redirection tetap diproses walau di dalam REM.
    **Pelajaran: (1) di `.bat`, jangan memakai tanda kurung di dalam `echo`
    yang berada di blok `if`/`for`, pakai `goto`/label; (2) cara tercepat
    mendiagnosis `.bat` yang menutup seketika adalah menjalankannya dengan
    stdin NUL dan keluaran dialihkan ke berkas lalu baca berkas itu — JANGAN
    lewat pipe; (3) bedakan dua kasus yang gejalanya mirip: batch yang gagal
    parse (selesai instan tanpa teks) versus skrip yang menunggu masukan
    manusia (mis. `Read-Host` pada `pasang-token-tunnel-prod.ps1`) — yang
    terakhir memang akan tampak menggantung dan TIDAK bisa diuji otomatis.**

30. **Token tunnel hasil tempel di jendela hitam bisa TERPOTONG, dan gejalanya
    menyesatkan.** Pada 30/09/2026 pengguna menjalankan pintasan
    `Pasang-Token-Tunnel.bat`; skrip menolak dengan pesan
    `Exception calling "FromBase64String" with "1" argument(s): "The input is
    not a valid Base-64 string ..."`. Uji terisolasi membuktikan pesan itu
    muncul justru saat panjang teks bersisa **1 mod 4**: baris
    `while ($pad.Length % 4 -ne 0) { $pad += '=' }` menambah TIGA `=`
    sehingga .NET menolak dengan "more than two padding characters" — jadi
    artinya salinan token kurang atau lebih satu karakter, BUKAN masalah
    library, token, atau dashboard. Perbaikan di
    `tools\pasang-token-tunnel-prod.ps1`: token dibersihkan dulu
    (`RapikanToken`: buang spasi/BOM/NUL/kutip, buang awalan
    `cloudflared service install`/`--token`), isian diulang sampai 3 kali
    dengan laporan panjang + kode karakter aneh, base64 url-safe ikut dicoba,
    dan ada jalur BERKAS: `C:\laragon\cloudflared\token-baru.txt` dibaca lebih
    dulu, kalau belum ada Notepad dibuka otomatis (opsi `-TanpaNotepad` untuk
    pengujian otomatis). **Pelajaran: (1) periksa dulu `panjang % 4` pada
    token base64 sebelum menuduh kunci/izin salah — sisa 1 mod 4 hampir
    selalu berarti salinan terpotong; (2) simpan pesan asli decoder TAPI
    tambahkan ukuran dasar (panjang, sisa bagi 4, kode karakter aneh) supaya
    bisa didiagnosis tanpa membocorkan token; (3) untuk rahasia panjang,
    sediakan jalur berkas sebagai alternatif tempel di TTY — isian panjang
    pada `Read-Host` tidak bisa diuji otomatis (butuh manusia) dan sudah
    terbukti pernah menghasilkan isian tidak utuh.**

31. **Empat jebakan PowerShell saat satu skrip memanggil skrip lain (ketahuan
    01/10/2026 waktu membuat `tools\deploy-laporan-prod.ps1`).** Semuanya
    menghasilkan gejala menyesatkan dan SUDAH dibuktikan dengan uji terisolasi,
    bukan dugaan:
    (a) **`,` berikatan lebih kuat daripada `+` di dalam array literal.**
    `@('--host=' + $x, '--port=' + $y)` menghasilkan SATU elemen berbunyi
    `--host=... --port=...`, sehingga `mysqldump` menerima satu argumen panjang
    dan menolak dengan `Usage: mysqldump [OPTIONS] database [tables]` — backup
    gagal tanpa sebab yang jelas. Kurung TIAP elemen:
    `@(('--host=' + $x), ('--port=' + $y))`.
    (b) **`Start-Process -Wait` menunggu SELURUH pohon proses.** Dipakai untuk
    memanggil `jaga-service-prod.ps1` (yang memang menyalakan service berjalan
    terus) → skrip deploy menggantung tanpa akhir walau semua service sudah
    jalan. Pakai `$proses.WaitForExit()` yang menunggu proses anak saja.
    (c) **`Start-Process -NoNewWindow -PassThru` mengembalikan `ExitCode`
    KOSONG di komputer ini** (Start-Process tidak memegang handle proses),
    sehingga `$kode -ne 0` selalu dianggap gagal — pesannya
    `DEPLOY GAGAL (exit code )` padahal deploy sukses. Jalur yang terbukti
    benar: .NET `System.Diagnostics.Process` + `ProcessStartInfo` dengan
    `UseShellExecute = $false` (uji terisolasi: `Start-Process` = kosong,
    .NET = 3).
    (d) **Operator regex dengan `$null` di kiri mengembalikan `$null`, BUKAN
    `$True`/`$False`.** Blok konfirmasi sempat memakai
    `if ($jawab -notmatch '^(y|ya)$')`; ketika `Read-Host` menerima akhir
    masukan (stdin diarahkan dari NUL) jawabannya `$null`, `$null -notmatch ...`
    mengembalikan `$null`, dan `if ($null)` dianggap SALAH sehingga pembatalan
    TIDAK jalan — pintasan Desktop justru lanjut deploy tanpa konfirmasi
    (terbukti saat mengujinya dengan `< NUL`). Perbaikan: normalkan dulu
    (`$jawaban = ''`, diisi hanya bila `$null -ne $jawab`) lalu uji eksplisit
    `$jawaban -ne 'y' -and $jawaban -ne 'ya'`. Catatan: operator perbandingan
    biasa berperilaku sebaliknya — `$null -ne 0` bernilai `$True`, itulah sebab
    exit code kosong sempat dilaporkan "DEPLOY GAGAL".
    Ditambah satu jebakan lama yang tetap berlaku: **keluaran skrip anak TIDAK
    boleh dipipe** (`& powershell.exe ... | ...`) karena proses service yang
    dinyalakan skrip anak ikut mewarisi handle pipe sehingga perintah
    menggantung selamanya; lewat .NET Process tanpa redirect, keluaran anak
    mewarisi konsol induk (terlihat langsung) dan exit code tetap terbaca.
    **Pelajaran: setiap skrip yang memanggil skrip lain WAJIB (1) mengurung
    ekspresi `+` di dalam array literal, (2) tidak memakai `Start-Process -Wait`
    untuk skrip yang menyalakan service, (3) mengambil exit code lewat .NET
    Process, (4) tidak memipe keluaran skrip anak, dan (5) membandingkan nilai
    yang bisa kosong secara eksplisit (jangan mengandalkan operator regex
    terhadap `$null`). Uji dengan menjalankan
    alurnya sungguhan lalu membaca berkas keluaran — `ParseFile` yang bersih
    (sintaks OK) TIDAK menjamin skrip jalan.**





32. **Build aset gagal dengan `'"node"' is not recognized` walau npm berjalan,
    karena folder node Laragon tidak ada di PATH mesin/User (01/10/2026).**
    Saat `Deploy-Laporan.bat` diklik dari pintasan Desktop, langkah
    `npm run build` di PROD gagal sehingga seluruh deploy dilaporkan
    SELESAI DENGAN GALAT, padahal commit, migrasi, `config:cache`, dan
    `view:cache` sudah berhasil. Penyebab: `tools\deploy-prod.ps1` memanggil
    npm lewat **path absolut** (`C:\laragon\bin\nodejs\node-v22\npm.cmd`)
    sehingga npm sendiri jalan, tetapi shim `node_modules\.bin\vite.cmd`
    memanggil `node` **dari PATH**. Folder `C:\laragon\bin\nodejs\node-v22`
    ternyata TIDAK ada di PATH mesin maupun User (diperiksa langsung dari
    registry) — hanya disuntik per sesi oleh Laragon/VS Code, sehingga
    terminal agent bisa memanggil `node` sedangkan cmd yang diklik dari
    Desktop tidak. Perbaikan: `$NodeDir` disisipkan sendiri ke `$env:Path`
    (proses saja, dikembalikan di `finally`) sebelum `npm run build`,
    `node.exe` + `npm.cmd` ikut divalidasi di awal skrip, dan keberadaan
    `node_modules\.bin\vite.cmd` di PROD diperiksa lebih dulu.
    **Pelajaran: (1) memanggil npm dengan path absolut TIDAK cukup — shim di
    `node_modules\.bin` selalu mencari `node` di PATH, jadi folder node WAJIB
    disisipkan dulu sebelum build; (2) "deploy gagal" di baris terakhir belum
    tentu berarti aplikasi rusak: periksa LANGKAH mana yang gagal dan apakah
    langkah itu memang diperlukan — di sini seluruh kelas Tailwind yang dipakai
    partial baru terbukti sudah ada di `public\build\assets\app-*.css` PROD
    (198 kelas diperiksa satu per satu), jadi tampilan tidak terpengaruh;
    (3) PATH sesi agent/IDE bisa berbeda dari PATH yang dilihat pintasan
    Desktop, jadi "perintah jalan di terminal saya" BUKAN bukti skrip akan
    jalan saat diklik manusia (bandingkan insiden #27).**

<!-- Tambahkan insiden baru di bawah ini, nomor urut lanjut -->

33. **Alat lain di LOKASI YANG SAMA dibuang diam-diam saat menambah alat kolaborasi (Step 8b), dan
    hirarki hilang setiap kali satu alat ditambahkan (01/10/2026).** Keluhan pengguna: "kolaborasi
    alat/area kadang tidak bisa, dan memilih alat harus menelusuri hirarki ulang sehingga lama".
    Setelah diuji dengan data asli DEV (4.252 alat, 130 node FuncLoc) memakai skrip sementara yang
    memanggil `ReportWizardService` langsung (tanpa kirim pesan Telegram), ditemukan EMPAT sebab:
    (a) **`tambahMultiItem()` membandingkan `funcloc_id` item dengan `state['funcloc_id']`.** Karena
    `lockEquipmentAndAdvance()` -> `LocationResolver::lengkapiDariAset()` MENGISI `state['funcloc_id']`
    dengan lokasi alat utama, maka setiap alat LAIN di lokasi yang sama (kasus paling umum: dua pompa
    pada satu section) dianggap "sama dengan alat utama" dan dibuang tanpa pesan. Sebelumnya pesannya
    tetap mengucapkan "Ditambahkan dari hirarki" sehingga teknisi yakin berhasil. Perbaikan:
    `multiItemSamaUtama()` membandingkan lewat `asset_id` saja untuk item ber-alat, dan lewat
    `funcloc_id` hanya untuk item lokasi (tanpa `asset_id`).
    (b) **`selesaikanPickerMulti()` selalu menutup hirarki** (membuang `funcloc_picker_mode` +
    `funcloc_path`), jadi menambah N alat = menelusuri PT -> Departemen -> Area -> Section N kali.
    Perbaikan: picker dibiarkan aktif dan layar digambar ulang (`renderLayarMulti()`), layar yang harus
    digambar diingat di state `multi_layar`, plus tombol `Selesai Menambah (n)` dan `Hapus Terakhir (n)`
    di setiap layar hirarki; daftar item ditampilkan di bawah pesan tiap layar.
    (c) **Mengetik kode ALAT selagi hirarki aktif selalu gagal**: seluruh teks dirutekan ke
    `handleFuncLocTextSearch()` yang hanya mencari `FunctionalLocation`, dan jawabannya hanya
    menyisakan tombol "Batalkan Laporan" (tombol hirarki sudah terhapus dari chat) sehingga teknisi
    nyangkut. Perbaikan: `cobaTambahDariKetik()` menambahkan kode alat langsung (atau menawarkan
    kandidat), dan layar "tidak ditemukan" menyediakan tombol `Kembali ke Layar Pilih`
    (`funcloc_kembali`).
    (d) Node tanpa anak & tanpa alat yang layarnya DIGAMBAR ULANG bisa menambah item berulang tanpa
    perintah teknisi; penyelesaian otomatis kini dimatikan saat penggambaran ulang
    (`buildFuncLocScreen(..., $selesaikanOtomatis: false)`).
    Label layar akar mode multi juga tidak lagi menulis "*Step 2*" (menyesatkan di Step 8b).
    Uji: 17 test di `tests/Feature/ReportMultiEquipmentTest.php` (termasuk uji mutasi: menetralkan
    perbaikan membuat 2-6 test GAGAL), dan seluruh suite 265 test hijau.
    **Pelajaran: (1) nilai fallback/penanda yang dipakai untuk membandingkan identitas WAJIB dibedakan
    per JENIS item (alat vs lokasi) — membandingkan kolom lokasi untuk item alat membuat seluruh alat
    satu lokasi dianggap duplikat, dan kegagalan itu SENYAP karena pesan sukses tetap muncul; (2) fitur
    yang "kadang tidak bisa" wajib diuji dengan DATA ASLI, karena data uji yang mengosongkan kolom
    (di sini `funcloc_id` pada fixture lama) menyembunyikan bug selama berbulan-bulan; (3) jangan pernah
    menutup/menghapus layar pilihan sebelum pengguna memutuskan selesai — bila layar lama terhapus dari
    chat dan tidak ada tombol kembali, satu-satunya jalan keluar adalah membatalkan laporan.**

34. **Tanda plus BERLEBIH di rangkaian penggabungan teks JavaScript menghasilkan
    `NaN` di tampilan (06/10/2026).** Keluhan user: "pada halaman panel seharusnya
    bukan NaN namun drajat celcius seperti pada detail". Kartu suhu dashboard
    `/panel` mencetak `24.4NaN` (satuan " C" hilang), sedangkan halaman detail
    ruangan mencetak `24.4 C`. Penyebab: satu baris penyusun kartu
    (`resources/views/panel/index.blade.php` baris 453 sebelum diperbaiki) berbunyi
    `+          + '<span class="text-sm font-semibold text-slate-400"> C</span>'` —
    tanda plus ganda: operator gabung, lalu unary plus. Unary plus memaksa teks
    span dikonversi ke angka menjadi `NaN`, sehingga `"24.4" + NaN` = `24.4NaN`.
    Seluruh pemeriksaan statis GAGAL menemukannya: kolom `suhu` decimal(5,2), cast
    `float` pada `PanelPembacaan`, payload `/panel/data` bernilai angka (dibuktikan
    lewat `curl` + `ConvertFrom-Json` = Decimal 24.5), berkas DEV dan PROD identik,
    dan blok `<script>` lolos `node --check`. Yang menemukan: RENDER NYATA memakai
    Chrome headless (`chrome.exe --headless=new --dump-dom` dengan profil
    `--user-data-dir` yang sama setelah membuka `/debug-login`) →
    `<p class="text-3xl ...">24.5NaN      </p>` tanpa span. Sesudah perbaikan:
    `24.4<span ...> C</span>` dan tidak ada `NaN`. **Pelajaran: (1) `NaN` pada
    tampilan hampir selalu berasal dari operasi aritmetika atas teks (unary plus,
    `-`, `*`, `Number()`), bukan dari data server — cari operator ganda di
    rangkaian penggabungan string; (2) `node --check` hanya membuktikan SINTAKS,
    bukan nilai hasil, jadi bug seperti ini lolos; (3) untuk bug tampilan yang
    tidak bisa disimpulkan dari data, WAJIB buktikan dengan render browser nyata
    (headless `--dump-dom`) sebelum menyatakan sebabnya; (4) pencarian `NaN` pada
    HTML hasil dump wajib PEKA huruf besar-kecil — kata "Maintenance" memuat
    "nan".** Uji penjaga: 2 test di `tests/Feature/PanelDashboardRingkasTest.php`
    (menolak pola baris `^\s*\+\s*\+` dan memastikan satuan C tetap ada); uji
    mutasi: tanda plus ganda dikembalikan → KEDUA test GAGAL.

35. **Satu laporan dari form web membuat SELURUH tombol Done/Continue wizard
    Telegram gagal menyimpan (07/10/2026).** Keluhan user: "report telegram di
    produksi tidak bisa menyelesaikan report; tekan Done/Continue tidak
    merespon". Semua service PROD memang jalan (queue:work, schedule:work,
    telegram:poll, serve:8000, tunnel) dan log poller justru memuat
    `Callback: wizard:confirm:save_done` berulang kali — jadi tombolnya
    DIKIRIM, bukan tidak sampai. Akar masalahnya di
    `PROD\storage\logs\laravel.log`: `production.ERROR: WizardService: Failed
    to save report for chat ... {"error":"SQLSTATE[23000] ... 1062 Duplicate
    entry 'RPT-20261007-0001' for key 'reports.report_code'"}`.
    Penyebabnya DUA generator kode laporan hidup berdampingan:
    `Report::generateReportCode()` (dipakai form web + `ReportCopyService`)
    menghasilkan akhiran ACAK lewat `Str::random(4)`, sedangkan
    `WizardReportSaverTrait::generateReportCode()` mengambil kode TERBESAR
    hari itu (`orderByDesc('report_code')`) lalu menghitung
    `(int) substr($kode, -4) + 1`. Begitu satu laporan dari form web dibuat
    (`RPT-20261007-Q5UW`, id 1676, 10:30), kode itu menjadi nilai terbesar hari
    itu (huruf diurutkan setelah angka) sehingga `(int) 'Q5UW'` = 0 dan kode
    berikutnya selalu `0001` yang sudah terpakai → semua penyimpanan wizard
    gagal 1062 sejak 12:50 (20 kegagalan tercatat sampai 13:07) dan teknisi
    hanya melihat pesan galat.
    Perbaikan di DEV (satu generator): `Report::generateReportCode()` kini
    SATU-SATUNYA generator nomor urut harian dan MENGABAIKAN akhiran bukan
    angka (kode acak tidak lagi meracuni urutan); `WizardReportSaverTrait::
    generateReportCode()` hanya mendelegasikan ke sana; semua insert laporan
    (induk, salinan kolaborasi, salinan multi alat) memakai
    `WizardReportSaverTrait::buatLaporanKodeUnik()` yang mencoba ulang bila
    kode bentrok (dua teknisi menyimpan bersamaan); pesan gagal simpan tidak
    lagi memuat SQL mentah (detailnya tetap ada di log). Tidak perlu mengubah
    DATA PROD: setelah perbaikan ini kode acak hari itu diabaikan sehingga
    nomor urut lanjut di 0020.
    Uji: `tests/Feature/ReportKodeUnikTest.php` (6 test, 18 assertions; uji
    mutasi 07/10/2026: mengembalikan logika lama `orderByDesc` + `substr`
    membuat 4 test GAGAL). Seluruh suite 446 test hijau (1955 assertions).
    **Pelajaran: (1) generator kode yang dipakai bersama WAJIB satu saja —
    format berbeda (angka vs acak) membuat satu jalur merusak jalur lain;
    (2) nilai "terbesar" pada kolom kode berbasis TEKS tidak boleh dipakai apa
    adanya untuk urutan numerik: kode berhuruf selalu menang dan cast `(int)`
    atas huruf = 0; (3) gejala "tombol tidak merespon" pada Telegram hampir
    selalu berarti handler-nya melempar galat — periksa `laravel.log` PROD
    lebih dulu, jangan hanya melihat daftar proses/service; (4) pesan galat ke
    pengguna jangan memuat pesan SQL mentah, simpan detailnya di log saja.**

36. **Blok `google_sheets` di `config/cm.php` HILANG sehingga Sync Google
    Sheets selalu berkata "URL belum dikonfigurasi di .env" padahal .env sudah
    terisi (08/10/2026).** Keluhan user: "tidak bisa sync data CM". Pesan yang
    tampil di halaman Overview CM (`CmController::syncGoogleSheets`, baris
    745-752) berbunyi "Sync Google Sheets: Data CM: URL belum dikonfigurasi di
    .env | Finding CM: URL belum dikonfigurasi di .env". Penyebabnya: di
    `C:\laragon\www\oleochemicalReport\config\cm.php` hanya tinggal JUDUL
    KOMENTAR "Google Sheets — Sumber Data Sync" (baris 67-77), sedangkan array
    `'google_sheets' => ['data_cm_url' => env('GOOGLE_SHEETS_DATA_CM_URL'),
    'finding_cm_url' => env('GOOGLE_SHEETS_FINDING_CM_URL')]` sudah TIDAK ADA,
    jadi `config('cm.google_sheets.data_cm_url')` bernilai null. Kedua `.env`
    (DEV dan PROD, baris 69-70) SEJAK AWAL sudah berisi URL yang benar.
    Perbaikan 08/10/2026 (DEV): blok itu dikembalikan ke `config/cm.php`, dan
    pesan dilewati kini menyebut nama kunci env-nya
    (`'URL belum dikonfigurasi di .env (GOOGLE_SHEETS_DATA_CM_URL)'`, lewat
    `SyncGoogleSheetsJob::$sources['env_key']`).
    Audit yang dijalankan (skrip sementara, sudah dihapus): 18 kunci
    `config('cm.*')` yang dipakai kode diperiksa, hanya kunci itu yang hilang.
    Satu temuan lain `cm.temperature_high_threshold` memang tidak pernah
    didefinisikan tetapi AMAN karena kodenya memakai nilai cadangan
    (`config('cm.temperature_high_threshold', 80)`,
    `app/Http/Controllers/CmController.php:353`).
    Bukti unduh (uji dry-run tanpa menulis database, 08/10/2026): Data CM HTTP
    200 (1.223.704 byte, sheet "Data AppSheet" + "Status CM" lengkap), Finding
    CM HTTP 200 (6.794.360 byte, sheet "Finding CM" lengkap).
    Uji: `tests/Feature/CmGoogleSheetsConfigTest.php` (3 test, 12 assertions;
    uji mutasi 08/10/2026: mengganti nama kunci `google_sheets` membuat test
    "config cm memuat kunci google sheets..." GAGAL — 1 failed, 2 passed).
    Seluruh suite 449 test hijau (1967 assertions).
    **Pelajaran: (1) pesan galat yang menyuruh "isi .env" belum tentu benar —
    periksa dulu apakah kunci `config()`-nya memang ada di folder `config/`,
    karena `config('x.y')` yang tidak terdefinisi mengembalikan null tanpa
    error apa pun (blok komentar yang tertinggal adalah jejaknya);
    (2) audit "kunci config dipakai vs terdefinisi" murah dilakukan: regex
    `config\('cm\.` atas `app/`, `resources/`, `tests/` lalu bandingkan dengan
    `Config::has()` — jalankan setiap habis menyunting berkas di `config/`;
    (3) `.env` boleh memakai kutip dan spasi setelah tanda `=`
    (`KEY= "https://..."`) — terbukti dibaca utuh oleh phpdotenv, jadi jangan
    buru-buru menyalahkan format `.env`; (4) untuk integrasi luar, uji unduhnya
    dulu lewat skrip sementara tanpa menulis database supaya kegagalan
    izin/berkas privat ketahuan sebelum menyentuh data.**
37. **502 Bad Gateway di domain publik BUKAN gangguan aplikasi, melainkan
    connector cloudflared yang menunggu QUIC gagal ~2 menit sesudah restart
    service (08/10/2026).** Keluhan user: `https://oleomtc.com` berbalas
    Cloudflare 502 (Error code 502) pukul 02:52:17 UTC. Pemeriksaan langsung
    saat itu membuktikan SEMUA lapisan lokal sehat: Apache PROD
    (`curl.exe -H 'Host: oleo-prod.lvh.me' http://127.0.0.1/login`) = 200,
    `artisan serve` port 8000 = 200, dan domain publik = 200 (sudah pulih
    sendiri). Berkas `PROD\storage\logs\jaga-service.log` memuat
    `[09:51:29] manual: php (queue/schedule/poll/serve): 5 proses dihentikan`
    dan `[09:51:29] manual: tunnel cloudflared: 1 proses dihentikan` (kata
    "manual:" HANYA ditulis oleh `tools\hentikan-service-prod.ps1` copy DEV,
    jadi itu jejak `stop-report.bat` yang dijalankan manusia), lalu
    `[09:51:42] tunnel cloudflared (mode token): dinyalakan`. Sesudah
    dinyalakan, `PROD\storage\logs\tunnel.err.log` berisi 9 baris
    `ERR Failed to dial a quic connection ... timeout: no recent network
    activity` (02:51:54 - 02:53:35 UTC), baru pukul 02:53:43
    `WRN ... egress UDP to port 7844 ... blocked or dropped` +
    `Switching to fallback protocol http2`, sehingga 4
    `Registered tunnel connection ... protocol=http2` baru terdaftar
    02:53:44 - 02:53:46 UTC. Jendela 502 yang dilihat user (02:52:17 UTC)
    tepat berada di antara restart dan keberhasilan fallback itu; Apache
    sendiri TIDAK pernah mati (proses httpd hidup sejak 07:06:37 WIB, 2,8 jam
    saat diperiksa).
    Perbaikan 08/10/2026 (DEV): ketiga tempat yang menyalakan cloudflared
    memakai `--protocol http2` — `tools\jaga-service-prod.ps1` baris 143 (mode
    token) dan baris 173 (mode config), `tools\pasang-token-tunnel-prod.ps1`
    baris 351, ditambah petunjuk manual di `tools\siapkan-tunnel-prod.ps1`
    baris 77. Flag itu TIDAK muncul di `cloudflared tunnel run --help` versi
    2026.9.3 (tersembunyi), karena itu dikonfirmasi dua cara: tes kontrol
    `cloudflared --ini-bukan-flag-xyz tunnel run --help` memang berbalas
    `Incorrect Usage. flag provided but not defined` (CLI ketat, jadi
    `--protocol` pasti sah), lalu uji nyata memakai connector SEMENTARA dengan
    token yang sama (replika, log dialihkan ke berkas sementara, connector
    produksi tidak diganggu): 4 koneksi terdaftar dalam 3-4 detik dengan
    `protocol=http2` dan NOL baris `Failed to dial`. Sesudah uji, jumlah proses
    tunnel kembali 1 dan domain publik tetap 200. Jalur deteksi proses juga
    dipastikan tidak rusak: CommandLine connector berbunyi
    `"...\cloudflared.exe" --protocol http2 tunnel run` sehingga pola
    `*tunnel run*` milik `cek-status-prod.ps1`, `hentikan-service-prod.ps1`,
    dan `jaga-service-prod.ps1` tetap cocok.
    **Pelajaran: (1) 502 dari Cloudflare = connector ADA tetapi dial ke
    origin/edge gagal, sedangkan 530/1033 = connector TIDAK ada — bedakan
    keduanya sebelum menyalahkan Apache; (2) waktu kejadian user wajib
    disandingkan dengan cap waktu UTC di log (WIB = UTC+7) supaya jendela
    insiden cocok; (3) kata "manual:" pada `jaga-service.log` adalah penanda
    tercepat bahwa ada MANUSIA yang merestart service, bukan service yang mati
    sendiri; (4) bila jaringan memblokir egress UDP 7844, jangan biarkan
    cloudflared memilih protokol sendiri: paksa `--protocol http2`, karena
    urutan percobaan QUIC (5s -> 8s -> 16s -> 32s ke banyak IP) memakan ~2
    menit downtime padahal jalur http2 sehat; (5) `stop-report.bat` +
    `start-report.bat` mematikan tunnel walau Apache tetap jalan, jadi kalau
    hanya ingin memastikan service hidup sebaiknya cukup `start-report.bat`
    (watchdog hanya menyalakan yang belum jalan dan tidak pernah mematikan
    tunnel).**

38. **`start-report.bat`/`stop-report.bat` ditulis ulang dengan pemeriksaan
    pra-syarat dan pemulihan otomatis (08/10/2026), dan dua jebakan PowerShell
    baru ketahuan dari UJI NYATA, bukan dari peninjauan kode.** Semua perubahan
    ada di copy DEV `C:\laragon\www\oleochemicalReport`.
    - `tools\cek-pra-syarat-prod.ps1` menjadi SATU-SATUNYA pemeriksa keadaan
      sebelum service dinyalakan/dihentikan. Keluarannya dua lapis: ringkasan
      `[pra] ...` untuk manusia PLUS penanda `KUNCI=NILAI` untuk dibaca `.bat`
      (`SERVICE_PHP`, `SERVICE_TUNNEL`, `PROSES_COPY_DEV`,
      `PELAYAN_8000=PROD|DEV|MATI`, `POLL_GANDA=0|1`, `LARAGON_MATI=0|1`,
      `MODE_PEMELIHARAAN=0|1`, `SIAP=0|1`). JANGAN mengganti penanda ini dengan
      kalimat: `.bat` mencocokkannya huruf demi huruf lewat `find`, dan kalimat
      ringkasannya memakai `write` yang spasi/formatnya berubah-ubah.
    - Dua sinyal BARU dipilih karena pemeriksaan lewat command line TIDAK
      cukup: (a) `PELAYAN_8000` diselidiki dengan memanggil `/debug-login`
      (302 = copy DEV, 404 = copy PROD, 000 = port mati) sebab aplikasi yang
      dijalankan `artisan` memakai jalur RELATIF sehingga command line-nya
      tidak memuat `oleochemicalReport`/`oleochemicalReport-prod` sama sekali;
      (b) `POLL_GANDA` menghitung lebih dari satu proses `telegram:poll`
      (dua poller = bot mati total, HTTP 409, Riwayat Insiden #21).
    - `start-report.bat`: memeriksa `SIAP=1` dan BERHENTI bila ada masalah (kata
      `paksa` untuk memaksa); `LARAGON_MATI=1` (Apache 80 DAN MySQL 3306
      dua-duanya mati) memakai pesan sendiri supaya pengguna tahu harus membuka
      Laragon, sedangkan `MODE_PEMELIHARAAN=1`, `PELAYAN_8000=DEV`, dan
      `POLL_GANDA=1` ditampilkan sebagai CATATAN karena ketiganya ditangani
      watchdog. Langkah 3 selalu menjalankan `tools\jaga-service-prod.ps1` (aman
      diulang: hanya menyalakan service yang belum jalan, membuang proses
      DUPLIKAT, dan TIDAK pernah mematikan tunnel), lalu langkah 4 menjalankan
      `tools\cek-status-prod.ps1` dan mengharapkan baris `8000/login : 200`
      (`findstr /r /c:"8000/login *: 200"`). Opsi `uji` dan `nopause` tetap ada,
      ditambah `paksa`.
    - `stop-report.bat`: mode `tunnel` menghentikan service latar TETAPI
      membiarkan connector cloudflared hidup lewat opsi BARU `-BiarkanTunnel`
      pada `tools\hentikan-service-prod.ps1` (skrip itu kini juga mengingatkan
      bahwa angka "sisa proses tunnel: 1" memang diharapkan pada mode itu).
      Kedua `.bat` memakai berkas sementara BERNAMA UNIK (`%RANDOM%`) dan
      menghapusnya di SEMUA label keluar, karena salinan DEV dan PROD bisa
      dijalankan bersamaan di satu komputer (nama tetap sempat saling menimpa).
    - Jebakan 1: `ProsesCopyDev()` semula mencocokkan command line `*artisan*`,
      padahal proses ANAK `artisan serve` berbunyi
      `php.exe -S 0.0.0.0:8000 ...\Foundation\resources\server.php` -- tanpa
      kata "artisan". Akibatnya tugas lama `Oleo Serve` dari copy DEV (Riwayat
      Insiden #21) LOLOS dari deteksi. Perbaikan: pola `artisan` ATAU
      `server.php`. Dibuktikan dengan menjalankan satu proses DEV sementara
      (`php -S 127.0.0.1:8099 ...\Foundation\resources\server.php`, lalu
      dihentikan lagi): sebelum perbaikan tidak terdeteksi, sesudah perbaikan
      muncul `[pra] proses copy DEV : 1 proses -> digantikan watchdog saat
      start-report.bat` dan `PROSES_COPY_DEV=1`, dan `SERVICE_PHP` naik dari 5
      ke 6.
    - Jebakan 2: **`angka + 'teks'` di PowerShell mencoba PENJUMLAHAN NUMERIK**,
      bukan penggabungan teks (`Cannot convert value "proses -> digantikan
      watchdog saat start-report.bat" to type "System.Int32"` /
      `InvalidCastFromStringToInteger`). Dua baris ringkasan
      (`$jumlahDev + ' proses ...'` dan `$jumlahPoll + ' proses ...'`) gagal
      TOTAL sehingga barisnya HILANG dari keluaran, sementara skrip tetap
      lanjut ke baris berikutnya dan seolah sehat. Perbaikan: `[string]$angka +
      ' teks'`; pola aman lain yang sudah dipakai berkas itu adalah mengawali
      rangkaian dengan teks (`'queue=' + $angka`). Pelajaran: awali setiap
      rangkaian penggabungan dengan nilai bertipe TEKS -- sama seperti
      pelajaran tanda plus ganda di JavaScript pada Riwayat Insiden #34.
    - Uji yang dijalankan 08/10/2026 (semuanya nyata, bukan asumsi): sintaks
      kedua `.bat` dengan `cmd /c "... uji paksa nopause < NUL > berkas 2>&1"`
      lalu membaca BERKASNYA (JANGAN lewat pipe), sintaks kedua `.ps1` lewat
      `Parser::ParseFile`, audit label/goto (`start-report.bat` 16 label,
      `stop-report.bat` 18 label, NIHIL label ganda, NIHIL `goto` tanpa label),
      audit encoding (kedua `.bat` murni CRLF dan murni ASCII, 0 byte di atas
      127), plus uji dengan proses DEV sementara di atas. Hasil saat itu:
      `SERVICE_PHP=6`, `SERVICE_TUNNEL=1`, `PROSES_COPY_DEV=0` (tanpa proses
      sementara), `PELAYAN_8000=PROD`, `POLL_GANDA=0`, `LARAGON_MATI=0`,
      `SIAP=1`.
    - Perlu diingat: kedua `.bat` copy DEV menunjuk copy PROD, sehingga
      pemeriksaan pra-syarat BARU aktif di PROD hanya setelah
      `Deploy-Laporan.bat` dijalankan. Sebelum itu `.bat` menampilkan
      "DILEWATI: tools\cek-pra-syarat-prod.ps1 belum ada di copy PROD" dan
      tetap melanjutkan seperti perilaku lama, jadi tidak ada risiko gagal
      hanya karena berkas belum tersalin.
39. **Berkas `.bat` berakhiran LF murni membuat sebagian `goto`/label TIDAK
    ditemukan cmd.exe, dan copy PROD SELALU menerima LF dari git (08/10/2026).**
    Pemicunya: sesudah `start-report.bat` copy DEV ditambah pemeriksaan
    pra-syarat, uji nyata dengan Laragon dimatikan menghasilkan
    `The system cannot find the batch label specified - laragon_mati` padahal
    label `:laragon_mati` JELAS ADA di berkas itu. Penyelidikan 08/10/2026
    (semuanya uji nyata dengan berkas SALINAN, tidak menyentuh service):
    (a) akhiran baris copy PROD memang LF MURNI -- pengukuran byte
    membuktikan
    `C:\laragon\www\oleochemicalReport-prod\start-report.bat` = CRLF 0 /
    LF 38 (1.745 byte) dan copy PROD `stop-report.bat` = CRLF 0 / LF 31
    (1.596 byte), sedangkan salinan DEV = 174 dan 161 CRLF (0 LF) karena DEV
    BUKAN hasil checkout git;
    (b) LF murni TIDAK selalu gagal, jadi JANGAN percaya "LF aman": versi
    berkas yang lebih pendek (169 baris, keadaan ter-commit saat itu) JALAN
    dalam bentuk LF, sedangkan berkas DEV terkini (175 baris, sesudah label
    `:tidak_siap_paksa` ditambahkan) SELALU gagal bila disimpan LF murni --
    9 dari 10 varian padding (0/1/2/3/4/5/6/8/12/20 baris `REM` di awal)
    berbalas `LABEL-GAGAL`. Bentuk CRLF SELALU jalan (berkas DEV sekarang,
    versi clone, maupun berkas kecil buatan);
    (c) bentuk CAMPURAN (setengah LF lalu CRLF, setengah CRLF lalu LF, hanya
    baris kosong sebelum label yang LF, hanya baris label yang LF, tiap 10
    baris LF) semuanya JALAN, dan berkas kecil buatan (satu `goto` + padding
    0..200 baris REM) juga jalan di LF maupun CRLF. Jadi kegagalan ini
    bergantung ISI/UKURAN berkas (perilaku pencarian label cmd.exe yang tidak
    dijamin untuk LF), BUKAN pola akhiran baris tertentu yang bisa dihindari
    dengan menata ulang label.
    Akar masalahnya di tingkat repo: `.gitattributes` (sebelum perbaikan)
    memuat `* text=auto eol=lf` sehingga SEMUA berkas teks -- termasuk `.bat` --
    dituliskan git sebagai LF, sedangkan copy PROD LAHIR dari `git clone`/
    `git pull`. Jadi `.bat` yang tampak "bertahun-tahun benar" di DEV berubah
    perilaku begitu dibawa ke PROD.
    Perbaikan 08/10/2026 (commit khusus, copy DEV
    `C:\laragon\www\oleochemicalReport`): `.gitattributes` menambah
    `*.bat text eol=crlf` + `*.cmd text eol=crlf` SETELAH baris
    `* text=auto eol=lf` (baris belakangan menang), sehingga checkout/pull
    berikutnya menuliskan seluruh `.bat` terlacak (7 berkas: `start-report.bat`,
    `stop-report.bat`, dan 5 di `sensor-suhu-esp32/`) dengan CRLF. Dibuktikan
    lewat simulasi pull di clone sementara (`%TEMP%\uji-pull`): sebelum
    perbaikan `start-report.bat` = LF 169 / CRLF 0, sesudah = CRLF 174 / LF 0,
    dan `git ls-files --eol` berbunyi `i/lf w/crlf attr/text eol=crlf` untuk
    ketujuh berkas `.bat` itu.
    Dampak ke PROD: baru terpasang sesudah `Deploy-Laporan.bat` dijalankan
    manusia, dan SESUDAH deploy WAJIB diperiksa ULANG dengan menghitung byte
    berkas PROD (LF-saja harus 0), karena `.gitattributes` baru dibaca git
    pada checkout yang sama. Perintah periksa (PowerShell, hanya membaca):
    `[IO.File]::ReadAllBytes('C:\laragon\www\oleochemicalReport-prod\start-report.bat')`
    lalu hitung pasangan byte 13+10; hasil benar = CRLF 174 dan 0 LF-saja.
    Uji 08/10/2026 (empat skenario, memakai SALINAN berkas dengan `PROD`
    dialihkan ke `%TEMP%\uji-prod` + stub `cek-pra-syarat-prod.ps1` yang
    dipilih lewat variabel `SKENARIO`, jadi TIDAK ada service yang disentuh):
    `SIAP=0` tanpa `paksa` -> GAGAL + petunjuk "Perbaiki dulu"; `SIAP=0` +
    `paksa` -> lanjut ("ada masalah, dilanjutkan karena opsi paksa");
    `LARAGON_MATI=1` tanpa `paksa` -> BERHENTI + "Buka Laragon, klik Start
    All"; `LARAGON_MATI=1` + `paksa` -> lanjut. Inilah yang membuat `paksa`
    benar-benar berguna: sebelumnya blok `:tidak_siap` TIDAK memeriksa opsi
    `paksa`, sehingga pengguna yang mengetik `paksa` tetap berhenti -- pesan
    "paksa dengan: start-report.bat paksa" hanya benar untuk masalah Laragon.
    Label baru `:tidak_siap_paksa` menutup celah itu.
    **Pelajaran: (1) JANGAN memakai LF untuk berkas `.bat`/`.cmd`: cmd.exe
    tidak menjamin menemukan label lewat `goto` pada berkas berakhiran LF, dan
    kegagalannya muncul sebagai "cannot find the batch label specified"
    walaupun labelnya ada -- perbaikannya di `.gitattributes`
    (`*.bat text eol=crlf`), BUKAN dengan menambah/merapikan label;
    (2) `.bat` yang "sudah lama jalan" di DEV belum tentu punya akhiran baris
    yang sama di PROD: periksa `git check-attr text eol -- <berkas>` atau
    `git ls-files --eol` sebelum menuduh logikanya salah; (3) setiap opsi
    "paksa" WAJIB diuji DUA KALI (dengan dan tanpa) pada SETIAP cabang
    berhenti, karena satu cabang yang lupa memeriksa `paksa` hanya ketahuan
    dari uji itu; (4) saat menguji `.bat`, jalankan SALINAN dengan `PROD`
    dialihkan ke folder stub di `%TEMP%` supaya service produksi tidak pernah
    tersentuh, dan tetap alihkan keluaran ke BERKAS -- jangan lewat pipe
    (Riwayat Insiden #31).**




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
Nama perusahaan pada dokumen `PROMPT.md` baris 5 (brief awal project) juga
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

1. Baca ulang file `AGENTS.md` ini secara penuh sebelum mulai kerja apapun.
2. Sebutkan file apa saja yang akan disentuh sebelum mulai edit.
3. Untuk fitur baru/kompleks ATAU untuk bug fix yang menyentuh definisi
   filter/agregasi data: baca dan laporkan dulu kondisi file terkait DAN
   definisi business logic yang dipahami, TUNGGU konfirmasi manusia sebelum
   menulis kode (lihat Riwayat Insiden #8).
4. Setiap file yang diubah ditulis ulang lengkap (kecuali perubahan kecil,
   lihat Aturan Wajib Edit File poin 2).
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
      yang sudah kotor dari sesi lain (mis. `app/Jobs/ProcessCmFindingsImport.php`,
      `.env.example`, `.gitignore`, `tmp-cek-aset.php`) tidak boleh ikut
      ter-commit.
   c. Setelah commit, langsung `git push hub main`. Pekerjaan dianggap
      "selesai" hanya setelah hash commit-nya dilaporkan ke user.
   d. Yang BELUM lulus uji JANGAN di-commit (jangan pernah commit kode setengah
      jalan/rusak). Kalau memang harus menyimpan pekerjaan darurat, buat
      commit terpisah berawalan `WIP:` dan sebutkan di laporan bahwa commit itu
      belum lulus uji.
   e. Deploy ke PROD TETAP manual lewat pintasan `Deploy-Laporan.bat`
      (dijalankan manusia, ada konfirmasi `y`) — commit/push TIDAK mengubah
      PROD sedikit pun. Setelah push, bandingkan commit PROD
      (`git -C 'C:\laragon\www\oleochemicalReport-prod' log --oneline -1`)
      dengan commit hub (`git rev-parse hub/main`) untuk tahu PROD masih
      tertinggal atau tidak, lalu laporkan.
   f. Alasan aturan ini: kalau nanti file corrupt dan perlu `git checkout`,
      kerugian yang hilang hanya sebatas perubahan sejak commit terakhir,
      bukan seluruh sesi kerja.

## ATURAN WAJIB: VERIFIKASI DULU, BARU KODING

Sebelum menulis SATU BARIS kode query, migration, atau apapun yang
menyentuh nama kolom/tabel/relasi database:

1. JANGAN asumsikan nama kolom dari "logikanya harusnya ada", dari pola
   umum framework/training data, atau dari kode/prompt sesi sebelumnya.
   WAJIB cek struktur asli dulu dengan salah satu cara ini (urut dari yang
   paling disarankan):
   - `php artisan model:show NamaModel` (paling disarankan, langsung
     menampilkan kolom, tipe, dan relasi tanpa raw SQL).
   - Baca file migration terkait di `database/migrations/`.
   - Baca `$fillable` dan relasi di Model terkait (`app/Models/*.php`).
2. Untuk RELASI ANTAR TABEL (join, where, whereHas), WAJIB baca dulu
   Model-nya untuk lihat relasi yang SUDAH didefinisikan. Jangan asumsikan
   ada kolom foreign key langsung kalau belum diverifikasi.
3. Kalau ragu apakah suatu kolom/relasi ada: STOP, laporkan dulu ke user
   "saya perlu cek struktur X dulu sebelum menulis kode Y", jalankan
   verifikasi, baru lanjut menulis kode setelah dikonfirmasi.
4. Setelah menulis query/kode yang menyentuh database, SEBELUM melaporkan
   "selesai": pastikan semua nama kolom yang dipakai sudah diverifikasi ada
   di Langkah 1-2, bukan hasil tebakan.
5. Sebelum menyimpulkan "library X tidak bisa/tidak mendukung Y" (contoh:
   PhpSpreadsheet tidak bisa baca cached value formula — lihat Riwayat
   Insiden #6), WAJIB buat test terisolasi minimal untuk membuktikan klaim
   itu, jangan langsung rencanakan rewrite besar berdasarkan asumsi.

## ATURAN WAJIB: PERINTAH TINKER/TERMINAL DI POWERSHELL

PowerShell TIDAK memakai backslash (`\`) sebagai karakter escape untuk
tanda dollar (`$`) — ini beda dari Bash/Linux. Perintah seperti
`--execute="...\$var..."` akan gagal dengan parse error yang membingungkan.
Double-quote here-string (`@" ... "@`) JUGA tetap melakukan variable
expansion (lihat Riwayat Insiden #4).

WAJIB ikuti pola ini untuk semua perintah `php artisan tinker --execute`:

1. SELALU bungkus seluruh argumen `--execute` dengan tanda kutip TUNGGAL
   (`'...'`), BUKAN tanda kutip ganda (`"..."`). Kutip tunggal di
   PowerShell tidak melakukan interpolasi variabel sama sekali.

   BENAR:
   ```
   php artisan tinker --execute='print_r(Schema::getColumnListing("cm_readings"));'
   ```
   SALAH (akan gagal/rawan strip variabel):
   ```
   php artisan tinker --execute="print_r(Schema::getColumnListing('cm_readings'));"
   ```

2. Untuk kode PHP yang butuh tanda kutip di dalamnya, gunakan kutip ganda
   di DALAM kutip tunggal luar, seperti contoh di atas.

3. Jika logic yang mau dijalankan lebih dari 1-2 baris atau perlu
   loop/foreach kompleks: JANGAN paksa lewat `--execute` satu baris.
   Gunakan `php artisan model:show NamaModel`, `php artisan route:list`,
   atau buat file `.php` test terpisah dan jalankan langsung
   (`php nama_file_test.php`) — lebih aman daripada raw command satu baris.

4. Untuk cek struktur tabel, LEBIH DIUTAMAKAN pakai:
   ```
   php artisan model:show NamaModel
   ```

5. Untuk file besar/berat (Excel, PDF, dsb), proses HARUS jalan sebagai
   queued job (`php artisan queue:work`), bukan sinkron — cek dulu
   `QUEUE_CONNECTION` di `.env` sebelum menyimpulkan job "hang"/"stuck"
   (lihat Riwayat Insiden #5).

6. **JANGAN lupa menghapus variabel lingkungan sesudah dipakai.** Jendela
   PowerShell yang dipakai menjalankan perintah BERTAHAN antar panggilan,
   sehingga `$env:DB_DATABASE='oleochemical'` (dipakai untuk mengarahkan
   perintah ke database PROD) masih terpasang pada panggilan berikutnya.
   Akibatnya `php artisan test` bisa gagal SELURUHNYA dengan
   `SQLiteDatabaseDoesNotExistException: Database file at path [oleochemical]
   does not exist` (kejadian 03/10/2026) padahal kodenya benar: `phpunit.xml`
   menulis `<env name="DB_DATABASE" value=":memory:"/>` TANPA `force`, sehingga
   variabel lingkungan asli MENANG atas phpunit.xml. Aturan: setiap perintah
   yang menyetel `DB_DATABASE` WAJIB diakhiri `Remove-Item Env:DB_DATABASE`
   pada perintah yang sama, dan periksa `$env:DB_DATABASE` lebih dulu kalau
   suite tiba-tiba merah semua.
