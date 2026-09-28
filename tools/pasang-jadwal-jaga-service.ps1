<#
    pasang-jadwal-jaga-service.ps1 — Memasang Windows Task Scheduler supaya
    tools\jaga-service-prod.ps1 berjalan otomatis setiap 5 menit.

    Tugas dibuat untuk pengguna saat ini. Kalau Windows menolak (butuh hak
    admin), jalankan PowerShell sebagai Administrator lalu ulangi perintahnya.
#>
$ErrorActionPreference = 'Stop'

$NamaTugas = 'oleo-jaga-service-prod'
$Skrip     = 'C:\laragon\www\oleochemicalReport\tools\jaga-service-prod.ps1'

if (-not (Test-Path $Skrip)) {
    throw ("Skrip watchdog tidak ditemukan: " + $Skrip)
}

$aksi = 'powershell.exe -NoProfile -ExecutionPolicy Bypass -File "' + $Skrip + '"'

Write-Host ("Membuat tugas '" + $NamaTugas + "' (setiap 5 menit)...")
$hasil = schtasks /create /tn $NamaTugas /tr $aksi /sc minute /mo 5 /f 2>&1
$hasil | ForEach-Object { Write-Host $_ }

if ($LASTEXITCODE -ne 0) {
    Write-Host 'GAGAL membuat tugas. Jalankan PowerShell sebagai Administrator lalu ulangi,' -ForegroundColor Yellow
    Write-Host 'atau jalankan skrip jaga secara manual bila ada service yang mati:' -ForegroundColor Yellow
    Write-Host ('  powershell -ExecutionPolicy Bypass -File ' + $Skrip) -ForegroundColor Yellow
    exit 1
}

Write-Host 'Tugas terpasang. Cek dengan:' -ForegroundColor Green
Write-Host ('  schtasks /query /tn ' + $NamaTugas)
