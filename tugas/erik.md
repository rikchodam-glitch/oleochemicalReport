# Papan Klaim: erik

Diperbarui: 2026-10-13 11:34 WIB

- Cabang aktif: `tugas/erik/potong-istirahat-laporan-non-shift`
- Mulai: 2026-10-13 11:34
- Status: SEDANG DIKERJAKAN
- Modul/berkas yang disentuh:
  - app/Services/Shift/JamKerjaService.php (istirahatHari/menitIstirahat/durasiKerja)
  - app/Http/Controllers/ReportController.php (update: durasi potong istirahat)
  - app/Services/Telegram/Traits/WizardStepHandlerTrait.php (Step 4 durasi)
  - resources/views/reports/partials/_waktu-kerja.blade.php (keterangan potongan)
  - app/Console/Commands/ReportPangkasIstirahatCommand.php (baru: reports:pangkas-istirahat)
  - tests/Feature/ReportIstirahatTest.php (baru)
  - AGENTS.md (keputusan arsitektur poin 31)
- Catatan: pemotongan istirahat HANYA untuk group non-shift (reguler & reguler
  staff); pekerja shift grub A-D tetap 8 jam penuh. Laporan lama disamakan lewat
  `php artisan reports:pangkas-istirahat` (simulasi default, `--execute` menulis).

## Riwayat
- tugas/erik/alur-kerja-banyak-teknisi (mulai 2026-10-13 10:44) -> dilanjutkan 2026-10-13 11:34
