# Riwayat Insiden 16-32

> Bagian dari dokumentasi proyek di folder `docs`.
> Peta dokumen: `AGENTS.md` di akar repo.

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
