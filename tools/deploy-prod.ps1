<#
    deploy-prod.ps1 — Deploy oleochemicalReport dari copy DEV ke copy PROD.

    Semua path absolut, jadi bisa dijalankan dari folder mana saja. Contoh:
        powershell -ExecutionPolicy Bypass -File C:\laragon\www\oleochemicalReport\tools\deploy-prod.ps1
        powershell -ExecutionPolicy Bypass -File ...\tools\deploy-prod.ps1 -PesanCommit "feat(x): pesan" -BangunAset

    Urutan kerja: commit DEV (opsional) -> push ke GitHub (kanal utama) lalu ke
    repo hub lokal (cadangan) -> arahkan remote origin copy PROD ke GitHub ->
    fetch + merge --ff-only di PROD (cadangan repo hub lokal bila GitHub tidak
    bisa dihubungi) -> mode pemeliharaan -> composer install --no-dev ->
    migrate --force -> config:cache + view:cache (BUKAN "artisan optimize",
    karena ada closure route di routes/web.php) -> storage:link bila belum ada
    -> build aset (opsional) -> restart queue/schedule/telegram poll ->
    keluar mode pemeliharaan.
#>
param(
    [string]$PesanCommit,
    [switch]$BangunAset,
    [switch]$LewatiService,
    [switch]$LewatiComposer,
    [switch]$LewatiDev
)

$ErrorActionPreference = 'Stop'

# ==== Konfigurasi path (ubah bila folder dipindah/di-rename) ====
$DevPath      = 'C:\laragon\www\oleochemicalReport'
$ProdPath     = 'C:\laragon\www\oleochemicalReport-prod'
$PhpExe       = 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe'
$ComposerPhar = 'C:\laragon\bin\composer\composer.phar'

# Folder node Laragon. WAJIB disisipkan sendiri ke $env:Path sebelum build:
# shim node_modules\.bin\vite.cmd memanggil "node" dari PATH (bukan path
# absolut), sedangkan folder ini tidak ada di PATH mesin/User (Laragon hanya
# menyuntiknya per sesi). Lihat AGENTS.md insiden #32.
$NodeDir      = 'C:\laragon\bin\nodejs\node-v22'
$NodeExe      = Join-Path $NodeDir 'node.exe'
$NpmCmd       = Join-Path $NodeDir 'npm.cmd'
# Kanal deploy (diputuskan user 13/10/2026): GitHub = sumber resmi, DEV push
# ke sana dan copy PROD menarik dari sana. Repo hub lokal (bare) DIPERTAHANKAN
# hanya sebagai cadangan supaya deploy tetap bisa jalan saat GitHub tidak bisa
# dihubungi; kegagalan cadangan tidak pernah membatalkan deploy.
$RepoUrl        = 'https://github.com/rikchodam-glitch/oleochemicalReport.git'
$RemoteUtama    = 'origin'
$RemoteCadangan = 'hub'
$HubPath        = 'C:\laragon\repo\oleochemicalReport.git'
$Branch         = 'main'

# Folder wajib yang harus ada SEBELUM config:cache (lihat AGENTS.md insiden #26).
$FolderWajib = @(
    'storage\framework\views',
    'storage\framework\sessions',
    'storage\framework\cache\data',
    'storage\logs',
    'bootstrap\cache'
)

# Pola command line proses service yang hanya boleh jalan di PROD.
$PolaProses = @('*artisan queue:work*', '*artisan schedule:work*', '*artisan telegram:poll*')

<#
    Cetak pesan progres deploy.
#>
function Tulis([string]$Pesan) {
    Write-Host ("[deploy] " + $Pesan)
}

<#
    Jalankan perintah git sambil menurunkan ErrorActionPreference sementara.
    Git menulis progres (mis. "From https://github.com/...") ke stderr, dan
    dengan preferensi 'Stop' tulisan itu berubah menjadi galat yang
    menghentikan skrip (lihat AGENTS.md insiden #31).

    Parameter: $Argumen = daftar argumen git.
    Return  : hashtable dengan kunci Keluaran (teks) dan Kode (exit code).
#>
function PanggilGit([string[]]$Argumen) {
    $lama = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'

    try {
        $keluaran = & git @Argumen 2>&1 | ForEach-Object { [string]$_ }
        $kode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $lama
    }

    return @{ Keluaran = $keluaran; Kode = $kode }
}

<#
    Tolak pull bila copy PROD punya perubahan yang belum di-commit PADA BERKAS
    YANG AKAN IKUT BERUBAH. Perubahan lain hanya diberi peringatan. Copy PROD
    memang tidak boleh diedit manual, tetapi penjagaan ini memastikan berkas
    yang terlanjur disentuh di PROD tidak pernah tertimpa diam-diam oleh pull.

    Parameter: tidak ada (memakai $ProdPath).
    Return  : tidak ada (melempar galat bila ada berkas yang akan tertimpa).
#>
function TolakPullBilaProdKotor {
    $kotor = @()

    foreach ($baris in @((PanggilGit @('-C', $ProdPath, 'status', '--porcelain', '--untracked-files=no')).Keluaran)) {
        $teks = ([string]$baris).Trim()

        if ($teks -ne '') { $kotor += $teks }
    }

    if ($kotor.Count -eq 0) { return }

    Tulis 'PERINGATAN: copy PROD punya perubahan belum di-commit (PROD seharusnya tidak diedit manual):'
    foreach ($baris in ($kotor | Select-Object -First 8)) { Write-Host ('          ' + $baris) }

    $namaKotor = @($kotor | ForEach-Object { ([string]$_).Substring(2).Trim() })
    $akanUbah  = @()

    foreach ($baris in @((PanggilGit @('-C', $ProdPath, 'diff', '--name-only', 'HEAD', 'FETCH_HEAD')).Keluaran)) {
        $teks = ([string]$baris).Trim()

        if ($teks -ne '') { $akanUbah += $teks }
    }

    $bentrok = @($namaKotor | Where-Object { $akanUbah -contains $_ })

    if ($bentrok.Count -eq 0) { return }

    throw ('Pull dibatalkan: berkas berikut akan berubah tetapi masih ada perubahan manual di PROD -> ' + ($bentrok -join ', ') + '. Kembalikan dulu dengan: git -C "' + $ProdPath + '" checkout -- <berkas>')
}

<#
    Jalankan artisan di copy PROD (lokasi kerja selalu PROD, bukan DEV).
#>
function ArtisanProd([string[]]$Argumen) {
    Push-Location $ProdPath
    try {
        $perintah = @('artisan') + $Argumen
        & $PhpExe @perintah
    } finally {
        Pop-Location
    }
}

<#
    Ambil proses php.exe yang command line-nya cocok dengan salah satu pola
    service PROD (queue:work, schedule:work, telegram:poll).
#>
function ProsesService {
    Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object {
        $cmd = [string]$_.CommandLine
        $cocok = $false
        foreach ($pola in $PolaProses) {
            if ($cmd -like $pola) { $cocok = $true }
        }
        $cocok
    }
}

# ==== 1. Validasi path ====
foreach ($path in @($DevPath, $ProdPath, $PhpExe, $ComposerPhar, $NodeExe, $NpmCmd)) {
    if (-not (Test-Path $path)) {
        throw ("Path tidak ditemukan: " + $path)
    }
}

Tulis ("DEV  : " + $DevPath)
Tulis ("PROD : " + $ProdPath)

# ==== 2. Commit DEV (opsional) ====
# Dengan -LewatiDev, bagian DEV dilewati seluruhnya (tidak commit, tidak
# periksa kebersihan, tidak push). Berguna bila copy DEV sedang dipakai sesi
# lain: PROD tetap menarik commit terakhir yang SUDAH ada di GitHub.
if ($LewatiDev) {
    Tulis 'LewatiDev: pemeriksaan & push DEV dilewati (PROD menarik commit terakhir dari GitHub).'
} else {
    if ($PesanCommit) {
        git -C $DevPath add -A
        git -C $DevPath commit -m $PesanCommit
        Tulis ("commit DEV dibuat: " + $PesanCommit)
    }

    $ubahBelumCommit = git -C $DevPath status --porcelain --untracked-files=no
    if ($ubahBelumCommit) {
        $ubahBelumCommit | ForEach-Object { Write-Host ("         " + $_) }
        throw 'Masih ada perubahan pada file ter-track di DEV. Commit dulu, lalu jalankan ulang deploy.'
    }

    $fileBaru = git -C $DevPath status --porcelain --untracked-files=all | Where-Object { $_ -like '??*' }
    if ($fileBaru) {
        Tulis 'PERINGATAN: file baru berikut belum di-commit sehingga tidak ikut ter-deploy:'
        $fileBaru | ForEach-Object { Write-Host ("         " + $_) }
    }

    # ==== 3. Push DEV -> GitHub (kanal utama) -> repo hub (cadangan) ====
    Tulis ('Push commit DEV ke GitHub (' + $RepoUrl + ')...')
    $pushUtama = PanggilGit @('-C', $DevPath, 'push', $RemoteUtama, $Branch)

    if ($pushUtama.Kode -ne 0) {
        foreach ($baris in @($pushUtama.Keluaran | Select-Object -Last 4)) { Write-Host ('          ' + $baris) }
        throw 'Push ke GitHub gagal: periksa koneksi internet dan kredensial GitHub.'
    }

    # Cadangan lokal: kegagalannya TIDAK membatalkan deploy.
    $pushCadangan = PanggilGit @('-C', $DevPath, 'push', $RemoteCadangan, $Branch)

    if ($pushCadangan.Kode -ne 0) {
        Tulis 'PERINGATAN: push ke repo hub lokal (cadangan) gagal; deploy tetap lanjut lewat GitHub.'
    }
}

# ==== 3b. Pull GitHub -> PROD (selalu dijalankan) ====
# Remote origin copy PROD disamakan dengan kanal utama supaya Deploy-Laporan.bat
# selalu mengambil commit dari GitHub, bukan dari repo hub lokal.
$urlProd = (([string]((PanggilGit @('-C', $ProdPath, 'remote', 'get-url', 'origin')).Keluaran | Select-Object -First 1)) + '').Trim()

if ($urlProd -ne $RepoUrl) {
    PanggilGit @('-C', $ProdPath, 'remote', 'set-url', 'origin', $RepoUrl) | Out-Null
    Tulis ('Remote origin PROD diarahkan ke GitHub (sebelumnya: ' + $urlProd + ').')
}

# Ambil commit terbaru dari GitHub. Bila GitHub tidak bisa dihubungi, repo hub
# lokal dipakai sebagai sumber cadangan supaya deploy tidak macet total.
$sumber = 'GitHub'
$ambil  = PanggilGit @('-C', $ProdPath, 'fetch', 'origin', $Branch)

if ($ambil.Kode -ne 0) {
    Tulis 'Fetch dari GitHub gagal, memakai repo hub lokal sebagai sumber cadangan...'
    $sumber = 'repo hub lokal (cadangan)'
    $ambil  = PanggilGit @('-C', $ProdPath, 'fetch', $HubPath, $Branch)

    if ($ambil.Kode -ne 0) { throw 'Commit tidak bisa diambil: GitHub maupun repo hub lokal sama-sama gagal.' }
}

TolakPullBilaProdKotor

$pull = PanggilGit @('-C', $ProdPath, 'merge', '--ff-only', 'FETCH_HEAD')

if ($pull.Kode -ne 0) {
    foreach ($baris in @($pull.Keluaran | Select-Object -Last 4)) { Write-Host ('          ' + $baris) }
    throw 'Deploy PROD gagal: commit tidak bisa dipasang dengan fast-forward (cek konflik atau branch).'
}

Tulis ('PROD menarik commit dari: ' + $sumber)
Tulis ("PROD sekarang di commit: " + (git -C $ProdPath log --oneline -1))

# ==== 4. Pastikan folder wajib ada sebelum config:cache ====
foreach ($folder in $FolderWajib) {
    New-Item -ItemType Directory -Path (Join-Path $ProdPath $folder) -Force | Out-Null
}
Tulis 'Folder storage/framework wajib dipastikan ada.'

# ==== 5. Mode pemeliharaan, update, lalu keluar ====
ArtisanProd @('down', '--retry=15') | Out-Null
Tulis 'PROD masuk mode pemeliharaan.'

try {
    if (-not $LewatiComposer) {
        Tulis 'composer install --no-dev...'
        & $PhpExe $ComposerPhar install --no-dev --optimize-autoloader --no-interaction --no-progress -d $ProdPath | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'composer install gagal.' }
    }

    Tulis 'migrasi database...'
    ArtisanProd @('migrate', '--force')

    Tulis 'cache produksi (config + view, tanpa optimize)...'
    ArtisanProd @('config:clear') | Out-Null
    ArtisanProd @('config:cache') | Out-Null
    ArtisanProd @('view:cache') | Out-Null

    $jalurStorage = Join-Path $ProdPath 'public\storage'
    if (-not (Test-Path $jalurStorage)) {
        New-Item -ItemType Junction -Path $jalurStorage -Target (Join-Path $ProdPath 'storage\app\public') | Out-Null
        Tulis 'public/storage (junction) dibuat.'
    }

    if ($BangunAset) {
        Tulis 'menyalin node_modules dari DEV lalu build aset...'
        robocopy (Join-Path $DevPath 'node_modules') (Join-Path $ProdPath 'node_modules') /MIR /NFL /NDL /NJH /NJS /NP | Out-Null

        $viteProd = Join-Path $ProdPath 'node_modules\.bin\vite.cmd'
        if (-not (Test-Path $viteProd)) {
            throw 'node_modules PROD tidak berisi vite (salinan dari DEV gagal?).'
        }

        # Shim vite.cmd memanggil "node" dari PATH sehingga build gagal dengan
        # pesan '"node" is not recognized' walau npm berjalan (insiden #32).
        $pathLama = $env:Path
        $env:Path = $NodeDir + ';' + $pathLama
        Push-Location $ProdPath
        try {
            Tulis ('node versi: ' + (& $NodeExe --version))
            & $NpmCmd run build
        } finally {
            Pop-Location
            $env:Path = $pathLama
        }
        if ($LASTEXITCODE -ne 0) { throw 'Build aset (npm run build) gagal.' }
    }
} finally {
    ArtisanProd @('up') | Out-Null
    Tulis 'PROD keluar dari mode pemeliharaan.'
}

# ==== 6. Restart service latar PROD ====
if (-not $LewatiService) {
    $prosesLama = ProsesService

    if ($prosesLama) {
        Tulis ("menghentikan " + $prosesLama.Count + " proses service PROD...")
        $prosesLama | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
    }

    Start-Sleep -Seconds 3

    if (ProsesService) {
        throw 'Masih ada proses service lama. Hentikan manual dulu supaya tidak ada dua poller Telegram (insiden #21).'
    }

    Start-Process -FilePath $PhpExe -ArgumentList 'artisan', 'queue:work', '--tries=1' -WorkingDirectory $ProdPath -WindowStyle Hidden
    Start-Process -FilePath $PhpExe -ArgumentList 'artisan', 'schedule:work' -WorkingDirectory $ProdPath -WindowStyle Hidden
    Start-Process -FilePath $PhpExe -ArgumentList 'artisan', 'telegram:poll' -WorkingDirectory $ProdPath -WindowStyle Hidden `
        -RedirectStandardOutput (Join-Path $ProdPath 'storage\logs\telegram-poll.out.log') `
        -RedirectStandardError (Join-Path $ProdPath 'storage\logs\telegram-poll.err.log')

    Start-Sleep -Seconds 6

    $layanan = @(
        @{ Nama = 'queue:work';    Pola = 'artisan queue:work' },
        @{ Nama = 'schedule:work'; Pola = 'artisan schedule:work' },
        @{ Nama = 'telegram:poll'; Pola = 'artisan telegram:poll' }
    )

    foreach ($item in $layanan) {
        $jumlah = (Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object { [string]$_.CommandLine -like ('*' + $item.Pola + '*') } | Measure-Object).Count

        if ($jumlah -ne 1) {
            throw ("Jumlah proses " + $item.Nama + " = " + $jumlah + " (harus tepat 1).")
        }
    }

    Tulis 'service PROD jalan: queue:work, schedule:work, telegram:poll (masing-masing 1 proses).'
}

# ==== 7. Ringkasan ====
Tulis 'SELESAI.'
Tulis ("commit PROD : " + (git -C $ProdPath log --oneline -1))
Tulis 'uji cepat   : curl.exe -H "Host: oleo-prod.lvh.me" http://127.0.0.1/login'
