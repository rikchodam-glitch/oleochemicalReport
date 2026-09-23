@echo off
rem ===================================================================
rem  Membuka port 5000 di Windows Firewall (menjalankan script PowerShell
rem  izinkan_port_5000.ps1 dengan hak Administrator / UAC).
rem ===================================================================
setlocal
set SCRIPT=%~dp0izinkan_port_5000.ps1

powershell -NoProfile -Command "Start-Process powershell -Verb RunAs -ArgumentList '-NoProfile','-ExecutionPolicy','Bypass','-File','\"%SCRIPT%\"'"

echo.
echo Jendela UAC akan muncul. Setelah disetujui, cek hasilnya di jendela
echo PowerShell yang terbuka.
pause
