@echo off
set PHP=C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe
set PROJECT=C:\laragon\www\oleochemicalReport

REM Jalankan php artisan serve
start /min "Oleochemical Server" cmd /k "%PHP% %PROJECT%\artisan serve --host=0.0.0.0 --port=8000"

REM Jalankan php artisan telegram:poll
start /min "Telegram Poll" cmd /k "%PHP% %PROJECT%\artisan telegram:poll"

REM Jalankan queue worker (wajib untuk proses import Sync Google Sheets, dll)
start /min "Queue Worker" cmd /k "%PHP% %PROJECT%\artisan queue:work --tries=1"

REM Jalankan scheduler (wajib untuk ping otomatis it:ping & snapshot berkala it:snapshot)
start /min "Scheduler" cmd /k "%PHP% %PROJECT%\artisan schedule:work"