# Hierarki Lokasi pada Dropdown

Dokumen ini menjelaskan sumber opsi lokasi setelah perbaikan 22/10/2026.
Sebelumnya beberapa dropdown memakai tabel master `departments`/`areas`/
`sub_areas` yang isinya tidak sama dengan pohon Functional Location.

## Sumber tunggal

`App\Services\LocationHierarchyService` menurunkan opsi dari
`functional_locations`:

- PT/Company → node level 0
- Departemen → node level 1
- Area → node level 2
- Sub Area → node level 3

Nilai yang dikirim ke form tetap ID kolom lama
(`department_id`/`area_id`/`sub_area_id`) karena tabel `assets`, `reports`,
dan `technicians` masih memakai kolom tersebut. ID diturunkan lewat
`AssetLocationService` (department_id node, lalu fallback kode segmen).

## Yang memakai sumber ini

- Filter Assets Management: Company → Dept → Area → Sub Area.
- Endpoint AJAX `/assets/locations/{departments,areas,subareas}`.
- Filter dan form Teknisi (dropdown Departemen).
- Filter dan form Laporan (dropdown Area).
- Filter Departemen pada halaman Bot Telegram.

Jika nilai lama tidak ada di pohon FuncLoc (mis. data laporan/teknisi lama),
nilai tersebut ditambahkan sebagai opsi fallback di form terkait agar data lama
tidak hilang.

## Contoh perbaikan

- PT **CES** sekarang menampilkan **1 departemen**: `CES-PROD`.
- PT **NPA** menampilkan 3 departemen.
- PT **EPE** menampilkan 5 departemen.
- Area CES diturunkan dari 19 node level 2 di bawah `CES-PROD`, bukan dari 13
  departemen master lama.

## Berkas

- `app/Services/LocationHierarchyService.php` (baru)
- `app/Services/AssetLocationService.php`
- `app/Http/Controllers/AssetController.php`
- `app/Http/Controllers/Traits/AssetLocationTrait.php`
- `app/Http/Controllers/TechnicianController.php`
- `app/Http/Controllers/ReportController.php`
- `app/Http/Controllers/BotController.php`
- `tests/Feature/AssetManagementTest.php`

## Uji

- Test dropdown: PT CES hanya mengembalikan satu departemen dari FuncLoc.
- `AssetManagementTest`: 6 test hijau, 26 assertion.
- Seluruh suite: 722 test hijau, 3.540 assertion.
- `view:cache` sukses.
