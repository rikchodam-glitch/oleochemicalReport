# ===================================================================
#  Membuka port 5000 di Windows Firewall supaya ESP32 bisa mengirim
#  data (jalur PUSH) ke komputer ini.
#
#  WAJIB dijalankan sebagai Administrator.
#  Cara paling mudah: klik kanan 06_izinkan_port_firewall.bat lalu
#  pilih "Run as administrator".
#
#  Catatan: kalau komputer ini memakai profil Domain dan kebijakan
#  firewall diatur terpusat oleh server (GPO), aturan lokal bisa saja
#  diabaikan. Karena itu firmware juga menyediakan jalur PULL yang
#  tidak membutuhkan aturan ini sama sekali.
# ===================================================================

$NamaAturan = "Sensor Suhu ESP32 - monitoring port 5000"

Write-Host "Membuat aturan firewall masuk untuk TCP port 5000 ..." -ForegroundColor Cyan

$lama = Get-NetFirewallRule -DisplayName $NamaAturan -ErrorAction SilentlyContinue
if ($lama) {
    Remove-NetFirewallRule -DisplayName $NamaAturan | Out-Null
    Write-Host "Aturan lama dihapus lebih dulu."
}

New-NetFirewallRule `
    -DisplayName $NamaAturan `
    -Description "Mengizinkan perangkat ESP32 mengirim data sensor ke server monitoring di port 5000." `
    -Direction Inbound `
    -Action Allow `
    -Protocol TCP `
    -LocalPort 5000 `
    -Profile Any | Out-Null

$aturan = Get-NetFirewallRule -DisplayName $NamaAturan
Write-Host ""
Write-Host "Aturan aktif:" -ForegroundColor Green
$aturan | Select-Object DisplayName, Enabled, Direction, Action, Profile | Format-List

Write-Host "Selesai. Uji dengan tools\test_receiver.py atau dengan menjalankan server.py." -ForegroundColor Green
Write-Host "Untuk menghapus aturan ini: Remove-NetFirewallRule -DisplayName '$NamaAturan'"
