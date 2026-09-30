@echo off
REM ======================================================================
REM  start-report.bat - menjalankan service latar PRODUKSI oleochemicalReport
REM  (web port 8000, bot Telegram, queue worker, scheduler).
REM
REM  File ini SENGAJA menunjuk ke COPY PROD:
REM    C:\laragon\www\oleochemicalReport-prod
REM  JANGAN diarahkan ke copy DEV (C:\laragon\www\oleochemicalReport):
REM  poller Telegram hanya boleh SATU dan hanya boleh jalan di PROD
REM  (dua poller sekaligus = bot mati total, error 409).
REM
REM  Service dijalankan lewat tools\jaga-service-prod.ps1 supaya AMAN
REM  dipanggil berulang: skrip itu hanya menyalakan yang belum jalan dan
REM  membuang proses duplikat.
REM
REM  SEJAK 30/09/2026 PENJAGAAN OTOMATIS DIMATIKAN atas permintaan user:
REM  tugas Windows "oleo-jaga-service-prod" dinonaktifkan, jadi service
REM  HANYA jalan kalau file ini dijalankan. Menghentikan lagi: stop-report.bat.
REM  Mengaktifkan kembali penjagaan otomatis:
REM    Enable-ScheduledTask -TaskName oleo-jaga-service-prod
REM ======================================================================
set PHP=C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe
set PROJECT=C:\laragon\www\oleochemicalReport-prod

echo Menjalankan dan memeriksa service PROD...
powershell -NoProfile -ExecutionPolicy Bypass -File "%PROJECT%\tools\jaga-service-prod.ps1"

echo.
echo === Status service terakhir (log watchdog) ===
powershell -NoProfile -Command "Get-Content '%PROJECT%\storage\logs\jaga-service.log' -Tail 5"

echo.
echo === Status tunnel dan domain publik ===
powershell -NoProfile -ExecutionPolicy Bypass -File "%PROJECT%\tools\cek-tunnel-prod.ps1"

echo.
echo Selesai. Log poller Telegram: %PROJECT%\storage\logs\telegram-poll.out.log
pause
