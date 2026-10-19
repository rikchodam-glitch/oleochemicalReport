# Laporan Teknisi di Halaman Equipment CM

> Bagian dari dokumentasi proyek di folder `docs`.
> Peta dokumen: `AGENTS.md` di akar repo.

## LAPORAN TEKNISI DI HALAMAN TIAP EQUIPMENT CM (14/10/2026)

Ditambahkan 14/10/2026 atas permintaan user: "hasil laporan tiap teknisi
(laporan yang sudah punya tagging equipment) dimasukkan ke halaman tiap
equipment, jangan sampai salah equipment — hanya yang ada tagging equipment-nya
saja yang dimasukkan". Ada di copy DEV; BELUM naik ke PROD (tunggu
`Deploy-Laporan.bat`; TIDAK ada migrasi baru).

1. **Sumber & aturan pencocokan (JANGAN diubah tanpa alasan kuat).** Card
   "Laporan Teknisi (Daftar Perbaikan)" di
   `resources\views\cm\equipment-show.blade.php` dibaca dari `$laporanTeknisi`
   yang disusun `CmController::equipmentShow()`. Sebuah laporan dianggap BERTAG
   equipment X HANYA bila
   (a) `reports.equipment_tag` = `cm_equipment.equipment_tag` sama persis, ATAU
   (b) `reports.asset_id` menunjuk asset yang `tech_ident_no`-nya sama persis
   dengan tag itu (relasi `CmEquipment::asset()`).
   Pencocokan lewat deskripsi pekerjaan (`LIKE '%tag%'`) SENGAJA TIDAK dipakai di
   sini (walaupun halaman detail asset masih memakainya untuk laporan lama),
   karena laporan equipment lain yang kodenya hanya mirip akan ikut tertarik.
   Contoh nyata DEV: laporan "Balancing 2-6163P8" (asset 2647 = equipment
   `2-6163P8`) muncul di halaman asset 2052 (`6163P8`), tetapi TIDAK boleh muncul
   di halaman equipment `6163P8`. Salinan kolaborator (`collaborator_of`)
   disembunyikan supaya satu pekerjaan tampil sekali; salinan multi alat tetap
   tampil di equipment masing-masing. Data yang belum bertag tetap TIDAK tampil
   (pekerjaan area), jadi equipment tanpa laporan bertag menampilkan pesan kosong.

2. **Rincian teknis.** Paginasi memakai parameter `pageLaporan`
   (`paginate(10, ['*'], 'pageLaporan')->appends(['range' => $range])`) supaya
   tidak berebut parameter `page` dengan tabel "Riwayat Pembacaan"; card ini
   menampilkan seluruh riwayat laporan bertag equipment tersebut (TIDAK disaring
   dropdown tahun `range`). Jumlah laporan bertag sangat bergantung data: DEV
   hanya 39 laporan (356 asset terhubung ke `cm_equipment`), sedangkan PROD 196
   laporan untuk 73 equipment — jadi tidak semua halaman equipment terisi.

3. **Uji penjaga:** `tests\Feature\CmEquipmentLaporanTeknisiTest.php` (6 test) —
   laporan bertag tampil (termasuk bila `equipment_tag` laporan terisi langsung),
   laporan equipment lain yang kodenya disebut di deskripsi TIDAK tampil,
   pekerjaan area tanpa tag tidak tampil, dan equipment tanpa laporan bertag
   menampilkan pesan kosong.

