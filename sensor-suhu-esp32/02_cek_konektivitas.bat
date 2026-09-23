@echo off
rem ===================================================================
rem  Pemeriksaan konektivitas ke perangkat ESP32 dan ke server monitoring.
rem  Jalankan server lebih dahulu (01_jalankan_server.bat) supaya
rem  pemeriksaan server ikut berhasil.
rem
rem  Pemakaian:
rem      02_cek_konektivitas.bat
rem      02_cek_konektivitas.bat --ip 192.168.41.240
rem ===================================================================
setlocal
cd /d "%~dp0"

set PYTHON_VENV=.venv\Scripts\python.exe
set PYTHON_LARAGON=C:\laragon\bin\python\python-3.13\python.exe

if exist "%PYTHON_VENV%" (
    "%PYTHON_VENV%" tools\check_connectivity.py %*
) else (
    "%PYTHON_LARAGON%" tools\check_connectivity.py %*
)

pause
