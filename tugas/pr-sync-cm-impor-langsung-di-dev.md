# Sync Google Sheets CM mengimpor LANGSUNG di DEV (baris Google Sheet baru tidak lagi tertinggal di tabel jobs)

## Ringkasan perubahan

Perbaikan satu akar masalah: halaman Finding CM tidak pernah memuat baris
tanggal baru dari spreadsheet padahal sync-nya dilaporkan sukses. Tidak ada
migrasi baru, jadi `php artisan migrate` tidak perlu dijalankan.

1. **Sebabnya "dijadwalkan" bukan "dijalankan".** `SyncGoogleSheetsJob` hanya
   men-DOWNLOAD berkas lalu menaruh job import (`ProcessCmFindingsImport` /
   `ProcessCmExcelImport`) di tabel `jobs`. DEV memang TIDAK menjalankan service
   `queue:work` (bagian Service di `AGENTS.md`), jadi job itu tidak pernah
   diambil, baris `import_logs` tertinggal berstatus 'processing', dan data
   tidak pernah masuk — tanpa satu pun pesan error di layar.
2. **Mode import jadi bisa dipilih.** Kunci config baru
   `cm.google_sheets.import_mode` (env `CM_SYNC_IMPORT_MODE`), bawaan 'inline'
   bila `APP_ENV=local` dan 'queue' di luar local. Jadi DEV mengimpor LANGSUNG
   di proses sync, sedangkan PROD tetap memakai queue seperti sebelumnya —
   perilaku PROD TIDAK berubah dan `.env` PROD tidak perlu disentuh.
3. **Jaring pengaman `import_logs`.** `SyncGoogleSheetsJob::runImportNow()`
   menjalankan job import di proses yang sama; bila job melempar error, baris
   `import_logs` ditandai 'failed' beserta pesannya, sehingga tidak ada lagi
   baris yang tertinggal 'processing' tanpa penjelasan.
4. **Opsi paksa untuk mesin mana pun.** `php artisan cm:sync-sheets --langsung`
   memaksa import langsung (berguna bila worker di suatu mesin sedang mati),
   dan laporan akhir perintah kini menyebut mode yang benar-benar dipakai.

## Checklist wajib (agenda integrator)

- [x] `php artisan test` HIJAU (579 test, 2755 assertions, 122,78 detik).
- [x] `php artisan view:cache` sukses (TIDAK ada berkas blade yang disentuh; dijalankan tetap sebagai pemeriksaan).
- [x] Perubahan HANYA pada berkas yang relevan (bukan `git add -A`).
- [x] Tidak ada berkas sampah (skrip `tmp-*`, `.bak`, hasil export sekali pakai).
- [x] TIDAK ada migrasi baru.
- [x] Sudah `git rebase origin/main` (bukan merge); cabang berdiri tepat di atas
      `f9b65c9` (main SESUDAH PR #2 di-merge).
- [x] Konflik rebase pada `AGENTS.md` + `tugas/erik.md` diselesaikan dengan
      aturan "ambil KEDUA sisi" (butir 6 ALUR KERJA BANYAK TEKNISI), bukan
      dengan membuang salah satu sisi.
- [x] `AGENTS.md` diperbarui (Riwayat Insiden 45 + catatan service DEV pada bagian Service).

## Bukti uji

```
php artisan test
  Tests:    579 passed (2755 assertions)
  Duration: 122.78s

php artisan test --filter=CmSyncImportModeTest
  Tests:    3 passed (14 assertions)
  Duration: 2.26s

php artisan view:cache
  INFO  Blade templates cached successfully.
```

Bukti gejala dan penutupnya di DEV (database `oleochemical_dev`):

```
`jobs`                        : berisi job CM (ProcessCmFindingsImport) yang tidak
                                pernah diambil karena tidak ada queue:work
`import_logs` (sebelum)       : baris terakhir tetap 'processing'
setelah job import dijalankan : 15 baris tanggal 13/10/2026 masuk
                                (insert_baru = 15, import_logs #71)
halaman Finding CM            : Total Finding 466 (data 13/10/2026 tampil)
```

## Berkas yang disentuh

```
1. config/cm.php                                   - kunci `google_sheets.import_mode`
2. .env.example                                    - contoh `CM_SYNC_IMPORT_MODE` (dikomentari)
3. app/Jobs/SyncGoogleSheetsJob.php                - importInline() + runImportNow(), status per sumber
4. app/Console/Commands/SyncGoogleSheetsCommand.php - opsi `--langsung`, pesan akhir sesuai mode
5. tests/Feature/CmSyncImportModeTest.php          - BARU: 3 test penjaga inline vs queue
6. AGENTS.md                                       - Riwayat Insiden 45 + catatan service DEV
7. tugas/erik.md                                   - papan klaim (status SELESAI, menunggu PR; cabang lembur PR #2 dicatat sudah di-merge)
8. tugas/pr-sync-cm-impor-langsung-di-dev.md       - berkas catatan PR ini
```

## Risiko / hal yang perlu diperhatikan integrator

- **Perilaku PROD tidak berubah**: di luar `APP_ENV=local` bawaannya tetap
  'queue', dan jalur `dispatch($job)` yang lama tidak dihapus melainkan bercabang.
  Tidak ada pengaturan `.env` PROD yang perlu ditambah.
- **Verifikasi PROD belum bisa dilakukan dari komputer copy DEV ini.** PROD
  dilayani `http://192.168.41.74:8000` (penanda: `GET /debug-login` = 404) dan
  MySQL-nya menolak koneksi dari komputer ini, jadi langkah pemeriksaan di bawah
  hanya bisa dijalankan di komputer PROD, sesudah deploy.
- Bila ternyata worker PROD sedang mati, perbaikannya cukup satu perintah di
  komputer PROD: `php artisan cm:sync-sheets --langsung` (tanpa mengubah `.env`).
- **Sudah rebase di atas PR #2 yang DI-MERGE lebih dulu (14/10/2026).** Cabang
  `tugas/erik/lembur-tanpa-jam-istirahat-man-hour-tidak-dobel` (PR #2) masuk ke
  `main` sebagai merge commit `f9b65c9` dan menyentuh berkas yang sama:
  `AGENTS.md` dan `tugas/erik.md`. Konflik rebasenya diselesaikan dengan aturan
  butir 6 bagian ALUR KERJA BANYAK TEKNISI: **ambil KEDUA sisi**, lalu nomor
  Riwayat Insiden diurutkan supaya tidak ada nomor kembar. Hasilnya nomor 44
  tetap milik insiden PR #2 dan entri cabang ini memakai 45, sehingga tidak ada
  catatan yang hilang maupun nomor yang tertukar.
- Perubahan ini hanya menyentuh jalur sync CM: tidak ada view, route, migrasi,
  maupun definisi man hour yang ikut berubah.
