<#
    penjaga-main-dev.ps1 — penjaga alur kerja banyak teknisi.

    Sejak 13/10/2026 cabang `main` di GitHub HANYA boleh diisi lewat Pull
    Request yang sudah di-merge (lihat AGENTS.md bagian "ALUR KERJA BANYAK
    TEKNISI"). Karena production menarik kode langsung dari GitHub, copy DEV
    yang menjalankan deploy WAJIB berada TEPAT pada commit `main` GitHub:

      - sejajar                 -> lolos (CARA=sejajar).
      - tertinggal dari GitHub  -> lolos; dengan -PerbaikiTertinggal copy DEV
                                   dimajukan dulu memakai fast-forward (aman,
                                   tidak ada commit lokal yang hilang).
      - berbeda/bercabang       -> DIBATALKAN (exit 1). Artinya ada commit di
                                   DEV yang belum masuk lewat Pull Request,
                                   atau copy DEV sedang berada di cabang tugas.
      - GitHub tidak terbaca    -> dilewati dengan peringatan (exit 0), supaya
                                   deploy tidak macet saat internet bermasalah.

    Dipakai oleh tools\deploy-laporan-prod.ps1 (pintasan Deploy-Laporan.bat).

    Parameter:
        -DevPath <path>     : copy DEV (bawaan C:\laragon\www\oleochemicalReport).
        -RepoUrl <url>      : URL repo GitHub (sumber resmi).
        -RemoteUtama <nama> : remote GitHub di copy DEV (bawaan origin).
        -HubRemote <nama>   : remote cadangan lokal (bawaan hub).
        -Branch <nama>      : cabang produksi (bawaan main).
        -PerbaikiTertinggal : majukan copy DEV (fast-forward) bila hanya tertinggal.
        -TanpaCadangan      : jangan samakan repo hub lokal dengan commit GitHub.

    Return: exit code 0 = boleh lanjut, 1 = harus dibatalkan.
            Penanda keluaran (untuk dibaca skrip lain) dicetak sebagai
            KUNCI=NILAI: KOMIT_DEV, KOMIT_GITHUB, CABANG_DEV, SEJAJAR,
            TERTINGGAL, CARA.
#>
param(
    [string]$DevPath = 'C:\laragon\www\oleochemicalReport',
    [string]$RepoUrl = 'https://github.com/rikchodam-glitch/oleochemicalReport.git',
    [string]$RemoteUtama = 'origin',
    [string]$HubRemote = 'hub',
    [string]$Branch = 'main',
    [switch]$PerbaikiTertinggal,
    [switch]$TanpaCadangan
)

$ErrorActionPreference = 'Stop'

<#
    Cetak pesan progres penjaga.

    Parameter: $Pesan = teks yang dicetak.
    Return  : tidak ada.
#>
function Tulis([string]$Pesan) {
    Write-Host ('[penjaga] ' + $Pesan)
}

<#
    Jalankan perintah luar sambil menurunkan ErrorActionPreference sementara
    (git menulis progres ke stderr).

    Parameter: $Perintah = path exe, $Argumen = daftar argumen.
    Return  : hashtable dengan kunci Keluaran (teks) dan Kode (exit code).
#>
function Panggil([string]$Perintah, [string[]]$Argumen) {
    $lama = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'

    try {
        $keluaran = & $Perintah @Argumen 2>&1 | ForEach-Object { [string]$_ }
        $kode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $lama
    }

    return @{ Keluaran = $keluaran; Kode = $kode }
}

<#
    Ambil baris pertama keluaran sebuah perintah git (spasi tepi dibuang).

    Parameter: $Argumen = argumen git.
    Return  : teks baris pertama (string kosong bila gagal).
#>
function BarisPertama([string[]]$Argumen) {
    $hasil = Panggil 'git' $Argumen
    return (([string]($hasil.Keluaran | Select-Object -First 1)) + '').Trim()
}

<#
    Ubah satu baris `git status --porcelain` menjadi path relatif.
    Penamaan ulang ("lama -> baru") diambil bagian barunya.

    Parameter: $Baris = satu baris porcelain.
    Return  : path relatif (string kosong bila baris tidak berbentuk itu).
#>
function PathDariPorcelain([string]$Baris) {
    # Baris porcelain berbentuk dua huruf status lalu path (" M catatan.txt"),
    # tetapi keluaran sudah di-Trim sehingga spasi pertama bisa HILANG. Karena
    # itu hanya DUA huruf pertama yang dibuang; Substring(3) membuat huruf
    # pertama path ikut terpotong sehingga berkas penghalang tak pernah ketemu.
    if ($Baris.Length -lt 3) { return '' }

    $path = $Baris.Substring(2).Trim().Trim('"')

    if ($path.Contains(' -> ')) { $path = $path.Split(' -> ')[-1].Trim().Trim('"') }

    return $path
}

<#
    Berkas TER-TRACK belum di-commit di copy DEV yang juga berubah di commit
    tujuan. Berkas itulah yang membuat fast-forward ditolak git ("Your local
    changes ... would be overwritten"), karena git tidak menimpa pekerjaan yang
    belum tersimpan di commit mana pun.

    Parameter: $KomitTujuan = commit yang akan dipasang.
    Return  : larik path relatif (boleh kosong).
#>
function BerkasPenghalang([string]$KomitTujuan) {
    $berubah = @()
    $hasil   = @()

    foreach ($baris in @((Panggil 'git' @('-C', $DevPath, 'diff', '--name-only', 'HEAD', $KomitTujuan)).Keluaran)) {
        $teks = ([string]$baris).Trim()

        if ($teks -ne '') { $berubah += $teks }
    }

    foreach ($baris in @((Panggil 'git' @('-C', $DevPath, 'status', '--porcelain', '--untracked-files=no')).Keluaran)) {
        $path = PathDariPorcelain ([string]$baris).Trim()

        if (($path -ne '') -and ($berubah -contains $path) -and ($hasil -notcontains $path)) {
            $hasil += $path
        }
    }

    return $hasil
}

<#
    Cetak penanda KUNCI=NILAI yang dibaca skrip lain.

    Parameter: $Sejajar/$Tertinggal = 0|1, sisanya teks ringkasan kondisi.
    Return  : tidak ada.
#>
function Penanda([int]$Sejajar, [int]$Tertinggal, [string]$Cara, [string]$KomitDev, [string]$KomitGithub, [string]$CabangDev) {
    Write-Host ('KOMIT_DEV=' + $KomitDev)
    Write-Host ('KOMIT_GITHUB=' + $KomitGithub)
    Write-Host ('CABANG_DEV=' + $CabangDev)
    Write-Host ('SEJAJAR=' + $Sejajar)
    Write-Host ('TERTINGGAL=' + $Tertinggal)
    Write-Host ('CARA=' + $Cara)
}

# ==== LOGIKA PENGECEKAN ====

if (-not (Test-Path (Join-Path $DevPath '.git'))) {
    Tulis ('PERINGATAN: copy DEV tidak punya repo git (' + $DevPath + '); penjaga dilewati.')
    Penanda 0 0 'tidak-dinilai' '' '' ''
    exit 0
}

# Ambil objek terbaru dari GitHub lebih dulu; `ls-remote` yang menentukan, karena
# ref remote-tracking lokal bisa basi (bandingkan Riwayat Insiden #42).
$ambil       = Panggil 'git' @('-C', $DevPath, 'fetch', $RemoteUtama, $Branch)
$barisGithub = BarisPertama @('ls-remote', $RepoUrl, ('refs/heads/' + $Branch))
$komitGithub = ''

if ($barisGithub -ne '') { $komitGithub = $barisGithub.Split()[0] }

$komitDev  = BarisPertama @('-C', $DevPath, 'rev-parse', 'HEAD')
$cabangDev = BarisPertama @('-C', $DevPath, 'rev-parse', '--abbrev-ref', 'HEAD')

Write-Host ('[penjaga] copy DEV      : ' + $DevPath)
Write-Host ('[penjaga] cabang aktif  : ' + $cabangDev)
Write-Host ('[penjaga] commit DEV    : ' + $komitDev)
Write-Host ('[penjaga] commit GitHub : ' + $komitGithub)

if ($komitGithub -eq '') {
    Tulis 'PERINGATAN: commit main GitHub tidak terbaca; penjaga dilewati (deploy tetap boleh lanjut).'
    Penanda 0 0 'tidak-dinilai' $komitDev $komitGithub $cabangDev
    exit 0
}

if ($komitDev -eq $komitGithub) {
    Tulis 'LOLOS: copy DEV berada tepat pada commit main GitHub.'
    Penanda 1 0 'sejajar' $komitDev $komitGithub $cabangDev
    exit 0
}

# Bila DEV bukan leluhur commit GitHub, berarti DEV punya commit sendiri
# (bercabang). Diperiksa dengan merge-base, bukan dengan perbandingan tanggal.
$leluhur = $false

if ($ambil.Kode -eq 0) {
    $uji     = Panggil 'git' @('-C', $DevPath, 'merge-base', '--is-ancestor', 'HEAD', $komitGithub)
    $leluhur = ($uji.Kode -eq 0)
}

if (-not $leluhur) {
    Write-Host ''
    Tulis 'DIBATALKAN: copy DEV TIDAK berada pada commit main GitHub.'
    Tulis ('  commit DEV    : ' + $komitDev + ' (cabang ' + $cabangDev + ')')
    Tulis ('  commit GitHub : ' + $komitGithub)
    Write-Host ''

    foreach ($baris in @((Panggil 'git' @('-C', $DevPath, 'log', '--oneline', '-5', ($komitGithub + '..HEAD'))).Keluaran)) {
        Write-Host ('  dev belum di-merge : ' + ([string]$baris).Trim())
    }

    Write-Host ''
    Tulis 'Sebab yang paling sering: (a) pekerjaan masih berada di cabang tugas dan belum'
    Tulis 'di-merge lewat Pull Request, (b) copy DEV sedang TIDAK di cabang main, atau'
    Tulis '(c) cabang utama DEV punya commit sendiri yang belum lewat Pull Request.'
    Tulis 'Langkah benar (satu alur; jangan mengerjakan langsung di cabang main):'
    Write-Host ('   powershell -NoProfile -ExecutionPolicy Bypass -File "' + (Join-Path $DevPath 'tools\unggah-tugas.ps1') + '"')
    Write-Host ('   powershell -NoProfile -ExecutionPolicy Bypass -File "' + (Join-Path $DevPath 'tools\kembalikan-ke-main.ps1') + '"')
    Write-Host ''
    Penanda 0 0 'dibatalkan' $komitDev $komitGithub $cabangDev
    exit 1
}

# Tertinggal: hanya boleh dimajukan bila diminta. Tanpa opsi itu tetap
# dikembalikan sebagai pembatalan supaya versi lama tidak pernah terpasang
# diam-diam.
if (-not $PerbaikiTertinggal) {
    Tulis 'DIBATALKAN: copy DEV tertinggal dari GitHub (pakai -PerbaikiTertinggal untuk memajukannya).'
    Penanda 0 1 'dibatalkan' $komitDev $komitGithub $cabangDev
    exit 1
}

$maju = Panggil 'git' @('-C', $DevPath, 'merge', '--ff-only', $komitGithub)

if ($maju.Kode -ne 0) {
    $berkasTerhalang = @(BerkasPenghalang $komitGithub)

    # Sebab tersering: berkas yang BELUM di-commit di copy DEV juga berubah di
    # commit GitHub. Git menolak menimpa pekerjaan yang belum tersimpan itu,
    # jadi fast-forward tidak jalan dan sebabnya harus disebut dengan jelas
    # (pesan "punya commit sendiri" DULU menyesatkan untuk keadaan ini,
    # bandingkan catatan insiden 14/10/2026).
    if ($berkasTerhalang.Count -gt 0) {
        Write-Host ''
        Tulis 'DIBATALKAN: fast-forward terhalang berkas yang BELUM di-commit di copy DEV.'
        Tulis 'Berkas berikut juga berubah di commit GitHub, sehingga git tidak boleh menimpanya:'

        foreach ($berkas in $berkasTerhalang) { Write-Host ('   berkas : ' + $berkas) }

        Write-Host ''
        Tulis 'Pilih salah satu:'
        Tulis '  a. pemilik pekerjaan itu meng-commit/mengunggahnya lebih dulu (alur Pull Request), ATAU'
        Tulis '  b. pindahkan sementara dengan aman (berkas DICADANGKAN lalu DIKEMBALIKAN):'
        Write-Host ('     powershell -NoProfile -ExecutionPolicy Bypass -File "' + (Join-Path $DevPath 'tools\siapkan-deploy.ps1') + '"')
        Write-Host ''
        Write-Host ('PENGHALANG=' + ($berkasTerhalang -join '; '))
        Penanda 0 1 'dibatalkan' $komitDev $komitGithub $cabangDev
        exit 1
    }

    Tulis 'DIBATALKAN: copy DEV tidak bisa dimajukan dengan fast-forward.'
    foreach ($baris in @($maju.Keluaran | Select-Object -Last 6)) { Write-Host ('  ' + ([string]$baris).Trim()) }
    Write-Host ''
    Write-Host ('   git -C "' + $DevPath + '" status --short')
    Write-Host ''
    Penanda 0 1 'dibatalkan' $komitDev $komitGithub $cabangDev
    exit 1
}

$komitDev = BarisPertama @('-C', $DevPath, 'rev-parse', 'HEAD')
Tulis ('LOLOS: copy DEV dimajukan (fast-forward) ke commit GitHub: ' + $komitDev)

# Cadangan offline disamakan dengan commit GitHub supaya jatuh-tempo ke GitHub
# tidak pernah memasang commit lama. Kegagalannya hanya peringatan.
if (-not $TanpaCadangan) {
    $pushCadangan = Panggil 'git' @('-C', $DevPath, 'push', $HubRemote, ($komitGithub + ':refs/heads/' + $Branch))

    if ($pushCadangan.Kode -ne 0) {
        Tulis 'PERINGATAN: sinkronisasi repo hub lokal (cadangan) gagal; deploy tetap lanjut.'
    } else {
        Tulis 'Cadangan repo hub lokal disamakan dengan commit GitHub.'
    }
}

Penanda 1 1 'dimajukan' $komitDev $komitGithub $cabangDev
exit 0
