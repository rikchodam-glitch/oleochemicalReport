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
- `database/migrations/2026_10_22_000003_add_cm_sync_to_assets_data_source.php`
- `routes/web.php`
- `tests/Feature/AssetManagementTest.php`

## Uji

- `AssetManagementTest`: 6 test hijau, 26 assertion.
- Seluruh suite: 722 test hijau, 3.540 assertion.
- `view:cache` sukses.

## INTEGRASI ASSETS MANAGEMENT ↔ CM EQUIPMENT + GALERI FOTO — 23/10/2026

Latar: user meminta detail Assets Management dan halaman CM Equipment
terhubung lebih jelas, foto equipment menyatu dengan Informasi Asset /
Ringkasan Equipment dalam bentuk slideshow, dan foto yang ditambahkan di
Assets Management ikut tampil di halaman CM.

Aturan yang dikerjakan:

- **Satu sumber foto** tetap `assets.photos` (JSON). Halaman CM membaca
  `photo_urls` dari asset yang bertaut (`cm_equipment.equipment_tag` =
  `assets.tech_ident_no`); tidak ada tabel/salinan foto baru. Foto yang
  ditambahkan lewat Edit Asset muncul di halaman CM setelah halaman dimuat
  ulang.
- **Detail asset**:
  - Preview CM tetap menampilkan statistik (readings, findings, last reading,
    vibrasi/temp maks, lubrikasi) dan kini punya tombol **Buka Halaman CM**
    yang jelas di header serta di kartu ringkasan.
  - Foto equipment dipindah ke dalam kartu **Informasi Asset** memakai partial
    `assets/partials/_foto-equipment.blade.php`.
- **Halaman CM Equipment**:
  - Deskripsi asset di header menjadi tautan ke detail Asset Management.
  - Foto equipment tampil di kartu **Foto Equipment** yang menempel langsung
    di bawah kartu **Ringkasan Equipment**, dengan tautan **Buka Asset
    Management**.
  - Asset manual (`data_source = 'manual'`) tetap menyembunyikan spek SAP,
    tetapi foto dan tautan Asset Management TETAP ditampilkan. Caranya:
    `CmController::equipmentShow()` menyimpan `$assetTerkait` sebelum relasi
    asset manual di-null-kan.
- **CM Equipment Status**: nama equipment yang punya asset menjadi tautan ke
  detail Asset Management; bila belum ada asset, tautan tetap menuju halaman
  CM equipment.
- **Galeri foto** (`partials/_galeri-foto-aset.blade.php` + komponen Alpine
  `galeriFotoAset`): tampilan seperti dokumentasi report (foto besar,
  sebelumnya/berikutnya, keyboard kiri/kanan, swipe, strip thumbnail, dan
  penghitung posisi). Galeri bersifat **lihat saja**; upload/hapus tetap di
  halaman Edit Asset.
- **Tanpa migrasi baru**. Di DEV, migration lama
  `2026_10_21_000001_add_active_status_to_cm_equipment` sempat tertunda
  sehingga halaman CM Equipment Status 500; migration itu dijalankan di DEV
  (bukan PROD) sebelum verifikasi.
- Berkas: `app/Http/Controllers/CmController.php`,
  `resources/views/assets/show.blade.php`,
  `resources/views/assets/partials/_cm-ringkasan.blade.php`,
  `resources/views/assets/partials/_foto-equipment.blade.php` (baru),
  `resources/views/partials/_galeri-foto-aset.blade.php` (baru),
  `resources/views/cm/equipment-show.blade.php`,
  `resources/views/cm/equipment-status.blade.php`.
- Uji baru `tests/Feature/AssetCmIntegrationTest.php` (4 test, 21 assertion):
  preview CM + galeri di detail asset, foto + tautan di halaman CM, foto yang
  diunggah lewat Edit Asset muncul di CM, dan nama equipment di CM Equipment
  Status menaut ke detail asset.
- Uji: `php artisan test` **738 test hijau, 3.668 assertions**;
  `php artisan view:cache` sukses.

## PENYEMPURNAAN FOTO: CAROUSEL DI SAMPING DETAIL — 23/10/2026

Latar: user meminta foto equipment tampil dalam SATU kotak yang berganti
otomatis dan bisa digeser manual, lalu klik foto membuka mode geser
maximized. Posisi foto harus tepat di samping detail: pada **Informasi Asset**
di samping Deskripsi dll, dan pada **Ringkasan Equipment** CM di samping
Company dll.

Perubahan:

- `assets/partials/_foto-equipment.blade.php` tidak lagi berupa grid thumbnail;
  kini berupa **satu kotak carousel**:
  - foto berganti otomatis tiap 5 detik (berhenti saat kursor di atas kotak
    atau saat galeri maximized dibuka),
  - tombol panah kiri/kanan, titik navigasi, dan geser (swipe) untuk manual,
  - klik foto membuka galeri maximized di posisi foto yang sedang tampil,
  - `baruGeser` mencegah klik palsu setelah swipe supaya galeri tidak
    terbuka tanpa sengaja.
- Detail Asset Management: kartu Informasi Asset dibagi
  **3/5 detail + 2/5 foto** pada layar lebar, sehingga foto berada tepat di
  samping Deskripsi/Tech Ident/Type dll.
- Halaman CM Equipment: grid Ringkasan Equipment menjadi
  **1/4 spek + 1/4 foto + 2/4 statistik**, sehingga foto berada tepat di
  samping Company/Equipment No/Tech Ident dll. Kartu foto terpisah di bawah
  Ringkasan Equipment dihapus.
- `partials/_galeri-foto-aset.blade.php`: state carousel (`indeksSlide`,
  `slideTimer`, `baruGeser`) dan galeri maximized memakai sumber foto yang
  sama; auto-slide berhenti saat galeri dibuka dan lanjut lagi setelah ditutup.
- Uji `AssetCmIntegrationTest` ditambah assertion `x-init="mulaiSlide()"` dan
  `aspect-[4/3]` (4 test, 25 assertion).
- Uji: `php artisan test` (seluruh suite) **738 test hijau, 3.672 assertions**;
  `php artisan view:cache` sukses.

## PERBAIKAN POSISI FOTO: KIRI DETAIL — 23/10/2026

- Foto dipindahkan ke **KIRI** detail dengan ukuran tetap seperti sebelumnya:
  - Detail Asset Management: **2/5 foto + 3/5 detail** (foto di kiri
    Deskripsi/Tech Ident/Type dll).
  - CM Equipment: **1/4 foto + 1/4 spek + 2/4 statistik** (foto di kiri
    Company/Equipment No/Tech Ident dll).
- Verifikasi DEV: pada `/assets/2071` posisi `Foto Equipment` muncul sebelum
  `Deskripsi`; pada `/cm/equipment/6400P5` muncul sebelum `Company`. Keduanya
  HTTP 200.
- Uji: seluruh suite **738 test hijau, 3.672 assertions**; `view:cache` sukses.
