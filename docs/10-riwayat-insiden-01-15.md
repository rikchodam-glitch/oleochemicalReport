# Riwayat Insiden 1-15

> Bagian dari dokumentasi proyek di folder `docs`.
> Peta dokumen: `AGENTS.md` di akar repo.

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
