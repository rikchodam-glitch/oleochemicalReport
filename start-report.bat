@echo off
setlocal EnableExtensions
title Oleochemical Maintenance - Jalankan Service PROD

REM ======================================================================
REM  start-report.bat - menyalakan dan memeriksa service latar PRODUKSI
REM  oleochemicalReport: queue:work, schedule:work, telegram:poll, web port
REM  8000, tunnel Cloudflare.
REM
REM  Selalu menunjuk COPY PROD: C:\laragon\www\oleochemicalReport-prod
REM  JANGAN diarahkan ke copy DEV: poller Telegram hanya boleh SATU dan
REM  hanya di PROD, sebab dua poller sekaligus membuat bot mati total 409.
REM
REM  Cara pakai:
REM    start-report.bat           nyalakan lalu laporkan hasilnya
REM    start-report.bat uji       hanya periksa, TIDAK menyalakan apa pun
REM    start-report.bat paksa     tetap lanjut walau Laragon belum jalan
REM    start-report.bat nopause   jangan menunggu tombol di akhir
REM  Dua opsi boleh digabung, mis. start-report.bat uji nopause
REM
REM  Aman dijalankan berulang: watchdog hanya menyalakan service yang belum
REM  jalan dan membuang proses duplikat, jadi cocok dipakai sesudah
REM  komputer mati mendadak.
REM ======================================================================

set "PROD=C:\laragon\www\oleochemicalReport-prod"
set "TMPCEK=%TEMP%\oleo-pra-syarat-start-%RANDOM%.txt"
set "TMPSTATUS=%TEMP%\oleo-status-start-%RANDOM%.txt"

set "UJI="
set "PAKSA="
set "PAUSE_AKHIR=1"

:parse
if "%~1"=="" goto :parse_selesai
if /i "%~1"=="uji" set "UJI=1"
if /i "%~1"=="paksa" set "PAKSA=1"
if /i "%~1"=="nopause" set "PAUSE_AKHIR="
shift
goto :parse

:parse_selesai

echo ======================================================================
echo  MENYALAKAN SERVICE PROD - Oleochemical Maintenance
echo ======================================================================
echo.
if defined UJI echo Mode uji: tidak ada service yang dinyalakan.
if defined UJI echo.

REM ---- 1. Berkas wajib ----
if not exist "%PROD%\.env" goto :prod_kurang
if not exist "%PROD%\artisan" goto :prod_kurang
if not exist "%PROD%\tools\jaga-service-prod.ps1" goto :prod_kurang
echo [1/4] Berkas copy PROD : OK
goto :pra

:prod_kurang
echo [1/4] Berkas copy PROD : GAGAL
echo.
echo   Copy PROD tidak lengkap:
echo     %PROD%
echo   Diperlukan .env, artisan, dan tools\jaga-service-prod.ps1.
echo   Susun dulu lewat tools\deploy-prod.ps1 atau Deploy-Laporan.bat.
goto :selesai

:pra
REM ---- 2. Pra-syarat: Laragon, mode pemeliharaan, proses sisa ----
if not exist "%PROD%\tools\cek-pra-syarat-prod.ps1" goto :tanpa_pra
echo [2/4] Memeriksa pra-syarat lokal...
powershell -NoProfile -ExecutionPolicy Bypass -File "%PROD%\tools\cek-pra-syarat-prod.ps1" > "%TMPCEK%" 2>&1
if exist "%TMPCEK%" type "%TMPCEK%"
echo.
findstr /c:"SIAP=1" "%TMPCEK%" >nul 2>&1
if errorlevel 1 goto :tidak_siap
findstr /c:"LARAGON_MATI=1" "%TMPCEK%" >nul 2>&1
if not errorlevel 1 goto :laragon_mati
findstr /c:"MODE_PEMELIHARAAN=1" "%TMPCEK%" >nul 2>&1
if not errorlevel 1 echo   CATATAN: situs masih mode pemeliharaan. Jalankan di PROD: php artisan up
findstr /c:"PELAYAN_8000=DEV" "%TMPCEK%" >nul 2>&1
if not errorlevel 1 echo   CATATAN: port 8000 dilayani copy DEV, watchdog akan menggantinya.
findstr /c:"POLL_GANDA=1" "%TMPCEK%" >nul 2>&1
if not errorlevel 1 echo   CATATAN: poller Telegram lebih dari satu, watchdog akan menyisakan satu.
goto :nyalakan

:tanpa_pra
echo [2/4] Memeriksa pra-syarat lokal : DILEWATI
echo   tools\cek-pra-syarat-prod.ps1 belum ada di copy PROD.
echo   Jalankan Deploy-Laporan.bat supaya pemeriksaan ini ikut terpasang.
goto :nyalakan

:tidak_siap
echo [2/4] Pra-syarat : GAGAL
echo.
echo   Ada masalah yang menghalangi service jalan - lihat daftar di atas.
echo   Perbaiki dulu, atau paksa dengan: start-report.bat paksa
goto :selesai

:laragon_mati
if defined PAKSA goto :laragon_paksa
echo [2/4] Pra-syarat : BERHENTI, Laragon belum jalan
echo.
echo   Apache port 80 DAN MySQL port 3306 dua-duanya tidak menerima koneksi.
echo   Buka Laragon, klik Start All, lalu jalankan file ini lagi.
echo   Kalau memang ingin memaksa: start-report.bat paksa
goto :selesai

:laragon_paksa
echo [2/4] Pra-syarat : Laragon belum jalan, dilanjutkan karena opsi paksa.
goto :nyalakan

REM ---- 3. Nyalakan service yang belum jalan ----
:nyalakan
if defined UJI goto :nyalakan_uji
echo [3/4] Menyalakan service yang belum jalan...
powershell -NoProfile -ExecutionPolicy Bypass -File "%PROD%\tools\jaga-service-prod.ps1"
goto :verifikasi

:nyalakan_uji
echo [3/4] Akan dijalankan: tools\jaga-service-prod.ps1
goto :verifikasi

REM ---- 4. Verifikasi hasil ----
:verifikasi
if defined UJI goto :verifikasi_uji
if not exist "%PROD%\tools\cek-status-prod.ps1" goto :tanpa_status
echo [4/4] Memeriksa hasilnya...
powershell -NoProfile -ExecutionPolicy Bypass -File "%PROD%\tools\cek-status-prod.ps1" > "%TMPSTATUS%" 2>&1
if exist "%TMPSTATUS%" type "%TMPSTATUS%"
echo.
findstr /r /c:"8000/login *: 200" "%TMPSTATUS%" >nul 2>&1
if errorlevel 1 goto :verifikasi_gagal
echo   HASIL: web port 8000 menjawab 200 - service lokal jalan.
echo   Tiga alamat di daftar atas semestinya 200; kalau ada yang bukan 200,
echo   lihat catatan pada baris alamat itu.
goto :selesai

:verifikasi_gagal
echo   HASIL: web port 8000 belum menjawab 200 - periksa daftar di atas.
goto :selesai

:tanpa_status
echo [4/4] Verifikasi : DILEWATI
echo   tools\cek-status-prod.ps1 belum ada di copy PROD.
goto :selesai

:verifikasi_uji
echo [4/4] Akan dijalankan: tools\cek-status-prod.ps1

:selesai
del "%TMPCEK%" >nul 2>&1
del "%TMPSTATUS%" >nul 2>&1
echo.
echo ======================================================================
echo  Catatan berkas:
echo    log watchdog : %PROD%\storage\logs\jaga-service.log
echo    log bot      : %PROD%\storage\logs\telegram-poll.out.log
echo  Cek status tanpa mengubah apa pun: tools\cek-status-prod.ps1
echo  Hentikan semua: stop-report.bat
echo  Hentikan tanpa mematikan domain publik: stop-report.bat tunnel
echo ======================================================================
if not defined PAUSE_AKHIR goto :keluar
echo.
pause

:keluar
endlocal


