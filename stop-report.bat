@echo off
setlocal EnableExtensions
title Oleochemical Maintenance - Hentikan Service PROD

REM ======================================================================
REM  stop-report.bat - menghentikan service latar PRODUKSI oleochemicalReport
REM  (queue:work, schedule:work, telegram:poll, web port 8000, dan tunnel
REM  Cloudflare kalau opsi tunnel tidak dipakai).
REM
REM  Selalu menunjuk COPY PROD: C:\laragon\www\oleochemicalReport-prod
REM  Skrip ini juga MENONAKTIFKAN tugas Windows oleo-jaga-service-prod;
REM  tanpa itu watchdog menyalakan ulang service dalam 5 menit.
REM
REM  Cara pakai:
REM    stop-report.bat           hentikan semua, tunnel ikut mati
REM    stop-report.bat tunnel    tunnel TETAP jalan sehingga domain publik
REM                              tetap bisa dibuka, hanya service php mati
REM    stop-report.bat uji       hanya periksa, TIDAK menghentikan apa pun
REM    stop-report.bat nopause   jangan menunggu tombol di akhir
REM  Dua opsi boleh digabung, mis. stop-report.bat tunnel nopause
REM
REM  Vhost Apache PROD tidak disentuh: aplikasi tetap bisa dibuka dari
REM  komputer ini lewat http://localhost/ walau service latar mati.
REM  Menyalakan kembali: start-report.bat
REM ======================================================================

set "PROD=C:\laragon\www\oleochemicalReport-prod"
set "TMPCEK=%TEMP%\oleo-pra-syarat-stop-%RANDOM%.txt"

set "UJI="
set "PAUSE_AKHIR=1"
set "BIARKAN_TUNNEL="

:parse
if "%~1"=="" goto :parse_selesai
if /i "%~1"=="tunnel" set "BIARKAN_TUNNEL=1"
if /i "%~1"=="uji" set "UJI=1"
if /i "%~1"=="nopause" set "PAUSE_AKHIR="
shift
goto :parse

:parse_selesai

echo ======================================================================
echo  MENGHENTIKAN SERVICE PROD - Oleochemical Maintenance
echo ======================================================================
echo.

if defined UJI echo Mode uji: tidak ada service yang dihentikan.
if defined UJI echo.

REM ---- 1. Berkas wajib ----
if not exist "%PROD%\tools\hentikan-service-prod.ps1" goto :tanpa_skrip
echo [1/3] Skrip penghenti : OK
goto :pra

:tanpa_skrip
echo [1/3] Skrip penghenti : GAGAL
echo.
echo   Tidak ditemukan: %PROD%\tools\hentikan-service-prod.ps1
echo   Copy PROD belum lengkap. Susun dulu lewat Deploy-Laporan.bat.
goto :ringkasan

:pra
REM ---- 2. Keadaan sebelum dihentikan ----
if not exist "%PROD%\tools\cek-pra-syarat-prod.ps1" goto :tanpa_pra
echo [2/3] Keadaan SEKARANG sebelum dihentikan:
powershell -NoProfile -ExecutionPolicy Bypass -File "%PROD%\tools\cek-pra-syarat-prod.ps1" > "%TMPCEK%" 2>&1
if exist "%TMPCEK%" type "%TMPCEK%"
echo.
goto :hentikan

:tanpa_pra
echo [2/3] Keadaan sebelum dihentikan : DILEWATI
echo   tools\cek-pra-syarat-prod.ps1 belum ada di copy PROD.
echo   Jalankan Deploy-Laporan.bat supaya pemeriksaan ini ikut terpasang.
goto :hentikan

:hentikan
REM ---- 3. Hentikan ----
if defined UJI goto :hentikan_uji
if not defined BIARKAN_TUNNEL goto :hentikan_semua
findstr /c:"BiarkanTunnel" "%PROD%\tools\hentikan-service-prod.ps1" >nul 2>&1
if errorlevel 1 goto :tunnel_tak_didukung
echo [3/3] Menghentikan service php, tunnel DIBIARKAN tetap jalan...
powershell -NoProfile -ExecutionPolicy Bypass -File "%PROD%\tools\hentikan-service-prod.ps1" -BiarkanTunnel
goto :verifikasi

:tunnel_tak_didukung
echo [3/3] CATATAN: copy PROD ini belum punya opsi -BiarkanTunnel, jadi
echo       tunnel ikut dimatikan. Jalankan Deploy-Laporan.bat untuk versi baru.
echo Menghentikan semua service...
powershell -NoProfile -ExecutionPolicy Bypass -File "%PROD%\tools\hentikan-service-prod.ps1"
goto :verifikasi

:hentikan_semua
echo [3/3] Menghentikan semua service termasuk tunnel...
powershell -NoProfile -ExecutionPolicy Bypass -File "%PROD%\tools\hentikan-service-prod.ps1"
goto :verifikasi

:hentikan_uji
if not defined BIARKAN_TUNNEL echo [3/3] Akan dijalankan: tools\hentikan-service-prod.ps1
if defined BIARKAN_TUNNEL echo [3/3] Akan dijalankan: tools\hentikan-service-prod.ps1 -BiarkanTunnel
goto :verifikasi

:verifikasi
if defined UJI goto :ringkasan_uji
if not exist "%PROD%\tools\cek-pra-syarat-prod.ps1" goto :verifikasi_tanpa_skrip
echo Memeriksa keadaan SESUDAH dihentikan...
powershell -NoProfile -ExecutionPolicy Bypass -File "%PROD%\tools\cek-pra-syarat-prod.ps1" > "%TMPCEK%" 2>&1
if exist "%TMPCEK%" type "%TMPCEK%"
echo.
findstr /c:"SERVICE_PHP=0" "%TMPCEK%" >nul 2>&1
if errorlevel 1 goto :masih_ada_php
if not defined BIARKAN_TUNNEL goto :verifikasi_tunnel
echo   HASIL: service php sudah mati, tunnel tetap jalan - sesuai opsi tunnel.
goto :ringkasan

:verifikasi_tunnel
findstr /c:"SERVICE_TUNNEL=0" "%TMPCEK%" >nul 2>&1
if errorlevel 1 goto :tunnel_masih_ada
echo   HASIL: semua service termasuk tunnel sudah mati.
goto :ringkasan

:masih_ada_php
echo   PERHATIAN: masih ada proses service php. Lihat baris 'proses service' di atas.
goto :ringkasan

:tunnel_masih_ada
echo   PERHATIAN: proses tunnel masih ada. Lihat baris 'proses service' di atas.
goto :ringkasan

:verifikasi_tanpa_skrip
echo Verifikasi dilewati: tools\cek-pra-syarat-prod.ps1 belum ada di copy PROD.

:ringkasan
del "%TMPCEK%" >nul 2>&1
echo.
echo ======================================================================
echo  SELESAI. Vhost Apache PROD tetap jalan: http://localhost/
echo  Menyalakan kembali: start-report.bat
echo ======================================================================
if not defined PAUSE_AKHIR goto :keluar
echo.
pause

:keluar
endlocal

:ringkasan_uji
del "%TMPCEK%" >nul 2>&1
echo.
echo ======================================================================
echo  SELESAI mode uji: tidak ada service yang dihentikan.
echo ======================================================================
if not defined PAUSE_AKHIR goto :keluar2
echo.
pause

:keluar2
endlocal
