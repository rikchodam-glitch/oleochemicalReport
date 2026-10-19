# Filter Halaman Laporan dan Kartu Total Pekerjaan

> Bagian dari dokumentasi proyek di folder `docs`.
> Peta dokumen: `AGENTS.md` di akar repo.

## FILTER HALAMAN LAPORAN TIDAK LAGI RESET TANGGAL + KARTU TOTAL PEKERJAAN DIPINDAH KE SAMPING DONE/CONTINUE (14/10/2026)

Diminta user 14/10/2026 (tiga keluhan sekaligus): (1) "Total Pekerjaan" dipindah
tepat di Done/Continue; (2) filter bug - sudah memilih tanggal lalu pindah dari
Reguler ke Shift, tanggalnya kembali ke hari ini; (3) membuka laporan dari profil
teknisi SHIFT ternyata masuk mode Reguler, dan begitu dipindah ke Shift kena bug
nomor 2. Semua perubahan ada di copy DEV `C:\laragon\www\oleochemicalReport`;
BELUM naik ke PROD (tunggu `Deploy-Laporan.bat`; TIDAK ada migrasi baru dan
TIDAK ada perubahan data).

### 1. Akar masalah (dibuktikan langsung ke copy DEV, bukan dugaan)

- **Tautan pindah tampilan/grub membuang tanggal.** `$tautanTampilan()` di
  `resources\views\reports\index.blade.php` membangun URL dari `$dasarTautan`
  (= `request()->except([... 'tanggal' ... 'date_from', 'date_to'])`) TANPA
  menambahkan kunci tanggal itu kembali. Bukti curl 14/10/2026 atas
  `http://oleochemicalReport.test/reports?tanggal=2026-09-20`: tab Shift
  berbunyi `href=".../reports?tampilan=shift"` sehingga halaman tujuan memakai
  `resolveTanggal(null)` = HARI INI. Rentang (`date_from`/`date_to`) hilang
  dengan sebab yang sama, dan form pemilih grub hanya mengirim satu isian
  `tanggal` tetap (`value="{{ $tanggalAktifStr }}"`) sehingga rentang tidak
  pernah terbawa.
- **Tautan dari profil teknisi tidak membawa tampilan.**
  `resources\views\technicians\show.blade.php` memakai
  `route('reports.index', ['technician_id' => $technician->id])` (dan varian
  ber-`tanggal` pada baris Rincian Man Hour Harian) tanpa `tampilan`, sehingga
  halaman Laporan terbuka pada tampilan Reguler yang HANYA memuat grup non-shift.
  Bukti data DEV: teknisi id 20 (grup `grub_a`) punya 3 laporan pada 26/09/2026,
  tetapi `/reports?technician_id=20&tanggal=2026-09-26` menampilkan **0 baris**,
  sedangkan `...&tampilan=shift` menampilkan **3 baris**.

### 2. Aturan yang dipakai sekarang (jangan diubah tanpa instruksi user)

- `$kunciTanggal` = `tanggal` / `date_from` / `date_to` yang ADA di query, dan
  `$tautanTampilan($mode, $grub)` menyertakannya. Jadi berpindah tab
  Reguler <-> Shift (atau memilih grub) TIDAK PERNAH mengubah tanggal/rentang
  yang sedang dipakai. Yang tetap MENETAPKAN tanggal adalah tautan tanggal
  (chip tanggal, tombol sebelumnya/berikutnya, "Hari Ini", tombol "Buka"), dan
  tautan itu sengaja membuang rentang supaya memilih satu hari benar-benar satu
  hari.
- Form pemilih grub mengirim `$kunciTanggal` (rentang bila rentang yang aktif,
  tanggal harian bila itu yang aktif) - bukan lagi satu isian `tanggal` tetap.
- Semua tautan ke halaman Laporan dari profil teknisi memakai satu variabel
  `$parameterLaporan` = `technician_id` + `tampilan` (Shift untuk pekerja
  Grub A-D, Reguler untuk reguler/reguler staff) + `grub` (khusus shift).
  Baris Rincian Man Hour Harian menambahkan `tanggal` hari itu.
- Kartu "Total Pekerjaan" (`data-kartu="total"`) kini berada di grup kartu status
  - urutan markup: Total, Continue, Done - lalu baris kartu jenis pekerjaan
  (8 kartu, satu baris penuh di `lg:grid-cols-8`). Angkanya tetap
  `$reports->total()` (paginator), jadi tetap DIJAMIN sama dengan jumlah baris
  tabel pada filter yang aktif.

### 3. Uji penjaga

`tests\Feature\ReportFilterTampilanTanggalTest.php` (4 test) dan
`tests\Feature\TechnicianTautanTampilanTest.php` (4 test). Uji mutasi nyata
14/10/2026 (dijalankan satu per satu lalu dikembalikan):
(a) `, $kunciTanggal` dihapus dari `$tautanTampilan` -> 2 test tab GAGAL;
(b) tautan profil teknisi dikembalikan ke `['technician_id' => ...]` -> 3 test
profil teknisi GAGAL;
(c) isian tersembunyi `$kunciTanggal` pada form grub dikembalikan menjadi satu
`tanggal` tetap -> test "pemilih grub membawa tanggal atau rentang" GAGAL;
(d) kartu Total dikembalikan ke baris kartu jenis pekerjaan -> test "kartu Total
Pekerjaan berada tepat di samping kartu Done dan Continue" GAGAL
("Failed asserting that 39201 is less than 31620").
**Pelajaran: (1) setiap tautan navigasi yang "mengatur ulang" sebagian parameter
WAJIB mengisi ulang parameter yang dianggap miliknya - membuang `tanggal` dari
daftar dasar tanpa menambahkannya kembali membuat filter pengguna hilang dan
halaman diam-diam kembali ke hari ini; (2) saat membangun tautan dari halaman
lain (profil teknisi -> halaman Laporan), parameter yang menentukan TAMPILAN
harus ikut dikirim, karena filter grup di sisi server memang menyembunyikan data
yang tidak sesuai tampilan; (3) uji yang hanya memeriksa "isian ada" bisa LULUS
walau perbaikannya dibatalkan (kasus tanggal tunggal pada form grub) - ujilah
KASUS yang benar-benar berubah (rentang) supaya uji mutasi menangkapnya;
(4) saat menghitung elemen berdasarkan nama atribut, batasi ke SATU form/kartu
(helper `formPemilihGrub()` pada uji); halaman ini memuat TIGA isian bernama
sama (form pemilih grub, panel Filter Lanjutan, popup Export) sehingga hitungan
global menyesatkan pada percobaan pertama.**

### 4. Bukti halaman NYATA (DEV, sesudah `/debug-login`)

- `/reports?tanggal=2026-09-20` -> tab: `?tampilan=reguler&tanggal=2026-09-20`
  dan `?tampilan=shift&tanggal=2026-09-20` (dulu keduanya tanpa tanggal).
- `/reports?date_from=2026-09-26&date_to=2026-09-27` -> tab Shift membawa
  `date_from` + `date_to` (dulu hilang).
- `/technicians/20` -> `?technician_id=20&tampilan=shift&grub=grub_a&tanggal=2026-09-26`
  (dan `...&grub=grub_a` untuk "Lihat semua di halaman Laporan"); membukanya
  menampilkan 3 baris laporan teknisi itu.
- Urutan kartu pada `/reports`: `data-kartu="total"` (posisi 31388) <
  `data-kartu="status-continue"` (31942) < `data-kartu="jenis-EI"` (41865).
- Seluruh suite **635 test hijau (3113 assertions)** dan `php artisan view:cache`
  sukses (14/10/2026).
