# Papan Klaim: erik

Diperbarui: 2026-10-27 08:11 WIB

- Cabang aktif: `tugas/erik/filter-bagian-request-sparepart`
- Mulai: 2026-10-27 08:11
- Status: SELESAI DIUJI, SIAP PR
- Modul/berkas yang disentuh:
  - Filter Bagian pada tabel Request Spare Part (Pengajuan, Kejar PO, Kejar Delivery, Selesai)
  - Kolom Bagian + Group pada semua tabel request; filter berlaku juga untuk tabel PR Menunggu PO
  - `app/Http/Controllers/SparepartRequestController.php`
  - `resources/views/sparepart/request/index.blade.php`
  - `resources/views/sparepart/request/partials/_pengajuan.blade.php`
  - `resources/views/sparepart/request/partials/_kejar-po.blade.php`
  - `resources/views/sparepart/request/partials/_kejar-delivery.blade.php`
  - `resources/views/sparepart/request/partials/_selesai.blade.php`
  - `resources/views/sparepart/request/partials/_teknisi.blade.php`
  - `resources/views/sparepart/request/partials/_bagian.blade.php` (baru)
  - `tests/Feature/SparepartRequestWebTest.php`
  - `docs/30-request-sparepart.md`
- Catatan: 29 test request hijau, 110 assertions; seluruh suite 771 test hijau, 3.833 assertions; `view:cache` sukses.

## Riwayat
- tugas/erik/import-dokumentasi-asset (mulai 2026-10-26 10:49) -> dilanjutkan 2026-10-27 08:11
