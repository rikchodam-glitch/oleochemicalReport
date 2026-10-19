# Aturan 14/10/2026: Pengelolaan Laporan Hanya dari Induk

> Bagian dari dokumentasi proyek di folder `docs`.
> Peta dokumen: `AGENTS.md` di akar repo.

## ATURAN WAJIB (14/10/2026): PENGELOLAAN LAPORAN HANYA DARI LAPORAN INDUK

Diperbaiki 14/10/2026 atas laporan user: "laporan kolaborasi seharusnya hanya bisa
diedit pada laporan induk; kalau yang dibuka baris salinan, muncul popup penolakan
dengan tombol cek laporan induk; saat laporan induk diedit, seluruh laporan
kolaborasi yang terhubung ikut berubah; dan saat laporan induk dihapus, laporannya
jangan pecah menurut alat yang diinput". Semua perubahan ada di copy DEV
`C:\laragon\www\oleochemicalReport`; BELUM naik ke PROD (tunggu `Deploy-Laporan.bat`;
TIDAK ada migrasi baru).

1. **Baris SALINAN tidak bisa diedit atau dihapus sendiri-sendiri (dua lapis).**
   - Lapisan tampilan: kartu Aksi Admin `resources\views\reports\show.blade.php`
     hanya merender tombol "Edit Laporan" / "Hapus Laporan" bila
     `$laporanInduk->id === $report->id`. Untuk baris salinan yang dirender adalah
     panel peringatan + dua tombol yang memanggil `bukaPenolakan()` (Alpine) +
     tautan "Cek Laporan Induk", ditambah popup penolakan dari partial BARU
     `resources\views\reports\partials\_penolakan-salinan.blade.php` (di-include
     SATU baris dari `show.blade.php`). State `penolakan` + `salinan` serta
     `bukaPenolakan()` / `tutupPenolakan()` ada di komponen `reportDetail()`;
     `enableEditMode()` menolak masuk mode edit pada baris salinan dan membuka
     popup itu, sedangkan `editMode` hanya boleh `true` pada halaman laporan INDUK
     yang benar-benar punya galat validasi.
   - Lapisan server (pengaman sebenarnya): `ReportController::penjagaKelolaInduk()`
     dipanggil PALING AWAL di `update()`, `updateStatus()`, dan `destroy()`.
     Pesannya menyebut kode laporan INDUK-nya, dan ketiga method itu tidak menulis
     apa pun bila ditolak.
   - JANGAN menghidupkan lagi tombol edit/hapus di halaman baris salinan: aturan
     inilah yang mencegah satu pekerjaan berubah hanya pada sebagian barisnya.

2. **Perubahan laporan INDUK disebarkan ke seluruh baris salinannya.**
   - `App\Services\ReportCopyService` kini punya SATU daftar bidang
     `bidangPekerjaan()` (deskripsi, klasifikasi/jenis, bidang CM, jam & durasi,
     root cause, temuan, foto, status, `completed_at`, penaut lanjutan, penanda
     sunting manual) + `bidangLokasi()` (`asset_id`, `area_id`, `funcloc_id`,
     `report_type`). `dataSalinan()` = gabungan keduanya, sehingga pembuatan
     salinan baru dan penyelarasan salinan lama TIDAK PERNAH berbeda aturan.
   - `sinkronkanSalinan(Report $induk)` meng-update relasi `collaboratorReports`
     dengan `bidangPekerjaan + bidangLokasi` (pekerjaan kolaborator identik dengan
     induk) dan `multiItems` HANYA dengan `bidangPekerjaan` (identitas alat/lokasi
     item TIDAK ditimpa). Dipanggil `ReportController::update()` dan
     `updateStatus()` sesudah induk disimpan. Laporan yang bukan induk
     mengembalikan 0 tanpa menulis apa pun.
   - `ai_analyzed` ditulis `(bool)` supaya model yang belum dibaca ulang dari
     database (attributnya masih NULL) tidak menggagalkan insert salinan — kolomnya
     NOT NULL, dan bug ini ketahuan dari test, bukan dari peninjauan kode.

3. **Bukti penghapusan laporan induk TIDAK memecah pekerjaan.** Aturan FK nyata di
   database DEV `oleochemical_dev` diperiksa 14/10/2026 lewat `information_schema`:
   `reports.collaborator_of -> reports ON DELETE CASCADE`,
   `reports.multi_of -> reports ON DELETE SET NULL`, dan
   `reports.technician_id -> technicians ON DELETE CASCADE`. `SET NULL` itulah
   sebab laporan "pecah per alat" bila baris salinan tidak dihapus lebih dulu;
   karena itu `Report::boot()::deleting` menghapus baris salinan lebih dulu
   (perbaikan 13/10/2026), dan sejak 14/10/2026 penghapusan baris salinan tidak
   lagi mungkin dari UI maupun rute. Dibuktikan pada MySQL nyata (skrip sementara,
   sudah dihapus): membuat induk + salinan kolaborasi + salinan alat, lalu
   menghapus INDUK -> `SISA_SETELAH_HAPUS=[]`, `multi_of` yatim 0,
   `collaborator_of` yatim 0.

4. **Uji penjaga:** `tests\Feature\ReportKelolaHanyaDariIndukTest.php` (12 test,
   71 assertions) — edit/hapus/update-status baris salinan ditolak (pesannya
   memuat kode induk), penyebaran perubahan ke salinan kolaborasi & alat, identitas
   baris salinan tetap, status induk menyebar, hapus induk membersihkan semua
   salinan, dan markup popup penolakan pada halaman baris salinan. Test lama
   `tests\Feature\ReportDetailTampilanTest.php` disesuaikan: jumlah
   `x-teleport="body"` 3 -> 4 (popup penolakan).
   Uji mutasi nyata 14/10/2026: (a) `penjagaKelolaInduk()` dibuat selalu `null` ->
   5 test GAGAL; (b) `sinkronkanSalinan()` dibuat selalu `return 0` -> 3 test
   GAGAL; (c) percabangan induk/salinan pada `show.blade.php` dibuat `@if(true)` ->
   2 test GAGAL. Seluruh suite **609 test hijau (2.924 assertions)** dan
   `php artisan view:cache` sukses.
   Bukti render nyata DEV (HTTP + cookie `/debug-login`): `/reports/787` (salinan
   kolaborasi) dan `/reports/765` (salinan alat) memuat popup penolakan +
   `@click="bukaPenolakan()"` + "Cek Laporan Induk" serta TIDAK memuat teks
   konfirmasi hapus; `/reports/786` (laporan induk) tetap memuat form hapus dan
   TIDAK memuat tombol penolakan.

5. **Catatan untuk sesi berikutnya:** penghapusan/penyebaran selalu lewat model
   (bukan query massal) supaya event `deleting`/`updating` tetap jalan. JANGAN
   membuat `Report::where(...)->delete()` atau `DB::table('reports')->delete()`
   di kode aplikasi, karena `multi_of ON DELETE SET NULL` akan meninggalkan baris
   salinan sebagai laporan lepas.


