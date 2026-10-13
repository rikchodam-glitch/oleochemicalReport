<#
    deploy-laporan-prod.ps1 — Deploy terpandu ke copy PROD dari satu klik
    (dipakai pintasan Desktop "Deploy-Laporan.bat").

    Urutan kerja:
      1. Validasi path (DEV, PROD, php.exe, mysqldump).
      2. Backup database PROD (mysqldump) ke C:\laragon\backup-oleochemical\db
         dan menyisakan sejumlah backup terbaru saja.
      3. Penjaga alur kerja banyak teknisi (tools\penjaga-main-dev.ps1): copy DEV
         yang menjalankan pintasan ini WAJIB berada tepat pada commit cabang main
         di GitHub. Bila hanya tertinggal, copy DEV dimajukan otomatis
         (fast-forward); bila berbeda/bercabang, deploy DIBATALKAN. Sejak
         13/10/2026 langkah ini TIDAK lagi mengunggah apa pun ke GitHub karena
         main hanya boleh diisi lewat Pull Request (lihat AGENTS.md bagian
         "ALUR KERJA BANYAK TEKNISI").
      4. Ringkasan: commit PROD sekarang, commit yang akan dipasang (dibaca
         langsung dari GitHub), migrasi tertunda, dan berkas DEV yang belum
         di-commit (hanya keterangan).
      5. Konfirmasi manusia sebelum melanjutkan.
      6. Deploy lewat tools\deploy-prod.ps1 (-LewatiDev -LewatiComposer
         -BangunAset -LewatiService): pull, migrate --force, config:cache,
         view:cache, build aset.
      7. Pastikan PROD keluar dari mode pemeliharaan dan tidak ada migrasi sisa.
      8. Restart SEMUA service latar: hentikan-service-prod.ps1 -BiarkanJadwal
         lalu jaga-service-prod.ps1 -> queue:work, schedule:work, telegram:poll,
         serve:8000 (port 8000), dan tunnel Cloudflare.
      9. Tampilkan status akhir memakai tools\cek-status-prod.ps1.

    Contoh pemakaian:
        powershell -ExecutionPolicy Bypass -File <copy PROD>\tools\deploy-laporan-prod.ps1
        ... -HanyaBackup       hanya backup database, tanpa deploy
        ... -LewatiBackup      deploy tanpa backup (tidak disarankan)
        ... -LewatiBangunAset  lewati npm run build
        ... -LewatiService     jangan restart service latar
        ... -TanpaKonfirmasi   untuk pengujian otomatis tanpa tanya
#>
param(
    [switch]$HanyaBackup,
    [switch]$LewatiBackup,
    [switch]$LewatiBangunAset,
    [switch]$LewatiService,
    [switch]$TanpaKonfirmasi,
    [int]$SimpanBackup = 10
)

$ErrorActionPreference = 'Stop'

# ==== Konfigurasi path (ubah bila folder dipindah/di-rename) ====
$DevPath      = 'C:\laragon\www\oleochemicalReport'
$ProdPath     = 'C:\laragon\www\oleochemicalReport-prod'
$PhpExe       = 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe'
$MySqlDump    = 'C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysqldump.exe'
# Kanal deploy (diputuskan user 13/10/2026, diperbarui 13/10/2026): GitHub =
# sumber resmi; cabang main di GitHub hanya boleh diisi lewat Pull Request,
# sehingga skrip ini TIDAK lagi mengunggah apa pun (lihat tools\penjaga-main-dev.ps1).
# Repo hub lokal DIPERTAHANKAN sebagai cadangan/offline saja.
$RepoUrl      = 'https://github.com/rikchodam-glitch/oleochemicalReport.git'
$HubRemote    = 'hub'
$Branch       = 'main'
$FolderBackup = 'C:\laragon\backup-oleochemical'
$FolderDb     = Join-Path $FolderBackup 'db'
$LogBackup    = Join-Path $FolderBackup 'backup-db.log'
$FileEnv      = Join-Path $ProdPath '.env'
$SkripDeploy  = Join-Path $ProdPath 'tools\deploy-prod.ps1'
$SkripStop    = Join-Path $ProdPath 'tools\hentikan-service-prod.ps1'
$SkripJaga    = Join-Path $ProdPath 'tools\jaga-service-prod.ps1'
$SkripStatus  = Join-Path $ProdPath 'tools\cek-status-prod.ps1'

<#
    Cetak pesan progres deploy.

    Parameter: $Pesan = teks yang dicetak.
    Return  : tidak ada.
#>
function Tulis([string]$Pesan) {
    Write-Host ('[deploy] ' + $Pesan)
}

<#
    Catat aktivitas backup ke berkas log di folder backup.

    Parameter: $Pesan = teks yang dicatat.
    Return  : tidak ada.
#>
function Catat([string]$Pesan) {
    if (-not (Test-Path $FolderBackup)) {
        New-Item -ItemType Directory -Force -Path $FolderBackup | Out-Null
    }

    $waktu = (Get-Date).ToString('yyyy-MM-dd HH:mm:ss')
    Add-Content -Path $LogBackup -Value ('[' + $waktu + '] ' + $Pesan) -Encoding UTF8
}

<#
    Jalankan perintah luar (exe) sambil menurunkan ErrorActionPreference
    sementara. PowerShell mengubah tulisan stderr perintah luar menjadi galat
    yang menghentikan skrip bila preferensinya 'Stop' (mis. git dan mysqldump
    memang menulis progres ke stderr).

    Parameter: $Perintah = path exe, $Argumen = daftar argumen, $Di = folder kerja.
    Return  : hashtable dengan kunci Keluaran (teks) dan Kode (exit code).
#>
function Panggil([string]$Perintah, [string[]]$Argumen, [string]$Di = '') {
    $lama = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'

    if ($Di -ne '') { Push-Location $Di }

    try {
        $keluaran = & $Perintah @Argumen 2>&1 | ForEach-Object { [string]$_ }
        $kode = $LASTEXITCODE
    } finally {
        if ($Di -ne '') { Pop-Location }
        $ErrorActionPreference = $lama
    }

    return @{ Keluaran = $keluaran; Kode = $kode }
}

<#
    Jalankan skrip PowerShell lain sebagai PROSES TERPISAH supaya keluaran
    progresnya tetap terlihat mengalir dan preferensi galat skrip ini tidak
    tercampur dengan skrip anak (deploy-prod.ps1 memakai Stop + git).

    Parameter: $Skrip = path skrip, $Argumen = daftar argumen.
    Return  : exit code proses anak.
#>
function SkripTerpisah([string]$Skrip, [string[]]$Argumen) {
    # Proses anak dijalankan lewat .NET Process (bukan Start-Process) dengan
    # UseShellExecute = $false TANPA redirect: proses anak mewarisi konsol induk
    # sehingga keluarannya terlihat langsung dan TIDAK lewat pipeline PowerShell.
    # Dua alasan keluaran tidak boleh lewat pipeline:
    #   (1) kalau mengalir ke keluaran fungsi, pemanggil menerima array berisi
    #       teks anak + exit code sehingga "kode -ne 0" salah menuduh gagal;
    #   (2) kalau dipipe, service yang dinyalakan skrip anak (serve/queue/poll)
    #       ikut mewarisi handle pipe sehingga perintah menggantung tanpa akhir.
    # Dipakai $proses.WaitForExit() (menunggu proses anak saja), BUKAN
    # Start-Process -Wait (menunggu seluruh pohon proses termasuk service yang
    # memang dirancang berjalan terus), dan BUKAN Start-Process -PassThru
    # (terbukti mengembalikan ExitCode kosong di komputer ini).
    $argumenTeks = ''
    foreach ($a in $Argumen) { $argumenTeks += ' "' + $a + '"' }

    $info = New-Object System.Diagnostics.ProcessStartInfo
    $info.FileName        = 'powershell.exe'
    $info.Arguments       = '-NoProfile -ExecutionPolicy Bypass -File "' + $Skrip + '"' + $argumenTeks
    $info.UseShellExecute = $false

    $proses = New-Object System.Diagnostics.Process
    $proses.StartInfo = $info
    $null = $proses.Start()
    $proses.WaitForExit()

    return $proses.ExitCode
}

<#
    Jalankan artisan di copy PROD (lokasi kerja selalu PROD) sambil menangkap
    keluarannya.

    Parameter: $Argumen = argumen artisan.
    Return  : exit code artisan.
#>
function ArtisanProd([string[]]$Argumen) {
    $hasil = Panggil $PhpExe (@('artisan') + $Argumen) $ProdPath
    return $hasil.Kode
}

<#
    Baca pasangan kunci=nilai dari .env copy PROD (tanda kutip pembungkus
    dibuang). Kredensial TIDAK ditulis di dalam skrip ini supaya penggantian
    user/kata sandi database tidak perlu menyunting skrip.

    Parameter: tidak ada.
    Return  : hashtable isi .env (kosong bila berkas tidak ada).
#>
function BacaEnv {
    $hasil = @{}

    if (-not (Test-Path $FileEnv)) { return $hasil }

    foreach ($baris in (Get-Content $FileEnv)) {
        $teks = ([string]$baris).Trim()

        if ($teks -eq '' -or $teks.StartsWith('#') -or -not $teks.Contains('=')) { continue }

        $bagian = $teks.Split('=', 2)
        $kunci  = $bagian[0].Trim()
        $nilai  = $bagian[1].Trim()

        if ($nilai.Length -ge 2 -and $nilai.StartsWith('"') -and $nilai.EndsWith('"')) {
            $nilai = $nilai.Substring(1, $nilai.Length - 2)
        }

        $hasil[$kunci] = $nilai
    }

    return $hasil
}

<#
    Hapus berkas backup paling lama sehingga folder hanya menyimpan
    $SimpanBackup berkas terbaru.

    Parameter: tidak ada.
    Return  : tidak ada.
#>
function PangkasBackup {
    $semua = @(Get-ChildItem -Path $FolderDb -Filter 'oleochemical-*.sql' -ErrorAction SilentlyContinue |
        Sort-Object LastWriteTime -Descending)

    if ($semua.Count -le $SimpanBackup) { return }

    foreach ($berkas in ($semua | Select-Object -Skip $SimpanBackup)) {
        Remove-Item $berkas.FullName -Force -ErrorAction SilentlyContinue
        Catat ('hapus backup lama: ' + $berkas.Name)
    }
}

<#
    Buat backup database PROD dengan mysqldump. Berkas yang gagal (kode keluar
    bukan 0 atau ukurannya tidak wajar) langsung dihapus supaya tidak ada
    backup palsu yang dianggap valid.

    Parameter: tidak ada (memakai .env PROD dan konfigurasi di atas).
    Return  : path berkas backup, atau $null bila gagal.
#>
function BackupDatabase {
    New-Item -ItemType Directory -Force -Path $FolderDb | Out-Null

    $isi   = BacaEnv
    $stamp = (Get-Date).ToString('yyyyMMdd-HHmmss')
    $tujuan = Join-Path $FolderDb ('oleochemical-' + $stamp + '.sql')

    # Tiap elemen WAJIB dikurung: di dalam array literal, koma berikatan lebih
    # kuat daripada '+' sehingga @('a' + $x, 'b' + $y) menyatu jadi SATU elemen.
    $argumen = @(
        ('--host=' + $isi['DB_HOST']),
        ('--port=' + $isi['DB_PORT']),
        ('--user=' + $isi['DB_USERNAME']),
        '--single-transaction',
        '--routines',
        '--triggers',
        '--default-character-set=utf8mb4',
        ('--result-file=' + $tujuan),
        $isi['DB_DATABASE']
    )

    # Kata sandi hanya dikirim bila memang diisi.
    if ($isi['DB_PASSWORD'] -ne '') { $argumen += ('--password=' + $isi['DB_PASSWORD']) }

    $hasil  = Panggil $MySqlDump $argumen
    $ukuran = 0

    if (Test-Path $tujuan) { $ukuran = (Get-Item $tujuan).Length }

    if ($hasil.Kode -ne 0 -or $ukuran -lt 1024) {
        $pesan = (@($hasil.Keluaran | Select-Object -First 3) -join ' | ')
        Catat ('GAGAL backup ' + $tujuan + ' (kode=' + $hasil.Kode + ', ukuran=' + $ukuran + ') ' + $pesan)
        Tulis ('Backup GAGAL. Pesan mysqldump: ' + $pesan)

        if (Test-Path $tujuan) { Remove-Item $tujuan -Force }

        return $null
    }

    Catat ('backup OK ' + $tujuan + ' (' + [math]::Round($ukuran / 1KB, 1) + ' KB)')
    PangkasBackup

    return $tujuan
}

<#
    Daftar migrasi yang belum dijalankan di PROD (migrate:status --pending).

    Parameter: tidak ada.
    Return  : array baris migrasi tertunda (kosong bila tidak ada).
#>
function DaftarMigrasiPending {
    $hasil  = Panggil $PhpExe @('artisan', 'migrate:status', '--pending') $ProdPath
    $daftar = @()

    foreach ($baris in @($hasil.Keluaran)) {
        $teks = ([string]$baris).Trim()

        if ($teks -eq '') { continue }
        if ($teks -like '*No pending migrations*') { continue }
        if ($teks -like '*Pending*') { $daftar += $teks }
    }

    return $daftar
}

<#
    Ambil satu baris commit terakhir sebuah repo git.

    Parameter: $Repo = path repo.
    Return  : teks commit ringkas (string kosong bila gagal).
#>
function KomitTerakhir([string]$Repo) {
    $hasil = Panggil 'git' @('-C', $Repo, 'log', '--oneline', '-1')
    return (([string]($hasil.Keluaran | Select-Object -First 1)) + '').Trim()
}

<#
    Ambil HASH commit terakhir sebuah repo git (hash penuh, tanpa pesan) supaya
    bisa dibandingkan dengan hash yang dilaporkan GitHub lewat ls-remote.

    Parameter: $Repo = path repo.
    Return  : hash commit (string kosong bila gagal).
#>
function KomitHash([string]$Repo) {
    $hasil = Panggil 'git' @('-C', $Repo, 'rev-parse', 'HEAD')
    return (([string]($hasil.Keluaran | Select-Object -First 1)) + '').Trim()
}

# ==== 1. Validasi path wajib ====
foreach ($path in @($DevPath, $ProdPath, $PhpExe, $SkripDeploy, $SkripStop, $SkripJaga)) {
    if (-not (Test-Path $path)) { throw ('Path tidak ditemukan: ' + $path) }
}

Tulis ('DEV  : ' + $DevPath)
Tulis ('PROD : ' + $ProdPath)

$isiEnv = BacaEnv

# ==== 2. Backup database PROD ====
$berkasBackup = $null

if ($LewatiBackup) {
    Tulis 'Backup database dilewati (opsi -LewatiBackup).'
} else {
    if (-not (Test-Path $MySqlDump)) { throw ('mysqldump tidak ditemukan: ' + $MySqlDump) }

    Tulis 'Backup database PROD (mysqldump)...'
    $berkasBackup = BackupDatabase

    # Migrasi tanpa backup terlalu berisiko: backup gagal = deploy dibatalkan.
    if (-not $berkasBackup) { throw 'Backup database gagal -> deploy dibatalkan supaya data tetap aman.' }

    $ukuranKb = [math]::Round((Get-Item $berkasBackup).Length / 1KB, 1)
    Tulis ('Backup database: ' + $berkasBackup + ' (' + $ukuranKb + ' KB)')
}

if ($HanyaBackup) {
    Tulis 'Selesai (mode -HanyaBackup: hanya backup database).'
    Tulis ('Folder backup   : ' + $FolderDb)
    Tulis ('Pulihkan dengan : mysql -u ' + $isiEnv['DB_USERNAME'] + ' ' + $isiEnv['DB_DATABASE'] + ' < "' + $berkasBackup + '"')
    return
}

# ==== 3. Penjaga alur kerja banyak teknisi ====
# Cabang main di GitHub hanya boleh diisi lewat Pull Request yang sudah di-merge
# (AGENTS.md "ALUR KERJA BANYAK TEKNISI"), sedangkan production menarik kode
# langsung dari GitHub. Karena itu copy DEV yang menjalankan pintasan ini WAJIB
# berada TEPAT pada commit main GitHub:
#   - sejajar            -> lanjut.
#   - hanya tertinggal   -> copy DEV dimajukan otomatis (fast-forward, aman).
#   - bercabang/berbeda  -> deploy DIBATALKAN supaya pekerjaan yang belum
#                           di-review TIDAK PERNAH sampai ke produksi.
# Penjaga ini juga menyamakan repo hub lokal (cadangan) dengan commit GitHub.
$skripPenjaga = Join-Path $PSScriptRoot 'penjaga-main-dev.ps1'

Tulis 'Memeriksa kesesuaian copy DEV dengan cabang main GitHub...'
$kodePenjaga = SkripTerpisah $skripPenjaga @('-DevPath', $DevPath, '-PerbaikiTertinggal')

if ($kodePenjaga -ne 0) {
    Catat ('deploy dibatalkan penjaga main-dev, exit code ' + $kodePenjaga)
    throw 'Deploy dibatalkan: copy DEV tidak berada pada commit cabang main GitHub (lihat pesan [penjaga] di atas). Pastikan pekerjaan sudah masuk lewat Pull Request, lalu di copy DEV jalankan: git checkout main dan git pull --ff-only origin main.'
}

$komitDev  = KomitTerakhir $DevPath
$komitProd = KomitTerakhir $ProdPath

# Yang benar-benar akan dipasang adalah commit di GitHub (kanal utama), bukan
# commit DEV lokal. Dibaca langsung dari GitHub supaya ringkasan ini sekaligus
# membuktikan push di atas benar-benar sampai.
$komitDevHash  = KomitHash $DevPath
$komitProdHash = KomitHash $ProdPath
$komitGithub   = ''

$hasilGithub = Panggil 'git' @('ls-remote', $RepoUrl, ('refs/heads/' + $Branch))

if ($hasilGithub.Kode -eq 0) {
    $barisGithub = (([string]($hasilGithub.Keluaran | Select-Object -First 1)) + '').Trim()

    if ($barisGithub -ne '') { $komitGithub = $barisGithub.Split()[0] }
}

if ($komitGithub -eq '') {
    Tulis 'PERINGATAN: commit GitHub tidak terbaca; pembanding memakai repo hub lokal.'
    $hasilHub    = Panggil 'git' @('-C', $DevPath, 'log', '--oneline', '-1', ($HubRemote + '/' + $Branch))
    $komitGithub = (([string]($hasilHub.Keluaran | Select-Object -First 1)) + '').Trim()
}

# ==== 4. Ringkasan sebelum deploy ====
$daftarPending = @(DaftarMigrasiPending)
$berkasKotor   = @()

foreach ($baris in @((Panggil 'git' @('-C', $DevPath, 'status', '--porcelain')).Keluaran)) {
    $teks = ([string]$baris).Trim()

    if ($teks -ne '') { $berkasKotor += $teks }
}

Write-Host ''
Write-Host '=== Ringkasan yang akan dipasang ==='
Write-Host ('commit PROD sekarang    : ' + $komitProd)
Write-Host ('sumber commit           : GitHub (' + $RepoUrl + ')')

if ($komitGithub -eq '') {
    Write-Host 'commit GitHub           : (tidak terbaca)'
} else {
    Write-Host ('commit GitHub           : ' + $komitGithub)
}

Write-Host ('commit DEV (lokal)      : ' + $komitDev)

if ($komitGithub -ne '' -and $komitDevHash -ne '' -and $komitDevHash -eq $komitGithub) {
    Write-Host 'catatan                 : GitHub sudah memuat commit DEV lokal; versi terbaru memang ini.'
}

if ($komitGithub -ne '' -and $komitDevHash -ne '' -and $komitDevHash -ne $komitGithub) {
    Write-Host 'peringatan              : commit GitHub BERBEDA dengan commit DEV lokal (commit DEV belum ter-push?).'
}

if ($komitGithub -ne '' -and $komitProdHash -ne '' -and $komitGithub -eq $komitProdHash) {
    Write-Host 'catatan                 : PROD sudah memuat commit itu; yang diperbarui hanya cache dan service.'
}

if ($daftarPending.Count -eq 0) {
    Write-Host 'migrasi tertunda        : tidak ada'
} else {
    Write-Host ('migrasi tertunda        : ' + $daftarPending.Count + ' migrasi akan dijalankan')
    foreach ($baris in ($daftarPending | Select-Object -First 10)) { Write-Host ('   - ' + $baris) }
}

if ($berkasKotor.Count -eq 0) {
    Write-Host 'berkas DEV belum commit : tidak ada'
} else {
    Write-Host ('berkas DEV belum commit : ' + $berkasKotor.Count + ' berkas (TIDAK ikut di-deploy)')
    foreach ($baris in ($berkasKotor | Select-Object -First 8)) { Write-Host ('   - ' + $baris) }
}

Write-Host ''

# ==== 5. Konfirmasi manusia ====
# Perhatian: Read-Host mengembalikan $null kalau masukan berakhir (mis. stdin
# diarahkan dari NUL), dan '$null -notmatch ...' mengembalikan $null sehingga
# cabang pembatalan TIDAK jalan (pembatalan gagal = deploy tetap lanjut).
# Karena itu jawaban dinormalkan dulu dan diuji eksplisit terhadap 'y'/'ya'.
if ($TanpaKonfirmasi) {
    Tulis 'Konfirmasi dilewati (opsi -TanpaKonfirmasi).'
} else {
    $jawab   = Read-Host '[deploy] Lanjutkan deploy ke PROD? tulis y untuk lanjut'
    $jawaban = ''

    if ($null -ne $jawab) { $jawaban = ([string]$jawab).Trim().ToLower() }

    if ($jawaban -ne 'y' -and $jawaban -ne 'ya') {
        Tulis ('Dibatalkan oleh pengguna, jawaban harus y atau ya. PROD tidak diubah.')
        return
    }
}

# ==== 6. Deploy: pull + migrasi + cache + build aset ====
# Service sengaja dilewati di sini (-LewatiService) karena langkah 8
# me-restart SEMUA service sekaligus, termasuk serve:8000 dan tunnel.
$argDeploy = @('-LewatiDev', '-LewatiComposer', '-LewatiService')

if (-not $LewatiBangunAset) { $argDeploy += '-BangunAset' }

Tulis 'Menjalankan deploy-prod.ps1 (pull, migrasi, cache, build aset)...'
$kodeDeploy = SkripTerpisah $SkripDeploy $argDeploy

# Apa pun hasilnya: pastikan PROD tidak tertinggal di mode pemeliharaan.
ArtisanProd @('up') | Out-Null

if ($kodeDeploy -ne 0) {
    Tulis ('DEPLOY GAGAL (exit code ' + $kodeDeploy + '). PROD dikembalikan ke mode normal.')
    Catat ('deploy gagal, exit code ' + $kodeDeploy)
} else {
    Tulis 'Deploy selesai.'
    Catat 'deploy selesai'
}

# ==== 7. Verifikasi migrasi ====
$sisaPending = @(DaftarMigrasiPending)

if ($sisaPending.Count -eq 0) {
    Tulis 'Semua migrasi sudah dijalankan di PROD.'
} else {
    Tulis ('PERINGATAN: masih ada ' + $sisaPending.Count + ' migrasi tertunda di PROD:')
    foreach ($baris in $sisaPending) { Write-Host ('   - ' + $baris) }
}

# ==== 8. Restart SEMUA service latar (queue, schedule, telegram poll, serve, tunnel) ====
if ($LewatiService) {
    Tulis 'Restart service dilewati (opsi -LewatiService).'
} else {
    Tulis 'Menghentikan semua service latar PROD...'
    SkripTerpisah $SkripStop @('-BiarkanJadwal') | Out-Null

    Tulis 'Menyalakan ulang semua service latar PROD...'
    SkripTerpisah $SkripJaga @() | Out-Null

    Tulis 'Service PROD dijalankan: queue:work, schedule:work, telegram:poll, serve:8000, tunnel.'
}

# ==== 9. Status akhir ====
Write-Host ''
SkripTerpisah $SkripStatus @() | Out-Null

Write-Host ''
Tulis ('commit PROD sekarang : ' + (KomitTerakhir $ProdPath))
Tulis ('log backup database  : ' + $LogBackup)

if ($kodeDeploy -ne 0) {
    Tulis 'SELESAI DENGAN GALAT (lihat pesan di atas).'
    exit 1
}

Tulis 'SELESAI.'
