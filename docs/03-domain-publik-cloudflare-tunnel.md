# Domain Publik (Cloudflare Tunnel)

> Bagian dari dokumentasi proyek di folder `docs`.
> Peta dokumen: `AGENTS.md` di akar repo.

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
