# AGENTS.md — oleochemicalReport

## PENTING — DUA COPY PROJECT DI KOMPUTER INI (DEV & PROD)

Sejak 28/09/2026 project ini SENGAJA ada dalam DUA copy di satu komputer
(diputuskan user: agar pengeditan tidak langsung terlihat di server):

| Peran | Path | Keterangan |
|---|---|---|
| **DEV** (tempat kerja agent) | `C:\laragon\www\oleochemicalReport` | SEMUA perubahan kode dikerjakan di sini. Database `oleochemical_dev`. Diuji lokal lewat `php artisan serve` di `http://localhost:8000`. |
| **PROD** (yang disajikan) | `C:\laragon\www\oleochemicalReport-prod` | Hasil deploy. Disajikan Apache pada vhost `oleo-prod.lvh.me` (nanti domain publik). Database `oleochemical` (data asli) dan pemegang `storage/app` data asli (foto/laporan). **JANGAN pernah mengedit file di sini.** |

- Repo hub lokal (perantara deploy): `C:\laragon\repo\oleochemicalReport.git`
  (bare). DEV punya remote **`hub`** ke sana; PROD punya remote **`origin`**
  ke sana. Remote `origin` di DEV = GitHub (cadangan, sering tertinggal).
- Vhost produksi: `C:\laragon\etc\apache2\sites-enabled\oleo-prod.conf`
  (DocumentRoot ke copy PROD, `ServerName oleo-prod.lvh.me` + `ServerAlias localhost`).
- Alur perubahan yang WAJIB diikuti: edit & uji di DEV -> commit di DEV ->
  `git push hub main` -> jalankan `tools\deploy-prod.ps1` (pull di PROD +
  dependensi + migrasi + cache + restart service). JANGAN mengedit atau
  menjalankan migrasi manual di PROD.
- Service latar (`queue:work`, `schedule:work`, `telegram:poll`) HANYA jalan di
  PROD; dua poller Telegram sekaligus = bot mati total (HTTP 409, insiden #21).
  Di DEV cukup `php artisan serve --port=8000`.
- Storage & database terpisah: DEV tidak memuat foto asli (gambar akan tampak
  rusak di dev; itu normal, bukan bug).
- `php artisan optimize` JANGAN dipakai — `routes/web.php` memakai closure
  route (`/`) sehingga `route:cache` gagal. Pakai `config:cache` + `view:cache`.

Setiap kali menyebutkan kondisi suatu file ("file X sudah ada", "kolom Y
begini"), WAJIB sertakan path lengkap absolut yang benar-benar dibaca saat
itu juga, dan sebutkan apakah yang dibaca itu copy DEV atau PROD. Jangan
mengandalkan laporan dari sesi sebelumnya tanpa verifikasi ulang.

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
   - Hak akses halaman diatur per Bagian di tabel `bagian_accesses`
     (`bagian` unik + `permissions` json); daftar modulnya di
     `config/akses.php` (label, grup menu, pola nama route, nama route menu
     sidebar, penanda `khusus_admin`). Akun `admin`/`supervisor` SELALU akses
     penuh dan tidak bisa dibatasi; modul bertanda `khusus_admin` (mis.
     Manajemen User) tidak bisa diberikan ke Bagian mana pun.
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


<!-- Tambahkan insiden baru di bawah ini, nomor urut lanjut -->

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
7. Commit progress ke git setelah satu fitur/perbaikan terverifikasi jalan
   dan STABIL, SEBELUM mulai perubahan berikutnya yang tidak terkait.
   Jangan menumpuk banyak perubahan berbeda tanpa commit di antaranya —
   kalau nanti terjadi corrupt dan perlu `git checkout`, kerugian yang
   hilang hanya sebatas perubahan sejak commit terakhir, bukan seluruh
   sesi kerja.

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
