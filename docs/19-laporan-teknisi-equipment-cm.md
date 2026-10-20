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

## PERBAIKAN 19/10/2026 — LAPORAN TEKNISI TIDAK MUNCUL DI DETAIL EQUIPMENT

Gejala: card "Laporan Teknisi (Daftar Perbaikan)" tetap 0 laporan walau teknisi
jelas pernah mengerjakan equipment tersebut. Penyebabnya DATA, bukan tampilan:

1. Laporan dari wizard Telegram sebelum 19/10/2026 hanya menyimpan `asset_id`;
   kolom `equipment_tag` selalu NULL sehingga aturan (a) tidak pernah cocok.
2. Backfill lama mengisi `equipment_tag` dengan `assets.equipment_no` (mis.
   `10002840`), padahal CM memakai `assets.tech_ident_no` (mis. `6400P5`).
3. Laporan yang dibuat tanpa memilih asset tidak punya `asset_id` maupun
   `equipment_tag` sama sekali.

Perbaikan (TANPA migrasi):

1. `Report::saving()` mengisi `equipment_tag` dari `assets.tech_ident_no` bila
   laporan punya `asset_id` dan tag masih kosong. Tag yang sudah diisi manual
   TIDAK ditimpa; kuncinya Tech Ident No, BUKAN `equipment_no`.
2. `WizardReportSaverTrait` menyimpan `equipment_tag = equipment_ident` saat
   wizard menyimpan laporan, dan tiap baris salinan multi alat memakai
   `tech_ident_no` alatnya sendiri.
3. `CmController::whereLaporanEquipment()` menyatukan tiga jalur pencocokan untuk
   card maupun tabel perbaikan: tag sama persis, `asset_id` yang
   `tech_ident_no`-nya sama, dan laporan lama tanpa tag/asset yang deskripsinya
   menyebut tag itu secara PERSIS (bukan `LIKE '%tag%'`).
4. `App\Services\EquipmentMentionService` mencocokkan penyebutan kode: abaikan
   besar/kecil huruf, pemisah boleh berbeda (`6811P05` = `6811-P05` =
   `6811 P05`), wajib di batas kata, longest match menang, dan kode di bawah 4
   karakter diabaikan. Inilah yang menjaga `2-6163P8` tetap TIDAK muncul di
   `6163P8`.
5. `php artisan reports:tag-equipment` (simulasi) / `--apply` merapikan data
   lama: tag kosong diisi dari asset, tag yang masih berisi `equipment_no`
   diganti `tech_ident_no`, dan laporan tanpa asset yang menyebut TEPAT SATU
   kode diberi tag (yang ambigu dibiarkan kosong).
6. `AssetInfoService::laporanAsset()` memakai aturan yang sama supaya balasan bot
   Telegram dan halaman asset tidak berbeda hasilnya.

Urutan deploy: `php artisan reports:tag-equipment` (periksa hasil simulasi) ->
`php artisan reports:tag-equipment --apply` -> `php artisan view:cache`.

Uji penjaga: `tests\Feature\ReportEquipmentTagTest.php` (5 test: hook saving, tag
manual tidak ditimpa, backfill mengganti `equipment_no`, backfill dari deskripsi,
tidak menebak saat ambigu) dan tambahan pada
`tests\Feature\CmEquipmentLaporanTeknisiTest.php` (laporan lama tanpa tag tetap
tampil, penyebutan kode yang mirip tidak ikut).

