<#
    cek-tunnel-prod.ps1 — Ringkasan kesiapan & kesehatan Cloudflare Tunnel PROD.

    Aman dijalankan kapan saja (sebelum tunnel dibuat pun berguna: script
    memberi tahu langkah mana yang belum selesai).

    Contoh:
        powershell -ExecutionPolicy Bypass -File C:\laragon\www\oleochemicalReport\tools\cek-tunnel-prod.ps1
#>
$Cloudflared = 'C:\laragon\bin\cloudflared\cloudflared.exe'
$FileKonfig  = 'C:\laragon\cloudflared\config-prod.yml'
$Domain      = 'app.saranapendidik.com'
$VhostLokal  = 'oleo-prod.lvh.me'
$FolderKred  = Join-Path $env:USERPROFILE '.cloudflared'
$FileToken   = 'C:\laragon\cloudflared\token-prod.txt'

# Mode token dipakai bila token-prod.txt sudah diisi (tunnel dibuat dari
# dashboard); kalau tidak, dipakai mode config (config-prod.yml).
$modeToken = (Test-Path $FileToken) -and ((Get-Content $FileToken -Raw).Trim() -ne '')

$jumlahGagal = 0

<#
    Cetak satu baris hasil pemeriksaan.
#>
function Lapor([string]$Nama, [bool]$Lulus, [string]$Keterangan) {
    $tanda = if ($Lulus) { '[ OK ]' } else { '[KURANG]' }

    Write-Host ($tanda + ' ' + $Nama.PadRight(34) + $Keterangan)

    if (-not $Lulus) {
        $script:jumlahGagal++
    }
}

Write-Host '=== Pemeriksaan Cloudflare Tunnel PROD ==='

# 1. cloudflared
if (Test-Path $Cloudflared) {
    $versi = (& $Cloudflared --version 2>&1 | Select-Object -First 1)
    Lapor 'Biner cloudflared' $true $versi
} else {
    Lapor 'Biner cloudflared' $false ('tidak ada di ' + $Cloudflared)
}

# 2. Login Cloudflare (cert.pem)
if ($modeToken) {
    Lapor 'Login Cloudflare (cert.pem)' $true 'tidak diperlukan pada mode token dashboard'
} else {
    $cert    = Join-Path $FolderKred 'cert.pem'
    $adaCert = Test-Path $cert
    $ketCert = 'belum: jalankan "cloudflared tunnel login"'
    if ($adaCert) { $ketCert = $cert }
    Lapor 'Login Cloudflare (cert.pem)' $adaCert $ketCert
}

# 3. Sumber konfigurasi tunnel
$idTunnel = ''
if ($modeToken) {
    Lapor 'Sumber konfigurasi tunnel' $true 'mode token dashboard (C:\laragon\cloudflared\token-prod.txt)'
} elseif (Test-Path $FileKonfig) {
    $isiKonfig = Get-Content $FileKonfig -Raw

    if ($isiKonfig -match 'tunnel:\s*(\S+)') {
        $idTunnel = $matches[1]
    }

    if ($idTunnel -eq 'TUNNEL-ID' -or $idTunnel -eq '') {
        Lapor 'Tunnel ID di config-prod.yml' $false 'masih placeholder: isi dengan Tunnel ID hasil "tunnel create"'
    } else {
        Lapor 'Tunnel ID di config-prod.yml' $true ('tunnel ' + $idTunnel)
    }
} else {
    Lapor 'Tunnel ID di config-prod.yml' $false ('file tidak ada: ' + $FileKonfig)
}

# 4. File kredensial tunnel (mode config saja)
if (-not $modeToken -and $idTunnel -ne '' -and $idTunnel -ne 'TUNNEL-ID') {
    $fileKred = Join-Path $FolderKred ($idTunnel + '.json')
    $adaKred  = Test-Path $fileKred
    $ketKred  = 'tidak ada: pastikan "tunnel create" sudah jalan & ID benar'
    if ($adaKred) { $ketKred = $fileKred }
    Lapor 'Kredensial tunnel' $adaKred $ketKred
}

# 5. Proses tunnel berjalan
$polaProses = '*config-prod.yml*'
if ($modeToken) { $polaProses = '*tunnel run*' }
$prosesTunnel = Get-CimInstance Win32_Process -Filter "Name='cloudflared.exe'" | Where-Object { [string]$_.CommandLine -like $polaProses }
Lapor 'Proses tunnel berjalan' (($prosesTunnel | Measure-Object).Count -eq 1) (($prosesTunnel | Measure-Object).Count.ToString() + ' proses (harus 1)')

# 6. DNS domain publik
$dns = Resolve-DnsName $Domain -ErrorAction SilentlyContinue | Where-Object { $_.Type -eq 'A' -and $_.IPAddress }
if ($dns) {
    $daftarIp   = ($dns | ForEach-Object { $_.IPAddress }) -join ', '
    $cloudflare = ($dns | Where-Object { $_.IPAddress -match '^(104\.|172\.6[0-9]\.|188\.114\.)' } | Measure-Object).Count -gt 0
    $ketDns     = $daftarIp + ' (bukan rentang Cloudflare?)'
    if ($cloudflare) { $ketDns = $daftarIp + ' (Cloudflare)' }
    Lapor 'DNS ' $true $ketDns
} else {
    Lapor 'DNS ' $false ('belum ada record A untuk ' + $Domain + ' -> jalankan "cloudflared tunnel route dns"')
}

# 7. Uji lokal (seperti permintaan cloudflared: Host vhost + X-Forwarded)
$lokal = curl.exe -s -o NUL -w '%{http_code}' -m 15 -H ('Host: ' + $VhostLokal) -H 'X-Forwarded-Proto: https' -H ('X-Forwarded-Host: ' + $Domain) -H 'X-Forwarded-Port: 443' http://127.0.0.1/login
Lapor 'Origin lokal (Apache PROD)' ($lokal -eq '200') ('HTTP ' + $lokal + ' (harus 200)')

# 8. Uji publik https://domain/login
$publik = curl.exe -s -m 25 -w '|%{http_code}' -L ('https://' + $Domain + '/login') 2>$null
$kode = ($publik -split '\|')[-1]

if ($kode -eq '200' -and (($publik -join '') -match 'Oleochemical Pro|NIK / Email')) {
    Lapor 'Publik https://domain/login' $true 'HTTP 200 + halaman login aplikasi tampil'
} elseif ($kode -eq '200' -or $kode -eq '302') {
    Lapor 'Publik https://domain/login' $true ('HTTP ' + $kode + ' (halaman login Cloudflare Access / redirect)')
} elseif ($publik -match '1033') {
    Lapor 'Publik https://domain/login' $false 'error 1033: tunnel belum jalan atau CNAME belum ada'
} elseif ($kode -eq '502' -or $kode -eq '504') {
    Lapor 'Publik https://domain/login' $false ('error ' + $kode + ': Apache localhost:80 tidak menjawab')
} else {
    Lapor 'Publik https://domain/login' $false ('HTTP ' + $kode)
}

Write-Host ''
if ($jumlahGagal -eq 0) {
    Write-Host 'SEMUA SIAP: tunnel sehat dan domain publik melayani aplikasi.' -ForegroundColor Green
} else {
    Write-Host ($jumlahGagal.ToString() + ' item belum siap — lihat baris [KURANG] di atas.') -ForegroundColor Yellow
}
