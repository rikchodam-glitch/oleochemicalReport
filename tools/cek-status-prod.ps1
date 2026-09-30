<#
    cek-status-prod.ps1 — Menampilkan status service latar PROD TANPA mengubah apa pun.

    Yang diperiksa:
      - status tugas Windows penjaga service (oleo-jaga-service-prod, Oleo Serve, Tele Poll)
      - jumlah proses queue:work, schedule:work, telegram:poll, serve:8000, tunnel
      - halaman /login lewat port 8000 dan lewat Apache (vhost PROD oleo-prod.lvh.me)
      - halaman /login lewat domain publik https://app.saranapendidik.com
      - 6 baris terakhir log watchdog dan 4 baris terakhir log bot Telegram

    Aman dijalankan kapan saja (hanya membaca, tidak menyalakan/menghentikan
    apa pun). Pasangan skrip ini:
      - menyalakan : start-report.bat / tools\jaga-service-prod.ps1
      - menghentikan: stop-report.bat / tools\hentikan-service-prod.ps1
#>
$ErrorActionPreference = 'Continue'

# Matikan progress bar Invoke-WebRequest supaya keluaran skrip tetap rapi.
$ProgressPreference = 'SilentlyContinue'

$ProdPath   = 'C:\laragon\www\oleochemicalReport-prod'
$LogJaga    = Join-Path $ProdPath 'storage\logs\jaga-service.log'
$LogPoller  = Join-Path $ProdPath 'storage\logs\telegram-poll.out.log'

<#
    Ambil kode HTTP sebuah URL.

    Parameter: $Url = alamat yang diperiksa.
    Return  : kode HTTP sebagai teks, atau keterangan bila tidak ada respons.
#>
function KodeHttp([string]$Url) {
    try {
        $respons = Invoke-WebRequest -Uri $Url -Method Get -TimeoutSec 15 -UseBasicParsing
        return [string][int]$respons.StatusCode
    } catch {
        if ($_.Exception.Response) {
            return [string][int]$_.Exception.Response.StatusCode
        }

        return 'tidak ada respons'
    }
}

<#
    Hitung proses php.exe yang command line-nya memuat pola tertentu.

    Parameter: $Pola = penggalan command line yang dicari.
    Return  : jumlah proses.
#>
function JumlahPhp([string]$Pola) {
    (Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object {
        [string]$_.CommandLine -like ('*' + $Pola + '*')
    } | Measure-Object).Count
}

<#
    Hitung proses cloudflared yang menjalankan tunnel.

    Parameter: tidak ada.
    Return  : jumlah proses.
#>
function JumlahTunnel {
    (Get-CimInstance Win32_Process -Filter "Name='cloudflared.exe'" | Where-Object {
        [string]$_.CommandLine -like '*tunnel run*'
    } | Measure-Object).Count
}

Write-Host '=== Tugas Windows penjaga service ==='
Get-ScheduledTask -TaskName 'oleo-jaga-service-prod', 'Oleo Serve', 'Tele Poll' -ErrorAction SilentlyContinue |
    Select-Object TaskName, State | Format-Table -AutoSize

Write-Host '=== Proses service PROD (harus 1 tiap baris kalau mau lengkap) ==='
Write-Host ('queue:work    : ' + (JumlahPhp 'artisan queue:work'))
Write-Host ('schedule:work : ' + (JumlahPhp 'artisan schedule:work'))
Write-Host ('telegram:poll : ' + (JumlahPhp 'artisan telegram:poll'))
Write-Host ('serve:8000    : ' + (JumlahPhp 'artisan serve'))
Write-Host ('tunnel        : ' + (JumlahTunnel))

Write-Host ''
Write-Host '=== Halaman login ==='
Write-Host ('http://127.0.0.1:8000/login          : ' + (KodeHttp 'http://127.0.0.1:8000/login') + '  (200 = jalan)')
Write-Host ('http://oleo-prod.lvh.me/login (Apache): ' + (KodeHttp 'http://oleo-prod.lvh.me/login'))
Write-Host ('https://app.saranapendidik.com/login : ' + (KodeHttp 'https://app.saranapendidik.com/login'))

Write-Host ''
Write-Host '=== Log watchdog (6 baris terakhir) ==='
if (Test-Path $LogJaga) { Get-Content $LogJaga -Tail 6 } else { Write-Host '(belum ada)' }

Write-Host ''
Write-Host '=== Log bot Telegram (4 baris terakhir) ==='
if (Test-Path $LogPoller) { Get-Content $LogPoller -Tail 4 } else { Write-Host '(belum ada)' }

Write-Host ''
Write-Host '=== Selesai (tidak ada yang diubah oleh skrip ini). ==='
