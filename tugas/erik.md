# Papan Klaim: erik

Diperbarui: 2026-10-14 15:45 WIB

- Cabang aktif: `tugas/erik/tambah-laporan-teknisi-di-halaman-tiap-equipment-cm`
- Mulai: 2026-10-14 15:31
- Status: SELESAI DIKERJAKAN — uji hijau (621 test lulus, 0 gagal)
- Modul/berkas yang disentuh:
  - `app/Http/Controllers/CmController.php` — `equipmentShow()`: query baru `$laporanTeknisi` (hasil laporan teknisi yang BERTAG equipment ini) dikirim ke view
  - `resources/views/cm/equipment-show.blade.php` — card baru "Laporan Teknisi (Daftar Perbaikan)" + tautan "Buka di Asset Management"
  - `tests/Feature/CmEquipmentLaporanTeknisiTest.php` (baru, 6 test)
  - `AGENTS.md` — bagian baru "LAPORAN TEKNISI DI HALAMAN TIAP EQUIPMENT CM (14/10/2026)"
  - `tugas/erik.md` + `tugas/pr-tambah-laporan-teknisi-di-halaman-tiap-equipment-cm.md`
- Catatan: sebuah laporan dianggap bertag equipment X HANYA bila `reports.equipment_tag` = `cm_equipment.equipment_tag` (sama persis) atau `reports.asset_id` menunjuk asset yang `tech_ident_no`-nya sama persis dengan tag itu. Pencocokan lewat deskripsi pekerjaan (`LIKE '%tag%'`) SENGAJA TIDAK dipakai (beda dengan halaman detail asset) supaya laporan equipment lain yang kodenya hanya mirip tidak ikut tampil — contoh nyata DEV: "Balancing 2-6163P8" (milik equipment `2-6163P8`) tampil di halaman asset `6163P8` tetapi TIDAK di halaman equipment `6163P8`. Salinan laporan kolaborator (`collaborator_of`) disembunyikan supaya satu pekerjaan tampil sekali. Paginasi card ini memakai parameter `pageLaporan` agar tidak berebut `page` dengan tabel Riwayat Pembacaan.

## Riwayat
- tugas/erik/grafik-jam-kerja-hari-off-dan-optimalisasi-grafik-man-hour (mulai 2026-10-14 14:35) -> SELESAI, sudah di-merge (PR #10)
- tugas/erik/warna-seragam-jenis-pekerjaan (mulai 2026-10-14 15:21) -> SELESAI, sudah di-merge (PR #11). Berkas: `app/Support/WarnaJenisPekerjaan.php` (baru), `TechnicianKinerjaService`, `Concerns/ResolvesReportAnalytics`, `reports/index` + `reports/show`, `_popup-export`, `technicians/show`, `work-orders/index` + `show`, `_grafik-perbaikan-script`, `WarnaJenisPekerjaanTest` (6 test), `AGENTS.md`. Tanpa migrasi/perubahan data.
- tugas/erik/tambah-trend-dan-analisa-pressure-serta-ampere-di-semua-halaman-equipment-cm (mulai 2026-10-14 11:42) -> SELESAI + diunggah, MENUNGGU tombol "Create pull request" lalu Merge oleh integrator
- tugas/erik/tambah-laporan-teknisi-di-halaman-tiap-equipment-cm (mulai 2026-10-14 15:31) -> cabang ini (tugas BERBEDA, bukan lanjutan cabang trend)
