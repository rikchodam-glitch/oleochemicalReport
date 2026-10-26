# Assets: import dokumentasi foto equipment

## Ringkasan

- Menambah perintah `assets:import-dokumentasi` untuk memasukkan foto dari
  folder dokumentasi ke kolom `assets.photos`.
- Mode bawaan **dry-run**; penulisan file dan database hanya dengan `--apply`.
- Pencocokan memakai `tech_ident_no` dan `equipment_no`:
  folder leluhur terdekat, nama file persis, nama file tanpa spasi/tanda,
  lalu tag yang tertanam di nama file.
- Foto disalin ke `assets/photos/{asset_id}/{sha256}.{ext}` pada disk `public`.
  Di PROD disk ini berada di `D:\storage\app\public`.
- Foto lama ditambahkan (tidak diganti), duplikat isi dilewati, perintah
  idempotent, dan sumber E: tidak diubah.
- Laporan CSV/JSON dan backup foto lama dibuat di `storage/app/imports`
  (di PROD berada di D:).

## Hasil dry-run DEV

- 2.221 berkas dipindai; 2.069 gambar jpg/jpeg/png.
- 1.672 foto unik cocok untuk **180 aset** (1.445 folder persis, 36 folder
  compact, 185 nama file persis, 6 smart match).
- 168 gambar belum cocok, 228 duplikat, 1 file kosong, 152 berkas non-gambar.
- Perkiraan ukuran yang akan disalin: **3,79 GB**.
- Belum ada data DEV yang diubah.

## Berkas yang disentuh

- `app/Console/Commands/ImportAssetDocumentation.php`
- `app/Services/Assets/AssetDocumentationImportService.php`
- `tests/Feature/AssetDocumentationImportTest.php`
- `docs/31-import-dokumentasi-asset.md`
- `docs/28-assets-management.md`
- `AGENTS.md`

## Bukti uji

- `php artisan test --filter=AssetDocumentationImportTest`: **4 test hijau,
  46 assertions**.
- Seluruh suite: **770 test hijau, 3.826 assertions**.
- `php artisan view:cache` sukses.

## Checklist

- [x] Dry-run tidak mengubah file/database dan tetap menulis laporan.
- [x] Apply mencocokkan folder, nama file, compact, smart, dan equipment_no.
- [x] Foto lama tetap dan foto baru ditambahkan.
- [x] Duplikat isi tidak menggandakan foto.
- [x] Aset dengan tag ganda dilewati dan dilaporkan.
- [x] File kosong/rusak/non-gambar dilewati.
- [x] Apply dua kali idempotent.
- [x] Opsi `--tag` hanya memproses aset terpilih.

## Catatan deploy dan apply

1. Merge PR dan deploy ke PROD.
2. Dry-run di PROD:
   `php artisan assets:import-dokumentasi --path="E:\Dokumentasi Equipment"`
3. Pastikan output menunjukkan disk `D:\storage\app\public` dan angka sesuai.
4. Periksa laporan CSV/JSON dan backup.
5. Jalankan `--apply` di luar jam sibuk.
6. Verifikasi beberapa aset dan file di
   `D:\storage\app\public\assets\photos`.
7. 168 gambar belum cocok; tidak ada aset baru yang dibuat.
8. Database tetap di C:; hanya foto/storage yang di D:.
