# Riwayat Insiden 33-51

> Bagian dari dokumentasi proyek di folder `docs`.
> Peta dokumen: `AGENTS.md` di akar repo.

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
    per JENIS item (alat vs lokasi) ΓÇö membandingkan kolom lokasi untuk item alat membuat seluruh alat
    satu lokasi dianggap duplikat, dan kegagalan itu SENYAP karena pesan sukses tetap muncul; (2) fitur
    yang "kadang tidak bisa" wajib diuji dengan DATA ASLI, karena data uji yang mengosongkan kolom
    (di sini `funcloc_id` pada fixture lama) menyembunyikan bug selama berbulan-bulan; (3) jangan pernah
    menutup/menghapus layar pilihan sebelum pengguna memutuskan selesai ΓÇö bila layar lama terhapus dari
    chat dan tidak ada tombol kembali, satu-satunya jalan keluar adalah membatalkan laporan.**

34. **Tanda plus BERLEBIH di rangkaian penggabungan teks JavaScript menghasilkan
    `NaN` di tampilan (06/10/2026).** Keluhan user: "pada halaman panel seharusnya
    bukan NaN namun drajat celcius seperti pada detail". Kartu suhu dashboard
    `/panel` mencetak `24.4NaN` (satuan " C" hilang), sedangkan halaman detail
    ruangan mencetak `24.4 C`. Penyebab: satu baris penyusun kartu
    (`resources/views/panel/index.blade.php` baris 453 sebelum diperbaiki) berbunyi
    `+          + '<span class="text-sm font-semibold text-slate-400"> C</span>'` ΓÇö
    tanda plus ganda: operator gabung, lalu unary plus. Unary plus memaksa teks
    span dikonversi ke angka menjadi `NaN`, sehingga `"24.4" + NaN` = `24.4NaN`.
    Seluruh pemeriksaan statis GAGAL menemukannya: kolom `suhu` decimal(5,2), cast
    `float` pada `PanelPembacaan`, payload `/panel/data` bernilai angka (dibuktikan
    lewat `curl` + `ConvertFrom-Json` = Decimal 24.5), berkas DEV dan PROD identik,
    dan blok `<script>` lolos `node --check`. Yang menemukan: RENDER NYATA memakai
    Chrome headless (`chrome.exe --headless=new --dump-dom` dengan profil
    `--user-data-dir` yang sama setelah membuka `/debug-login`) ΓåÆ
    `<p class="text-3xl ...">24.5NaN      </p>` tanpa span. Sesudah perbaikan:
    `24.4<span ...> C</span>` dan tidak ada `NaN`. **Pelajaran: (1) `NaN` pada
    tampilan hampir selalu berasal dari operasi aritmetika atas teks (unary plus,
    `-`, `*`, `Number()`), bukan dari data server ΓÇö cari operator ganda di
    rangkaian penggabungan string; (2) `node --check` hanya membuktikan SINTAKS,
    bukan nilai hasil, jadi bug seperti ini lolos; (3) untuk bug tampilan yang
    tidak bisa disimpulkan dari data, WAJIB buktikan dengan render browser nyata
    (headless `--dump-dom`) sebelum menyatakan sebabnya; (4) pencarian `NaN` pada
    HTML hasil dump wajib PEKA huruf besar-kecil ΓÇö kata "Maintenance" memuat
    "nan".** Uji penjaga: 2 test di `tests/Feature/PanelDashboardRingkasTest.php`
    (menolak pola baris `^\s*\+\s*\+` dan memastikan satuan C tetap ada); uji
    mutasi: tanda plus ganda dikembalikan ΓåÆ KEDUA test GAGAL.

35. **Satu laporan dari form web membuat SELURUH tombol Done/Continue wizard
    Telegram gagal menyimpan (07/10/2026).** Keluhan user: "report telegram di
    produksi tidak bisa menyelesaikan report; tekan Done/Continue tidak
    merespon". Semua service PROD memang jalan (queue:work, schedule:work,
    telegram:poll, serve:8000, tunnel) dan log poller justru memuat
    `Callback: wizard:confirm:save_done` berulang kali ΓÇö jadi tombolnya
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
    berikutnya selalu `0001` yang sudah terpakai ΓåÆ semua penyimpanan wizard
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
    **Pelajaran: (1) generator kode yang dipakai bersama WAJIB satu saja ΓÇö
    format berbeda (angka vs acak) membuat satu jalur merusak jalur lain;
    (2) nilai "terbesar" pada kolom kode berbasis TEKS tidak boleh dipakai apa
    adanya untuk urutan numerik: kode berhuruf selalu menang dan cast `(int)`
    atas huruf = 0; (3) gejala "tombol tidak merespon" pada Telegram hampir
    selalu berarti handler-nya melempar galat ΓÇö periksa `laravel.log` PROD
    lebih dulu, jangan hanya melihat daftar proses/service; (4) pesan galat ke
    pengguna jangan memuat pesan SQL mentah, simpan detailnya di log saja.**

36. **Blok `google_sheets` di `config/cm.php` HILANG sehingga Sync Google
    Sheets selalu berkata "URL belum dikonfigurasi di .env" padahal .env sudah
    terisi (08/10/2026).** Keluhan user: "tidak bisa sync data CM". Pesan yang
    tampil di halaman Overview CM (`CmController::syncGoogleSheets`, baris
    745-752) berbunyi "Sync Google Sheets: Data CM: URL belum dikonfigurasi di
    .env | Finding CM: URL belum dikonfigurasi di .env". Penyebabnya: di
    `C:\laragon\www\oleochemicalReport\config\cm.php` hanya tinggal JUDUL
    KOMENTAR "Google Sheets ΓÇö Sumber Data Sync" (baris 67-77), sedangkan array
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
    "config cm memuat kunci google sheets..." GAGAL ΓÇö 1 failed, 2 passed).
    Seluruh suite 449 test hijau (1967 assertions).
    **Pelajaran: (1) pesan galat yang menyuruh "isi .env" belum tentu benar ΓÇö
    periksa dulu apakah kunci `config()`-nya memang ada di folder `config/`,
    karena `config('x.y')` yang tidak terdefinisi mengembalikan null tanpa
    error apa pun (blok komentar yang tertinggal adalah jejaknya);
    (2) audit "kunci config dipakai vs terdefinisi" murah dilakukan: regex
    `config\('cm\.` atas `app/`, `resources/`, `tests/` lalu bandingkan dengan
    `Config::has()` ΓÇö jalankan setiap habis menyunting berkas di `config/`;
    (3) `.env` boleh memakai kutip dan spasi setelah tanda `=`
    (`KEY= "https://..."`) ΓÇö terbukti dibaca utuh oleh phpdotenv, jadi jangan
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
    memakai `--protocol http2` ΓÇö `tools\jaga-service-prod.ps1` baris 143 (mode
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
    origin/edge gagal, sedangkan 530/1033 = connector TIDAK ada ΓÇö bedakan
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
    KEDUA berkas PROD (LF-saja harus 0), karena `.gitattributes` baru dibaca
    git pada checkout yang sama. Perintah periksa (PowerShell, hanya membaca):
    `[IO.File]::ReadAllBytes('C:\laragon\www\oleochemicalReport-prod\start-report.bat')`
    lalu hitung pasangan byte 13+10; hasil benar = `start-report.bat` CRLF 174 /
    LF-saja 0 dan `stop-report.bat` CRLF 161 / LF-saja 0. Simulasi checkout
    pada clone bersih 08/10/2026 sudah membuktikan angka itu (sebelum: CRLF 0 /
    LF 38 dan 31; sesudah: 174/0 dan 161/0, working tree bersih, dan berkas
    hasil checkout DIJALANKAN tanpa galat label), jadi kalau hasil di PROD
    berbeda berarti `.gitattributes` belum ikut ter-pull.
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
40. **Dua kendala ALAT (tool) yang membuat agent menyimpulkan hal yang salah
    (09/10/2026).** Keduanya BUKAN bug aplikasi dan BUKAN tanda berkasnya
    kosong/hilang, tetapi hasilnya tampak seperti "berkas tidak ada isinya"
    sehingga sesi bisa berputar-putar tanpa kemajuan.
    (a) **`read_files` dengan rentang baris (`start_line`/`end_line`) atas
    berkas yang SUDAH pernah dibaca utuh di sesi yang sama dijawab
    `[outdated - see the latest file content]` TANPA isi**, dan panggilannya
    tetap dilaporkan `success: true` sehingga terlihat seperti berhasil. Ini
    pernah dicatat di Keputusan Arsitektur poin 22, tetapi kejadian 09/10/2026
    membuktikan dampaknya lebih luas: berkas yang isinya baru TERPOTONG
    (bukan dibaca utuh) juga bisa kena, dan bila agent menganggap "hasil kosong
    = isi tidak diketahui = berkas bermasalah", pekerjaan berhenti padahal
    berkasnya utuh. Jalan keluar yang TERBUKTI berhasil, pakai yang pertama:
    (1) baca ULANG berkasnya TANPA rentang (baca utuh) ΓÇö isinya keluar normal;
    (2) cetak rentangnya lewat PowerShell sebagai BACAAN SAJA
    (`(Get-Content -LiteralPath <berkas>)[a..b]` + nomor baris). JANGAN menulis
    berkas lewat PowerShell (Aturan Wajib Edit File poin 7 tetap berlaku).
    (b) **Hasil `run_commands` kadang kembali sebagai
    `[migration] Tool result missing in legacy conversation history.`** ΓÇö
    SELURUH keluaran perintah hilang, jadi tidak ada bukti apa pun (bukan
    berarti perintahnya gagal, bukan berarti perintahnya tidak dijalankan).
    Bila agent lalu berasumsi "perintahnya diam = berkas tidak ada / kode tidak
    ada", kesimpulannya salah total. Aturan: (1) setiap panggilan
    `run_commands` yang kesimpulannya dipakai WAJIB memuat penanda keluaran
    yang disengaja (mis. `Write-Output 'UJI-SEDERHANA-OK'` atau `COUNT=...`)
    supaya hasil yang hilang bisa dibedakan dari hasil yang benar-benar kosong;
    (2) begitu hasilnya hilang, ULANGI perintahnya (boleh dipecah lebih kecil)
    sebelum menyimpulkan apa pun; (3) jangan pernah menyimpulkan "berkas tidak
    ada/kosong" dari keluaran yang HILANG ΓÇö buktikan dengan panggilan kedua
    yang memuat penanda.
    **Pelajaran: hasil tool yang kosong/hilang adalah masalah PENGAMATAN, bukan
    bukti tentang keadaan repo. Sebelum melaporkan "tidak ada kendala" atau
    "berkasnya kosong", pastikan dulu hasilnya benar-benar terbaca (baca utuh
    tanpa rentang + perintah dengan penanda).**







41. **19 berkas/folder sampah menumpuk di copy DEV (ditambah 184 view
    terkompilasi yang sudah yatim) karena tidak ada aturan kebersihan repo
    (12/10/2026).** Ditemukan saat user meminta pembersihan: skrip uji
    sementara (`_cek_popup_jadwal.php`, `tmp-cek-aset.php`,
    `compare-it-assets.php`), cadangan
    `resources/views/cm/overview.blade.php.bak`, partial yatim
    `resources/views/cm/_top-vibrasi-table.blade.php`, dua keluaran
    `Project_BOT20260908_13*.txt`, dua CSV hasil export sekali pakai di
    `storage/app/`, 8 berkas `sensor-suhu-esp32/_tmp_*.txt`, 2 folder
    `__pycache__`, dan 184 view yatim di `storage/framework/views` (sisa blade
    yang lebih dulu dihapus). Semuanya dihapus 12/10/2026 di copy DEV
    `C:\laragon\www\oleochemicalReport` (commit `755c8c0`), lalu
    `php artisan view:clear` + `view:cache` dijalankan ulang (164 berkas
    terkompilasi, 0 yatim) dan suite tetap hijau (541 test, 2567 assertions).
    Sisa pekerjaan sesi lain yang SENGAJA tidak dihapus karena masih dipakai:
    modul CM/Google Drive yang belum di-commit,
    `storage/app/private/shift-schedules` (74 xlsx, dirujuk
    `ShiftScheduleController`), `database.sqlite`, `export.ps1`, dan `.kilo/`.
    **Pelajaran: (1) sampah berkas tidak pernah ketahuan dari test ΓÇö hanya dari
    `git status --short` dan pemeriksaan view terkompilasi; (2) setiap kali
    berkas blade dihapus, `php artisan view:clear` WAJIB dijalankan karena view
    lama tetap tersimpan dan tetap bisa melayani rutenya; (3) sebelum menghapus
    apa pun, pastikan dulu berkas itu memang yatim/sekali pakai
    (`search_codebase` untuk blade, pemeriksaan rujukan kode untuk berkas data) ΓÇö
    `storage/app/private/shift-schedules` terlihat seperti tumpukan berkas
    sampai terbukti dirujuk `ShiftScheduleController`. Aturan lengkapnya ada di
    bagian "ATURAN WAJIB: JANGAN TINGGALKAN SAMPAH BERKAS".**

42. **Riwayat GitHub dan DEV BERCABANG karena commit dikerjakan di copy lain,
    sementara alur deploy masih memakai repo hub lokal (13/10/2026).** Latar:
    alur yang dikehendaki user adalah DEV -> GitHub -> PROD, tetapi
    `tools\deploy-laporan-prod.ps1` dan `tools\deploy-prod.ps1` masih
    `git push hub main` ke repo bare lokal lalu PROD menarik dari repo itu.
    Pemeriksaan 13/10/2026 09:57 menemukan hal yang lebih berbahaya: ref
    `origin/main` di DEV BASI (menunjuk `89b9b15`, berkas ref ditulis
    12/10/2026 14:09) sementara GitHub sebenarnya sudah di `98e7001` - tiga
    commit baru (`c5b663b` blok `config/cm.php` google_sheets, `b565766` default
    Overview CM bulan terakhir, `98e7001` nama `package-lock.json`) dibuat
    `erikadam <erikbutak@gmail.com>` pukul 09:48 dari copy lain (PC lain),
    sehingga `git rev-list --left-right --count origin/main...main` = `3 113`.
    Pelajaran: (a) `git branch -vv` yang berbunyi "ahead 113" TIDAK membuktikan
    push akan fast-forward -- ref remote-tracking lokal bisa basi, jadi WAJIB
    `git fetch` (atau `git ls-remote`) dulu sebelum menyimpulkan aman;
    (b) `git ls-remote` dengan `-c credential.helper=` membuktikan repo GitHub
    ini PUBLIK (bisa dibaca siapa saja), jadi copy PROD boleh menarik tanpa
    kredensial.
    Tindakan 13/10/2026 (copy DEV `C:\laragon\www\oleochemicalReport`):
    (a) `git merge origin/main` -> konflik HANYA di `config/cm.php` (blok
    `google_sheets` ditambahkan di dua sisi; versi DEV dipakai karena
    komentarnya lebih rapi; `package-lock.json`, `CmController.php`, dan
    `overview.blade.php` ter-merge otomatis) -> commit `5e3ad5b`, seluruh suite
    **549 test hijau (2620 assertions)** + `php artisan view:cache` sukses;
    (b) kedua skrip deploy dialihkan ke GitHub: `deploy-prod.ps1` push ke
    `origin` (fatal bila gagal) lalu `hub` (peringatan saja), mengarahkan remote
    `origin` copy PROD ke GitHub, `fetch` + `merge --ff-only FETCH_HEAD` dengan
    CADANGAN path repo hub lokal bila GitHub tidak bisa dihubungi, plus penjaga
    baru `TolakPullBilaProdKotor()` yang membatalkan pull bila ada berkas PROD
    yang belum di-commit PADA BERKAS YANG IKUT BERUBAH (hanya peringatan bila
    tidak bentrok); `deploy-laporan-prod.ps1` push ke GitHub + hub dan membaca
    commit yang akan dipasang LANGSUNG dari GitHub (`git ls-remote`) sehingga
    ringkasannya sekaligus membuktikan push sampai;
    (c) helper baru `PanggilGit` (menurunkan `ErrorActionPreference` sementara
    supaya tulisan stderr git tidak menghentikan skrip, bandingkan insiden #31)
    dipakai untuk semua perintah git yang menulis ke stderr, dan `KomitHash`
    (`rev-parse`) untuk membandingkan hash GitHub dengan hash DEV/PROD.
    Jebakan yang harus diingat: copy PROD memuat berkas yang DISENTUH MANUAL
    (`resources/views/reports/partials/_temuan-kartu.blade.php`, label
    "Temuan (Opsional)" diubah menjadi "Temuan") sehingga penjaga berkas kotor
    itu memang diperlukan; copy DEV juga memuat perubahan belum-commit dari sesi
    lain (`resources/views/reports/partials/_temuan.blade.php` dengan perubahan
    label yang SAMA, `.env.example`, `.gitignore`, dua berkas
    `RENCANA_MODUL_*.md` yang terhapus, `app/Jobs/ProcessCmFindingsImport.php`,
    `?? .kilo/`, dan `?? app/Console/Commands/CmDriveCheck.php`) yang SENGAJA
    tidak ikut di-commit.

43. **`Deploy-Laporan.bat` meng-unggah cabang `main` dari komputer yang
    menekannya (ditemukan dan ditutup 13/10/2026).** Pemeriksaan nyata atas
    berkas di copy DEV `C:\laragon\www\oleochemicalReport`:
    `tools\deploy-laporan-prod.ps1` baris 353-370 versi lama memanggil
    `git push origin main` DARI copy DEV, dan kegagalannya hanya dicatat sebagai
    PERINGATAN (baris 357-360) sehingga deploy TETAP LANJUT memasang apa pun yang
    sudah ada di GitHub; `tools\deploy-prod.ps1` baris 194-208 mengerjakan hal
    yang sama walaupun jalur `-LewatiDev` (yang dipakai pintasan) memang
    melewatinya. Akibatnya dua hal yang sama-sama tidak terlihat pengguna:
    (a) siapa pun yang menekan pintasan di komputer yang `main`-nya masih memuat
    commit belum di-merge akan memasang pekerjaan itu ke production tanpa review;
    (b) bila push-nya ditolak (non-fast-forward), yang terpasang malah commit
    orang lain tanpa pemberitahuan yang jelas. Penutupnya: (i) langkah push
    diganti penjaga baru `tools\penjaga-main-dev.ps1` ΓÇö copy DEV wajib berada pada
    commit `main` GitHub, hanya tertinggal = dimajukan fast-forward, sedangkan
    berbeda/bercabang = deploy DIBATALKAN; (ii) alur kerja bercabang diperkenalkan
    (`tugas/<nama>/<ringkas>` + Pull Request + papan klaim `tugas/<nama>.md` +
    hook `.githooks/pre-push` + skrip `siapkan-clone`/`mulai-tugas`/`unggah-tugas`/
    `cek-sebelum-push`); (iii) aturan lengkapnya ada di bagian "ALUR KERJA BANYAK
    TEKNISI" di atas.
    **Pelajaran: (1) perintah yang mengubah keadaan bersama (push/deploy) WAJIB
    DIBATALKAN saat keadaan tidak seperti yang diharapkan ΓÇö mencatat "PERINGATAN"
    lalu lanjut adalah cara paling halus untuk memasang versi yang salah;
    (2) skrip deploy harus membuktikan sumber commit-nya lewat `ls-remote` (bukan
    ref remote-tracking lokal yang bisa basi) dan memperlakukan perbedaan sebagai
    KESALAHAN; (3) hook git TIDAK pernah ikut ter-clone karena `core.hooksPath`
    bawaan menunjuk `.git/hooks` di komputer masing-masing, jadi aturan berbasis
    hook WAJIB disertai langkah pemasangan sekali per clone (`core.hooksPath` ke
    folder `.githooks` yang ikut repository).**

44. **Man hour terhitung DOBEL karena satu angka dibaca dari kolom durasi
    tersimpan sementara angka lain dihitung dari jam laporan (13/10/2026).**
    Keluhan user: "laporan di luar jam kerja atau hari libur malah jadi dobel,
    mis. kerja 6 jam di hari Minggu bagi reguler jadi 12 jam kerja".
    Penyelidikan: `ManHourService::harian()` menjumlahkan kolom
    `reports.work_duration_minutes` sebagai jam kerja NORMAL, PADAHAL kolom itu
    pada laporan lama masih memuat durasi PENUH (angka dari sebelum aturan
    istirahat/lembur dipasang), dan pada saat yang sama method itu menghitung
    LEMBUR dari jam laporan ΓÇö menit yang sama muncul di kedua angka (contoh
    nyata PROD `id=251`: rentang 30 menit tampil 30 menit normal + 30 menit
    lembur). Pemeriksaan data membuktikan 391 laporan PROD terpengaruh (172 di
    antaranya benar-benar tampil berlebih = 33.190 menit), sedangkan DEV
    `oleochemical_dev` bersih (0 baris) karena durasinya sudah pernah dipangkas.
    Perbaikan: satu sumber angka ΓÇö man hour SELALU dihitung dari jam laporan
    (`JamKerjaService::ringkasJamKerja()`), kolom durasi hanya cadangan bila
    laporan tidak punya jam mulai/selesai; sekaligus jam istirahat tidak lagi
    dihitung lembur (`lembur = total - dalamJendela`).
    **Pelajaran: (1) JANGAN mencampur satu angka yang dibaca dari kolom
    tersimpan dengan angka lain yang dihitung dari data mentah ΓÇö keduanya
    berbeda begitu aturannya berubah, dan selisihnya muncul sebagai angka dobel
    TANPA error apa pun; hitung keduanya dari sumber yang sama; (2) periksa data
    NYATA di setiap database sebelum menyimpulkan luasnya dampak: "DEV bersih"
    bukan bukti bug tidak ada (di sini DEV 0 baris, PROD 391 baris); (3) kolom
    hasil hitungan yang boleh basi WAJIB punya perintah penyama
    (`reports:pangkas-istirahat`) DAN pembacanya harus tetap benar walau kolom
    itu belum disamakan, supaya perbaikan tidak bergantung pada langkah manual
    di produksi.**

45. **Halaman Finding CM tidak ikut berubah padahal spreadsheet Google sudah
    memuat baris tanggal baru (dilaporkan 13/10/2026).** Gejala: baris
    "13 Oct 2026" sudah ada di sheet Finding CM, tetapi aplikasi masih
    menampilkan data sampai 12/10/2026. Penyebabnya BUKAN URL/config (insiden
    #36 sudah menutup itu) dan bukan pula download yang gagal: `SyncGoogleSheetsJob`
    hanya men-DOWNLOAD berkas lalu menjadwalkan `ProcessCmFindingsImport` /
    `ProcessCmExcelImport` ke queue, sedangkan DEV memang TIDAK menjalankan
    service `queue:work` (bagian service di atas). Akibatnya job menumpuk di
    tabel `jobs`, baris `import_logs` tertinggal berstatus 'processing', dan
    data tidak pernah masuk ΓÇö tanpa satu pun pesan error di layar.
    Bukti: `oleochemical_dev.jobs` masih berisi job CM yang belum diambil dan
    `import_logs` terakhir 'processing'; setelah job dijalankan, 15 baris
    tanggal 13/10/2026 masuk (insert_baru=15, log #71) dan halaman menampilkan
    Total Finding 466.
    Penutupnya: (i) kunci config baru `cm.google_sheets.import_mode`
    (`CM_SYNC_IMPORT_MODE`) ΓÇö bawaan 'inline' bila `APP_ENV=local` (import
    dijalankan langsung di proses yang sama, tanpa `queue:work`) dan 'queue'
    di luar local sehingga perilaku PROD TIDAK berubah; (ii) opsi
    `php artisan cm:sync-sheets --langsung` untuk memaksa import langsung
    kapan saja (mis. saat worker PROD sedang mati); (iii) jaring pengaman
    `SyncGoogleSheetsJob::runImportNow()` supaya baris `import_logs` tidak
    pernah tertinggal 'processing' bila import langsung melempar error;
    (iv) penjaga uji `tests/Feature/CmSyncImportModeTest.php`.
    **Pelajaran: (1) "sudah dijadwalkan" bukan berarti "sudah jalan" ΓÇö pada
    mesin tanpa worker, sync yang dilaporkan sukses hanya memindahkan masalah
    ke tabel `jobs`; (2) setiap alur ber-queue wajib punya mode/pengaman untuk
    lingkungan tanpa `queue:work`, jangan mengandalkan ingatan operator;
    (3) copy PROD kini TIDAK ada di komputer DEV ini (tidak ada folder
    `oleochemicalReport-prod`, tidak ada proses php, tidak ada listener port
    8000); aplikasi yang dipakai kantor dilayani `http://192.168.41.74:8000`
    (`GET /debug-login` = 404 = PROD) dan MySQL-nya MENOLAK koneksi dari
    komputer ini, jadi perbaikan data PROD wajib dijalankan di komputer itu
    (mis. `php artisan cm:sync-sheets --langsung`), bukan dari copy DEV.**
    Catatan penomoran: nomor 44 di atas sudah terpakai cabang
    `tugas/erik/lembur-tanpa-jam-istirahat-man-hour-tidak-dobel` yang SUDAH
    DI-MERGE lewat Pull Request #2 (merge commit `f9b65c9`, 14/10/2026), jadi
    entri ini memakai 45 supaya tidak ada nomor kembar. Kesesuaian nomor itu
    diperiksa ulang saat rebase cabang ini ke `main` (14/10/2026).



46. **Fast-forward ditolak karena berkas belum di-commit, dan pesan lamanya
    menyalahkan hal yang salah (14/10/2026).** Deploy pagi itu DIBATALKAN penjaga
    walau copy DEV hanya tertinggal satu commit: `main` GitHub sudah `ec1154d`
    (PR #3), `main` DEV masih `f9b65c9`, dan `.env.example` yang diubah sesi lain
    di copy DEV juga diubah PR itu sehingga `git merge --ff-only` menolak
    ("Your local changes to the following files would be overwritten by merge" /
    "Please commit your changes or stash them before you merge"). Pesan penjaga
    saat itu hanya berbunyi "copy DEV tidak bisa dimajukan dengan fast-forward"
    lalu menyuruh `git checkout main` + `git pull --ff-only origin main` - DUA
    perintah yang gagal dengan sebab yang SAMA, jadi tidak menolong. Perbaikan
    14/10/2026 (copy DEV): alat BARU `tools\siapkan-deploy.ps1` (cadangkan ke
    `C:\laragon\backup-oleochemical\siapkan-deploy\dev-<cap waktu>\` -> `git stash`
    hanya berkas penghalang -> fast-forward -> kembalikan perubahannya sebagai
    perubahan yang TETAP belum di-commit, tanpa commit baru; bentrok dua sisi
    digabung otomatis, `-JanganGabung`/`-Kering` untuk mode hati-hati); penjaga
    dan `kembalikan-ke-main.ps1` kini menyebut nama berkas penghalang
    (`PENGHALANG=`) dan menunjuk skrip itu; `deploy-laporan-prod.ps1` tidak lagi
    menyarankan `git checkout main`/`pull --ff-only` yang menyesatkan.
    Uji: `tests/Feature/SiapkanDeployTest.php` (10 test, 60 assertions; repo git
    tiruan di folder sementara, copy DEV/PROD tidak disentuh). Uji mutasi
    14/10/2026: membiarkan `$penghalang` kosong DAN mematikan penggabungan
    bentrok membuat 3 test GAGAL (10 test hijau sesudah dikembalikan).
    **Pelajaran: (1) bila fast-forward ditolak, BEDAKAN dua sebabnya - berkas
    belum di-commit yang juga berubah di commit tujuan (git menolak menimpa)
    versus cabang/commit yang berbeda; petunjuk yang salah membuat pengguna
    mengulang perintah yang sama-sama gagal; (2) `git status --porcelain` yang
    sudah di-Trim kehilangan spasi pertamanya, sehingga pemotongan path WAJIB
    memakai `Substring(2)` - `Substring(3)` memotong huruf pertama nama berkas
    sehingga berkas penghalang TIDAK PERNAH ditemukan (bug ini nyata terjadi dan
    ketahuan dari uji nyata ke repo tiruan, BUKAN dari peninjauan kode);
    (3) pekerjaan sesi lain yang belum di-commit tidak boleh hilang maupun ikut
    ter-commit saat deploy: ubah hanya berkas yang menghalangi, cadangkan dulu,
    kembalikan sebagai perubahan belum di-commit (jangan pernah membuat commit
    atas nama orang lain, dan jangan memakai `git reset --hard`/`git checkout -- .`).**

47. **Deploy tertahan karena DEV masih di cabang tugas yang punya commit
    catatan PR setelah fitur di-merge (20/10/2026).** Gejala: `Deploy-Laporan.bat`
    dibatalkan penjaga dengan `commit DEV 350a24c` (cabang
    `tugas/erik/laporan-kolaborasi-shift-tampil-di-tab-shift`) sedangkan
    `commit GitHub main 05be5ce`; pesan menyebut "copy DEV tidak berada pada
    commit main GitHub". Fitur sebenarnya SUDAH masuk main lewat PR #25
    (`82830c6`), dan PR #22 (Sparepart), #23/#24 (Dashboard) juga sudah di-merge.
    Satu-satunya commit yang belum masuk main adalah `350a24c` yang HANYA
    mengganti nama catatan PR menjadi
    `tugas/pr-laporan-kolaborasi-shift-tampil-di-tab-shift.md` ΓÇö bukan kode.
    Penyebab: setelah PR di-merge, masih dibuat commit tambahan di cabang tugas
    (berkas catatan PR), lalu deploy dijalankan dari cabang itu. Penjaga
    bertindak benar: deploy hanya boleh dari `main` yang tepat sejajar GitHub.
    Penutup: `tools\kembalikan-ke-main.ps1` memindahkan DEV ke `main` `05be5ce`;
    penjaga melaporkan `SEJAJAR=1`, `CARA=sejajar`. PROD tidak disentuh dan
    backup DB sudah dibuat
    (`C:\laragon\backup-oleochemical\db\oleochemical-20261020-084447.sql`).
    Skrip itu tetap mencetak `KEMBALI_MAIN=BELUM` karena ujung cabang tugas
    (commit catatan) belum di-merge; penentu siap deploy adalah penjaga
    (`SEJAJAR=1`), bukan status ujung cabang tugas.
    **Pelajaran: (1) semua catatan PR, termasuk berkas `tugas/pr-*.md`, WAJIB
    di-commit SEBELUM PR dibuat/di-merge; jangan menambah commit ke cabang tugas
    setelah PR-nya di-merge. (2) Deploy hanya dari `main`; jangan pernah
    menjalankan `Deploy-Laporan.bat` dari cabang tugas. (3) Bila
    `kembalikan-ke-main.ps1` mengatakan `KEMBALI_MAIN=BELUM` tetapi penjaga
    `SEJAJAR=1` dan commit fitur sudah ada di `origin/main`, periksa dulu apakah
    commit yang tertinggal hanya administratif (mis. catatan PR) ΓÇö jangan
    reset/hapus commit atau branch. Periksa dengan
    `git log --oneline origin/main -5` dan
    `git merge-base --is-ancestor <commit-fitur> origin/main`. (4) Setelah DEV
    kembali ke main, deploy boleh diulang; berkas kotor sesi lain tidak ikut
    di-deploy karena deploy memakai commit main, bukan working tree.**

48. **`/cuti` tetap memunculkan klasifikasi pekerjaan karena hanya dipasang di
    webhook, sedangkan bot PROD memakai poller (20/10/2026).** Gejala: teknisi
    mengetik `/cuti`, tetapi bot membalas pilihan klasifikasi pekerjaan seperti
    laporan biasa. Penyebab: fitur cuti ditambahkan ke
    `TelegramWebhookController`, padahal bot PROD dijalankan lewat
    `php artisan telegram:poll` -> `TelegramMessageHandlerTrait`
    (`PollTelegramUpdates`). Webhook tidak dipakai pada alur PROD, sehingga
    perintah jatuh ke `handleReport()` dan masuk wizard klasifikasi. Perbaikan:
    `handleCutiCommand()` ditambahkan ke trait, tepat sebelum routing wizard/
    `handleReport()`, dengan alasan setelah `/cuti` disimpan ke token. Uji
    `CutiTest` menambah jalur poller (anonymous class memakai trait) dan
    memastikan tidak ada laporan/klasifikasi yang dibuat. **Pelajaran: (1) satu
    bot bisa punya DUA pintu masuk (webhook dan poller); perubahan perintah
    harus dipasang di keduanya atau dijadikan satu router bersama; (2) uji
    jalur yang benar-benar dipakai PROD, bukan hanya endpoint webhook; (3)
    perintah seperti `/cuti` wajib dicek SEBELUM wizard, karena wizard aktif
    akan menelan teks apa pun sebagai jawaban langkah.**

49. **Tautan cuti langsung "tidak berlaku" setelah `/cuti` (20/10/2026).**
    Gejala: teknisi baru mengetik `/cuti`, membuka tautan, tetapi halaman
    menyatakan tautan kedaluwarsa/dipakai. Bukti PROD `cuti_tokens`:
    token id=1 dibuat 09:44:38, dibuka 09:44:39, lalu `used_at` 09:44:53 ΓÇö
    tepat saat token id=2 dibuat. Artinya `/cuti` kedua (atau pemrosesan
    ganda) membatalkan token pertama yang sedang dibuka. Penyebab:
    `CutiService::buatTautan()` menandai SEMUA token lama yang belum dipakai
    sebagai `used_at`, termasuk token yang sudah `opened_at`. Perbaikan:
    hanya token lama yang **belum pernah dibuka** yang dibatalkan; token yang
    sudah dibuka tetap berlaku sampai kedaluwarsa. Uji baru memastikan form
    pertama tetap bisa dibuka setelah tautan kedua dibuat.
    **Pelajaran: (1) jangan membatalkan tautan yang sedang diisi hanya karena
    ada permintaan tautan baru; (2) bedakan "belum dipakai" dan "belum pernah
    dibuka"; (3) saat tautan dilaporkan tidak berlaku, periksa timestamp
    created/opened/used di `cuti_tokens` ΓÇö selisih detik menunjukkan token
    baru membatalkan token lama.**

50. **Tautan cuti tetap "tidak berlaku" di PROD karena pratinjau tautan
    Telegram (20/10/2026).** Gejala: setelah `/cuti`, tautan dibuka tetapi
    halaman menyatakan tidak berlaku. Bukti PROD: token id=3 dibuat 09:54:01,
    `opened_at` 09:54:03, `used_at` kosong; tabel `sessions` **tidak memiliki**
    session dengan `cuti_token_id`. Artinya URL dibuka oleh pratinjau
    Telegram (tanpa cookie), bukan browser teknisi. `opened_at` sudah terisi,
    lalu GET dari browser teknisi dianggap "dibuka di perangkat lain" dan
    ditolak. Perbaikan: (1) GET form tidak lagi menolak session baru hanya
    karena `opened_at` terisi ΓÇö tautan boleh dibuka ulang sampai
    `used_at`/kedaluwarsa; (2) submission tetap membutuhkan session dari GET
    terakhir; (3) pesan bot mematikan `disable_web_page_preview`. Uji
    menambahkan skenario pratinjau tanpa cookie lalu membuka dari browser lain.
    **Pelajaran: (1) `opened_at` BUKAN bukti user session ΓÇö crawler/pratinjau
    aplikasi juga membuka URL; (2) binding session sebaiknya divalidasi saat
    submit, bukan saat GET; (3) matikan preview untuk tautan form berisi data
    pribadi.**

51. **"Laporan Teknisi" di detail equipment CM selalu kosong padahal laporannya
    ada (19/10/2026).** Gejala: user membuka equipment CM dan card "Laporan
    Teknisi (Daftar Perbaikan)" menampilkan 0 laporan, walau teknisi sudah
    mengirim laporan untuk alat itu lewat bot Telegram. Penyelidikan: aturan
    tampilnya sudah benar (hanya laporan BERTAG, lihat `docs/19`), tetapi datanya
    tidak pernah bertag ΓÇö wizard Telegram hanya menyimpan `asset_id` sehingga
    `reports.equipment_tag` selalu NULL, dan backfill lama mengisi kolom itu
    dengan `assets.equipment_no` (mis. `10002840`), padahal CM memakai
    `assets.tech_ident_no` (mis. `6400P5`). Perbaikan: `Report::saving()` dan
    `WizardReportSaverTrait` mengisi `equipment_tag` dari Tech Ident No (tag
    yang sudah diisi manual tidak ditimpa);
    `CmController::whereLaporanEquipment()` dan `AssetInfoService::laporanAsset()`
    memakai tiga jalur pencocokan; laporan lama tanpa tag/asset dicocokkan lewat
    `EquipmentMentionService` (batas kata + longest match, bukan `LIKE`),
    sehingga `2-6163P8` tidak muncul di `6163P8`; command
    `reports:tag-equipment` merapikan data lama (simulasi dulu, `--apply` untuk
    menyimpan) dan melewati laporan yang menyebut lebih dari satu equipment.
    Uji: `ReportEquipmentTagTest` (5 test) + tambahan pada
    `CmEquipmentLaporanTeknisiTest`; seluruh `php artisan test` hijau
    (701 test, 3440 assertions saat PR dibuat). Tidak ada migrasi.
    **Pelajaran: (1) "tampilan kosong" belum tentu bug tampilan ΓÇö periksa dulu
    kolom yang dipakai untuk mencocokkan, di sini `equipment_tag` memang tidak
    pernah terisi; (2) satu kunci equipment harus konsisten di SEMUA penulis
    data: backfill yang memakai `equipment_no` membuat halaman CM mustahil cocok
    walaupun datanya "ada"; (3) pencocokan teks untuk data lama harus PERSIS
    (batas kata + longest match), karena `LIKE '%tag%'` diam-diam menampilkan
    equipment lain dan justru melanggar permintaan "jangan sampai salah
    equipment"; (4) perbaikan data lama WAJIB punya mode simulasi sebelum
    `--apply`, dan jangan menebak saat satu laporan menyebut beberapa
    equipment.**
