# Papan Klaim: erik

Diperbarui: 2026-10-13 11:34 WIB

- Cabang aktif: `tugas/erik/potong-istirahat-laporan-non-shift`
- Mulai: 2026-10-13 11:34
- Status: SEDANG DIKERJAKAN
- Modul/berkas yang disentuh:
  - app/Services/Shift/JamKerjaService.php (istirahat + jendela kerja + normal/lembur)
  - app/Services/Shift/ManHourService.php (lembur harian + lemburPerTanggal)
  - app/Http/Controllers/ReportController.php (durasi normal potong istirahat)
  - app/Http/Controllers/TechnicianController.php (data rincian lembur ke profil)
  - app/Services/Telegram/Traits/WizardStepHandlerTrait.php (Step 4 durasi)
  - app/Console/Commands/ReportPangkasIstirahatCommand.php (baru: reports:pangkas-istirahat)
  - resources/views/reports/partials/_waktu-kerja.blade.php (normal + lembur)
  - resources/views/technicians/show.blade.php (chip lembur + segmen oranye + tombol)
  - resources/views/technicians/partials/_modal-lembur.blade.php (baru: popup Cek Overtime)
  - tests/Feature/{ReportIstirahatTest,TechnicianLemburTest}.php (baru)
  - tests/Feature/{ReportEditJamKerjaTest,ReportDurasiKonsistenTest}.php (disesuaikan)
  - AGENTS.md (keputusan arsitektur poin 31)
- Catatan: pemotongan istirahat HANYA untuk group non-shift (reguler & reguler
  staff); pekerja shift grub A-D tetap 8 jam penuh. LEMBUR dipisahkan dari man
  hour (normal = jam kerja, lembur = jam di luar jendela kerja + hari OFF) dan
  ditampilkan sebagai segmen batang oranye + popup "Cek Overtime" di profil
  teknisi. Laporan lama disamakan lewat `php artisan reports:pangkas-istirahat`
  (simulasi default, `--execute` menulis).

## Riwayat
- tugas/erik/alur-kerja-banyak-teknisi (mulai 2026-10-13 10:44) -> dilanjutkan 2026-10-13 11:34
