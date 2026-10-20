# Laporan Teknisi Kembali Muncul di Detail Equipment CM

## Ringkasan perubahan

Card **"Laporan Teknisi (Daftar Perbaikan)"** pada halaman detail equipment CM
(`/cm/equipment/{tag}`) selama ini menampilkan 0 laporan, walaupun teknisinya
sudah mengirim laporan untuk alat tersebut. Penyebabnya **data, bukan tampilan**:
laporan dari wizard Telegram hanya menyimpan `asset_id` sehingga
`reports.equipment_tag` selalu NULL, sementara backfill lama mengisi kolom itu
dengan `assets.equipment_no` (mis. `10002840`) padahal CM memakai
`assets.tech_ident_no` (mis. `6400P5`).

Perbaikan ini membuat penautan laporan ke equipment CM konsisten memakai
**Tech Ident No**, ditambah jalur pencocokan PERSIS untuk laporan lama yang
menyebut kode equipment di deskripsi pekerjaan (tetap mematuhi permintaan
"jangan sampai salah equipment": `2-6163P8` TIDAK muncul di `6163P8`).

## Yang berubah

1. **Saat menyimpan laporan** — `Report::saving()` mengisi `equipment_tag` dari
   `assets.tech_ident_no` bila laporan punya `asset_id` dan tag masih kosong.
   Tag yang sudah diisi manual TIDAK ditimpa. Hal yang sama diterapkan di
   `WizardReportSaverTrait` (laporan utama memakai `equipment_ident`, tiap baris
   salinan multi alat memakai `tech_ident_no` alatnya sendiri).
2. **Saat membaca laporan** — `CmController::whereLaporanEquipment()` menyatukan
   tiga jalur pencocokan untuk card maupun tabel perbaikan: tag sama persis,
   `asset_id` yang `tech_ident_no`-nya sama, dan laporan lama tanpa tag/asset
   yang deskripsinya menyebut tag tersebut.
3. **Pencocokan teks yang aman** — `App\Services\EquipmentMentionService`
   mencocokkan penyebutan kode di batas kata, abaikan besar/kecil huruf,
   pemisah boleh berbeda (`6811P05` = `6811-P05` = `6811 P05`), longest match
   menang, dan kode di bawah 4 karakter diabaikan. Bukan `LIKE '%tag%'`.
4. **Perbaikan data lama** — command baru `php artisan reports:tag-equipment`
   (simulasi) / `--apply`: tag kosong diisi dari asset, tag yang masih berisi
   `equipment_no` diganti `tech_ident_no`, dan laporan tanpa asset yang menyebut
   TEPAT SATU kode diberi tag (yang ambigu dibiarkan kosong).
5. **Bot Telegram** — `AssetInfoService::laporanAsset()` memakai aturan yang sama
   supaya balasan bot dan halaman asset tidak berbeda hasilnya.

## Checklist wajib (agenda integrator)

- [x] `php artisan test` HIJAU (tempel jumlah test + assertions di bawah).
- [x] `php artisan view:cache` sukses (ada berkas blade yang disentuh).
- [x] Perubahan HANYA pada berkas yang relevan (bukan `git add -A`).
- [x] Tidak ada berkas sampah (skrip `tmp-*`, `.bak`, hasil export sekali pakai).
- [x] Tidak ada migrasi baru (perubahan hanya kode + data lewat command).
- [x] Sudah `git rebase origin/main` (bukan merge) sebelum dibuka.
- [x] Peta `AGENTS.md` dan dokumen `docs/` sudah diperbarui (`docs/19`,
      `docs/12-riwayat-insiden-33-51.md` insiden #51).

## Bukti uji

```
php artisan test --filter="ReportEquipmentTagTest|CmEquipmentLaporanTeknisiTest|TelegramAssetInfoTest|ReportHistoryFilterTest"
  Tests: 42 passed (177 assertions)

php artisan test   (seluruh suite)
  Tests: 679 passed (3317 assertions)

php artisan view:cache
  INFO  Blade templates cached successfully.
```

Uji baru `tests/Feature/ReportEquipmentTagTest.php` (5 test):
hook `saving` mengisi tag dari Tech Ident No; tag manual tidak ditimpa; command
backfill mengganti `equipment_no` menjadi `tech_ident_no`; command mengisi tag
laporan tanpa asset dari deskripsi; command TIDAK menebak saat deskripsi
menyebut lebih dari satu equipment.

## Berkas yang disentuh

```
1.  app/Http/Controllers/CmController.php                  - whereLaporanEquipment() 3 jalur + dipakai card & tabel perbaikan
2.  app/Models/Report.php                                  - hook saving mengisi equipment_tag dari assets.tech_ident_no
3.  app/Providers/AppServiceProvider.php                   - singleton EquipmentMentionService + shim REGEXP untuk SQLite
4.  app/Services/EquipmentMentionService.php               - BARU: pencocokan penyebutan kode equipment (batas kata, longest match)
5.  app/Services/Telegram/AssetInfoService.php             - laporanAsset() ikut jalur laporan lama
6.  app/Services/Telegram/Traits/WizardReportSaverTrait.php- simpan equipment_tag saat wizard menyimpan laporan
7.  app/Console/Commands/ReportsTagEquipment.php           - BARU: reports:tag-equipment (simulasi / --apply)
8.  resources/views/cm/equipment-show.blade.php            - keterangan card menyebut laporan lama
9.  tests/Feature/ReportEquipmentTagTest.php               - BARU: 5 test penjaga
10. tests/Feature/CmEquipmentLaporanTeknisiTest.php        - tambahan test laporan lama tanpa tag & kode mirip
11. docs/19-laporan-teknisi-equipment-cm.md                 - aturan pencocokan terbaru + urutan deploy
12. docs/12-riwayat-insiden-33-51.md                        - insiden #51 (berkas lama 33-46 di-rename)
13. AGENTS.md                                               - peta dokumen menyesuaikan nama berkas riwayat insiden
14. tugas/erik.md                                           - papan klaim
15. tugas/pr-perbaiki-penautan-laporan-equipment-cm.md      - berkas ini
```

## Risiko / hal yang perlu diperhatikan integrator

- **Butuh langkah deploy khusus (tanpa migrasi):** setelah `git pull` dan
  `php artisan view:cache`, jalankan berurutan di PROD:
  1. `php artisan reports:tag-equipment` (simulasi, periksa daftar yang akan
     diubah);
  2. `php artisan reports:tag-equipment --apply`;
  3. `php artisan view:cache`.
  Tanpa langkah 1-2 halaman tetap benar untuk laporan BARU, tetapi laporan lama
  baru muncul lewat jalur pencocokan deskripsi (lebih lambat, dan hanya untuk
  laporan yang tag/asset-nya masih kosong).
- Command mengubah `reports.equipment_tag` (kolom tag, bukan isi laporan); tidak
  menyentuh `work_description`, teknisi, maupun lampiran.
- Pencocokan deskripsi memakai `REGEXP` (MySQL 8 mendukung; SQLite di test
  memakai shim di `AppServiceProvider`). Jalur ini hanya dieksekusi untuk
  laporan dengan `equipment_tag` kosong DAN `asset_id` NULL.
