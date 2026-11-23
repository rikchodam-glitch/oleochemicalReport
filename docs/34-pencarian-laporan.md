# Pencarian Laporan Pintar (halaman Laporan)

> Bagian dari dokumentasi proyek di folder `docs`.
> Peta dokumen: `AGENTS.md` di akar repo.
>
> Perubahan 23/11/2026. Tidak ada migrasi baru dan tidak ada perubahan data.

## Latar dan keputusan user

Pencarian lama sulit dipakai karena kata kunci hanya mencari di tanggal aktif
(default hari ini) dan tab Reguler/Shift yang sedang dipilih. User meminta kotak
pencarian seperti online shop: data banyak, saran responsif, dan informatif.

Keputusan yang disepakati:

1. Kata kunci mencari **seluruh tanggal + kedua tab (Reguler & Shift)**.
2. Mesin pencarian **multi-kata + prioritas hasil**, tanpa infrastruktur baru
   (tanpa Scout/Meilisearch/FULLTEXT).
3. Dropdown saran berisi: **potongan laporan, teknisi, area, alat, lokasi**.
4. Kotak pencarian baru **menggantikan** input "Pekerjaan / Lokasi / Alat" di
   panel Filter Lanjutan.

## Aturan pencarian

### Cakupan tanggal dan tab

- `cakupan=semua` (dikirim kotak pencarian dan tautan saran) melewati batas
  satu hari. Rentang `date_from`/`date_to` yang diisi eksplisit tetap dipakai
  untuk mempersempit hasil.
- Pencarian tanpa `tanggal`, `date_from`, `date_to`, dan `tampilan` juga
  dianggap `cakupan=semua` supaya tautan lama ikut mencari seluruh riwayat.
- `tanggal` eksplisit tetap dihormati (tautan lama dari profil teknisi, man
  hour, dan halaman lokasi tidak berubah).
- Pada mode semua tanggal, `applyGroupFilter()` dipanggil dengan `'semua'`
  sehingga Reguler dan Shift tampil bersama. Pengecualian: bila
  `technician_id` aktif, tab teknisi tersebut dipertahankan agar jumlah
  pekerjaan/man hour di halaman teknisi tidak berubah.
- Navigasi tanggal, tab tampilan, dan chip tanggal disembunyikan pada mode ini;
  kolom Tanggal pada tabel ditampilkan. Banner di bawah kotak pencarian
  menyebutkan cakupan dan jumlah hasil, plus tautan "Kembali ke tampilan
  tanggal".

### Kata kunci

- Minimal 2 huruf, maksimal 8 token (`config/pencarian.php`).
- Karakter `%`, `_`, dan `\` dibuang supaya tidak mengubah pola LIKE.
- **AND antar kata**, **OR antar kolom/relasi** per kata.
- Kolom langsung: `work_description`, `report_code`, `root_cause`, `finding`,
  `equipment_tag`.
- Relasi: teknisi (nama/NIK), area (kode/nama), Functional Location (kode/nama),
  alat (tech ident/equipment no/deskripsi/kode lokasi), teknisi kolaborator, dan
  alat/lokasi tambahan (`multiItems`).
- Sinonim ringan dari `config('pencarian.sinonim')`, misalnya pompa↔pump,
  valve↔kran, bearing↔bantalan. Jangan menambah sinonim yang terlalu umum.

### Urutan hasil

Prioritas (skor CASE): kode laporan persis → awalan kode laporan → equipment
tag → awalan deskripsi → deskripsi → kata pertama pada kode/deskripsi/alat/
lokasi/teknisi/area. Setelah skor, diurutkan `report_date` terbaru.

Catatan teknis penting: parameter `?` di dalam ekspresi `ORDER BY CASE ...`
tidak diikat dengan benar oleh SQLite (hasil urutan salah walau nilainya sama
saat dipakai di SELECT). Karena itu skor dihitung sebagai kolom terpilih
`relevansi` lalu diurutkan lewat alias (`orderByDesc('relevansi')`). Atribut
`relevansi` disembunyikan dari model sebelum dikirim ke view.

### Dropdown saran

Endpoint: `GET /reports/cari?q=...` (`reports.cari`, hanya pengguna login,
`throttle:120,1`). Aturan kata kunci memakai service yang sama dengan tabel.

- Kelompok: `laporan` (maks. 4), `teknisi`, `area`, `alat`, `lokasi`
  (maks. 3 per kelompok).
- Grup laporan menyertakan `total` seluruh laporan yang cocok; tiap item
  membawa URL tujuan.
- Klik laporan membuka detail laporan. Klik teknisi/area/alat/lokasi membuka
  halaman Laporan dengan filter `technician_id`/`area_id`/`asset_code`/
  `funcloc_id` + `cakupan=semua`.
- `asset_code` mencocokkan tech ident ATAU nomor equipment, sehingga alat yang
  belum punya tech ident tetap bisa dibuka dari saran.
- `funcloc_id` adalah filter baru di `ReportController::buildBaseQuery()`.
  Karena tidak punya kotak di Filter Lanjutan, lokasi aktif tampil sebagai chip
  di banner pencarian dengan tombol hapus (kata kunci & facet lain tetap).
- Baris salinan kolaborasi (`collaborator_of`) dan salinan multi alat
  (`multi_of`) tidak muncul sebagai baris saran; laporan induknya yang tampil.

### Kotak pencarian (UI)

- Debounce 300 ms, minimal 2 huruf, jawaban usang dibatalkan lewat
  `AbortController`.
- Navigasi panah atas/bawah, Enter untuk memilih/mencari, Esc untuk menutup,
  atribut ARIA combobox/listbox.
- Teks saran dirender sebagai teks; sorotan kata memakai HTML yang sudah
  di-escape (`sorot()`), sehingga data database tidak bisa menyisipkan HTML.
- Empty state menyebut kata kunci dan mengarahkan tekan Enter; error jaringan
  menampilkan pesan yang dapat ditindaklanjuti.
- Filter Lanjutan tetap bisa dipakai sebagai facet; kata kunci + `cakupan`
  dibawa sebagai isian tersembunyi. Tombol "Reset Filter" keluar dari mode
  pencarian dan kembali ke tanggal hari ini.

### Export

Export Excel/CSV memakai `buildReportQuery()` yang sama, sehingga:
- kata kunci ikut memakai aturan baru,
- `cakupan=semua` pada tautan export melewati batas satu hari,
- ringkasan filter export menulis "Cakupan: semua tanggal".

## Berkas

- `app/Services/PencarianLaporanService.php` (baru)
- `app/Http/Controllers/ReportSearchController.php` (baru)
- `config/pencarian.php` (baru)
- `app/Http/Controllers/ReportController.php`
- `app/Http/Controllers/Traits/ReportExportTrait.php`
- `routes/web.php`
- `resources/views/reports/index.blade.php`
- `resources/views/reports/partials/_pencarian-utama.blade.php` (baru)
- `tests/Feature/ReportSearchTest.php` (baru)

## Uji

- `ReportSearchTest`: 10 test, 102 assertion. Mencakup: pencarian lintas
  tanggal/grup, AND multi-kata, kolom/relasi baru, prioritas kode persis,
  tanggal eksplisit vs cakupan semua, klik saran (termasuk chip lokasi dan
  alat tanpa tech ident), struktur JSON endpoint, minimal 2 huruf, tamu
  ditolak, dan kotak pencarian menggantikan input lama.
- Seluruh suite 23/11/2026: **854 test hijau, 4.372 assertion**.
- `php artisan view:cache` sukses.
