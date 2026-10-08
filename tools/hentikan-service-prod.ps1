<#
    hentikan-service-prod.ps1 — Menghentikan SEMUA service latar PROD.

    Yang dihentikan:
      - queue:work     (pemroses antrean: import Excel, kirim pesan, dsb)
      - schedule:work  (penjadwal: shifts:generate, ai:reset-quota, dsb)
      - telegram:poll  (bot Telegram)
      - serve:8000     (artisan serve + proses anak php -S 0.0.0.0:8000)
      - tunnel cloudflared (domain publik; DILEWATI bila opsi -BiarkanTunnel dipakai)

    Opsi -BiarkanTunnel (dipakai stop-report.bat tunnel) hanya menghentikan
    service latar, connector tunnel dibiarkan hidup. Karena tunnel mengarah ke
    Apache port 80 yang tetap menyajikan copy PROD, situs publik TIDAK ikut
    mati - berguna untuk menjeda bot/antrean tanpa memutus pengguna.

    Sekaligus MENONAKTIFKAN tugas Windows "oleo-jaga-service-prod" (dipakai
    opsi -BiarkanJadwal kalau jadwal itu tidak ingin diubah) — tanpa itu
    watchdog menyalakan ulang semua service dalam 5 menit sehingga
    penghentian ini tidak ada artinya.

    Aman dijalankan berulang: kalau service memang sudah mati, skrip hanya
    melaporkan bahwa tidak ada yang perlu dihentikan.

    Yang TIDAK disentuh: vhost Apache PROD (http://localhost/ dan
    http://oleo-prod.lvh.me), jadi aplikasi tetap bisa dibuka dari komputer
    ini walau semua service latar mati. Pemulihan: jalankan start-report.bat
    atau tools\jaga-service-prod.ps1.
#>
param(
    [switch]$BiarkanJadwal,
    [switch]$BiarkanTunnel
)

$ErrorActionPreference = 'Stop'

$ProdPath    = 'C:\laragon\www\oleochemicalReport-prod'
$BerkasLog   = Join-Path $ProdPath 'storage\logs\jaga-service.log'
$TugasJadwal = 'oleo-jaga-service-prod'

<#
    Catat aktivitas ke berkas log PROD (berkas yang sama dengan watchdog
    supaya riwayat nyala/mati service ada di satu tempat).

    Parameter: $Pesan = teks yang dicatat.
    Return  : tidak ada.
#>
function Catat([string]$Pesan) {
    $waktu = (Get-Date).ToString('yyyy-MM-dd HH:mm:ss')
    Add-Content -Path $BerkasLog -Value ('[' + $waktu + '] manual: ' + $Pesan) -Encoding UTF8
}

<#
    Ambil proses php.exe yang termasuk service latar PROD.

    Parameter: tidak ada.
    Return  : daftar proses (bisa kosong).
#>
function ProsesPhpService {
    $pola = @(
        'artisan queue:work',
        'artisan schedule:work',
        'artisan telegram:poll',
        'artisan serve'
    )

    Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object {
        $cmd = [string]$_.CommandLine
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
    Hentikan daftar proses dan catat hasilnya.

    Parameter: $Daftar = daftar proses, $Label = nama kelompok untuk pesan.
    Return  : tidak ada.
#>
function Hentikan($Daftar, [string]$Label) {
    $jumlah = ($Daftar | Measure-Object).Count

    if ($jumlah -eq 0) {
        Write-Host ('[stop] ' + $Label + ': sudah mati')
        return
    }

    foreach ($proses in $Daftar) {
        Write-Host ('[stop] ' + $Label + ': hentikan PID ' + $proses.ProcessId)
        Stop-Process -Id $proses.ProcessId -Force -ErrorAction SilentlyContinue
    }

    Catat ($Label + ': ' + $jumlah + ' proses dihentikan')
}

# ==== 1. Jadwal watchdog dinonaktifkan ====
if ($BiarkanJadwal) {
    Write-Host '[stop] jadwal watchdog dibiarkan (opsi -BiarkanJadwal).'
} else {
    try {
        Disable-ScheduledTask -TaskName $TugasJadwal -ErrorAction Stop | Out-Null
        Write-Host ('[stop] tugas ' + $TugasJadwal + ' dinonaktifkan.')
        Catat ('jadwal ' + $TugasJadwal + ' dinonaktifkan')
    } catch {
        Write-Host ('[stop] GAGAL menonaktifkan tugas ' + $TugasJadwal + ': ' + $_.Exception.Message)
        Catat ('GAGAL menonaktifkan jadwal ' + $TugasJadwal)
    }
}

# ==== 2. Proses service dihentikan ====
Hentikan (ProsesPhpService) 'php (queue/schedule/poll/serve)'

if ($BiarkanTunnel) {
    Write-Host '[stop] tunnel cloudflared dibiarkan (opsi -BiarkanTunnel): domain publik tetap jalan.'
} else {
    Hentikan (ProsesTunnel) 'tunnel cloudflared'
}

Start-Sleep -Seconds 3

# ==== 3. Verifikasi ====
$sisaPhp    = (ProsesPhpService | Measure-Object).Count
$sisaTunnel = (ProsesTunnel | Measure-Object).Count
$kode8000   = '000'

try {
    $kode8000 = curl.exe -s -o NUL -w '%{http_code}' -m 5 http://127.0.0.1:8000/login
} catch {
    $kode8000 = '000'
}

Write-Host ('[stop] sisa proses php service : ' + $sisaPhp)
Write-Host ('[stop] sisa proses tunnel      : ' + $sisaTunnel)

if ($BiarkanTunnel) {
    Write-Host '[stop] tunnel sengaja dibiarkan (opsi -BiarkanTunnel): angka 1 di atas memang diharapkan.'
}
Write-Host ('[stop] port 8000               : ' + $kode8000 + ' (000 = mati)')
Write-Host '[stop] vhost Apache PROD tetap jalan: http://localhost/'
Write-Host '[stop] SELESAI.'

Catat ('selesai: sisa php=' + $sisaPhp + ', sisa tunnel=' + $sisaTunnel + ', port 8000=' + $kode8000)
