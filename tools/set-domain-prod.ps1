<#
    set-domain-prod.ps1 — Mengalihkan domain publik aplikasi PROD dengan aman.

    Urutan kerja:
      1) normalisasi domain (skema dan garis miring dibuang)
      2) periksa DNS domain baru (berhenti bila belum ada record, kecuali -Paksa)
      3) ganti baris APP_URL di .env copy PROD
      4) php artisan config:clear + config:cache di copy PROD
      5) tampilkan hasil (config(app.url), kode HTTP /login, status service)

    Contoh:
        powershell -ExecutionPolicy Bypass -File C:\laragon\www\oleochemicalReport\tools\set-domain-prod.ps1 -Domain oleomtc.com
        powershell -ExecutionPolicy Bypass -File C:\laragon\www\oleochemicalReport\tools\set-domain-prod.ps1 -Domain oleomtc.com -Paksa

    Penting: record DNS hostname di Cloudflare WAJIB dibuat lebih dulu (bentuk
    CNAME ke <TUNNEL-ID>.cfargotunnel.com, status Proxied). Selama record itu
    belum ada, halaman publik dan tautan "Detail web" pada pesan Telegram akan
    gagal dibuka walau APP_URL sudah diganti.
#>
param(
    [Parameter(Mandatory = $true)]
    [string]$Domain,

    [switch]$Paksa
)

$ErrorActionPreference = 'Stop'

$ProdPath = 'C:\laragon\www\oleochemicalReport-prod'
$FileEnv  = Join-Path $ProdPath '.env'
$Php      = 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe'

# 1. Normalisasi domain.
$Domain  = $Domain.Trim() -replace '^[a-zA-Z]+://', ''
$Domain  = ($Domain -split '/')[0]
$UrlBaru = 'https://' + $Domain

Write-Host ('[domain] domain baru : ' + $UrlBaru)

if (-not (Test-Path $FileEnv)) {
    Write-Host ('[domain] GAGAL: .env copy PROD tidak ada di ' + $FileEnv)
    exit 1
}

# 2. Periksa DNS domain baru.
$dns = Resolve-DnsName $Domain -ErrorAction SilentlyContinue | Where-Object { $_.Type -eq 'A' -and $_.IPAddress }

if ($dns) {
    $daftarIp   = ($dns | ForEach-Object { $_.IPAddress }) -join ', '
    $cloudflare = ($dns | Where-Object { $_.IPAddress -match '^(104\.|172\.6[0-9]\.|188\.114\.)' } | Measure-Object).Count -gt 0
    $ket        = $daftarIp

    if ($cloudflare) { $ket = $ket + ' (Cloudflare)' }

    Write-Host ('[domain] DNS          : OK - ' + $ket)
} else {
    $LogTunnel = 'C:\laragon\www\oleochemicalReport-prod\storage\logs\tunnel.err.log'
    $idTunnel  = ''

    if (Test-Path $LogTunnel) {
        $barisId = Select-String -Path $LogTunnel -Pattern 'Starting tunnel tunnelID=([0-9a-f\-]{36})' -ErrorAction SilentlyContinue | Select-Object -First 1

        if ($barisId -and $barisId.Matches.Count -gt 0) { $idTunnel = $barisId.Matches[0].Groups[1].Value }
    }

    $target = '<TUNNEL-ID>.cfargotunnel.com'
    if ($idTunnel -ne '') { $target = $idTunnel + '.cfargotunnel.com' }

    Write-Host ('[domain] DNS          : BELUM ADA record A/CNAME untuk ' + $Domain)
    Write-Host ('[domain] Buat dulu di Cloudflare zona ' + $Domain + ' > DNS > Records > Add record: Type CNAME, Name @ (apex), Target ' + $target + ', Proxy Proxied.')
    Write-Host '[domain] Atau: buka ulang editor Public Hostname hostname itu pada tunnel oleo-report lalu Simpan, Cloudflare akan membuatkan record DNS-nya.'

    if (-not $Paksa) {
        Write-Host '[domain] DIBATALKAN: APP_URL tidak diubah. Ulangi dengan -Paksa kalau ingin tetap lanjut.'
        exit 1
    }

    Write-Host '[domain] -Paksa dipakai: APP_URL tetap diganti walau DNS belum ada.'
}

# 3. Ganti baris APP_URL di .env copy PROD (tanpa BOM supaya dotenv tetap membaca bersih).
$isi  = Get-Content $FileEnv
$lama = $isi | Where-Object { $_ -match '^APP_URL=' } | Select-Object -First 1

if (-not $lama) {
    Write-Host '[domain] GAGAL: baris APP_URL tidak ditemukan di .env copy PROD.'
    exit 1
}

if ($lama.Trim() -eq ('APP_URL=' + $UrlBaru)) {
    Write-Host '[domain] .env PROD  : sudah memakai domain ini, tidak ada perubahan.'
} else {
    $baru = $isi | ForEach-Object {
        if ($_ -match '^APP_URL=') { 'APP_URL=' + $UrlBaru } else { $_ }
    }

    $utf8TanpaBom = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllLines($FileEnv, $baru, $utf8TanpaBom)

    Write-Host ('[domain] .env PROD  : ' + $lama.Trim() + '  ->  APP_URL=' + $UrlBaru)
}

# 4. Bersihkan dan bangun ulang cache config PROD.
Push-Location $ProdPath
try {
    Write-Host '[domain] config:clear + config:cache...'
    & $Php artisan config:clear | Out-Host
    & $Php artisan config:cache | Out-Host
    Write-Host '[domain] config app.url sekarang:'
    & $Php artisan tinker --execute='echo config("app.url");' | Out-Host
} finally {
    Pop-Location
}

# 5. Uji halaman publik domain baru.
Write-Host ''
Write-Host ('[domain] uji https://' + $Domain + '/login ...')
$kode = curl.exe -s -o NUL -w '%{http_code}' -m 30 ('https://' + $Domain + '/login')
Write-Host ('[domain] hasil        : HTTP ' + $kode + '  (200 = siap dipakai)')

Write-Host ''
Write-Host '[domain] status service terkini:'
& (Join-Path $PSScriptRoot 'cek-status-prod.ps1')

Write-Host ''
Write-Host '[domain] SELESAI.'
