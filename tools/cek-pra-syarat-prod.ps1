<#
    cek-pra-syarat-prod.ps1 — Memeriksa pra-syarat service latar PROD.

    Skrip ini HANYA MEMBACA: tidak menyalakan, menghentikan, atau menghapus
    apa pun. Dipakai start-report.bat dan stop-report.bat supaya pengguna tahu
    keadaan sebenarnya sebelum mengubah layanan. Paling berguna sesudah
    komputer mati mendadak: proses sisa dari copy DEV, jalur PHP yang berubah
    sesudah Laragon diperbarui, dan Laragon yang belum dinyalakan semuanya
    terlihat di sini.

    Keluaran: baris berlabel '[pra]' untuk dibaca manusia, ditutup beberapa
    baris penanda mesin yang dibaca berkas .bat:
      SERVICE_PHP=<n>        jumlah proses service php (queue/schedule/poll/serve)
      SERVICE_TUNNEL=<n>     jumlah proses tunnel cloudflared
      PROSES_COPY_DEV=<n>    proses service yang jalan dari copy DEV (harus 0)
      PELAYAN_8000=PROD|DEV|MATI
                             copy mana yang melayani port 8000. Route
                             /debug-login hanya ada saat APP_ENV=local, jadi
                             404 = copy PROD (benar), 302 = copy DEV.
      POLL_GANDA=0|1         lebih dari satu proses telegram:poll (bot bisa 409)
      LARAGON_MATI=0|1       Apache DAN MySQL dua-duanya tidak menerima koneksi
      MODE_PEMELIHARAAN=0|1  berkas storage\framework\down ada
      SIAP=0|1               0 = ada masalah yang menghalangi service jalan

    Waktu tunggu koneksi sengaja dipendekkan supaya skrip tetap cepat.
#>
$ErrorActionPreference = 'Continue'
$ProgressPreference    = 'SilentlyContinue'

$ProdPath    = 'C:\laragon\www\oleochemicalReport-prod'
$DevPath     = 'C:\laragon\www\oleochemicalReport'
$PhpExe      = 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe'
$Cloudflared = 'C:\laragon\bin\cloudflared\cloudflared.exe'
$CloudflaredCadangan = 'C:\Program Files (x86)\cloudflared\cloudflared.exe'
$FileToken   = 'C:\laragon\cloudflared\token-prod.txt'

<#
    Cetak satu baris laporan berlabel '[pra]'.

    Parameter: $Label = nama pemeriksaan, $Nilai = hasilnya.
    Return  : tidak ada.
#>
function Tulis([string]$Label, [string]$Nilai) {
    Write-Host ('[pra] ' + $Label.PadRight(21) + ': ' + $Nilai)
}

<#
    Periksa apakah ada yang menerima koneksi TCP di 127.0.0.1 pada port itu.

    Parameter: $Port = nomor port (80 untuk Apache, 3306 untuk MySQL).
    Return  : $true bila koneksi berhasil dalam 1,5 detik.
#>
function PortTerbuka([int]$Port) {
    $klien = New-Object System.Net.Sockets.TcpClient

    try {
        $tugas = $klien.ConnectAsync('127.0.0.1', $Port)

        if (-not $tugas.Wait(1500)) { return $false }

        return $klien.Connected
    } catch {
        return $false
    } finally {
        $klien.Close()
    }
}

<#
    Ambil proses php.exe yang termasuk service latar PROD.
    Pola argumennya sengaja sama dengan tools\hentikan-service-prod.ps1 supaya
    angka laporan ini cocok dengan yang dipakai skrip hentikan/jaga.

    Parameter: tidak ada.
    Return  : daftar proses (bisa kosong).
#>
function ProsesService {
    $pola = @(
        'artisan queue:work',
        'artisan schedule:work',
        'artisan telegram:poll',
        'artisan serve'
    )

    Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object {
        $cmd   = [string]$_.CommandLine
        $cocok = $false

        foreach ($p in $pola) {
            if ($cmd -like ('*' + $p + '*')) { $cocok = $true }
        }

        # Proses anak artisan serve: "php -S 0.0.0.0:8000 ...server.php".
        if (($cmd -like '*oleochemicalReport*') -and ($cmd -like '*server.php*')) { $cocok = $true }

        $cocok
    }
}

<#
    Ambil proses tunnel cloudflared yang dipakai watchdog.

    Parameter: tidak ada.
    Return  : daftar proses (bisa kosong).
#>
function ProsesTunnel {
    Get-CimInstance Win32_Process -Filter "Name='cloudflared.exe'" | Where-Object {
        [string]$_.CommandLine -like '*tunnel run*'
    }
}

<#
    Ambil proses service yang jalan dari copy DEV, bukan copy PROD.
    Proses seperti ini menyerobot port 8000 dan (bila pernah terjadi) membuat
    dua poller Telegram saling berebut sehingga bot mati total (insiden #21).

    Parameter: tidak ada.
    Return  : daftar proses (bisa kosong).
#>
function ProsesCopyDev {
    Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object {
        $cmd = [string]$_.CommandLine

        # Proses yang menyebut jalur copy DEV. Path PROD tidak ikut cocok sebab
        # 'C:\laragon\www\oleochemicalReport' bukan awalan dari
        # 'C:\laragon\www\oleochemicalReport-prod' (setelah 'Report' ada '-').
        $menyebutDev = ($cmd -like ('*' + $DevPath + '*')) -and ($cmd -notlike ('*' + $ProdPath + '*'))

        # 'artisan' untuk perintah artisan, 'server.php' untuk proses anak
        # artisan serve (php -S 0.0.0.0:8000 ...server.php) yang dipakai tugas
        # lama 'Oleo Serve' dari copy DEV. Keduanya WAJIB ikut diperiksa:
        # command line anak serve tidak memuat kata 'artisan'.
        $menyebutDev -and (($cmd -like '*artisan*') -or ($cmd -like '*server.php*'))
    }
}

<#
    Baca kode HTTP sebuah alamat lokal memakai curl.exe (hasilnya sama dengan
    pengujian manual; Invoke-WebRequest pernah melaporkan 'tidak ada respons'
    padahal curl menjawab 200 di komputer ini).

    Parameter: $Url = alamat yang diperiksa.
    Return  : kode HTTP tiga digit, atau '000' bila tidak ada respons.
#>
function KodeHttp([string]$Url) {
    $kode = curl.exe -s -o NUL -w '%{http_code}' -m 8 $Url

    if ($kode -is [array]) { $kode = $kode[-1] }

    $kode = [string]$kode

    if ($kode -match '^\d{3}$') { return $kode }

    return '000'
}

$phpAda       = Test-Path $PhpExe
$cloudflared1 = Test-Path $Cloudflared
$cloudflared2 = Test-Path $CloudflaredCadangan
$tokenAda     = $false

if (Test-Path $FileToken) {
    $tokenAda = ((Get-Content $FileToken -Raw).Trim() -ne '')
}

$envAda      = Test-Path (Join-Path $ProdPath '.env')
$apacheBuka  = PortTerbuka 80
$mysqlBuka   = PortTerbuka 3306
$laragonMati = ((-not $apacheBuka) -and (-not $mysqlBuka))
$modeDown    = Test-Path (Join-Path $ProdPath 'storage\framework\down')
$logJaga     = Join-Path $ProdPath 'storage\logs\jaga-service.log'

$daftarPhp    = ProsesService
$daftarTunnel = ProsesTunnel
$daftarDev    = ProsesCopyDev

$jumlahPhp    = ($daftarPhp | Measure-Object).Count
$jumlahTunnel = ($daftarTunnel | Measure-Object).Count
$jumlahDev    = ($daftarDev | Measure-Object).Count

$jumlahQueue = ($daftarPhp | Where-Object { [string]$_.CommandLine -like '*queue:work*' } | Measure-Object).Count
$jumlahJadwal = ($daftarPhp | Where-Object { [string]$_.CommandLine -like '*schedule:work*' } | Measure-Object).Count
$jumlahPoll  = ($daftarPhp | Where-Object { [string]$_.CommandLine -like '*telegram:poll*' } | Measure-Object).Count
$jumlahServe = ($daftarPhp | Where-Object { [string]$_.CommandLine -like '*artisan serve*' } | Measure-Object).Count

# Copy mana yang menjawab di port 8000? Route /debug-login hanya ada saat
# APP_ENV=local, jadi 404 = copy PROD (benar), 302 = copy DEV, 000 = mati.
# Pemeriksaan ini melengkapi ProsesCopyDev(): proses artisan yang dipanggil
# dengan jalur relatif tidak memuat nama copy di command line-nya.
$kodeDebug8000 = KodeHttp 'http://127.0.0.1:8000/debug-login'
$pelayan8000   = 'MATI'

if ($kodeDebug8000 -eq '404') {
    $pelayan8000 = 'PROD'
} elseif ($kodeDebug8000 -ne '000') {
    $pelayan8000 = 'DEV'
}

Tulis 'waktu' (Get-Date).ToString('yyyy-MM-dd HH:mm:ss')

if (Test-Path $ProdPath) {
    Tulis 'copy PROD' ('OK (' + $ProdPath + ')')
} else {
    Tulis 'copy PROD' ('TIDAK ADA (' + $ProdPath + ')')
}

if ($envAda) {
    Tulis 'berkas .env' 'OK'
} else {
    Tulis 'berkas .env' 'TIDAK ADA -> copy PROD belum siap'
}

if ($phpAda) {
    Tulis 'php.exe' 'OK'
} else {
    Tulis 'php.exe' ('TIDAK ADA di ' + $PhpExe + ' -> sesuaikan jalur di folder tools')
}

if ($cloudflared1) {
    Tulis 'cloudflared' 'OK'
} elseif ($cloudflared2) {
    Tulis 'cloudflared' 'OK (dari folder Program Files, bukan folder Laragon)'
} else {
    Tulis 'cloudflared' 'TIDAK ADA -> domain publik tidak akan jalan'
}

if ($tokenAda) {
    Tulis 'token tunnel' 'OK'
} else {
    Tulis 'token tunnel' 'TIDAK ADA atau kosong -> domain publik tidak akan jalan'
}

if ($apacheBuka) {
    Tulis 'Apache port 80' 'OK'
} else {
    Tulis 'Apache port 80' 'BELUM JALAN'
}

if ($mysqlBuka) {
    Tulis 'MySQL port 3306' 'OK'
} else {
    Tulis 'MySQL port 3306' 'BELUM JALAN'
}

if ($modeDown) {
    Tulis 'mode pemeliharaan' 'AKTIF -> situs menampilkan halaman pemeliharaan'
} else {
    Tulis 'mode pemeliharaan' 'tidak aktif'
}

Tulis 'proses service' ('queue=' + $jumlahQueue + ' schedule=' + $jumlahJadwal + ' poll=' + $jumlahPoll + ' serve=' + $jumlahServe + ' tunnel=' + $jumlahTunnel)

if ($jumlahDev -eq 0) {
    Tulis 'proses copy DEV' 'tidak ada (bagus)'
} else {
    # [string] wajib: $jumlahDev bertipe angka, dan 'angka + teks' di PowerShell
    # mencoba penjumlahan numerik (gagal dengan InvalidCastFromStringToInteger).
    Tulis 'proses copy DEV' ([string]$jumlahDev + ' proses -> digantikan watchdog saat start-report.bat')
}

if ($pelayan8000 -eq 'PROD') {
    Tulis 'pelayan port 8000' 'copy PROD (benar)'
} elseif ($pelayan8000 -eq 'DEV') {
    Tulis 'pelayan port 8000' 'copy DEV -> akan digantikan watchdog saat start-report.bat'
} else {
    Tulis 'pelayan port 8000' 'tidak ada yang menjawab (port 8000 mati)'
}

if ($jumlahPoll -gt 1) {
    Tulis 'poller Telegram' ([string]$jumlahPoll + ' proses -> BAHAYA, bot bisa mati total (409)')
}

if (Test-Path (Join-Path $ProdPath 'storage\app\telegram_poll.stop')) {
    Tulis 'penanda lama' 'telegram_poll.stop ada -> dihapus sendiri oleh poller saat mulai'
} else {
    Tulis 'penanda lama' 'tidak ada'
}

Tulis 'http 8000' (KodeHttp 'http://127.0.0.1:8000/login')
Tulis 'http Apache' (KodeHttp 'http://localhost/login')

if (Test-Path $logJaga) {
    $barisTerakhir = Get-Content $logJaga -Tail 1

    if ($barisTerakhir) {
        Tulis 'log watchdog' ([string]$barisTerakhir)
    } else {
        Tulis 'log watchdog' '(kosong)'
    }
} else {
    Tulis 'log watchdog' '(belum ada)'
}

$siap = $true

if (-not (Test-Path $ProdPath)) { $siap = $false }
if (-not $phpAda) { $siap = $false }
if (-not $envAda) { $siap = $false }

Write-Host ''
Write-Host ('SERVICE_PHP=' + $jumlahPhp)
Write-Host ('SERVICE_TUNNEL=' + $jumlahTunnel)
Write-Host ('PROSES_COPY_DEV=' + $jumlahDev)
Write-Host ('PELAYAN_8000=' + $pelayan8000)

if ($jumlahPoll -gt 1) { Write-Host 'POLL_GANDA=1' } else { Write-Host 'POLL_GANDA=0' }

if ($laragonMati) { Write-Host 'LARAGON_MATI=1' } else { Write-Host 'LARAGON_MATI=0' }
if ($modeDown)    { Write-Host 'MODE_PEMELIHARAAN=1' } else { Write-Host 'MODE_PEMELIHARAAN=0' }

if ($siap) { Write-Host 'SIAP=1' } else { Write-Host 'SIAP=0' }

