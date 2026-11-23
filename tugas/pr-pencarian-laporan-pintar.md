# Laporan: pencarian pintar lintas tanggal, tab, dan saran

## Ringkasan perubahan

- Halaman Laporan mendapat kotak pencarian utama yang mencari **seluruh
  tanggal dan kedua tab (Reguler + Shift)**, bukan hanya tanggal yang aktif.
- Pencarian **multi-kata** (AND antar kata, OR antar kolom/relasi) dengan
  **prioritas hasil**: kode laporan persis/awalan lebih dulu, lalu deskripsi,
  alat, lokasi, teknisi, dan area. Ada sinonim ringan (pompa/pump, valve/kran,
  bearing/bantalan) lewat `config/pencarian.php`.
- Kolom yang dicari diperluas: penyebab (`root_cause`), temuan (`finding`),
  equipment tag, teknisi (nama/NIK), area, Functional Location, deskripsi alat,
  teknisi kolaborator, dan alat/lokasi tambahan (multi item).
- Dropdown saran responsif dan informatif: potongan laporan, teknisi, area,
  alat, dan lokasi. Debounce 300 ms, minimal 2 huruf, jawaban usang dibatalkan
  (`AbortController`), navigasi panah/Enter/Esc, atribut ARIA, dan sorotan kata
  yang di-escape.
- Klik saran langsung memfilter: laporan membuka detail; teknisi/area/alat/
  lokasi membuka daftar lintas tanggal (`cakupan=semua`). Filter lokasi tampil
  sebagai chip yang bisa dihapus tanpa membuang kata kunci.
- Input "Pekerjaan / Lokasi / Alat" di Filter Lanjutan dihapus (diganti kotak
  utama). Kata kunci dan `cakupan=semua` dibawa sebagai isian tersembunyi.
- Export Excel/CSV memakai aturan kata kunci yang sama dan menulis
  "Cakupan: semua tanggal" pada ringkasan filter.

## Berkas utama

- `app/Services/PencarianLaporanService.php` (baru)
- `app/Http/Controllers/ReportSearchController.php` (baru)
- `config/pencarian.php` (baru)
- `app/Http/Controllers/ReportController.php`
- `app/Http/Controllers/Traits/ReportExportTrait.php`
- `routes/web.php`
- `resources/views/reports/index.blade.php`
- `resources/views/reports/partials/_pencarian-utama.blade.php` (baru)
- `tests/Feature/ReportSearchTest.php` (baru)
- `docs/34-pencarian-laporan.md` (baru), `docs/20-...`, `AGENTS.md`

## Uji

- `ReportSearchTest`: 10 test, 102 assertion.
- Seluruh suite: **854 test hijau, 4.372 assertion**.
- `php artisan view:cache` sukses.
- Verifikasi DEV nyata: `/reports?search=pompa` menampilkan banner seluruh
  tanggal, kolom Tanggal, total 65 pekerjaan, dan `/reports/cari?q=pompa`
  mengembalikan kelompok laporan + alat.

## Catatan

- Tidak ada migrasi baru, tidak ada service tambahan (tanpa Scout/Meilisearch).
- Berkas kotor milik sesi lain sengaja tidak ikut di-commit.
- Sesudah merge: `tools\kembalikan-ke-main.ps1` lalu `Deploy-Laporan.bat` di
  komputer integrator.
