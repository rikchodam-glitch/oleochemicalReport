<#
    siapkan-tunnel-prod.ps1 — Panduan dan pengisian konfigurasi Cloudflare
    Tunnel untuk aplikasi PRODUKSI (copy C:\laragon\www\oleochemicalReport-prod).

    Script ini TIDAK menyimpan kredensial Cloudflare; langkah login dan
    pembuatan tunnel dijalankan manual karena membutuhkan akun Cloudflare.
    Jalankan tanpa hak admin:

        powershell -ExecutionPolicy Bypass -File C:\laragon\www\oleochemicalReport\tools\siapkan-tunnel-prod.ps1
        powershell -ExecutionPolicy Bypass -File ... -Domain report.contoh.com -TunnelId 1234abcd-....

    Setelah tunnel jalan, ubah APP_URL di .env copy PROD menjadi
    https://<domain> lalu jalankan:
        php artisan config:clear && php artisan config:cache
#>
param(
    [string]$Domain = '',
    [string]$TunnelId = ''
)

$ErrorActionPreference = 'Stop'

# ==== Konfigurasi path ====
$Cloudflared = 'C:\laragon\bin\cloudflared\cloudflared.exe'
$FileKonfig  = 'C:\laragon\cloudflared\config-prod.yml'
$NamaTunnel  = 'oleo-report'
$VhostLokal  = 'oleo-prod.lvh.me'
$ProdPath    = 'C:\laragon\www\oleochemicalReport-prod'

if (-not (Test-Path $Cloudflared)) {
    throw ("cloudflared tidak ditemukan di " + $Cloudflared)
}

if (-not (Test-Path $FileKonfig)) {
    throw ("Konfigurasi tunnel tidak ditemukan di " + $FileKonfig)
}

Write-Host '=== Status persiapan tunnel ==='
Write-Host ("cloudflared   : " + (& $Cloudflared --version))
Write-Host ("konfigurasi   : " + $FileKonfig)
Write-Host ("nama tunnel   : " + $NamaTunnel)
Write-Host ("vhost lokal   : " + $VhostLokal + " (dilayani Apache dari copy PROD)")
Write-Host ("copy PROD     : " + $ProdPath)

# Isi TUNNEL ID dan domain ke konfigurasi bila parameternya diberikan.
if ($Domain -ne '' -and $TunnelId -ne '') {
    $isi = Get-Content $FileKonfig -Raw
    $isi = $isi -replace 'tunnel: TUNNEL-ID', ("tunnel: " + $TunnelId)
    $isi = $isi -replace 'credentials-file: .*', ("credentials-file: " + $env:USERPROFILE + '\.cloudflared\' + $TunnelId + '.json')
    $isi = $isi -replace 'hostname: domain-anda.com', ("hostname: " + $Domain)
    Set-Content -Path $FileKonfig -Value $isi -Encoding UTF8
    Write-Host 'Konfigurasi tunnel diperbarui (tunnel id + domain).' -ForegroundColor Green

    # Pastikan file kredensial hasil "cloudflared tunnel login" + "tunnel create"
    # benar-benar ada, supaya tunnel tidak gagal jalan karena Tunnel ID salah.
    $FileKredensial = Join-Path $env:USERPROFILE ('.cloudflared\' + $TunnelId + '.json')

    if (Test-Path $FileKredensial) {
        Write-Host ('Kredensial tunnel OK: ' + $FileKredensial) -ForegroundColor Green
    } else {
        Write-Host ('PERINGATAN: file kredensial tidak ditemukan: ' + $FileKredensial) -ForegroundColor Yellow
        Write-Host 'Jalankan "cloudflared tunnel login" lalu "cloudflared tunnel create oleo-report"' -ForegroundColor Yellow
        Write-Host '(atau periksa Tunnel ID dengan "cloudflared tunnel list").' -ForegroundColor Yellow
    }
}

Write-Host ''
Write-Host '=== Langkah yang perlu dijalankan (berurutan) ==='
Write-Host '1. cloudflared tunnel login'
Write-Host '   (buka browser, pilih domain yang dipakai)'
Write-Host '2. cloudflared tunnel create oleo-report'
Write-Host '   catat TUNNEL ID yang ditampilkan'
Write-Host '3. cloudflared tunnel route dns oleo-report <domain>'
Write-Host '4. Isi konfigurasi:'
Write-Host ('   powershell -ExecutionPolicy Bypass -File ' + $MyInvocation.MyCommand.Path + ' -Domain <domain> -TunnelId <tunnel-id>')
Write-Host '5. Jalankan tunnel dari copy PROD:'
Write-Host ('   ' + $Cloudflared + ' tunnel --config ' + $FileKonfig + ' run')
Write-Host '6. Uji dari internet: buka https://<domain>'
Write-Host '7. Permanen (butuh hak admin, jalankan di PowerShell sebagai Administrator):'
Write-Host ('   ' + $Cloudflared + ' service install')
Write-Host ''
Write-Host 'Catatan: bila vhost baru saja ditambahkan dan belum aktif, minta Laragon'
Write-Host 'di-reload (Laragon > Apache > Reload) atau uji dengan:'
Write-Host ('   curl.exe -H "Host: ' + $VhostLokal + '" http://127.0.0.1/login')
