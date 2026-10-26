# Import Dokumentasi Foto Equipment

> Bagian dari dokumentasi proyek di folder `docs`.
> Peta dokumen: `AGENTS.md` di akar repo.

## Tujuan

Memasukkan dokumentasi foto dari folder `E:\Dokumentasi Equipment` ke kolom
`assets.photos` berdasarkan tag aset (`tech_ident_no` atau `equipment_no`).
Sumber dokumentasi tidak diubah; perintah hanya membaca sumber dan menyalin
foto ke storage aplikasi.

## Lokasi penyimpanan

| Komponen | Lokasi |
|---|---|
| Storage PROD | Junction `storage\app` → `D:\storage\app` |
| Foto publik PROD | `D:\storage\app\public\assets\photos\{asset_id}\...` |
| Storage DEV | `C:\laragon\www\oleochemicalReport\storage\app` |
| Database MySQL | `C:\laragon\data\mysql-8.4` (tidak ikut dipindah) |
| Sumber dokumentasi | `E:\Dokumentasi Equipment` (tidak diubah) |

Perintah memakai disk `public`, jadi di PROD otomatis menulis ke D: tanpa
hardcode path. Perintah juga menampilkan path fisik disk yang dipakai.

## Perintah

```bash
# Dry-run: hanya memindai dan menulis laporan
php artisan assets:import-dokumentasi --path="E:\Dokumentasi Equipment"

# Apply: salin foto dan perbarui assets.photos
php artisan assets:import-dokumentasi --path="E:\Dokumentasi Equipment" --apply
```

Opsi:

| Opsi | Arti |
|---|---|
| `--path` | Folder sumber (wajib) |
| `--apply` | Menulis file dan database; tanpa ini dry-run |
| `--report` | Lokasi laporan CSV (default `storage/app/imports/dokumentasi-<waktu>.csv`) |
| `--limit` | Batasi jumlah gambar yang diproses (untuk uji) |
| `--tag` | Batasi ke satu tag aset |
| `--ext` | Ekstensi gambar (default `jpg,jpeg,png`) |
| `--skip-dir` | Folder yang dilewati (default `__MACOSX`) |
| `--no-smart` | Matikan pencocokan tag di tengah nama file |

## Aturan pencocokan

1. Indeks aset dibuat dari `tech_ident_no` dan `equipment_no`:
   - persis: huruf besar, trim;
   - compact: buang spasi/tanda baca/tanda hubung.
2. Untuk setiap gambar, dicari folder leluhur terdekat yang cocok.
3. Jika tidak ada, nama file dicocokkan persis/compact.
4. Jika masih tidak ada, dicari tag yang tertanam di nama file (minimal 5
   karakter).
5. Folder yang cocok menang; konflik folder vs nama file dicatat.
6. Tag ganda/ambigu dilewati dan dilaporkan.
7. File yang tidak cocok TIDAK membuat aset baru.

## Penyimpanan foto

- Tujuan: `assets/photos/{asset_id}/{sha256}.{ext}` pada disk `public`.
- Foto divalidasi dengan `getimagesize`; file kosong/rusak/HEIC/non-gambar
  dilewati.
- Foto lama tetap; path baru ditambahkan ke `assets.photos`.
- Duplikat isi (hash sama) dalam aset yang sama dilewati.
- Perintah idempotent: dijalankan ulang tidak menggandakan foto.
- Copy, bukan move; E: tidak dihapus.
- Backup JSON daftar foto lama dibuat sebelum apply.

## Laporan dan backup

- CSV detail semua berkas: status, alasan, aset, path sumber, hash, tujuan,
  dan jenis pencocokan.
- JSON ringkasan: mode, path disk, ringkasan angka, dan daftar foto baru per
  aset.
- Backup JSON foto lama untuk aset yang berubah.
- Di PROD semuanya ditulis di `D:\storage\app\imports\...`.

## Hasil dry-run DEV (26/10/2026)

Perintah dijalankan di DEV dengan sumber E:; belum ada data yang diubah.

| Ukuran | Jumlah |
|---|---|
| Berkas dipindai | 2.221 |
| Gambar jpg/jpeg/png | 2.069 |
| Cocok (unik) | 1.672 |
| - folder persis | 1.445 |
| - folder abaikan spasi/tanda | 36 |
| - nama file persis | 185 |
| - nama file compact | 0 |
| - tag di nama file (smart) | 6 |
| Belum cocok | 168 |
| Ambigu | 0 |
| Duplikat | 228 |
| Dilewati: ekstensi | 152 |
| Dilewati: kosong | 1 |
| Aset cocok | 180 |
| Perkiraan ukuran disalin | 3,79 GB |

Contoh smart match yang lolos: `Motor Decanter Atas (M6400S10-2)` →
`M6400S10-2`, `Motor Decanter Bawah (M6400S10-1)` → `M6400S10-1`,
`Nameplate Motor Separator 6163S2` → `6163S2`.

Contoh yang belum cocok: `BV-408`/`BV-409`/... pada folder Breather Valve,
`DIAFRAGMA+`, `LT-R01`, dan beberapa nama file deskriptif. Semua tercatat di
laporan untuk pemetaan manual bila diperlukan.

## Alur apply PROD

1. Deploy kode perintah ke PROD.
2. Dry-run di PROD, periksa laporan dan path disk (harus D:).
3. Periksa backup yang akan dibuat.
4. Jalankan `--apply` di luar jam sibuk.
5. Verifikasi beberapa aset di UI dan file di
   `D:\storage\app\public\assets\photos`.
6. Laporkan jumlah aset/foto masuk, file dilewati, dan daftar belum cocok.

## Uji

- `php artisan test --filter=AssetDocumentationImportTest`: **4 test hijau,
  46 assertions**.
- Seluruh suite: **770 test hijau, 3.826 assertions**.
- `php artisan view:cache` sukses.

## Berkas

- `app/Console/Commands/ImportAssetDocumentation.php`
- `app/Services/Assets/AssetDocumentationImportService.php`
- `tests/Feature/AssetDocumentationImportTest.php`
- `docs/31-import-dokumentasi-asset.md`

## Batasan

- Tidak membuat aset baru dan tidak mengubah tag/deskripsi aset.
- HEIC dilewati karena tidak tampil di browser.
- File besar disalin apa adanya; halaman dengan banyak PNG edited bisa berat.
- Pemetaan manual untuk 168 gambar yang belum cocok belum disediakan.
- Pemindahan database ke D: bukan bagian fitur ini.
