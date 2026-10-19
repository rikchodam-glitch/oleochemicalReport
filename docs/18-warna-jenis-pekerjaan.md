# Warna Jenis Pekerjaan Seragam

> Bagian dari dokumentasi proyek di folder `docs`.
> Peta dokumen: `AGENTS.md` di akar repo.

## WARNA JENIS PEKERJAAN SERAGAM DI FILTER DAN GRAFIK (14/10/2026)

Diminta user 14/10/2026: "warna di setiap jenis pekerjaan perlu di-fixkan seperti
pada filter laporan dan pada halaman detail alat dan lokasi, di mana ada chart
berwarna untuk menampilkan persentase semua jenis pekerjaan... setiap jenis
pekerjaan punya ciri khas, di filter serta di chart seragam, contoh untuk Project
warnanya merah... pastikan kontras, berbeda-beda, tidak blend dengan background,
dan tidak terlihat mirip antar warna." Semua perubahan ada di copy DEV
`C:\laragon\www\oleochemicalReport`; BELUM naik ke PROD (tunggu
`Deploy-Laporan.bat`; TIDAK ada migrasi baru dan TIDAK ada perubahan data).

### 1. Satu sumber warna: `app\Support\WarnaJenisPekerjaan` (BARU)

- `WarnaJenisPekerjaan::PALET` adalah SATU-SATUNYA daftar warna jenis pekerjaan
  (11 jenis). `TechnicianKinerjaService::WARNA_JENIS_PEKERJAAN` kini hanya
  MENYALIN konstanta itu (nama lama dipertahankan karena sudah dipakai trait
  `ResolvesReportAnalytics`), dan trait itu memakai `WarnaJenisPekerjaan::hex()`.
- Karena itu warna satu jenis SELALU sama di: kartu ringkasan + lencana tabel +
  popup export halaman Laporan, lencana kepala halaman + kartu "Jenis Pekerjaan"
  pada detail laporan, lencana daftar laporan halaman detail teknisi, kartu
  kinerja teknisi, grafik tren + donut komposisi pada detail alat/lokasi, dan
  lencana/kartu halaman Work Order.
- Properti `protected array $warnaJenisPekerjaan` di `ResolvesReportAnalytics`
  dan peta kelas Tailwind per jenis di seluruh berkas blade SUDAH DIHAPUS
  (dulu ada EMPAT peta berbeda: halaman Laporan, dua di detail laporan, dan satu
  di detail teknisi yang bahkan hanya memuat 7 jenis sehingga CM/dCM/PM/UMUM
  tampil abu-abu). JANGAN membuat peta warna baru di blade; ambil dari helper.

### 2. Palet dan angka pembuktian (jangan diubah tanpa mengulang uji)

| Jenis | Warna utama (grafik) | Teks lencana (digelapkan 0,78) |
|---|---|---|
| CM | #2563EB | #1D4DB7 |
| dCM | #EA580C | #B74509 |
| PM | #16A34A | #117F3A |
| CM/PM | #0F766E | #0C5C56 |
| EI | #7C3AED | #612DB9 |
| Mekanik | #CA8A04 | #9E6C03 |
| Fabrikasi | #86198F | #691470 |
| Sipil | #92400E | #72320B |
| IT | #DB2777 | #AB1E5D |
| Project | #B91C1C | #901616 |
| UMUM | #65A30D | #4F7F0A |
| (kosong/tak dikenal) | #94A3B8 | - |

Angkanya DIUKUR, bukan dikira: jarak warna CIE76 antar jenis terkecil **27,4**
(penjaga uji: >= 25), kontras isi grafik terhadap latar putih minimal **2,80**
(penjaga: >= 2,5), kontras TEKS lencana minimal **4,57** (penjaga: >= 4,5 =
ambang WCAG AA untuk teks kecil). **Project = MERAH (#B91C1C)** sesuai instruksi
user; warna jenis lain digeser supaya tiap jenis tetap punya rona sendiri
(CM biru, dCM jingga, Mekanik kuning keemasan, PM hijau, CM/PM teal, EI ungu,
Fabrikasi mago, Sipil coklat, IT pink, UMUM lime).

### 3. Cara memakai (WAJIB, jangan menyalin kode warna ke blade)

- isi grafik / titik / garis : `WarnaJenisPekerjaan::hex($jenis)`
- teks berwarna (angka kartu): `WarnaJenisPekerjaan::teks($jenis)`
- atribut style lencana      : `WarnaJenisPekerjaan::gayaLencana($jenis)`
  (latar `rgba(utama, 0,12)` + teks gelap + garis `rgba(utama, 0,38)`)
- garis berwarna             : `WarnaJenisPekerjaan::gayaGaris($jenis)`

Alasan lencana memakai atribut `style`, BUKAN kelas Tailwind: warna lencana
diturunkan dari hex palet, sedangkan kelas Tailwind hanya terkompilasi kalau
ditulis LITERAL di berkas yang dipindai saat build. Kelas yang dibentuk dari data
PHP (mis. `'bg-'.$nama.'-100'`) TIDAK pernah terkompilasi dan kegagalannya SENYAP
(lencana tampil tanpa warna). Kelas `border` tetap boleh dipakai bersama `style`
karena atribut `style` menang di peramban.

### 4. Uji penjaga

`tests/Feature/WarnaJenisPekerjaanTest.php` (6 test, 88 assertions): palet lengkap
+ unik, Project merah, jarak warna CIE76 >= 25, kontras grafik >= 2,5 dan teks
lencana >= 4,5, helper konsisten, serta halaman NYATA (kartu/lencana/popup export
halaman Laporan, grafik + donut halaman alat dan lokasi, lencana detail laporan
dan detail teknisi) memakai kode warna palet yang sama.
Uji mutasi 14/10/2026 (nyata, dijalankan lalu dikembalikan):
(a) `PALET['Project']` diganti `#64748B` -> 1 test GAGAL;
(b) `ResolvesReportAnalytics::warnaJenisPekerjaan()` dibuat selalu `#0E9E8E` ->
1 test GAGAL.
Seluruh suite **621 test hijau (3041 assertions)** dan `php artisan view:cache`
sukses. Bukti halaman nyata DEV (sesudah `/debug-login`, semua 200): `/reports`,
`/assets/1997`, `/locations/71`, `/reports/788`, `/technicians/39` semuanya
memuat kode warna palet dan NILAI sisa kelas lama dari peta jenis pekerjaan sudah
nihil; 7 blok `<script>` halaman alat lolos `node --check`.
