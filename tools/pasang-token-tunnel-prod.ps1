<#
    pasang-token-tunnel-prod.ps1 — Memasang token Cloudflare Tunnel PROD yang BARU
    (rotasi token) lalu menyalakan ulang proses connector-nya saja.

    Token diambil dari salah satu cara:
      - parameter -DariFile <berkas teks berisi token>
      - tanpa -DariFile: skrip meminta token diketik (isian tersembunyi)

    Token TIDAK pernah dicetak ke layar dan tidak masuk ke log; yang
    ditampilkan hanya Tunnel ID hasil dekode token.

    Contoh:
        powershell -ExecutionPolicy Bypass -File C:\laragon\www\oleochemicalReport\tools\pasang-token-tunnel-prod.ps1 -DariFile "C:\Users\sapusr\Desktop\token-baru.txt"

    Urutan kerja: baca token -> validasi (harus base64 JSON yang memuat Tunnel
    ID) -> cadangkan token lama -> tulis token baru -> hentikan connector lama
    -> nyalakan connector baru (mode token, perintah sama dengan
    tools\jaga-service-prod.ps1) -> verifikasi 1 proses + Tunnel ID di log.

    PENTING: Public Hostname harus sudah ditambahkan pada tunnel BARU di
    dashboard Cloudflare SEBELUM token dipasang, kalau tidak domain akan
    langsung error 1033.
#>
param(
    [string]$DariFile,
    [switch]$TanpaNotepad
)

$ErrorActionPreference = 'Stop'

$ProdPath    = 'C:\laragon\www\oleochemicalReport-prod'
$Cloudflared = 'C:\laragon\bin\cloudflared\cloudflared.exe'
$FileToken   = 'C:\laragon\cloudflared\token-prod.txt'
$FileTokenBaru = 'C:\laragon\cloudflared\token-baru.txt'
$LogTunnel   = Join-Path $ProdPath 'storage\logs\tunnel.err.log'
$PolaId      = 'Starting tunnel tunnelID=([0-9a-f\-]{36})'
$PolaProses  = '*tunnel run*'

<#
    Baca berkas token sementara (token-baru.txt) dan kembalikan isinya yang
    sudah dibersihkan.

    Parameter: tidak ada.
    Return  : token bersih sebagai teks, atau teks kosong bila berkas belum
              ada atau isinya terlalu pendek untuk dianggap token.
#>
function BacaTokenBerkas {
    if (-not (Test-Path $FileTokenBaru)) { return '' }

    $bersih = (RapikanToken ((Get-Content $FileTokenBaru -Raw))).Token

    if ($bersih.Length -lt 80) { return '' }

    return $bersih
}

<#
    Bersihkan teks calon token: buang spasi/BOM/NUL dan kutip pembungkus,
    buang awalan perintah cloudflared bila ikut tersalin, lalu sisakan hanya
    karakter base64. Isi token TIDAK pernah dicetak.

    Parameter: $Token = teks mentah calon token.
    Return  : objek berisi Token (hasil bersih), Buang (jumlah karakter aneh),
              dan Rincian (daftar kode karakter yang dibuang).
#>
function RapikanToken([string]$Token) {
    $teks = $Token

    $teks = $teks -replace '(?i)cloudflared\s+service\s+install', ''
    $teks = $teks -replace '(?i)cloudflared\s+tunnel\s+run', ''
    $teks = $teks -replace '(?i)--token-file', ''
    $teks = $teks -replace '(?i)--token', ''
    $teks = $teks -replace '(?i)TUNNEL_TOKEN_FILE', ''
    $teks = $teks -replace '(?i)TUNNEL_TOKEN', ''

    $aneh  = [System.Collections.Generic.List[string]]::new()
    $hasil = [System.Text.StringBuilder]::new()

    foreach ($ch in $teks.ToCharArray()) {
        if ([char]::IsWhiteSpace($ch) -or [int]$ch -eq 0 -or [int]$ch -eq 0xFEFF) { continue }

        if ($ch -match '[A-Za-z0-9+/=_-]') {
            [void]$hasil.Append($ch)
        } else {
            $aneh.Add(('U+{0:X4}' -f [int]$ch))
        }
    }

    return [pscustomobject]@{
        Token   = $hasil.ToString()
        Buang   = $aneh.Count
        Rincian = ($aneh | Select-Object -Unique) -join ' '
    }
}

<#
    Ambil token dari berkas atau dari isian pengguna yang tidak ditampilkan.
    Bila -Berkas kosong, berkas token-baru.txt dipakai lebih dulu kalau ada.
    Isian diulang sampai 3 kali bila hasil bersihnya jelas bukan token.

    Parameter: $Berkas = path berkas token (boleh kosong).
    Return  : token bersih sebagai teks.
#>
function AmbilToken([string]$Berkas) {
    if (-not $Berkas -and (Test-Path $FileTokenBaru)) { $Berkas = $FileTokenBaru }

    if ($Berkas) {
        if (-not (Test-Path $Berkas)) { throw ('Berkas token tidak ditemukan: ' + $Berkas) }

        $bersih = (RapikanToken ((Get-Content $Berkas -Raw))).Token

        Write-Host ('[token] token dibaca dari berkas: ' + $Berkas)

        if ($bersih -eq '') { throw ('Isi berkas token kosong: ' + $Berkas) }

        return $bersih
    }

    # Menempel token langsung di jendela hitam bisa terpotong, jadi berkas token
    # dibuka lewat Notepad lebih dulu. Kalau berkas tetap kosong, isian
    # tersembunyi tetap dipakai sebagai cadangan.
    if (-not $TanpaNotepad) {
        Write-Host ''
        Write-Host '[token] Menempel di jendela hitam bisa terpotong, jadi Notepad dibuka.'
        Write-Host '[token] Tempel token di Notepad, simpan berkasnya, lalu tutup Notepad.'
        Write-Host ('[token] Berkas yang dipakai: ' + $FileTokenBaru)

        $notepad = Start-Process -FilePath 'notepad.exe' -ArgumentList $FileTokenBaru -PassThru
        $batas   = (Get-Date).AddSeconds(180)

        while ((Get-Date) -lt $batas) {
            Start-Sleep -Seconds 3

            if ((BacaTokenBerkas) -ne '') { break }

            if (-not (Get-Process -Id $notepad.Id -ErrorAction SilentlyContinue)) { break }
        }

        $bersih = BacaTokenBerkas

        if ($bersih -ne '') {
            Write-Host ('[token] token dibaca dari berkas: ' + $FileTokenBaru)

            return $bersih
        }

        Write-Host '[token] Berkas belum terisi; lanjut ke isian tersembunyi.'
    }

    for ($coba = 1; $coba -le 3; $coba++) {
        Write-Host 'Tempel token tunnel dari dashboard Cloudflare lalu tekan Enter.'
        Write-Host '(Isian tidak ditampilkan di layar.)'

        $aman = Read-Host -AsSecureString
        $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($aman)

        try {
            $mentah = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr)
        } finally {
            [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)
        }

        $hasil  = RapikanToken $mentah
        $bersih = $hasil.Token

        if ($bersih.Length -ge 80 -and $hasil.Buang -eq 0) { return $bersih }

        Write-Host ''
        Write-Host ('[token] isian ini belum bisa dipakai (percobaan ' + $coba + ' dari 3).')
        Write-Host ('[token] panjang terpakai: ' + $bersih.Length + '; karakter aneh dibuang: ' + $hasil.Buang)

        if ($hasil.Buang -gt 0) { Write-Host ('[token] kode karakter aneh: ' + $hasil.Rincian) }
        if ($bersih.Length -lt 80) { Write-Host '[token] terlalu pendek: kemungkinan salinan terpotong.' }

        Write-Host '[token] Cara paling aman: simpan token ke berkas lalu ulangi.'
        Write-Host ('[token]   buka Notepad, tempel token, simpan sebagai ' + $FileTokenBaru)
        Write-Host ''
    }

    throw ('Isian token gagal 3 kali. Simpan token ke ' + $FileTokenBaru + ' lalu jalankan ulang skrip ini.')
}

<#
    Dekode token (base64 dari JSON) dan ambil Tunnel ID-nya. Base64 url-safe
    ikut diterima. Bila gagal, pesan galat memuat ukuran dasar untuk
    diagnosis tanpa membocorkan isi token.

    Parameter: $Token = token cloudflared (sebaiknya sudah dibersihkan).
    Return  : Tunnel ID sebagai teks.
#>
function IdDariToken([string]$Token) {
    $info = RapikanToken $Token
    $teks = $info.Token

    if ($teks -eq '') { throw 'Token kosong.' }

    $percobaan = @(
        @{ Nama = 'base64 standar'; Nilai = $teks },
        @{ Nama = 'base64 url-safe'; Nilai = ($teks.Replace('-', '+').Replace('_', '/')) }
    )

    $galat = ''

    foreach ($coba in $percobaan) {
        $pad = $coba.Nilai

        while ($pad.Length % 4 -ne 0) { $pad += '=' }

        try {
            $objek = ([Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($pad)) | ConvertFrom-Json)
        } catch {
            $galat = $_.Exception.Message
            continue
        }

        if (-not $objek.t) { throw 'Token terbaca, tetapi tidak memuat Tunnel ID (bukan token tunnel?).' }

        return [string]$objek.t
    }

    $rincianBuang = ''

    if ($info.Buang -gt 0) { $rincianBuang = '; karakter aneh dibuang: ' + $info.Buang + ' -> ' + $info.Rincian }

    throw ('Token tidak bisa didekode. Panjang terpakai: ' + $teks.Length +
        ' (sisa bagi 4: ' + ($teks.Length % 4) + ')' + $rincianBuang +
        '. Pesan asli: ' + $galat +
        '. Sisa bagi 4 selain 0 biasanya berarti salinan token terpotong.')
}

<#
    Ambil Tunnel ID dari log connector (baris "Starting tunnel tunnelID=...").

    Parameter: tidak ada.
    Return  : Tunnel ID terakhir yang tercatat, atau teks kosong.
#>
function IdTerakhirDiLog {
    if (-not (Test-Path $LogTunnel)) { return '' }

    $baris = Select-String -Path $LogTunnel -Pattern $PolaId -ErrorAction SilentlyContinue | Select-Object -Last 1

    if ($baris -and $baris.Matches.Count -gt 0) { return $baris.Matches[0].Groups[1].Value }

    return ''
}

<#
    Ambil daftar hostname dari snapshot ingress terakhir di log (baris
    "Updated to new configuration"). Berguna untuk memastikan tunnel baru
    memang sudah punya Public Hostname.

    Parameter: tidak ada.
    Return  : array hostname unik.
#>
function HostnameDiLog {
    if (-not (Test-Path $LogTunnel)) { return @() }

    $baris = Select-String -Path $LogTunnel -Pattern 'Updated to new configuration' -ErrorAction SilentlyContinue | Select-Object -Last 1

    if (-not $baris) { return @() }

    $daftar = [regex]::Matches($baris.Line, '[a-zA-Z0-9\-]+(?:\.[a-zA-Z0-9\-]+)+') | ForEach-Object { $_.Value }

    return @($daftar | Where-Object { $_ -notmatch 'lvh\.me$' -and $_ -match '\.[a-z]{2,}$' } | Sort-Object -Unique)
}

<#
    Hitung proses connector cloudflared yang sedang jalan.

    Parameter: tidak ada.
    Return  : jumlah proses.
#>
function JumlahConnector {
    (Get-CimInstance Win32_Process -Filter "Name='cloudflared.exe'" |
        Where-Object { [string]$_.CommandLine -like $PolaProses } | Measure-Object).Count
}

Write-Host '=== Pasang token Cloudflare Tunnel PROD ==='

if (-not (Test-Path $Cloudflared)) { throw ('Biner cloudflared tidak ada: ' + $Cloudflared) }

$tokenBaru = AmbilToken $DariFile

if ($tokenBaru -eq '') { throw 'Token kosong, tidak ada yang dipasang.' }

try {
    $idBaru = IdDariToken $tokenBaru
} catch {
    Write-Host ('[token] DITOLAK: ' + $_.Exception.Message)
    Write-Host '[token] Tidak ada berkas atau proses yang diubah.'
    exit 1
}

$idLama = IdTerakhirDiLog

Write-Host ('[token] panjang token baru : ' + $tokenBaru.Length + ' karakter')
Write-Host ('[token] tunnel ID token BARU: ' + $idBaru)

if ($idLama -ne '') {
    Write-Host ('[token] connector JALAN     : tunnel ' + $idLama)
} else {
    Write-Host '[token] connector JALAN     : (belum terbaca dari log)'
}

if ($idLama -eq $idBaru) { Write-Host '[token] catatan: token baru menunjuk tunnel yang SAMA dengan yang sedang jalan.' }

if ($idLama -ne $idBaru -and $idLama -ne '') {
    Write-Host ''
    Write-Host '[token] PERHATIAN: tunnel BARU harus sudah punya Public Hostname, mis.'
    Write-Host '[token]   oleomtc.com dan app.saranapendidik.com -> HTTP localhost:80'
    Write-Host '[token]   + Host Header oleo-prod.lvh.me'
    Write-Host '[token] Kalau belum, domain akan error 1033 setelah connector pindah.'
}

# Cadangkan token lama supaya bisa dikembalikan bila tunnel baru belum siap.
$tokenLama = ''

if (Test-Path $FileToken) { $tokenLama = (Get-Content $FileToken -Raw).Trim() }

$berkasCadangan = ''

if ($tokenLama -ne '') {
    $berkasCadangan = $FileToken + '.lama-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.txt'
    Copy-Item -Path $FileToken -Destination $berkasCadangan -Force
    Write-Host ('[token] token lama dicadangkan ke ' + $berkasCadangan)
}

$utf8TanpaBom = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllText($FileToken, $tokenBaru, $utf8TanpaBom)
Write-Host ('[token] token baru ditulis ke ' + $FileToken)

# Hentikan semua connector lama.
$prosesLama = Get-CimInstance Win32_Process -Filter "Name='cloudflared.exe'" |
    Where-Object { [string]$_.CommandLine -like $PolaProses }

if ($prosesLama) {
    Write-Host ('[token] menghentikan ' + ($prosesLama | Measure-Object).Count + ' connector lama...')
    $prosesLama | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
    Start-Sleep -Seconds 3
}

if ((JumlahConnector) -ne 0) { throw 'Masih ada connector cloudflared yang jalan. Hentikan manual lalu ulangi.' }

# Nyalakan connector baru: perintah sama dengan tools\jaga-service-prod.ps1.
$env:TUNNEL_TOKEN = (Get-Content $FileToken -Raw).Trim()

try {
    Start-Process -FilePath $Cloudflared -ArgumentList 'tunnel', 'run' -WindowStyle Hidden `
        -RedirectStandardOutput (Join-Path $ProdPath 'storage\logs\tunnel.out.log') `
        -RedirectStandardError (Join-Path $ProdPath 'storage\logs\tunnel.err.log')
} finally {
    Remove-Item Env:\TUNNEL_TOKEN -ErrorAction SilentlyContinue
}

Start-Sleep -Seconds 10

$jumlahDijalankan = JumlahConnector
$idJalan          = IdTerakhirDiLog
$hostname         = HostnameDiLog

Write-Host ''
Write-Host ('[token] connector jalan : ' + $jumlahDijalankan + ' proses (harus 1)')

if ($idJalan -ne '') {
    Write-Host ('[token] tunnel ID di log: ' + $idJalan)
} else {
    Write-Host '[token] tunnel ID di log: belum terbaca, tunggu sebentar lalu jalankan tools\cek-status-prod.ps1'
}

if ($hostname.Count -gt 0) {
    Write-Host ('[token] hostname publik di tunnel ini: ' + ($hostname -join ', '))
} else {
    Write-Host '[token] hostname publik BELUM terbaca dari log; kemungkinan tunnel baru belum'
    Write-Host '[token] diberi Public Hostname. Tambahkan dulu di dashboard tunnel.'
}

Write-Host ''

if ($jumlahDijalankan -eq 1 -and $idJalan -eq $idBaru) {
    Write-Host '[token] SELESAI: token baru sudah dipakai.' -ForegroundColor Green

    if (Test-Path $FileTokenBaru) {
        Remove-Item -Path $FileTokenBaru -Force
        Write-Host ('[token] berkas sementara dihapus supaya token tidak tersimpan di dua tempat: ' + $FileTokenBaru)
    }
} else {
    Write-Host '[token] BELUM tuntas: periksa baris di atas dan log tunnel.err.log.' -ForegroundColor Yellow

    if ($berkasCadangan -ne '') {
        Write-Host ('[token] mengembalikan token lama: jalankan skrip ini dengan -DariFile ' + $berkasCadangan)
    }
}
