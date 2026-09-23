@echo off
rem ===================================================================
rem  Simulator perangkat ESP32: untuk menguji server dan dashboard
rem  tanpa perangkat keras.
rem
rem  Setelah simulator jalan, ubah sementara config.json:
rem      "perangkat": [{ "nama": "sensor-suhu-01", "ip": "127.0.0.1", "port": 8099, "aktif": true }]
rem
rem  Port 8099 dipakai karena 8080 sudah dipakai Laragon nginx.
rem ===================================================================
setlocal
cd /d "%~dp0"

set PYTHON_VENV=.venv\Scripts\python.exe
set PYTHON_LARAGON=C:\laragon\bin\python\python-3.13\python.exe
set PY=%PYTHON_LARAGON%
if exist "%PYTHON_VENV%" set PY=%PYTHON_VENV%

echo [simulator] Menjalankan simulator perangkat pada port 8099 ...
"%PY%" tools\simulator_perangkat.py --port 8099 %*
pause
