# Perbaiki dashboard Sparepart: semua PR, MRP, dan label

## Ringkasan

- Menampilkan **semua nomor PR/PO** yang terbaca, bukan hanya satu dokumen
  terpilih. `sp_material.pr_open_nums` dan `po_open_nums` menyimpan nomor yang
  masih terbuka; seluruh nomor tetap ada di `pr_nums`/`po_nums`.
- Menambah **import EXPORT MRP.XLSX** (SAP MD07) lewat form dan perintah
  `sparepart:import --mrp=...`, beserta kolom `Stock by SAP`, `Safety Stk`,
  `Stok Min (MRP)`, `Unit`, `Tipe MRP`, `Min Monitoring vs MRP`, dan
  `MRP Diperbarui` pada tabel serta export.
- Mengganti label tampilan: `Kejar PO` -> **Kejar Delivery**,
  `Buat PO` -> **Kejar PO**. Nilai internal `KEJAR KEDATANGAN (PO OPEN)` dan
  `BUAT PO` tidak berubah supaya data/URL lama tetap valid.
- Merapikan tabel: header lengket, zebra/hover, badge, kolom Purchaser/Vendor,
  kolom MRP, filter `punya=mrp`, dan panel rincian empat kartu.

## Bukti

- Data DEV sebelumnya sudah memuat semua PR: 183 material punya >1 baris PR.
  Contoh `30003838` punya 2 PR terbuka (`1000004103`, `1000004108`); sebelumnya
  tabel hanya menampilkan `1000004108`.
- Import #16 dari `D:\RYAN\Dasboard\EPE\IMPORT`:
  stok 3.328 baris/2.775 material, PR 14.557/1.664, PO 14.835/1.521,
  MRP 566/566. Setelah import, `30003838` menampilkan kedua PR terbuka.
- Kartu aksi DEV: Kejar Delivery 25 / Kejar PO 51 / Cek-Buat PR 45 / Buat PR 28
  dari 149 item REORDER, sama dengan sheet "Daftar Aksi" dashboard lama.
- Halaman `/sparepart`, `/sparepart/impor`, `/sparepart/riwayat`, dan
  `/sparepart?punya=mrp` HTTP 200.

## Uji

- `php artisan test --filter=Sparepart`: **38 test hijau, 252 assertions**.
- `php artisan test` (seluruh suite): **669 test hijau, 3.277 assertions**.
- Tiga test man hour yang semula gagal karena memakai `now()->subDay()` (pada
  19/10/2026 jatuh hari Minggu) distabilkan dengan tanggal tetap hari kerja
  `2026-10-15`; tidak ada logika aplikasi yang diubah.
- `php artisan view:cache` sukses.

## Berkas

- `database/migrations/2026_10_19_000001_add_mrp_and_open_numbers_to_sparepart.php`
- `app/Models/SpMaterial.php`, `app/Models/SpImpor.php`
- `app/Services/Sparepart/SparepartReader.php`
- `app/Services/Sparepart/SparepartImportService.php`
- `app/Services/Sparepart/Concerns/SparepartHitungTrait.php`
- `app/Http/Controllers/SparepartController.php`
- `app/Http/Controllers/Traits/SparepartExportTrait.php`
- `app/Console/Commands/SparepartImportCommand.php`
- `resources/views/sparepart/{index,impor,riwayat}.blade.php`
- `resources/views/sparepart/partials/_peringatan-cakupan.blade.php`
- `tests/Feature/SparepartMultiPrMrpTest.php` (baru) + test Sparepart lama
- `tests/Feature/ReportEditAlatTambahanTest.php`,
  `tests/Feature/ReportEditKolaborasiTest.php`,
  `tests/Feature/ReportTeknisiKolaborasiTest.php` (stabilisasi tanggal uji)
- `docs/15-modul-spare-part.md`
- `tugas/erik.md`
