# Papan Klaim: erik

Diperbarui: 2026-10-14 13:59 WIB

- Cabang aktif: `tugas/erik/kelola-laporan-hanya-dari-induk`
- Mulai: 2026-10-14 13:59
- Status: SEDANG DIKERJAKAN
- Modul/berkas yang disentuh:
  - app/Services/ReportCopyService.php (bidangPekerjaan/bidangLokasi + sinkronkanSalinan())
  - app/Http/Controllers/ReportController.php (penjagaKelolaInduk pada update/updateStatus/destroy + penyebaran ke salinan)
  - resources/views/reports/show.blade.php (Aksi Admin: cabang induk vs baris salinan, popup penolakan)
  - resources/views/reports/partials/_penolakan-salinan.blade.php (BARU: popup penolakan + tombol Cek Laporan Induk)
  - tests/Feature/ReportKelolaHanyaDariIndukTest.php (BARU: 12 test, 71 assertions)
  - tests/Feature/ReportDetailTampilanTest.php (jumlah x-teleport 3 -> 4 karena popup baru)
  - AGENTS.md (aturan pengelolaan laporan hanya dari laporan induk)
- Catatan: baris salinan (kolaborasi & alat/lokasi tambahan) tidak bisa diedit/dihapus dari barisnya sendiri; perubahan laporan INDUK disebarkan ke seluruh salinannya, dan hapus induk membersihkan semua salinannya.

## Riwayat
- tugas/erik/tambah-trend-dan-analisa-pressure-serta-ampere-di-semua-halaman-equipment-cm (mulai 2026-10-14 11:42) -> dilanjutkan 2026-10-14 13:59
