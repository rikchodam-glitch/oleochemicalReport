# Dashboard Sparepart: stok min MRP Safety Stk, Stok Beda, history PR, filter header

## Ringkasan

- Stok minimum halaman Sparepart kini memakai **Safety Stk EXPORT MRP**
  (MD07/MD04), bukan lagi kolom `Jumlah` pada daftar pantau. Material yang
  tidak ada di berkas MRP diberi keterangan **Belum MRP** dan tidak dinilai
  REORDER (tanpa fallback stok minimum manual).
- Stok gudang tetap diambil dari **Laporan Stok Gudang G001** (stok awal +
  masuk - keluar per kode material).
- Indikator **Min Beda** diganti **Stok Beda**: membandingkan Sisa Stok G001
  dengan `Plnt stock` MRP (Stock by SAP), dengan nilai Stok Sama/Stok Beda.
- **History PR** dari EXPORT PR ME5A disimpan per material (`pr_history`) dan
  ditampilkan pada panel rincian sebagai tabel per nomor PR (status, qty,
  tanggal kirim, nomor PO, pemohon).
- **Filter header tabel**: klik judul kolom membuka kontrol saringan sesuai
  karakteristik data (teks, rentang angka, pilihan status/aksi/PR/PO/MRP,
  rentang tanggal). Saringan cepat di atas tabel tetap tersedia.
- Export Excel ikut diperbarui: `Stok Min (MRP)`, `Stok Gudang G001`,
  `Reorder Pt (MRP)`, `Stok Beda`, dan status `Belum MRP`.

## Bukti

- Migrasi baru `2026_10_23_000001_add_pr_history_to_sparepart` (kolom JSON
  `sp_material.pr_history`).
- DEV diimpor ulang penuh (import #18) dari `D:\RYAN\Dasboard\EPE\IMPORT`:
  REORDER 337, aman 95, Belum MRP 17; `pr_history` terisi untuk 449 material.
  Import #19 identik menghasilkan **tetap 449** (idempoten).
- Halaman `/sparepart` dan seluruh kombinasi saringan kolom HTTP 200.
- Catatan: REORDER naik dari 149 (aturan lama) menjadi 337 karena Safety Stk
  MRP umumnya lebih tinggi daripada stok minimum manual. Ini akibat keputusan
  user 23/10/2026 (Safety Stk dipilih sebagai stok minimum).
- PROD tidak disentuh.

## Uji

- `php artisan test --filter=Sparepart`: **42 test hijau, 313 assertions**.
- `php artisan test` (seluruh suite): **734 test hijau, 3.642 assertions**.
- `php artisan view:cache`: sukses.

## Berkas

- `database/migrations/2026_10_23_000001_add_pr_history_to_sparepart.php`
- `app/Models/SpMaterial.php`
- `app/Services/Sparepart/Concerns/SparepartHitungTrait.php`
- `app/Services/Sparepart/SparepartImportService.php`
- `app/Services/Sparepart/SparepartPantauService.php`
- `app/Services/Sparepart/SparepartReader.php`
- `app/Http/Controllers/SparepartController.php`
- `app/Http/Controllers/Traits/SparepartExportTrait.php`
- `app/Console/Commands/SparepartImportCommand.php`
- `resources/views/sparepart/{index,impor,pantau,riwayat}.blade.php`
- `tests/Pest.php`, `tests/Feature/Sparepart*`
- `docs/15-modul-spare-part.md`
