# Keputusan Arsitektur 31-33

> Bagian dari dokumentasi proyek di folder `docs`.
> Peta dokumen: `AGENTS.md` di akar repo.

31. **Durasi laporan (man hour) memotong jam istirahat pekerja NON-SHIFT
    (diputuskan 13/10/2026 atas instruksi user, sudah ada kodenya).** Semua
    perubahan ada di copy DEV `C:\laragon\www\oleochemicalReport`.
    - Keluhan asal: admin mengisi 10:00-16:00 pada halaman detail laporan,
      tetapi durasi tersimpan 360 menit padahal 12:00-13:00 seharusnya tidak
      dihitung. Sebelum ini pemotongan istirahat HANYA ada pada TARGET harian
      (`JamKerjaService::targetMenit()`), tidak pada durasi laporan.
    - Satu sumber aturan BARU di `app\Services\Shift\JamKerjaService.php`:
      `durasiKerja()` (= `Report::durasiDariJam()` dikurangi `menitIstirahat()`),
      `menitIstirahat()` (irisan rentang dengan jendela istirahat, sadar lintas
      tengah malam), dan `istirahatHari()` (mengembalikan `[]` untuk pekerja
      shift / hari libur). Jendela istirahat dibaca dari
      `config('cm.man_hour.jam_kerja')` (Senin-Kamis & Sabtu 12:00-13:00; Jumat
      11:00-13:00 = 2 jam), jadi mengubah jam istirahat cukup lewat config.
    - Cakupan (KEPUTUSAN USER, jangan diubah tanpa instruksi eksplisit): HANYA
      group `Technician::GRUP_NON_SHIFT` (`reguler`, `reguler_staff`) yang
      dipotong. Pekerja shift grub A-D tetap 8 jam penuh (keputusan 26/09/2026),
      dan hari libur group (Minggu reguler, Sabtu reguler) tidak punya jendela
      istirahat sehingga tidak dipotong. Teknisi/group kosong juga tidak dipotong
      (tidak bisa dinilai).
    - Dipakai di `ReportController::update()` (mode edit halaman detail) dan
      `WizardStepHandlerTrait::handleDurationInput()` (Step 4 wizard Telegram),
      supaya angka dari kedua jalur itu identik. Halaman "Buat Laporan" TIDAK
      terpengaruh karena hanya meminta durasi manual (tanpa jam mulai/selesai).
    - Tampilan: `resources\views\reports\partials\_waktu-kerja.blade.php`
      menambah keterangan "Istirahat dikurangi: 12:00-13:00" (atau "Tanpa
      potongan istirahat (pekerja shift atau hari libur)") dan baris "Potongan
      istirahat" yang dihitung JavaScript dengan aturan sama
      (`menitIstirahat()`, jendela istirahat dikirim lewat `@json($jendelaIstirahat)`).
    - Laporan LAMA disamakan lewat perintah BARU
      `php artisan reports:pangkas-istirahat` (simulasi default; `--execute`
      menulis, `--laporan=<csv>` menulis daftar SELURUH baris yang diperiksa,
      `--limit=<n>` untuk uji coba). Hanya kolom `work_duration_minutes` yang
      diubah (mass update tanpa menyentuh `updated_at`), laporan tanpa jam
      mulai/selesai tidak pernah disentuh, dan ada penjaga keseimbangan kategori.
      JANGAN menyamakan nilai laporan lama dengan `UPDATE` manual; pakai perintah
      ini supaya aturannya tetap satu sumber.
    - Bukti SIMULASI 13/10/2026 dengan aturan AKHIR (istirahat + lembur dipisah;
      belum ada satu baris pun yang ditulis): DEV `oleochemical_dev` 568 laporan
      berjam -> 101 durasi berubah, man hour normal 80.250 -> 69.830 menit
      sedangkan LEMBUR 10.420 menit (69.830 + 10.420 = 80.250, jadi tidak ada
      menit yang hilang); PROD `oleochemical` 1.935 laporan berjam -> 389 durasi
      berubah, normal 316.953 -> 270.298 menit sedangkan lembur 46.655 menit
      (jumlahnya sama). Tahap antara (istirahat saja, sebelum lembur dipisah)
      tercatat di `...pangkas-istirahat-dev-simulasi.csv` /
      `...-prod-simulasi.csv`; hasil akhir per baris ada di
      `C:\laragon\backup-oleochemical\pangkas-istirahat-{dev,prod}-20261013.csv`
      (kolom `lembur` ikut ditulis).
    - TEMUAN awal yang memicu keputusan lembur (13/10/2026): 2 laporan (DEV dan
      PROD) yang rentangnya SELURUHNYA jatuh di jam istirahat (mis. reguler
      staff Jumat 11:00-13:00 dan 11:00-12:15) menjadi 0 menit man hour normal.
      Dengan lembur dipisah, menit itu TIDAK hilang: seluruhnya tercatat sebagai
      lembur.
    - **LEMBUR (overtime) dipisahkan dari man hour (keputusan lanjutan user
      13/10/2026, sudah ada kodenya).** Aturannya: LEMBUR = seluruh menit di
      luar jendela kerja bruto hari itu (sebelum jam masuk, sesudah jam pulang,
      JAM ISTIRAHAT) DITAMBAH seluruh jam pada hari libur/OFF, sehingga
      `total = normal + lembur`. Jendela kerja diambil
      `JamKerjaService::jendelaKerja()` (jadwal shift import/proyeksi pola ->
      jam kerja harian non-shift; null = libur/OFF), dan
      `JamKerjaService::jadwalRentang()` menjadi satu-satunya tempat resolusi
      jadwal (dipakai juga `ManHourService::harian()`). Bentuk kode:
      `menitDalamJendela()`, `ringkasJamKerja(?TechnicianShift, ?grup, tanggal,
      mulai, selesai)` (mengembalikan `total/normal/lembur/istirahat/jendela/
      libur`), dan `durasiKerja(?Technician, ...)` yang kini menerima MODEL
      Technician (bukan kode group saja) supaya jadwal shift ikut dinilai.
      Group teknisi yang tidak dikenal tetap dihitung jam kerja biasa (tidak
      dipotong dan tidak dijadikan lembur) supaya angka lama tidak berubah.
    - Tampilan lembur: chip "Lembur: X j" + tombol **Cek Overtime** pada kartu
      Man Hour `resources\views\technicians\show.blade.php`, kolom "Lembur" +
      segmen batang ORANYE (`bg-amber-500`) pada tabel Rincian Man Hour Harian,
      dataset batang oranye bertumpuk ("Lembur (jam)") pada grafik harian, dan
      popup BARU `resources\views\technicians\partials\_modal-lembur.blade.php`
      berisi rincian per tanggal (tanggal, hari, label shift, total lembur) dan
      daftar laporan penyebabnya lengkap dengan jam kerja, menit lembur, serta
      tautan ke detail laporan. Datanya dari
      `ManHourService::lemburPerTanggal()` (dipakai
      `TechnicianController::show()` sebagai `$lemburRincian`). Halaman edit
      laporan (`_waktu-kerja.blade.php`) juga menampilkan "Lembur: X" pada baris
      keterangan jam, sedangkan kolom durasi read-only tetap angka NORMAL.
    - Akibat yang DISADARI dan dipilih user: laporan pada hari libur/OFF serta
      laporan di luar jam kerja memiliki man hour NORMAL 0 karena seluruhnya
      menjadi lembur (mis. reguler 22:00-06:00, reguler staff Sabtu 08:00-17:00).
      Uji lama `ReportEditJamKerjaTest` dan `ReportDurasiKonsistenTest`
      disesuaikan: keduanya dulu mengharapkan man hour normal 480/60 menit dan
      sekarang memeriksa `total` + `lembur` lewat `ringkasJamKerja()`.
    - Uji: `tests/Feature/ReportIstirahatTest.php` (10 test: service, form edit,
      halaman detail, wizard Telegram, perintah simulasi/--execute) dan
      `tests/Feature/TechnicianLemburTest.php` (5 test: pemisahan lembur,
      pengelompokan per tanggal, penanda + tombol + segmen oranye, isi popup,
      hari OFF). Seluruh suite **564 test hijau (2696 assertions)** dan
      `php artisan view:cache` sukses (13/10/2026).

32. **Jam istirahat BUKAN lembur + man hour dihitung dari JAM laporan, bukan
    dari kolom durasi tersimpan (diputuskan user 13/10/2026, penyempurnaan
    aturan butir 31 pada hari yang sama).**
    Semua perubahan ada di copy DEV `C:\laragon\www\oleochemicalReport`.
    - Keluhan asal user: "jam lembur di jam istirahat tidak perlu dihitung saja,
      jadi auto terpotong durasinya; kecuali jam setelah jam kerja, ini berlaku
      untuk shift juga" DITAMBAH temuan "laporan di luar jam kerja atau hari
      libur malah jadi dobel, mis. kerja 6 jam di hari Minggu bagi reguler jadi
      12 jam kerja".
    - Aturan BARU di `JamKerjaService::ringkasJamKerja()`: `istirahat` =
      `min(menitIstirahat, menitDalamJendela)`; `normal = dalamJendela -
      istirahat`; **`lembur = total - dalamJendela`** (DULU `total - normal`
      sehingga jam istirahat ikut menjadi lembur). Jadi
      `total = normal + lembur + istirahat` dan tidak ada menit yang dihitung
      dua kali. Jam di luar jendela kerja (sebelum jam masuk / sesudah jam
      pulang) dan seluruh jam pada hari libur/OFF TETAP lembur — berlaku juga
      untuk pekerja shift (grub A-D memang tidak punya jendela istirahat).
    - Sebab angka DOBEL: kolom `reports.work_duration_minutes` laporan LAMA
      masih memuat durasi PENUH (belum pernah dipangkas), sedangkan lembur
      dihitung dari jam, sehingga menit yang sama muncul di kedua angka.
      Perbaikan: `ManHourService` memakai method BARU `menitNormalLaporan()`
      di `harian()` DAN `menitPerTeknisi()`, artinya **angka man hour SELALU
      dihitung ulang dari jam laporan** dan kolom `work_duration_minutes` hanya
      CADANGAN untuk laporan tanpa jam mulai/selesai (mis. laporan yang dibuat
      dari form web dengan durasi manual). Perintah
      `reports:pangkas-istirahat` tetap ada untuk menyamakan data lama, tetapi
      KINI OPSIONAL karena pembacanya sudah benar tanpa penyamaan itu.
    - Tampilan: partial BARU
      `resources\views\reports\partials\_waktu-kerja-lihat.blade.php` (mode
      lihat: durasi + "lembur X Jam (di luar jam kerja)"), di-include SATU baris
      dari `show.blade.php` setelah blok lama (28 baris) dihapus;
      `_waktu-kerja.blade.php` (mode edit) kini mengisi angka awalnya dari
      `ringkasJamKerja()` dan skripnya memakai rumus baru yang sama; kalimat
      "jam istirahat" pada definisi lembur di
      `resources\views\technicians\partials\_modal-lembur.blade.php` dihapus.
    - Sekaligus membereskan dua bug PENGHAPUSAN: (1) berkas foto yang DIPAKAI
      BERSAMA laporan induk tidak lagi terhapus saat baris salinan dihapus
      (`Report::pathFotoLaporanLain()`, dibaca sekali per penghapusan tanpa
      fungsi JSON khusus supaya jalan di MySQL maupun SQLite); (2) menghapus
      laporan INDUK kini ikut menghapus baris salinannya — sebelumnya
      `multi_of` memakai `nullOnDelete` sehingga salinan alat tambahan berubah
      menjadi laporan biasa dan jamnya terhitung dua kali (salinan kolaborasi
      memang sudah cascade di database).
    - Uji: `tests/Feature/ReportLemburTanpaIstirahatTest.php` (7 test: rumus
      istirahat, jam sesudah jam kerja, pekerja shift, TIDAK dobel pada hari
      libur, angka dari jam untuk laporan lama, halaman detail, laporan tanpa
      jam) dan `tests/Feature/ReportHapusSalinanAmanTest.php` (5 test:
      salinan dihapus tidak menghapus foto induk, foto khusus salinan tetap
      dibersihkan, hapus induk menghapus salinan + fotonya, lewat rute hapus,
      laporan tanpa salinan). `TechnicianLemburTest` disesuaikan (lembur
      10:00-16:00 sekarang 0 menit). Uji mutasi 13/10/2026: (a) mengembalikan
      `lembur = total - normal` membuat 6 test GAGAL; (b) menonaktifkan
      `menitNormalLaporan()` (selalu memakai kolom durasi) membuat 3 test
      GAGAL; (c) membuat `pathFotoLaporanLain()` selalu kosong dan (d)
      mematikan penghapusan baris salinan masing-masing membuat 3 test GAGAL.
      Seluruh suite **576 test hijau (2741 assertions)** dan
      `php artisan view:cache` sukses (13/10/2026).
    - Bukti data NYATA PROD `oleochemical` (13/10/2026, skrip sementara
      `tmp-oleo\cek-manhour-prod.php`, TIDAK menulis apa pun): 1.942 laporan
      berjam -> total rentang 319.418 menit = normal 271.373 + lembur 32.010 +
      istirahat 16.035 (SEIMBANG, tidak ada menit hilang); 172 laporan yang
      sebelumnya tampil dobel, 33.190 menit tampilan berlebih hilang.

33. **Grafik jam kerja pada hari libur/OFF tidak lagi menampilkan batang "jam
    kerja" (diputuskan 14/10/2026 atas laporan user, sudah ada kodenya).**
    Semua perubahan ada di copy DEV `C:\laragon\www\oleochemicalReport`; BELUM
    naik ke PROD (tunggu `Deploy-Laporan.bat`; TIDAK ada migrasi baru).
    - Keluhan asal user: "grafik jam kerja di hari off muncul grafik hijau,
      padahal seharusnya grafik lembur jika ada laporan di hari Minggu".
    - Akar masalah: laporan yang TIDAK punya jam mulai/selesai (durasi diketik
      manual lewat form web) dihitung lewat CADANGAN
      `reports.work_duration_minutes` sebagai man hour NORMAL **tanpa melihat
      hari itu libur atau bukan**. Bukti data DEV `oleochemical_dev`: laporan id
      1 (Erik Khoirul Adam, 19/07/2026 Minggu, tanpa jam, durasi 480) menghasilkan
      `menit=480, lembur=0` sehingga batangnya hijau; sesudah perbaikan menjadi
      `menit=0, lembur=480` dengan catatan "Ada 1 pekerjaan pada hari OFF".
    - Perbaikan di `App\Services\Shift\JamKerjaService::ringkasJamKerja()`:
      jendela kerja hari itu dihitung LEBIH DULU (sebelum rentang jam diperiksa),
      sehingga penanda `libur` (`$jendela === null`) ikut benar untuk laporan
      tanpa jam. `ManHourService` menambah `menitLaporan()` yang mengembalikan
      `normal` + `lembur` sekaligus: laporan tanpa jam pada hari libur/OFF ->
      seluruh durasi cadangan menjadi LEMBUR, sedangkan pada hari kerja tetap
      seperti semula (durasi cadangan = man hour normal). `menitNormalLaporan()`
      kini hanya pembungkus tipis dari `menitLaporan()` supaya angka daftar
      teknisi dan profil teknisi tidak pernah berbeda.
    - Ikutan yang disengaja: hari tanpa target (kode shift 'x' MAUPUN hari libur
      jadwal non-shift seperti Minggu reguler / Sabtu-Minggu reguler staff) kini
      selalu diberi catatan "Ada N pekerjaan pada hari OFF" (dulu hanya hari
      dengan baris jadwal kode 'x'), dan `statusHari()` ikut menghitung lembur
      sehingga hari kerja di hari libur berstatus 'lebih', bukan 'libur'.
    - Tampilan: blok grafik halaman detail teknisi dipindah ke partial BARU
      `resources\views\technicians\partials\_grafik-manhour.blade.php`
      (`@include` satu baris dari `technicians\show.blade.php`) lalu
      dioptimalkan: **Target digambar sebagai GARIS putus-putus** (batang target
      membuat Chart.js menaruh kelompok batang kedua di sebelah kanan sehingga
      grafik menyesatkan), satu batang bertumpuk per hari (jam kerja + lembur),
      tooltip memuat total/target/jadwal/jumlah laporan + catatan hari itu,
      sumbu X mewarnai tanggal hari libur yang ada pekerjaannya dengan merah
      (`#E11D48`), kanvas memakai `maintainAspectRatio: false` di wadah `h-64`,
      dan ada keterangan warna di bawah grafik.
    - Uji: `tests\Feature\TechnicianGrafikHariOffTest.php` (6 test, 24
      assertions: hari libur tanpa jam = lembur, hari kerja tanpa jam tetap
      memakai durasi tersimpan, pekerja shift hari OFF, popup Cek Overtime, data
      grafik pada halaman detail, dan markup partial). Dua test
      `TechnicianShiftManHourTest` disesuaikan karena memang mengunci perilaku
      lama: `total_menit` 1.260 -> 960 (+ lembur 300) dan laju mingguan 49,0 ->
      36,2 j. Seluruh suite **615 test hijau (2.953 assertions)** dan
      `php artisan view:cache` sukses (14/10/2026).
    - Bukti render nyata DEV (HTTP + cookie `/debug-login`, `/technicians/39`):
      halaman 200 dan memuat `"libur":true`, `"lembur":480`, `type: 'line'`,
      `stepped: 'middle'`, `maintainAspectRatio: false`, `'#E11D48'`, serta
      keterangan "Batang teal = jam kerja normal"; blok `<script>` grafik yang
      benar-benar disajikan (8.982 karakter) lolos `node --check`, dan popup
      "Cek Overtime" memuat tanggal 27 September 2026 dengan "lembur 8,0 j".

## MAN HOUR BULANAN DAN GRAFIK GESER — 27/10/2026

- `TechnicianController::rentangManHour()` sekarang SELALU memakai bulan
  berjalan (awal sampai akhir bulan), bukan periode file jadwal shift terakhir.
  Alasan: periode import terakhir berakhir di tanggal 26, sehingga tanggal 27
  sampai akhir bulan tidak tampil di daftar maupun detail teknisi.
- `technicians/show.blade.php`: Rincian Man Hour Harian diurutkan terbaru di
  atas memakai `array_reverse($manHourHarian)`, sehingga tanggal 1 berada di
  paling bawah.
- Grafik `grafikManHour` dibungkus `#grafikManHourScroll` dengan
  `overflow-x-auto`, lebar minimum 42 px per hari (minimal 720 px). Seluruh
  tanggal 28-31 hari muat dan grafik bisa digeser horizontal memakai scrollbar
  atau drag mouse; layar sentuh memakai scroll native.
- Uji baru di `TechnicianShiftManHourTest`: periode bulan berjalan, tanggal 1
  di bawah, wadah grafik bisa digeser, dan label bulan tampil.
- Uji: `TechnicianShiftManHourTest` **17 test hijau, 160 assertions**; seluruh
  suite **772 test hijau, 3.842 assertions**; `view:cache` sukses.

## REVISI MAN HOUR: KEMBALI KE PERIODE 26-25 + TABEL SCROLL — 27/10/2026

- Koreksi permintaan user: periode grafik/tabel TETAP memakai periode jadwal
  shift terakhir (umumnya 26-25), bukan bulan kalender 1-31. Bila jadwal belum
  pernah diimpor, fallback tetap bulan berjalan.
- Data grafik/tabel diperluas satu bulan SEBELUM dan SESUDAH periode 26-25,
  sehingga tanggal di luar periode tetap bisa dilihat. Tampilan awal tetap
  di 26-25: grafik di-scroll ke tanggal 26, tabel di-scroll ke tanggal 25.
  Ringkasan, target, dan rincian lembur tetap dihitung untuk periode 26-25.
- Grafik `grafikManHour` selebar 42 px per hari dan bisa digeser horizontal
  (scrollbar/drag mouse); area sebelum/sesudah periode tersedia untuk digeser.
- Rincian Man Hour Harian memakai `max-h-[32rem] overflow-auto` dengan header
  sticky; tabel memuat data sebelum/sesudah periode dan bisa digulir
  ke atas/bawah.
- Uji: `TechnicianShiftManHourTest` **17 test hijau, 167 assertions**; seluruh
  suite **772 test hijau, 3.849 assertions**; `view:cache` sukses.

## PERIODE MAN HOUR OTOMATIS 26-25 — 27/10/2026

- `TechnicianController::rentangManHour()` sekarang menghitung periode dari
  tanggal berjalan, bukan dari file jadwal shift terakhir:
  - tanggal 1-25  -> mulai tanggal 26 bulan lalu;
  - tanggal 26-akhir bulan -> mulai tanggal 26 bulan ini.
  Selesai selalu tanggal 25 bulan berikutnya, sehingga periode otomatis
  bergeser saat tanggal 26 tiba.
- Grafik/tabel tetap memuat satu bulan sebelum dan sesudah periode berjalan;
  tampilan awal tetap di 26-25. Ringkasan, target, dan rincian lembur mengikuti
  periode berjalan.
- Baris shift masa depan tetap dibuat otomatis oleh scheduler
  `shifts:generate --execute`; data lama TIDAK dihapus dan masih bisa dilihat
  dengan menggeser grafik/menggulir tabel.
- Uji: seluruh suite **772 test hijau, 3.849 assertions**; `view:cache` sukses.

## FOKUS AWAL HARI INI — 27/10/2026

- Saat halaman detail teknisi pertama kali dibuka, grafik di-scroll sehingga
  tanggal HARI INI berada di paling kiri dan tabel di-scroll sehingga baris
  tanggal HARI INI berada di paling atas. Tidak perlu mencari/menggeser dulu.
- Data grafik/tabel tetap mencakup satu bulan sebelum dan sesudah periode
  26-25 berjalan; tanggal lain tetap bisa dilihat dengan menggeser grafik atau
  menggulir tabel.
- Ringkasan, target, dan rincian lembur tetap mengikuti periode 26-25 berjalan.
- Uji: `TechnicianShiftManHourTest` **17 test hijau, 168 assertions**; seluruh
  suite **772 test hijau, 3.850 assertions**; `view:cache` sukses.
