# Pencarian Asset Management dan IT Assets

> Bagian dari dokumentasi proyek di folder `docs`.
> Peta dokumen: `AGENTS.md` di akar repo.
>
> Perubahan 23/11/2026. Tidak ada migrasi baru dan tidak ada perubahan data.

## Latar dan keputusan user

Setelah halaman Laporan mendapat kotak pencarian pintar (docs/34), user meminta
alat pencarian dengan performa yang sama pada halaman **Assets** dan
**Assets IT**: kotak pencarian besar, saran responsif/informatif, dan pencarian
multi-kata yang tidak terhalang filter lokasi/tipe.

Keputusan yang dipakai:

1. Kotak pencarian utama di atas halaman, bersifat **global**: form hanya
   mengirim kata kunci sehingga filter lokasi/tipe/plant/VLAN yang sedang aktif
   tidak menyembunyikan hasil.
2. Filter tetap tersedia di panel Filter; menerapkan filter **membawa kata
   kunci** lewat isian tersembunyi. Panel Filter tidak lagi memuat input
   pencarian.
3. Mesin pencarian multi-kata + prioritas hasil, tanpa FULLTEXT/Scout.
4. Dropdown saran memakai bentuk item yang sama di kedua halaman.

## Perilaku UI (kedua halaman)

- Kotak pencarian utama di bagian atas, debounce 300 ms, minimal 2 huruf,
  jawaban usang dibatalkan (`AbortController`).
- Navigasi panah atas/bawah, Enter untuk memilih/mencari, Esc menutup dropdown,
  atribut ARIA combobox/listbox.
- Teks saran dirender sebagai teks; sorotan kata memakai HTML yang sudah
  di-escape (`sorot()`), sehingga data database tidak bisa menyisipkan HTML.
- Banner hasil: "Hasil pencarian '...' di seluruh data • N aset/perangkat" +
  tautan hapus pencarian.
- Panel Filter tidak terbuka otomatis hanya karena ada kata kunci; filter
  lokasi/tipe tetap membuka panel seperti biasa.
- Input lama di panel Filter dihapus:
  - Assets: `id="searchAsset"` kini milik kotak utama.
  - IT Assets: `id="searchItAsset"` untuk kotak utama.

## Pencarian Asset Management

Aturan kata kunci (`app/Services/PencarianAsetService.php`):

- **AND antar kata**, **OR antar kolom/relasi** per kata.
- Kolom langsung: `equipment_no`, `tech_ident_no`, `description`,
  `functional_loc`, `equipment_type`, `object_type`, `manufacturer`,
  `model_number`.
- Relasi: PT (`company`), departemen, area, sub area, Functional Location
  (kode/nama), dan perangkat IT cermin (`itAsset`: tag/nama/IP).
- Sinonim ringan dari `config('pencarian_entitas.sinonim_aset')`
  (pompa↔pump, valve↔kran, bearing↔bantalan, dll.).
- Urutan hasil: kode alat persis → awalan kode alat → equipment no → deskripsi
  prefix/contains → tipe/lokasi; lalu `created_at` terbaru.

Dropdown saran (`GET /assets/cari?q=...`, nama rute `assets.cari`):

- **Aset** (maks. 5): kode alat (mono), deskripsi, meta tipe/area/funcloc.
  Klik membuka detail asset.
- **Tipe** (maks. 3): `equipment_type` yang cocok, meta jumlah aset. Klik
  mengirim `equipment_type` ke halaman Assets.
- **Lokasi** (maks. 3): Functional Location (kode/nama), meta level + jumlah
  aset langsung. Klik mencari kode lokasi di halaman Assets, sehingga asset
  pada turunan kode tersebut ikut muncul.

## Pencarian IT Assets

Aturan kata kunci (`app/Services/PencarianItAssetService.php`):

- **AND antar kata**, **OR antar kolom/relasi** per kata.
- Kolom langsung: `tag_name`, `name`, `ip_address`, `brand`, `model`,
  `serial_number`, `plant`, `department`, `location`, `application`,
  `device_type`.
- Relasi: Functional Location (kode/nama).
- Label/kode tipe ikut dicari: "laptop" menemukan `LAPTOP`, "switch managed"
  menemukan `SWITCH_MANAGED`. Sinonim dari
  `config('pencarian_entitas.sinonim_it')` (kamera↔CCTV, komputer↔PC, dst.)
  dipakai untuk daftar hasil.
- Urutan hasil: tag persis → awalan tag → IP persis → awalan IP → nama →
  pabrikan/model/serial → lokasi/plant; lalu tag.
- Sort kolom dari header tabel tetap menang bila dipilih pengguna. Mode layout
  CCTV tidak memakai urutan relevansi (dikelompokkan per PT/switch).

Dropdown saran (`GET /it-assets/cari?q=...`, nama rute `it-assets.cari`):

- **Perangkat** (maks. 5): tag (mono), nama, meta tipe/IP/plant/lokasi. Klik
  membuka detail perangkat.
- **Tipe** (maks. 3): tipe perangkat dari kode/label (pencocokan AND antar
  kata), meta jumlah perangkat. Klik mengirim `device_type`.
- **Plant** (maks. 3): plant yang cocok, meta jumlah perangkat. Klik mengirim
  `plant`.
- **Lokasi** (maks. 3): nilai kolom `location` yang cocok, meta jumlah
  perangkat. Klik mengisi kata kunci lokasi.
- **VLAN** (maks. 3): hanya bila kata kunci memuat angka; diambil dari
  `ip_networks.vlan`, meta jumlah perangkat pada `192.168.<vlan>.x`. Klik
  mengirim `vlan`.

## Endpoint dan pengamanan

- `GET /assets/cari` dan `GET /it-assets/cari`, hanya pengguna login (grup
  admin), `throttle:120,1`.
- Kata kunci < 2 huruf mengembalikan `{total: 0, kelompok: []}` tanpa query.
- Respons: `{kata_kunci, total, kelompok: [{tipe,label,jumlah,items}]}`; item
  `{kunci,judul,subjudul,meta,url}`.
- Karakter wildcard LIKE (`%`, `_`, `\`) dibuang oleh
  `app/Support/PencarianKataKunci.php`.

## Catatan teknis

Parameter `?` di dalam ekspresi `ORDER BY CASE ...` tidak diikat dengan benar
oleh SQLite. Karena itu skor relevansi dihitung sebagai kolom terpilih
`relevansi` lalu diurutkan lewat alias, sama seperti pencarian laporan.
Atribut `relevansi` disembunyikan dari model sebelum dikirim ke view.

## Berkas

- `app/Support/PencarianKataKunci.php` (baru)
- `config/pencarian_entitas.php` (baru)
- `app/Services/PencarianAsetService.php` (baru)
- `app/Services/PencarianItAssetService.php` (baru)
- `app/Http/Controllers/AssetSearchController.php` (baru)
- `app/Http/Controllers/ItAssetSearchController.php` (baru)
- `app/Http/Controllers/AssetController.php`
- `app/Http/Controllers/ItAssetController.php`
- `routes/web.php`
- `resources/views/partials/_pencarian-entitas.blade.php` (baru)
- `resources/views/assets/index.blade.php`
- `resources/views/it-assets/index.blade.php`
- `tests/Feature/AssetSearchTest.php` (baru)
- `tests/Feature/ItAssetSearchTest.php` (baru)

## Uji

- `AssetSearchTest`: 8 test (multi-kata, relasi, baris cermin IT, ranking,
  kelompok saran, kata kunci pendek, tamu, kotak utama + filter).
- `ItAssetSearchTest`: 9 test (kolom lengkap, label/sinonim tipe, ranking,
  kelompok perangkat/plant/lokasi, tipe + VLAN, kata kunci pendek, tamu,
  kotak utama + filter, mode layout CCTV).
- Total uji baru: **17 test, 147 assertion**.
- Seluruh suite 23/11/2026: **871 test hijau, 4.519 assertion**.
- `php artisan view:cache` sukses.
- Verifikasi DEV nyata: `/assets?search=pompa` (967 aset), `/assets/cari?q=cctv`
  (kelompok aset + tipe), `/it-assets?search=cctv` (95 perangkat),
  `/it-assets/cari?q=kamera` (kelompok perangkat + tipe), dan mode layout CCTV
  dengan pencarian.
