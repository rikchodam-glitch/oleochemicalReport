# PR: Daftar laporan mengikuti mode grup teknisi saat disaring per teknisi

Cabang: `tugas/erik/tautan-laporan-mengikuti-grup`

## Ringkasan

Laporan user 15/10/2026: pada halaman detail teknisi, saat mengklik tanggal untuk
melihat laporan, daftarnya kosong padahal keterangan menunjukkan ada 2 laporan —
karena halaman Laporan terbuka pada tampilan **Reguler** sedangkan teknisinya
**shift**. Permintaan: bila yang dicek teknisi shift, muka halaman Laporan harus
mode **Shift**; bila reguler baru Reguler.

## Temuan (verifikasi dulu)

- Tautan dari profil teknisi SUDAH membawa `tampilan` + `grub` (perbaikan
  14/10/2026, `$parameterLaporan` di `technicians/show.blade.php:37-51`);
  direproduksi di DEV: `/reports?technician_id=3&tampilan=shift&grub=grub_d&tanggal=2026-08-20`
  -> **2 baris**, sedangkan tanpa `tampilan` -> **0 baris** (default Reguler).
- Jadi kasus kosong hanya muncul saat parameter `tampilan` TIDAK dikirim (mis.
  tautan lama/build yang belum diperbarui). Perbaikan ini menutup celah itu.

## Perubahan

- `ReportController::index`: bila `technician_id` dikirim TANPA parameter
  `tampilan`, mode diambil dari grup teknisi itu — pekerja shift (Grub A-D)
  memakai tampilan **Shift** beserta grub-nya, teknisi non-shift tetap
  **Reguler**. Parameter `tampilan` yang dikirim eksplisit (tab) tetap
  dihormati, dan `technician_id` kosong/tidak ada tidak terpengaruh.
- Tanpa migrasi/perubahan data; tidak mengubah definisi agregasi man hour.

## Bukti uji

- `tests/Feature/TechnicianTautanTampilanTest.php` (6 test): tautan profil
  (shift/reguler), membuka tautan tanggal, **saringan teknisi shift tanpa
  parameter tampilan -> tampilan shift**, **tab eksplisit tetap dihormati**,
  **saringan teknisi reguler tetap reguler**.
- `php artisan test` **662 test hijau (3217 assertions)**.
- Uji mutasi: menonaktifkan blok inferensi membuat uji "saringan teknisi shift
  tanpa parameter tampilan otomatis memakai tampilan shift" GAGAL, lalu
  dikembalikan.
- Verifikasi nyata DEV (sesudah `/debug-login`):
  `/reports?technician_id=3&tanggal=2026-08-20` (tanpa `tampilan`) -> HTTP 200
  dan **2 baris** (`data-laporan-id`), padahal sebelumnya 0 baris.

## Berkas yang disentuh

- `app/Http/Controllers/ReportController.php`
- `tests/Feature/TechnicianTautanTampilanTest.php`
- `tugas/erik.md`

## Checklist

- [x] Uji otomatis hijau
- [x] `php artisan view:cache` tidak terpengaruh (tanpa perubahan blade)
- [x] Tanpa migrasi / tanpa perubahan data
- [x] Tautan profil tetap membawa tampilan; sisi server kini ikut menjaga
