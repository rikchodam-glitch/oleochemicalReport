# Grafik jam kerja hari OFF = lembur (bukan jam kerja) + optimalisasi grafik man hour

## Ringkasan perubahan

Satu perbaikan bug angka man hour dan optimalisasi grafik halaman detail
teknisi. TIDAK ada migrasi baru, jadi `php artisan migrate` tidak perlu
dijalankan.

1. **Laporan TANPA jam mulai/selesai pada hari libur/OFF kini dihitung LEMBUR.**
   Dulu laporan yang durasinya diketik manual (tanpa jam) dibaca dari CADANGAN
   `reports.work_duration_minutes` sebagai man hour NORMAL **tanpa melihat hari
   itu libur atau bukan**, sehingga grafik halaman detail teknisi menampilkan
   batang HIJAU "Jam kerja" pada hari Minggu. Sekarang:
   `JamKerjaService::ringkasJamKerja()` menghitung jendela kerja LEBIH DULU
   (penanda `libur` benar walau jam kosong) dan `ManHourService::menitLaporan()`
   memindahkan seluruh durasi itu ke LEMBUR (batang oranye). Pada hari kerja
   perilakunya TIDAK berubah (durasi tersimpan = man hour normal).
2. **Optimalisasi grafik man hour.** Blok grafik dipindah ke partial baru
   `resources/views/technicians/partials/_grafik-manhour.blade.php`
   (halaman 813 baris tidak perlu ditulis ulang lagi): target digambar sebagai
   **garis putus-putus** (batang target membuat Chart.js menaruh kelompok batang
   kedua di sebelah kanan sehingga grafik menyesatkan), satu batang bertumpuk per
   hari (jam kerja + lembur), tooltip memuat total/target/jadwal/jumlah laporan +
   catatan, tanggal hari libur yang ada pekerjaannya diberi warna MERAH, kanvas
   memakai `maintainAspectRatio: false` di wadah `h-64`, dan ada keterangan warna.
3. **Ikutan:** hari libur jadwal non-shift (Minggu reguler, Sabtu-Minggu reguler
   staff) kini ikut ditandai "Ada N pekerjaan pada hari OFF" dan berstatus
   'lebih' — dulu hanya hari OFF pekerja shift (kode 'x').

## Checklist wajib (agenda integrator)

- [x] `php artisan test` HIJAU (615 test, 2953 assertions).
- [x] `php artisan view:cache` sukses.
- [x] Perubahan HANYA pada berkas yang relevan (bukan `git add -A`).
- [x] Tidak ada berkas sampah (skrip pemeriksa `tmp-oleo\cek-grafik-manhour.php`
      sudah dihapus pada sesi ini).
- [x] TIDAK ada migrasi baru.
- [x] Sudah `git rebase origin/main` (bukan merge) sebelum dibuka.
- [x] `AGENTS.md` diperbarui (Keputusan Arsitektur poin 33).

## Bukti uji

```
php artisan test
  Tests:    615 passed (2953 assertions)
  Duration: 126.59s

php artisan view:cache
  INFO  Blade templates cached successfully.
```

Bukti data nyata DEV `oleochemical_dev` (HANYA BACA; skrip sementara sudah
dihapus):

```
SEBELUM perbaikan - teknisi 1 (reguler), 19/07/2026 Minggu, tanpa jam, durasi 480
  19/07 Minggu  target=0  normal=480  lembur=0   <- batang HIJAU (bug)

SESUDAH perbaikan
  19/07 Minggu  target=0  normal=0    lembur=480 status=lebih
                catatan: "Ada 1 pekerjaan pada hari OFF"

teknisi 39 (reguler), 27/09/2026 Minggu 08:00-16:00 (tidak berubah, sudah benar)
  27/09 Minggu  target=0  normal=0    lembur=480 status=lebih
```

Bukti render nyata DEV (HTTP + cookie `/debug-login`, `/technicians/39`, halaman
200): memuat `"libur":true`, `"lembur":480`, `type: 'line'`,
`stepped: 'middle'`, `maintainAspectRatio: false`, `'#E11D48'`, dan keterangan
"Batang teal = jam kerja normal"; blok `<script>` grafik yang benar-benar
disajikan (8.982 karakter) lolos `node --check` (exit 0); popup "Cek Overtime"
memuat tanggal 27 September 2026 dengan "lembur 8,0 j".

## Berkas yang disentuh

1. `app/Services/Shift/JamKerjaService.php` — jendela kerja dihitung lebih dulu
   sehingga penanda `libur` benar untuk laporan tanpa jam.
2. `app/Services/Shift/ManHourService.php` — `menitLaporan()` (normal + lembur),
   catatan hari OFF untuk hari libur non-shift, `statusHari()` + lembur.
3. `resources/views/technicians/partials/_grafik-manhour.blade.php` (BARU) —
   seluruh grafik + datanya.
4. `resources/views/technicians/show.blade.php` — wadah kanvas + keterangan,
   blok grafik lama (91 baris) diganti satu `@include`.
5. `tests/Feature/TechnicianGrafikHariOffTest.php` (BARU, 6 test) dan
   `tests/Feature/TechnicianShiftManHourTest.php` (2 harapan disesuaikan karena
   mengunci perilaku lama).
6. `AGENTS.md` (Keputusan Arsitektur poin 33), `tugas/erik.md`, berkas ini.

## Risiko / hal yang perlu diperhatikan integrator

- Perubahan ini menyentuh DEFINISI angka man hour untuk laporan TANPA jam pada
  hari libur/OFF: man hour normalnya turun (menjadi 0) dan LEMBUR-nya naik dengan
  jumlah menit yang sama, sehingga tidak ada menit yang hilang.
- Kolom `reports.work_duration_minutes` TIDAK diubah oleh perbaikan ini (angka
  tersimpannya tetap apa adanya) — yang berubah hanya cara membacanya. TIDAK ada
  perintah penyama data yang perlu dijalankan di PROD.
- TIDAK ada migrasi baru; deploy cukup lewat `Deploy-Laporan.bat` seperti biasa.
