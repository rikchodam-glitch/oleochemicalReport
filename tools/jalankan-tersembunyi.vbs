' Jalankan skrip PowerShell tanpa jendela (dipakai tugas Windows "oleo-jaga-service-prod").
' WScript.Run dengan nilai 0 = jendela disembunyikan, sehingga tidak ada
' jendela PowerShell yang muncul-tutup setiap 5 menit.
Option Explicit

Dim shell, jalurSkrip, perintah

jalurSkrip = "C:\laragon\www\oleochemicalReport\tools\jaga-service-prod.ps1"
perintah   = "powershell.exe -NoProfile -ExecutionPolicy Bypass -File """ & jalurSkrip & """"

Set shell = CreateObject("WScript.Shell")
shell.Run perintah, 0, False
