# Laporan hanya boleh diedit & dihapus dari laporan INDUK (baris salinan ditolak + perubahan induk disebar ke salinan)

## Ringkasan perubahan

Perbaikan atas laporan user: baris salinan (kolaborasi teknisi / alat-lokasi
tambahan) dulu bisa dibuka lalu diedit atau dihapus sendiri-sendiri. Sekarang
seluruh pengelolaan hanya lewat laporan INDUK. Tidak ada migrasi baru.

1. **Baris salinan tidak bisa diedit/dihapus sendiri (dua lapis).**
   - Tampilan: kartu Aksi Admin `resources/views/reports/show.blade.php` merender
     tombol "Edit Laporan"/"Hapus Laporan" HANYA untuk laporan INDUK. Pada baris
     salinan muncul panel peringatan + tombol yang membuka **popup penolakan**
     (`resources/views/reports/partials/_penolakan-salinan.blade.php`, partial
     baru) berisi penjelasan dan tombol **"Cek Laporan Induk"** yang mengarah ke
     halaman detail induknya. `enableEditMode()` juga menolak masuk mode edit pada
     baris salinan (membuka popup itu).
   - Server (pengaman sebenarnya): `ReportController::penjagaKelolaInduk()`
     dipanggil paling awal di `update()`, `updateStatus()`, dan `destroy()`;
     pesannya menyebut kode laporan INDUK-nya dan tidak ada data yang ditulis.
2. **Perubahan laporan INDUK disebarkan ke seluruh baris salinannya.**
   `App\Services\ReportCopyService` kini punya satu daftar bidang
   (`bidangPekerjaan()` + `bidangLokasi()`) yang dipakai BAIK saat membuat salinan
   baru maupun saat menyelaraskan salinan lama lewat method baru
   `sinkronkanSalinan()`. Isi pekerjaan (deskripsi, temuan, root cause, jam,
   durasi, status, foto, bidang CM) ikut berubah pada salinan kolaborasi DAN
   salinan alat; identitas tiap baris tetap (teknisi kolaborator, kode laporan,
   serta `asset_id`/`funcloc_id`/`area_id`/`report_type` milik item tambahan).
3. **Laporan induk yang dihapus tidak pecah per alat.** Ditemukan bahwa FK
   database berbunyi `reports.multi_of -> reports ON DELETE SET NULL`
   (diperiksa pada `oleochemical_dev` lewat `information_schema`), yaitu sebab
   baris salinan berubah menjadi laporan lepas bila tidak dihapus lebih dulu.
   Karena itu baris salinan dihapus lebih dulu lewat event model
   (`Report::boot()::deleting`) dan sejak perubahan ini penghapusan baris salinan
   tidak lagi mungkin dari UI maupun rute — satu-satunya jalan adalah dari laporan
   induk, yang sudah dibuktikan membersihkan seluruh salinannya pada MySQL nyata.

## Checklist wajib (agenda integrator)

- [x] `php artisan test` HIJAU (609 test, 2.924 assertions, 143 detik).
- [x] `php artisan view:cache` sukses.
- [x] Perubahan HANYA pada berkas yang relevan (bukan `git add -A`).
- [x] Tidak ada berkas sampah (skrip uji sementara `tmp-oleo\*` sudah dihapus).
- [x] TIDAK ada migrasi baru.
- [x] `AGENTS.md` diperbarui (bagian "PENGELOLAAN LAPORAN HANYA DARI LAPORAN INDUK").

## Bukti uji

1. Test baru `tests/Feature/ReportKelolaHanyaDariIndukTest.php` (12 test, 71
   assertions): penolakan edit/hapus/update-status baris salinan (pesan memuat
   kode induk), penyebaran perubahan induk ke salinan kolaborasi & alat, identitas
   baris salinan tetap, status induk menyebar, hapus induk membersihkan semua
   salinan, dan markup popup penolakan + tombol "Cek Laporan Induk" pada halaman
   baris salinan.
2. Uji mutasi nyata (dijalankan, lalu dikembalikan):
   - `penjagaKelolaInduk()` dibuat selalu `null` -> 5 test GAGAL.
   - `sinkronkanSalinan()` dibuat selalu `return 0` -> 3 test GAGAL.
   - percabangan induk/salinan pada `show.blade.php` dibuat `@if(true)` -> 2 test
     GAGAL.
3. Bukti database MySQL nyata (skrip sementara, sudah dihapus) pada
   `oleochemical_dev`: membuat induk + salinan kolaborasi + salinan alat, lalu
   menghapus INDUK -> `SISA_SETELAH_HAPUS=[]`, `multi_of` yatim 0,
   `collaborator_of` yatim 0.
4. Bukti render nyata DEV (HTTP + cookie `/debug-login`): `/reports/787` (salinan
   kolaborasi) dan `/reports/765` (salinan alat) memuat popup penolakan +
   `@click="bukaPenolakan()"` + "Cek Laporan Induk" dan TIDAK memuat teks
   konfirmasi hapus; `/reports/786` (induk) tetap memuat form hapus dan TIDAK
   memuat tombol penolakan.

## Berkas yang disentuh

- `app/Services/ReportCopyService.php`
- `app/Http/Controllers/ReportController.php`
- `resources/views/reports/show.blade.php`
- `resources/views/reports/partials/_penolakan-salinan.blade.php` (baru)
- `tests/Feature/ReportKelolaHanyaDariIndukTest.php` (baru)
- `tests/Feature/ReportDetailTampilanTest.php` (jumlah `x-teleport` 3 -> 4)
- `tugas/erik.md`, `AGENTS.md`
