# Papan Klaim: erik

Diperbarui: 2026-10-19 09:37 WIB

- Cabang aktif: `tugas/erik/perbaiki-dashboard-sparepart-pr-dan-mrp`
- Mulai: 2026-10-19 09:37
- Status: SEDANG DIKERJAKAN
- Modul/berkas yang disentuh:
  - `app/Models/SpMaterial.php`
  - `app/Services/Sparepart/SparepartReader.php`
  - `app/Services/Sparepart/SparepartImportService.php`
  - `app/Services/Sparepart/Concerns/SparepartHitungTrait.php`
  - `app/Http/Controllers/SparepartController.php`
  - `app/Http/Controllers/Traits/SparepartExportTrait.php`
  - `resources/views/sparepart/index.blade.php`
  - `resources/views/sparepart/impor.blade.php`
  - `database/migrations/2026_10_19_000001_add_mrp_and_open_numbers_to_sp_material.php`
  - `tests/Feature/Sparepart*Test.php`, `tests/Pest.php`
- Catatan: menambah import/kolom MRP dan menampilkan semua nomor PR.

## Riwayat
- tugas/erik/pisahkan-dokumentasi-agents-ke-docs (mulai 2026-10-19 09:21) -> dilanjutkan 2026-10-19 09:37
