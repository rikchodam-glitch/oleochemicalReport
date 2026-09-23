@echo off
rem ===================================================================
rem  Menjalankan server monitoring suhu & kelembapan (Flask + SQLite).
rem  Bila folder .venv belum ada, script ini membuatnya dan memasang
rem  Flask otomatis memakai Python bawaan Laragon.
rem ===================================================================
setlocal
cd /d "%~dp0"

set PYTHON_LARAGON=C:\laragon\bin\python\python-3.13\python.exe

if not exist ".venv\Scripts\python.exe" (
    echo [setup] Membuat virtual environment .venv ...
    if exist "%PYTHON_LARAGON%" (
        "%PYTHON_LARAGON%" -m venv .venv
    ) else (
        python -m venv .venv
    )
    if not exist ".venv\Scripts\python.exe" (
        echo [GAGAL] Virtual environment tidak bisa dibuat. Pastikan Python 3 tersedia.
        pause
        exit /b 1
    )
    echo [setup] Memasang dependensi dari server\requirements.txt ...
    ".venv\Scripts\python.exe" -m pip install --quiet -r server\requirements.txt
)

echo [jalan] Server monitoring dijalankan. Tekan Ctrl+C untuk berhenti.
".venv\Scripts\python.exe" server\server.py
pause
