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

# Web port 8000 harus menyajikan copy PROD. Route /debug-login hanya ada di DEV
# (APP_ENV=local), jadi: 404 = PROD (benar), 302/200 = copy lain -> nyalakan ulang.
$prosesServe = Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object {
    [string]$_.CommandLine -like '*artisan serve*'
}
$serveDariProd = $false

try {
    $kodeServe = curl.exe -s -o NUL -w '%{http_code}' -m 10 http://127.0.0.1:8000/debug-login
    $serveDariProd = ($kodeServe -eq '404')
} catch {
    $serveDariProd = $false
}

if ((($prosesServe | Measure-Object).Count -eq 1) -and $serveDariProd) {
    Catat 'serve --host=0.0.0.0 --port=8000: OK (1 proses, copy PROD)'
} else {
    if ($prosesServe) {
        Catat ('serve 8000: proses ada tapi bukan PROD (' + ($prosesServe | Measure-Object).Count + ' proses) -> dinyalakan ulang dari PROD')
        $prosesServe | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
        Start-Sleep -Seconds 3
    } else {
        Catat 'serve 8000: tidak ada proses -> dinyalakan dari PROD'
    }

    Start-Process -FilePath $PhpExe -ArgumentList 'artisan', 'serve', '--host=0.0.0.0', '--port=8000' -WorkingDirectory $ProdPath -WindowStyle Hidden
    Start-Sleep -Seconds 4
}

# Cloudflare Tunnel: dua mode yang didukung.
#   1. Mode token  : file token-prod.txt berisi connector token dari dashboard
#                    (dipakai kalau file itu ada).
#   2. Mode config : config-prod.yml berisi tunnel id + credentials-file hasil
#                    "cloudflared tunnel login" + "tunnel create".
$KonfigTunnel = 'C:\laragon\cloudflared\config-prod.yml'
$FileToken    = 'C:\laragon\cloudflared\token-prod.txt'
$Cloudflared  = 'C:\laragon\bin\cloudflared\cloudflared.exe'

if (-not (Test-Path $Cloudflared)) {
    Catat 'tunnel cloudflared: dilewati (biner cloudflared tidak ada)'
} elseif (Test-Path $FileToken) {
    $tokenTunnel = (Get-Content $FileToken -Raw).Trim()

    if ($tokenTunnel -eq '') {
        Catat 'tunnel cloudflared (mode token): dilewati (file token kosong)'
    } else {
        $prosesTunnel = Get-CimInstance Win32_Process -Filter "Name='cloudflared.exe'" | Where-Object {
            [string]$_.CommandLine -like '*tunnel run*'
        }

        if (($prosesTunnel | Measure-Object).Count -eq 1) {
            Catat 'tunnel cloudflared (mode token): OK (1 proses)'
        } else {
            if ($prosesTunnel) {
                Catat ('tunnel cloudflared (mode token): ada ' + ($prosesTunnel | Measure-Object).Count + ' proses -> dibersihkan')
                $prosesTunnel | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
                Start-Sleep -Seconds 3
            }

            # Token dilewatkan lewat variabel lingkungan, bukan argumen perintah,
            # supaya tidak terlihat di daftar proses.
            # --protocol http2 WAJIB di jaringan ini: egress UDP 7844 diblokir
            # sehingga QUIC gagal berulang dan cloudflared baru pindah sendiri ke
            # http2 setelah ~2 menit (penyebab 502 Bad Gateway 08/10/2026 09:52 WIB).
            # Dipaksa http2 -> 4 koneksi terdaftar dalam ~3 detik (terverifikasi).
            $env:TUNNEL_TOKEN = $tokenTunnel
            try {
                Start-Process -FilePath $Cloudflared -ArgumentList '--protocol', 'http2', 'tunnel', 'run' -WindowStyle Hidden `
                    -RedirectStandardOutput (Join-Path $ProdPath 'storage\logs\tunnel.out.log') `
                    -RedirectStandardError (Join-Path $ProdPath 'storage\logs\tunnel.err.log')
            } finally {
                Remove-Item Env:\TUNNEL_TOKEN -ErrorAction SilentlyContinue
            }

            Catat 'tunnel cloudflared (mode token): dinyalakan'
        }
    }
} elseif (Test-Path $KonfigTunnel) {
    $isiKonfig = Get-Content $KonfigTunnel -Raw

    if ($isiKonfig -match 'TUNNEL-ID') {
        Catat 'tunnel cloudflared: dilewati (konfigurasi masih placeholder TUNNEL-ID)'
    } else {
        $prosesTunnel = Get-CimInstance Win32_Process -Filter "Name='cloudflared.exe'" | Where-Object {
            [string]$_.CommandLine -like '*config-prod.yml*'
        }

        if (($prosesTunnel | Measure-Object).Count -eq 1) {
            Catat 'tunnel cloudflared: OK (1 proses)'
        } else {
            if ($prosesTunnel) {
                Catat ('tunnel cloudflared: ada ' + ($prosesTunnel | Measure-Object).Count + ' proses -> dibersihkan')
                $prosesTunnel | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
                Start-Sleep -Seconds 3
            }

            # --protocol http2: alasannya sama dengan mode token di atas.
            Start-Process -FilePath $Cloudflared -ArgumentList '--protocol', 'http2', 'tunnel', '--config', $KonfigTunnel, 'run' -WindowStyle Hidden `
                -RedirectStandardOutput (Join-Path $ProdPath 'storage\logs\tunnel.out.log') `
                -RedirectStandardError (Join-Path $ProdPath 'storage\logs\tunnel.err.log')

            Catat 'tunnel cloudflared: dinyalakan'
        }
    }
}
