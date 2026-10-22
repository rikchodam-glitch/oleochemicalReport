# Rekonstruksi Assets Management

Dokumen ini mencatat perubahan halaman Assets Management (22/10/2026).

## Daftar asset

- Pencarian langsung: mengetik di kolom pencarian mengirim form setelah
  pengguna berhenti mengetik (debounce 450 ms) sehingga equipment muncul tanpa
  menekan Tombol Terapkan.
- Kolom **Status** dan **Aksi** dihapus dari tabel.
- Baris tabel dapat diklik (atau tekan Enter) untuk membuka detail.
- Kode alat juga berupa tautan langsung ke detail.
- Filter Status dihapus dari panel; filter lokasi/tipe tetap.
- Opsi PT/Dept/Area/SubArea diturunkan dari hierarki Functional Location
  (bukan tabel master departments/areas yang lama).
- Tombol **Sinkronisasi CM** menampilkan jumlah equipment CM yang belum punya
  asset.

## Detail asset

- Tombol **Edit Asset** dan **Hapus Asset** berdampingan di header.
- Status asset tidak lagi ditampilkan.
- Foto equipment ditampilkan dalam galeri; pengelolaan foto ada di halaman
  edit.
- Ringkasan Equipment (CM) ditampilkan bila ada `cm_equipment.equipment_tag`
  yang sama dengan `assets.tech_ident_no`:
  total readings, findings open/closed, last reading + kondisi, vibrasi maks,
  temperatur maks, tipe lubrikasi, dan tautan ke halaman CM.
- Data Source `cm_sync` ditampilkan sebagai "Sinkron CM".

## Foto equipment

- Kolom `assets.photos` (JSON) menyimpan banyak path.
- Upload baru di halaman edit: `photos[]` multiple, preview di browser.
- Foto lama dapat dihapus dengan checkbox `hapus_foto[]`; file fisik ikut
  dihapus dari disk `public`.
- Foto disimpan di `assets/photos/{asset_id}`.
- Menghapus asset ikut menghapus seluruh file fotonya.

## Sinkronisasi CM ke Assets

Tombol **Sinkronisasi CM** (`POST /assets/sync-cm`) membuat asset untuk setiap
`cm_equipment` yang belum punya asset dengan `tech_ident_no` sama
(case-insensitive, trim):

- `tech_ident_no` = `equipment_tag`
- `description` = `equipment_tag`
- lokasi diambil dari FuncLoc dengan `code` = `pt_location`/`plant`; jika tidak
  ada, memakai FuncLoc level 0 pertama
- `data_source = cm_sync`, status `active`
- asset yang sudah ada dilewati
- equipment tanpa FuncLoc yang bisa dipakai dilewati dan dihitung di pesan.

Sinkronisasi ini hanya membuat asset baru; tidak menimpa data asset yang sudah
ada.

## Berkas

- `app/Http/Controllers/AssetController.php`
- `app/Models/Asset.php`
- `resources/views/assets/index.blade.php`
- `resources/views/assets/show.blade.php`
- `resources/views/assets/edit.blade.php`
- `resources/views/assets/partials/_cm-ringkasan.blade.php`
- `database/migrations/2026_10_22_000002_add_photos_to_assets_table.php`
- `routes/web.php`
- `tests/Feature/AssetManagementTest.php`

## Uji

- `AssetManagementTest`: 6 test hijau, 26 assertion.
- Seluruh suite: 722 test hijau, 3.540 assertion.
- `view:cache` sukses.
