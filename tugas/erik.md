# Papan Klaim: erik

Diperbarui: 2026-11-16 11:50 WIB

- Cabang aktif: `tugas/erik/perbaiki-petunjuk-deploy-dari-cabang-tugas`
- Mulai: 2026-11-16 11:48
- Status: SEDANG DIKERJAKAN
- Modul/berkas yang disentuh:
  - `tools/penjaga-main-dev.ps1` — petunjuk urutan saat copy DEV di cabang tugas
  - `tools/siapkan-deploy.ps1` — sarankan `-PindahkanPerubahan` bila ada berkas kotor
  - `tools/deploy-laporan-prod.ps1` — pesan batal deploy sesuai urutan yang benar
  - `AGENTS.md` — aturan deploy saat sesi lain masih di cabang tugas
  - `docs/02-alur-kerja-banyak-teknisi.md` — bagian 6e
- Catatan: Deploy 16/11/2026 dibatalkan karena DEV di cabang `tugas/erik/halaman-service-it` dan `app/Jobs/ProcessCmFindingsImport.php` belum di-commit. `siapkan-deploy.ps1` tidak boleh dijalankan sebelum DEV di `main`; urutannya `kembalikan-ke-main.ps1 -PindahkanPerubahan` lalu `siapkan-deploy.ps1` lalu deploy.

## Riwayat
- tugas/erik/perbaiki-tampilan-riwayat-pembacaan-dan-temuan-equipment-cm (mulai 2026-10-27 12:12) -> dilanjutkan 2026-11-16 11:48
- tugas/erik/perbaiki-petunjuk-deploy-dari-cabang-tugas (mulai 2026-11-16 11:48)
