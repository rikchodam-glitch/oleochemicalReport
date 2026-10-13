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
    Tulis 'Sebab yang paling sering: (a) pekerjaan masih berada di cabang tugas dan'
    Tulis 'belum di-merge lewat Pull Request, atau (b) copy DEV sedang TIDAK di cabang'
    Tulis 'main. Langkah benar: selesaikan lewat Pull Request (tools\unggah-tugas.ps1),'
    Tulis 'minta integrator meng-merge, lalu di copy DEV jalankan:'
    Write-Host ('   git -C "' + $DevPath + '" checkout main')
    Write-Host ('   git -C "' + $DevPath + '" pull --ff-only ' + $RemoteUtama + ' ' + $Branch)
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
    Tulis 'DIBATALKAN: copy DEV tidak bisa dimajukan dengan fast-forward.'
    foreach ($baris in @($maju.Keluaran | Select-Object -Last 4)) { Write-Host ('  ' + ([string]$baris).Trim()) }
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
