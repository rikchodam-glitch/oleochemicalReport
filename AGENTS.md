# AGENTS.md — oleochemicalReport

Dokumen ini **hanya peta**. Rincian ada di folder `docs/`. Jangan menambah uraian
panjang di sini: tulis ke berkas tematik di `docs/`, lalu perbarui tabel peta.
Tujuannya agar sesi baru cukup membaca aturan pokok di bawah + dokumen yang
relevan dengan tugasnya.

## Aturan pokok (wajib, jangan dilanggar)

- **DEV** = `C:\laragon\www\oleochemicalReport` (tempat kerja). **PROD** =
  `C:\laragon\www\oleochemicalReport-prod` (yang disajikan). JANGAN pernah
  mengedit, menghapus, atau menjalankan migrasi manual di PROD.
- JANGAN bekerja atau mengunggah di cabang `main`. Selalu:
  `tools\mulai-tugas.ps1` -> cabang `tugas/<nama>/<ringkas>` -> edit -> uji ->
  commit -> `tools\unggah-tugas.ps1` -> `tools\buat-pr-tugas.ps1` -> manusia
  membuka Pull Request.
- **Commit segera setelah uji hijau**, satu perubahan = satu commit, hanya berkas
  terkait. DILARANG `git add -A` / `git add .`.
- Deploy HANYA di komputer integrator, manual lewat `Deploy-Laporan.bat`,
  sesudah Pull Request di-merge. Commit/push tidak mengubah PROD.
- **Verifikasi dulu, baru koding**: nama tabel/kolom/relasi wajib dicek lewat
  `php artisan model:show`, migration, atau model. Jangan berasumsi.
- **Blade**: baca ulang dari disk sebelum edit; default **overwrite penuh**.
  `find_and_replace` parsial hanya untuk satu baris unik. Satu kali gagal =
  langsung pindah ke overwrite penuh. JANGAN menulis berkas `.php`/`.blade.php`
  lewat PowerShell/terminal.
- **Uji**: `php artisan test` wajib hijau. Jika menyentuh blade:
  `php artisan view:cache` juga wajib sukses. Jangan commit kode yang belum lulus.
- Perubahan definisi bisnis/filter/agregasi data WAJIB dikonfirmasi ke user
  sebelum dikoding.
- Jangan tinggalkan sampah berkas (skrip `tmp-*`, `.bak`, hasil export sekali
  pakai, view yatim). Hapus pada giliran yang sama.
- Gaya kode: komentar Bahasa Indonesia, tanpa emoji, docblock untuk method baru,
  null-safe untuk relasi yang bisa kosong, early return.
- `php artisan optimize` DILARANG (route closure di `routes/web.php`).
  Gunakan `config:cache` + `view:cache`.
- Service latar (`queue:work`, `schedule:work`, `telegram:poll`, serve:8000,
  tunnel) HANYA di PROD. Dua poller Telegram = bot mati total (HTTP 409).

## Peta dokumen

Semua berkas di bawah ini ada di `docs/`.

| Dokumen | Isi | Baca saat |
|---|---|---|
| [01-ikhtisar-dev-prod-dan-deploy.md](docs/01-ikhtisar-dev-prod-dan-deploy.md) | DEV/PROD, deploy satu klik, service latar, pintasan Desktop, storage/database, `.env` | Menyentuh deploy, service, atau lingkungan |
| [02-alur-kerja-banyak-teknisi.md](docs/02-alur-kerja-banyak-teknisi.md) | Cabang tugas, papan klaim, Pull Request, konflik, penjaga deploy | Memulai tugas atau menyelesaikan konflik |
| [03-domain-publik-cloudflare-tunnel.md](docs/03-domain-publik-cloudflare-tunnel.md) | Cloudflare Tunnel, DNS, domain publik, trusted proxy | Mengubah domain/tunnel/akses publik |
| [04-keputusan-arsitektur-01-10.md](docs/04-keputusan-arsitektur-01-10.md) | Keputusan arsitektur 1-10 (CM, lokasi, Excel, sinkronisasi Master Equipment, threshold, wizard, man hour) | Menyentuh modul CM, import, shift, man hour |
| [05-keputusan-arsitektur-11-17.md](docs/05-keputusan-arsitektur-11-17.md) | Keputusan 11-17 (status laporan, user/Bagian, akses, wizard, aset, idle, kolaborasi) | Menyentuh laporan, user, akses, aset |
| [06-keputusan-arsitektur-18-23.md](docs/06-keputusan-arsitektur-18-23.md) | Keputusan 18-23 (detail laporan, panel, import/export aset) | Menyentuh halaman detail laporan, panel, aset |
| [07-keputusan-arsitektur-24-30.md](docs/07-keputusan-arsitektur-24-30.md) | Keputusan 24-30 (tata letak detail, edit lokasi/foto/teknisi, export, UMUM, pesan panel) | Menyentuh UI laporan, export, jenis pekerjaan, panel |
| [08-keputusan-arsitektur-31-33.md](docs/08-keputusan-arsitektur-31-33.md) | Keputusan 31-33 (istirahat, lembur, man hour, grafik hari OFF) | Menyentuh durasi, lembur, man hour, grafik teknisi |
| [09-aturan-wajib-edit-file.md](docs/09-aturan-wajib-edit-file.md) | Aturan edit berkas, overwrite blade, recovery | Sebelum mengedit berkas apa pun |
| [10-riwayat-insiden-01-15.md](docs/10-riwayat-insiden-01-15.md) | Insiden 1-15 dan pelajarannya | Melakukan diagnosis/refactor berisiko |
| [11-riwayat-insiden-16-32.md](docs/11-riwayat-insiden-16-32.md) | Insiden 16-32 dan pelajarannya | Melakukan diagnosis/refactor berisiko |
| [12-riwayat-insiden-33-51.md](docs/12-riwayat-insiden-33-51.md) | Insiden 33-51 dan pelajarannya | Melakukan diagnosis/refactor berisiko |
| [13-nama-sistem-kode-alur-sesi-kebersihan.md](docs/13-nama-sistem-kode-alur-sesi-kebersihan.md) | Branding, aturan kode, alur sesi baru, kebersihan repo | Mengubah teks tampilan atau menutup sesi |
| [14-verifikasi-db-dan-tinker.md](docs/14-verifikasi-db-dan-tinker.md) | Verifikasi struktur DB dan perintah tinker/PowerShell yang aman | Menulis query/migration atau memakai tinker |
| [15-modul-spare-part.md](docs/15-modul-spare-part.md) | Modul Spare Part (PR/PO, stok, aksi, export) | Menyentuh halaman Spare Part |
| [16-aturan-14-oktober-perbarui-dev-dan-pr.md](docs/16-aturan-14-oktober-perbarui-dev-dan-pr.md) | Kewajiban memperbarui DEV lalu langsung menyiapkan PR | Memulai dan mengakhiri tugas |
| [17-aturan-pengelolaan-laporan-induk.md](docs/17-aturan-pengelolaan-laporan-induk.md) | Laporan hanya boleh dikelola dari laporan induk | Menyentuh edit/hapus laporan atau salinan |
| [18-warna-jenis-pekerjaan.md](docs/18-warna-jenis-pekerjaan.md) | Palet warna jenis pekerjaan yang seragam | Mengubah filter, lencana, atau grafik |
| [19-laporan-teknisi-equipment-cm.md](docs/19-laporan-teknisi-equipment-cm.md) | Laporan teknisi di halaman tiap equipment CM | Menyentuh halaman equipment CM |
| [20-filter-laporan-dan-total-pekerjaan.md](docs/20-filter-laporan-dan-total-pekerjaan.md) | Filter tanggal/tampilan dan kartu total pekerjaan | Menyentuh filter atau ringkasan halaman Laporan |
| [21-dashboard-utama.md](docs/21-dashboard-utama.md) | Dashboard lintas modul: KPI, grafik, akses per Bagian, aturan query | Mengubah `/dashboard`, menambah modul, atau grafik |
| [22-laporan-kolaborasi-shift.md](docs/22-laporan-kolaborasi-shift.md) | Laporan Reguler/Reguler Staff yang berkolaborasi shift/Staff Grub tampil di tab Shift saja | Mengubah tab Reguler/Shift, filter grup, atau kolaborasi |
| [23-fitur-cuti.md](docs/23-fitur-cuti.md) | Cuti diinput Admin Reguler, teknisi hanya melihat, dampak man hour | Mengubah cuti, role Admin Reguler, atau target man hour |
| [24-broadcast-kolaborasi.md](docs/24-broadcast-kolaborasi.md) | Broadcast Telegram detail pekerjaan ke seluruh kolaborator | Mengubah kolaborasi laporan atau notifikasi Telegram |
| [25-form-laporan-teknisi.md](docs/25-form-laporan-teknisi.md) | Form laporan web token `/laporan`, multi-laporan, hierarki, multi-alat, foto, kolaborator | Mengubah form laporan, token, foto, kolaborator, atau akses teknisi |
| [26-halaman-semua-cuti.md](docs/26-halaman-semua-cuti.md) | Halaman read-only semua cuti untuk admin, supervisor, admin_general, admin_reguler | Mengubah akses atau tampilan seluruh cuti |
| [27-menu-perintah-bot-telegram.md](docs/27-menu-perintah-bot-telegram.md) | Menu command native bot (`/start`, `/laporan`, `/info`, `/cuti`, `/reset`) | Mengubah daftar command atau tombol menu bot |
| [28-assets-management.md](docs/28-assets-management.md) | Pencarian live, detail via klik, foto equipment, ringkasan CM, sinkronisasi CM | Mengubah halaman Assets Management atau sinkronisasi CM |
| [29-hierarki-lokasi-dropdown.md](docs/29-hierarki-lokasi-dropdown.md) | Opsi PT/Dept/Area/SubArea dari Functional Location untuk Assets, Teknisi, Laporan, Bot | Mengubah dropdown/filter lokasi |

## Dokumen pendukung di `docs/`

| Dokumen | Isi |
|---|---|
| [ASSET_MAPPING.md](docs/ASSET_MAPPING.md) | Pemetaan kolom Excel ZPM ke database aset |
| [PROMPT.md](docs/PROMPT.md) | Brief awal proyek, stack, dan kebutuhan modul |
| [rancangan-proyek-sensor-suhu-esp32.md](docs/rancangan-proyek-sensor-suhu-esp32.md) | Rancangan perangkat sensor suhu/kelembapan ESP32 |
| [sensor-suhu-esp32-README.md](docs/sensor-suhu-esp32-README.md) | Pemakaian dan troubleshooting firmware sensor |
| [README-LARAVEL.md](docs/README-LARAVEL.md) | README bawaan Laravel (referensi framework) |
| [pull_request_template.md](docs/pull_request_template.md) | Template Pull Request (dibaca GitHub dari folder `docs/`) |

## Sengaja TIDAK dipindah dari tempatnya

- `tugas/**/*.md` — papan klaim `tugas/<nama>.md` dan catatan PR
  `tugas/pr-<ringkas>.md`; dibaca/ditulis oleh `tools\mulai-tugas.ps1` dan
  `tools\buat-pr-tugas.ps1`.
- `.kilo/**/*.md` — konfigurasi command/agent Kilo.
- `vendor/`, `node_modules/`, `sensor-suhu-esp32/.venv/` — berkas dependensi.

## Alur sesi (ringkas)

1. `powershell -ExecutionPolicy Bypass -File tools\mulai-tugas.ps1 -Nama <nama> -Tugas "<ringkas>"`
2. Isi `tugas/<nama>.md` bagian modul/berkas yang disentuh.
3. Kerjakan perubahan; baca dokumen peta yang relevan di atas.
4. `php artisan test` (dan `php artisan view:cache` bila menyentuh blade).
5. Commit hanya berkas terkait, lalu
   `powershell -ExecutionPolicy Bypass -File tools\unggah-tugas.ps1`.
6. `powershell -ExecutionPolicy Bypass -File tools\buat-pr-tugas.ps1`.
7. Laporkan nama cabang, hash commit, tautan/status PR, dan berkas kotor sesi
   lain yang sengaja tidak ikut di-commit.

## Merawat peta ini

- Tambah rincian baru ke berkas yang paling sesuai di `docs/`; jangan menyalin
  isinya ke `AGENTS.md`.
- Setiap dokumen baru di `docs/` wajib ditambahkan ke tabel peta di atas.
- Jika ringkasan di `AGENTS.md` berbeda dengan rincian di `docs/`, rincian di
  `docs/` yang berlaku; laporkan perbedaannya agar peta diperbaiki.
- Jaga `AGENTS.md` tetap pendek (target di bawah 200 baris).
