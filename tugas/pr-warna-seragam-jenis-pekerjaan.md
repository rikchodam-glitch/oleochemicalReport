# Warna jenis pekerjaan seragam di filter dan grafik (Project = merah)

## Ringkasan perubahan

Permintaan user 14/10/2026: warna setiap jenis pekerjaan "di-fix" supaya seragam
antara **filter laporan** (kartu ringkasan, lencana tabel, popup export) dan
**grafik persentase jenis pekerjaan** di halaman detail alat/lokasi; tiap jenis
punya ciri khas sendiri, contoh **Project = merah**, dan warnanya kontras serta
tidak mirip satu sama lain. TIDAK ada migrasi baru.

1. **Sumber tunggal warna baru**: `app/Support/WarnaJenisPekerjaan.php` (BARU).
   `PALET` memuat 11 jenis; `TechnicianKinerjaService::WARNA_JENIS_PEKERJAAN`
   kini hanya menyalin konstanta itu (nama lama dipertahankan supaya pemakaian
   lama tetap jalan), dan `ResolvesReportAnalytics` memakai `hex()` dari helper
   yang sama. Jadi grafik tren/donut halaman alat/lokasi, kartu kinerja teknisi,
   kartu + lencana halaman Laporan, lencana detail laporan, lencana detail
   teknisi, dan lencana halaman Work Order memakai warna yang SAMA untuk satu
   jenis pekerjaan.
2. **Palet diukur, bukan dikira**: jarak warna CIE76 terkecil antar jenis
   **27,4** (batas uji 25), kontras isi grafik vs latar putih **minimal 2,80**
   (batas 2,5), kontras TEKS lencana **minimal 4,57** (batas 4,5 = WCAG AA).
   Project = **#B91C1C (merah)** sesuai instruksi; jenis lain digeser agar tetap
   punya rona sendiri (CM biru, dCM jingga, PM hijau, CM/PM teal, EI ungu,
   Mekanik kuning keemasan, Fabrikasi mago, Sipil coklat, IT pink, UMUM lime).
3. **Lencana/kartu memakai atribut `style`** yang diturunkan dari hex palet
   (`gayaLencana/gayaTeks/gayaGaris`), bukan kelas Tailwind hasil rakitan data:
   kelas Tailwind yang dibentuk saat render tidak pernah terkompilasi sehingga
   warnanya bisa hilang tanpa pesan error. Kelas `border` tetap dipakai bersama
   `style` karena `style` menang di peramban.
4. **Popup export laporan** ikut diselaraskan: titik penanda warna di tiap
   pilihan jenis pekerjaan + `accent-color` kotak centang memakai warna palet.

## Checklist wajib (agenda integrator)

- [x] `php artisan test` HIJAU (621 test, 3041 assertions).
- [x] `php artisan view:cache` sukses.
- [x] Perubahan HANYA pada berkas yang relevan (bukan `git add -A`).
- [x] Tidak ada berkas sampah (dua skrip sementara di `tmp-oleo\` sudah dihapus).
- [x] TIDAK ada migrasi baru dan TIDAK ada perubahan data.
- [x] Sudah `git rebase origin/main` (bukan merge) sebelum dibuka.
- [x] `AGENTS.md` diperbarui (bagian "WARNA JENIS PEKERJAAN SERAGAM").
- [x] Uji mutasi dijalankan (dua mutasi, dua-duanya tertangkap).

## Bukti uji

```
php artisan test
  Tests:    621 passed (3041 assertions)
  Duration: 149.16s

php artisan view:cache
  INFO  Blade templates cached successfully.
```

Uji mutasi (dijalankan satu per satu, lalu dikembalikan):

1. `PALET['Project']` diganti `#64748B` (abu-abu lama) -> test "setiap jenis
   pekerjaan punya warna sendiri dan Project memakai merah" GAGAL.
2. `ResolvesReportAnalytics::warnaJenisPekerjaan()` dibuat selalu `#0E9E8E`
   (warna grafik tidak lagi mengikuti palet) -> test "grafik halaman detail
   alat/lokasi memakai warna palet yang sama dengan halaman Laporan" GAGAL.

Bukti halaman NYATA di DEV (Apache `http://oleochemicalReport.test`, sesudah
login `/debug-login`; semua halaman berbalas HTTP 200):

- `/reports` memuat SELURUH warna palet (kartu filter: teks `#612DB9` EI,
  `#9E6C03` Mekanik, ..., `#901616` Project; popup export: `background-color:
  #B91C1C`, `accent-color: #7C3AED`).
- `/assets/1997`, `/locations/71`, `/reports/788`, `/technicians/39` memuat
  warna palet yang sama dan TIDAK memuat sisa kelas lama
  (`bg-rose-100`/`bg-cyan-100`/`text-rose-600`/`bg-violet-100` dari peta jenis
  pekerjaan; satu-satunya `bg-violet-100` yang tersisa berasal dari komponen
  `status-badge` untuk TIPE laporan "area work", bukan jenis pekerjaan).
- 7 blok `<script>` pada halaman `/assets/1997` (yang disajikan apa adanya)
  lolos `node --check` (exit 0).

## Berkas yang disentuh

1. `app/Support/WarnaJenisPekerjaan.php` (BARU) - palet + penurunannya
   (`hex`, `teks`, `latar`, `gayaLencana`, `gayaTeks`, `gayaGaris`).
2. `app/Services/TechnicianKinerjaService.php` - konstanta warna menyalin palet.
3. `app/Http/Controllers/Concerns/ResolvesReportAnalytics.php` - grafik memakai
   helper (properti `$warnaJenisPekerjaan` yang jadi sumber kedua DIHAPUS).
4. `resources/views/reports/index.blade.php` - kartu ringkasan jenis pekerjaan
   (angka berwarna) + lencana kolom Jenis.
5. `resources/views/reports/show.blade.php` - DUA peta warna lama (lencana di
   kepala halaman dan kartu Jenis Pekerjaan) diganti helper.
6. `resources/views/reports/partials/_popup-export.blade.php` - titik penanda +
   `accent-color` per jenis.
7. `resources/views/technicians/show.blade.php` - lencana jenis pada daftar
   laporan; peta lama hanya memuat 7 jenis sehingga CM/dCM/PM/UMUM tampil abu-abu
   (kini lengkap 11 jenis).
8. `resources/views/work-orders/index.blade.php` + `show.blade.php` - lencana
   dan kartu ringkasan CM/dCM/PM memakai palet yang sama.
9. `resources/views/partials/_grafik-perbaikan-script.blade.php` - warna
   cadangan grafik diambil dari palet.
10. `tests/Feature/WarnaJenisPekerjaanTest.php` (BARU, 6 test, 88 assertions).
11. `AGENTS.md`, `tugas/erik.md`, berkas ini.

## Risiko / hal yang perlu diperhatikan integrator

- Murni tampilan: tidak ada kolom database, angka, atau aturan bisnis yang
  berubah. Deploy cukup `Deploy-Laporan.bat` seperti biasa (tanpa migrasi).
- Warna lencana sekarang ditulis sebagai atribut `style`, jadi kalau integrator
  mencari kelas Tailwind lama (`bg-rose-100 text-rose-700` dsb.) pada halaman
  Laporan/detail laporan, kelas itu memang sudah tidak ada lagi.
- Kalau suatu saat ada jenis pekerjaan BARU, cukup tambahkan di
  `WarnaJenisPekerjaan::PALET` + daftar pilihan pada `_pelengkap.blade.php` /
  `create.blade.php` / filter / popup export; uji jarak warna akan menolak warna
  yang terlalu mirip dengan jenis lain.
