# Verifikasi Database dan Perintah Tinker/Terminal

> Bagian dari dokumentasi proyek di folder `docs`.
> Peta dokumen: `AGENTS.md` di akar repo.

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
