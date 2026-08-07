# AGENTS.md — oleochemicalReport

## PENTING — ROOT PROJECT

Project utama dan SATU-SATUNYA target semua perubahan:
`C:\Users\ASUS\oleochemicalReport`

Setiap kali menyebutkan kondisi suatu file ("file X sudah ada", "kolom Y
begini"), WAJIB sertakan path lengkap absolut yang benar-benar dibaca saat
itu juga. Jangan mengandalkan laporan dari sesi sebelumnya tanpa verifikasi
ulang.

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