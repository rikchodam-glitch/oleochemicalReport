# Papan Klaim: erik

Diperbarui: 2026-10-14 09:57 WIB

- Cabang aktif: `tugas/erik/siapkan-deploy-aman-saat-berkas-belum-di-commit`
- Mulai: 2026-10-14 09:57
- Status: SEDANG DIKERJAKAN
- Modul/berkas yang disentuh:
  - tools/siapkan-deploy.ps1 (BARU: penyeragam commit DEV yang aman saat ada berkas belum di-commit)
  - tools/penjaga-main-dev.ps1 (hanya PESAN saat fast-forward terhalang berkas lokal)
  - tools/kembalikan-ke-main.ps1 (hanya PESAN saat fast-forward terhalang berkas lokal)
  - tools/deploy-laporan-prod.ps1 (hanya kalimat saran saat deploy dibatalkan penjaga)
  - tests/Feature/SiapkanDeployTest.php (BARU: uji otomatis alat kerja penyiapan deploy)
  - AGENTS.md (catatan alat baru + riwayat insiden baru)
  - tugas/pr-siapkan-deploy-aman-saat-berkas-belum-di-commit.md (isi Pull Request)
- Catatan: pekerjaan sesi lain yang belum di-commit di copy DEV ini (.env.example, .gitignore,
  RENCANA_MODUL_IT_ASSETS.md, RENCANA_MODUL_RUANG_PANEL.md, app/Jobs/ProcessCmFindingsImport.php,
  resources/views/reports/partials/_temuan.blade.php, app/Console/Commands/CmDriveCheck.php, .kilo/)
  TIDAK disentuh dan TIDAK ikut di-commit.

## Riwayat
- tugas/erik/sync-cm-impor-langsung-di-dev (mulai 2026-10-13) -> dilanjutkan 2026-10-14 09:57
