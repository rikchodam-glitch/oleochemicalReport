<#
    jaga-service-prod.ps1 — Memastikan service latar PROD tetap hidup.

    Mengecek tiga proses di copy PROD dan menyalakan ulang yang mati:
      - queue:work   (pemroses antrean: import Excel, kirim pesan, dsb)
      - schedule:work (penjadwal: shifts:generate, ai:reset-quota, dsb)
      - telegram:poll (bot Telegram; HANYA boleh satu proses, insiden #21)

    Aman dijalankan berulang kali. Dijalankan otomatis tiap 5 menit lewat
    Windows Task Scheduler (lihat tools\pasang-jadwal-jaga-service.ps1).
#>
$ErrorActionPreference = 'Stop'

# ==== Konfigurasi ====
$ProdPath      = 'C:\laragon\www\oleochemicalReport-prod'
$PhpExe        = 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe'
$LogOutPoller  = Join-Path $ProdPath 'storage\logs\telegram-poll.out.log'
$LogErrPoller  = Join-Path $ProdPath 'storage\logs\telegram-poll.err.log'
$BerkasLog     = Join-Path $ProdPath 'storage\logs\jaga-service.log'

<#
    Tulis catatan aktivitas watchdog ke berkas log PROD.
#>
function Catat([string]$Pesan) {
    $waktu = (Get-Date).ToString('yyyy-MM-dd HH:mm:ss')
    Add-Content -Path $BerkasLog -Value ("[" + $waktu + "] " + $Pesan) -Encoding UTF8
}

<#
    Ambil proses php.exe yang command line-nya memuat argumen tertentu.
#>
function ProsesDengan([string]$Pola) {
    Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object {
        [string]$_.CommandLine -like ("*" + $Pola + "*")
    }
}

<#
    Nyalakan satu service PROD.
#>
function Nyalakan([string]$Argumen, [string]$Pola, [switch]$Poller) {
    $jumlah = (ProsesDengan $Pola | Measure-Object).Count

    if ($jumlah -eq 1) {
        Catat ($Argumen + ": OK (1 proses)")
        return
    }

    if ($jumlah -gt 1) {
        # Lebih dari satu proses: hentikan semua dulu supaya tidak dobel
        # (dua poller Telegram = bot mati total, insiden #21).
        Catat ($Argumen + ": ada " + $jumlah + " proses -> bersihkan lalu nyalakan ulang")
        ProsesDengan $Pola | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
        Start-Sleep -Seconds 3
    }

    if ($Poller) {
        Start-Process -FilePath $PhpExe -ArgumentList 'artisan', 'telegram:poll' -WorkingDirectory $ProdPath -WindowStyle Hidden `
            -RedirectStandardOutput $LogOutPoller -RedirectStandardError $LogErrPoller
    } else {
        $argumenProses = @('artisan') + $Argumen.Split(' ')
        Start-Process -FilePath $PhpExe -ArgumentList $argumenProses -WorkingDirectory $ProdPath -WindowStyle Hidden
    }

    Catat ($Argumen + ": dinyalakan")
}

if (-not (Test-Path $ProdPath)) {
    throw ("Copy PROD tidak ditemukan: " + $ProdPath)
}

Nyalakan -Argumen 'queue:work --tries=1' -Pola 'artisan queue:work'
Nyalakan -Argumen 'schedule:work' -Pola 'artisan schedule:work'
Nyalakan -Argumen 'telegram:poll' -Pola 'artisan telegram:poll' -Poller
Nyalakan -Argumen 'serve --host=0.0.0.0 --port=8000' -Pola 'artisan serve'
