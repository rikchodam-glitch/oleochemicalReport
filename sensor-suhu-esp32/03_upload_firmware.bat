@echo off
rem ===================================================================
rem  Meng-upload firmware ke board ESP32 memakai Arduino IDE 1.8 (CLI).
rem  Board harus terhubung lewat USB dan port COM diketahui.
rem
rem  Pemakaian:
rem      03_upload_firmware.bat COM3
rem ===================================================================
setlocal
cd /d "%~dp0"

set PORT_COM=%1
if "%PORT_COM%"=="" set PORT_COM=COM3

set BOARD=esp32:esp32:esp32doit-devkit-v1
set IDE=C:\Program Files (x86)\Arduino\arduino_debug.exe
set SKETCH=%~dp0firmware\ethernet_dht11\ethernet_dht11.ino

if not exist "%IDE%" (
    echo [GAGAL] Arduino IDE tidak ditemukan di %IDE%
    echo Sesuaikan variabel IDE di dalam berkas ini.
    pause
    exit /b 1
)

echo [upload] Board %BOARD% ke port %PORT_COM% ...
"%IDE%" --upload --board %BOARD% --port %PORT_COM% "%SKETCH%"

echo.
echo [selesai] Bila berhasil, buka Serial Monitor kecepatan 115200 untuk melihat log perangkat.
pause
