# Rekonstruksi Assets Management

## Ringkasan

- Pencarian asset langsung memfilter setelah berhenti mengetik (debounce).
- Kolom Status dan Aksi dihapus dari tabel; detail dibuka dengan klik baris/kode.
- Detail asset punya tombol Edit + Hapus berdampingan; status tidak ditampilkan.
- Footer/detail menampilkan Ringkasan Equipment CM yang tertaut.
- Tombol Sinkronisasi CM membuat asset untuk equipment CM yang belum ada dan
  melewati yang sudah ada.
- Foto equipment bisa lebih dari satu: upload multiple, preview, hapus per foto.
- Data Source `cm_sync` ditampilkan sebagai "Sinkron CM".
- Opsi lokasi (Assets, Teknisi, Laporan, Bot) memakai hierarki Functional
  Location; PT CES kini hanya menampilkan 1 departemen (CES-PROD).

## Data

- Migrasi baru: `assets.photos` JSON dan nilai `cm_sync` pada enum
  `assets.data_source`.
- Foto disimpan di disk `public` folder `assets/photos/{asset_id}`.
- Menghapus asset menghapus file fotonya.
- Sinkronisasi CM tidak menimpa asset yang sudah ada.

## Berkas

- `app/Http/Controllers/AssetController.php`
- `app/Models/Asset.php`
- `resources/views/assets/index.blade.php`, `show.blade.php`, `edit.blade.php`
- `resources/views/assets/partials/_cm-ringkasan.blade.php` (baru)
- `database/migrations/2026_10_22_000002_add_photos_to_assets_table.php` (baru)
- `database/migrations/2026_10_22_000003_add_cm_sync_to_assets_data_source.php` (baru)
- `routes/web.php`
- `tests/Feature/AssetManagementTest.php` (baru)
- `docs/28-assets-management.md`, `AGENTS.md`
- `tugas/erik.md`

## Uji

- `AssetManagementTest`: 6 test hijau, 26 assertion.
- Seluruh suite: 722 test hijau, 3.540 assertion.
- `view:cache` sukses.
