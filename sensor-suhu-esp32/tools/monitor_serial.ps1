# ===================================================================
#  Pengganti Serial Monitor Arduino IDE.
#  Menampilkan log firmware ESP32 dari port COM yang dipilih.
#
#  Pemakaian:
#      powershell -ExecutionPolicy Bypass -File tools\monitor_serial.ps1
#      powershell -ExecutionPolicy Bypass -File tools\monitor_serial.ps1 -Port COM3 -Baud 115200
#
#  Tekan Ctrl+C untuk berhenti.
# ===================================================================

param(
    [string]$Port = "COM3",
    [int]$Baud = 115200
)

Write-Host "Membuka $Port pada $Baud bps. Tekan Ctrl+C untuk berhenti." -ForegroundColor Cyan
Write-Host "Pastikan Arduino IDE / program lain tidak sedang memakai port yang sama." -ForegroundColor DarkGray

try {
    $serial = New-Object System.IO.Ports.SerialPort $Port, $Baud, "None", 8, "One"
    $serial.NewLine = "`n"
    $serial.ReadTimeout = 1000
    $serial.Open()
} catch {
    Write-Host "Gagal membuka $Port : $($_.Exception.Message)" -ForegroundColor Red
    exit 1
}

try {
    while ($true) {
        try {
            $baris = $serial.ReadLine()
            Write-Host $baris.TrimEnd()
        } catch [System.TimeoutException] {
            continue
        }
    }
} finally {
    if ($serial.IsOpen) { $serial.Close() }
    Write-Host "Port ditutup." -ForegroundColor Cyan
}
