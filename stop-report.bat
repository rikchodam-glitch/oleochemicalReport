@echo off
REM ======================================================================
REM  stop-report.bat - MENGHENTIKAN semua service latar PRODUKSI
REM  oleochemicalReport (web port 8000, bot Telegram, queue worker,
REM  scheduler, tunnel Cloudflare).
REM
REM  File ini SENGAJA menunjuk ke COPY PROD:
REM    C:\laragon\www\oleochemicalReport-prod
REM
REM  Skrip ini juga MENONAKTIFKAN tugas Windows "oleo-jaga-service-prod";
REM  tanpa itu watchdog akan menyalakan ulang semua service dalam 5 menit.
REM
REM  Menyalakan kembali : start-report.bat
REM  Vhost Apache PROD  : http://localhost/ dan http://oleo-prod.lvh.me
REM                       (tidak disentuh, aplikasi tetap bisa dibuka dari
REM                       komputer ini walau semua service latar mati)
REM ======================================================================
set PROJECT=C:\laragon\www\oleochemicalReport-prod

echo Menghentikan service latar PROD...
powershell -NoProfile -ExecutionPolicy Bypass -File "%PROJECT%\tools\hentikan-service-prod.ps1"

echo.
echo === Status setelah dihentikan ===
powershell -NoProfile -Command "Get-ScheduledTask -TaskName 'oleo-jaga-service-prod','Oleo Serve','Tele Poll' | Select-Object TaskName,State | Format-Table -AutoSize"
powershell -NoProfile -Command "'proses php.exe     : ' + (Get-Process php -ErrorAction SilentlyContinue | Measure-Object).Count"
powershell -NoProfile -Command "'proses cloudflared : ' + (Get-Process cloudflared -ErrorAction SilentlyContinue | Measure-Object).Count"

echo.
echo Selesai. Jalankan start-report.bat kalau ingin menyalakan kembali.
pause
