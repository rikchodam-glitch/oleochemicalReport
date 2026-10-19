# Modul Spare Part (Integrasi PR/PO)

> Bagian dari dokumentasi proyek di folder `docs`.
> Peta dokumen: `AGENTS.md` di akar repo.

## MODUL SPARE PART (INTEGRASI PR/PO) — KEPUTUSAN 09/10/2026

Semua perubahan di copy DEV `C:\laragon\www\oleochemicalReport` (commit
`767f119`), **BELUM naik ke PROD** (tunggu `Deploy-Laporan.bat`; ada migrasi
baru `2026_10_09_000002_sparepart_pantau_keadaan_impor`).

- **Halaman awal = TABEL KEADAAN**, satu baris per kode material (bukan lagi
  daftar periode yang harus dibuka satu-satu; `sparepart.show` dan
  `resources/views/sparepart/show.blade.php` DIHAPUS). Rute: `sparepart.index`,
  `sparepart.export`, `sparepart.impor` (GET+POST `sparepart.impor.proses`),
  `sparepart.riwayat`, `sparepart.pantau` (+ `store`/`update`/`destroy`/`impor`).
- **Tiga tabel**: `sp_pantau` (daftar pantau = populasi/filter halaman, bisa
  diimpor dari sheet MASTER DATA atau diedit manual), `sp_material` (keadaan
  terkini per kode material, hasil UPSERT tiap import), `sp_impor` (riwayat
  import + analisa JSON). Tujuh tabel rancangan lama dibuang (`sp_batch`,
  `sp_monitoring`, `sp_g001`, `sp_pr`, `sp_po`, `sp_mrp`, `sp_integrasi`)
  beserta model/trait lamanya (`SpBatch`, `SpMonitoring`, `SpG001`, `SpPr`,
  `SpPo`, `SpMrp`, `SpIntegrasi`, `SparepartPenyimpananTrait`). Aman karena
  modul ini belum pernah ada di database produksi (diperiksa 09/10/2026:
  `show tables like 'sp%'` pada `oleochemical` kosong).
- **Empat berkas sumber** (NAMA berkas bebas, ISI yang dikunci): laporan stok
  gudang (lembar `G001`/`MASUK`/`KELUAR`), EXPORT PR (ME5A), EXPORT PO (ME2M),
  dan EXPORT MRP (MD07). Judul kolom wajib ada di `SparepartReader::JUDUL_*`; berkas yang kehilangan
  judul wajib DITOLAK (`App\Exceptions\SparepartFormatException`, memuat daftar
  judul yang hilang) **sebelum ada baris database yang ditulis**. Judul dikenali
  dari NAMA (urutan kolom boleh bergeser). Berkas PR user bernama `EXPORT Okt
  2026.XLSX`, PO bernama `EXPORT PO Okt 2026.XLSX`.
- **Sisa stok G001 DIHITUNG** = stok awal + barang MASUK - barang KELUAR per
  kode material (bukan membaca sel formula), supaya material yang punya lebih
  dari satu baris S.Loc tidak dihitung dobel. Dibuktikan 09/10/2026: cocok
  dengan sel "Sisa Stok" berkas nyata (5 baris acak) dan setara sheet
  `StokManual` pengguna (410 material cocok, 0 beda).
- **Stok minimum = kolom `Jumlah` pada sheet MASTER DATA** (pilihan user
  09/10/2026), disimpan di `sp_pantau.stok_min` dan dapat diedit manual.
  **Status REORDER = stok tersedia <= stok minimum**; material yang tidak
  tercantum di laporan stok dihitung sebagai stok nol (perilaku ini yang
  mereproduksi angka dashboard lama).
- **Aturan AKSI TIDAK berubah** (label tampilan sejak 19/10/2026): Kejar
  Delivery (ada PO terbuka) -> Kejar PO (ada PR terbuka) -> Cek/Buat PR (ada PR
  tetapi tidak ada yang terbuka) -> Buat PR.
  Bukti 09/10/2026: aturan ini diterapkan pada kolom hasil dashboard lama
  (`Integrasi_PR_PO_SparePart.xlsx`, sheet Daftar Aksi) **cocok 149/149 baris**
  (sebaran 25/51/45/28). Kolom `sp_material.pr_terbuka` (jumlah PR terbuka per
  material) yang menyimpan dasar aturan itu — jangan diganti menjadi status PR
  terpilih, kombinasi itu pernah menghasilkan "Buat PO 0" yang salah.
- **Bukti import berkas nyata 09/10/2026 (DEV)**: stok 3.328 baris/2.775
  material, PR 397 baris/199 material/109 dokumen, PO 2.990 baris/791
  material/1.548 dokumen, daftar pantau 449 kode -> **REORDER 149, AMAN 300**
  (sama dengan dashboard lama) dan import kedua mencatat "tidak berubah 449"
  (upsert idempoten).
- **CATATAN CAKUPAN BERKAS (penting untuk pengguna)**: `EXPORT Okt 2026.XLSX`
  dan `EXPORT PO Okt 2026.XLSX` di folder "File Import" adalah CUPLIKAN
  Oktober 2026, sehingga hanya 26 material pantau yang punya PR dan 140 yang
  punya PO; akibatnya kolom AKSI didominasi "BUAT PR" (398) dan "Buat PO" = 0.
  Dashboard lama memakai ekspor HISTORI PENUH (2024 - 07 Okt 26) sehingga
  menghasilkan 25/51/45/28. Bila angka AKSI ingin setara dashboard, unggah
  ekspor rentang penuh, bukan cuplikan bulan berjalan. Sejak 09/10/2026 keadaan
  itu TERDETEKSI otomatis: cakupan tiap berkas terhadap daftar pantau dicatat di
  analisa import dan cakupan di bawah 50% memunculkan peringatan di halaman
  Sparepart + lencana `CAKUPAN SEMPIT` di riwayat import (lihat butir "Peringatan
  cakupan berkas sumber").
- **Import boleh SEBAGIAN** (pilihan user 09/10/2026): minimal satu berkas;
  bagian yang tidak diunggah memakai nilai terakhir (`SparepartImportService`
  menyalin `BLOK_STOK`/`BLOK_PR`/`BLOK_PO`/`BLOK_MRP` dari baris lama). Cap waktu per
  sumber (`stok_diupdate_at`, `pr_diupdate_at`, `po_diupdate_at`,
  `mrp_diupdate_at`) menunjukkan bagian mana yang terakhir dipasok.
- **Analisa tiap import** tersimpan di `sp_impor.analisa` dan tampil di
  `sparepart.riwayat`: rincian baris/lembar/judul kolom per berkas, material di
  luar daftar pantau (+contoh kode), keadaan baru/diubah/tidak berubah,
  sebaran status dan aksi, serta kode yang naik ke REORDER atau kembali aman.
- **Hak akses**: modul memakai pola `sparepart.*` di `config/akses.php`; unggah
  berkas dan pengelolaan daftar pantau adalah aksi TULIS sehingga hanya akun
  `admin` (mode hanya baca middleware) yang bisa menjalankannya.
- **Berkas export disamakan dengan dashboard lama (diputuskan user 09/10/2026,
  sudah ada kodenya; commit `6fb27fb` + `fdef226`; BELUM naik ke PROD)**: isi
  berkas EXPORT dibuat setara sheet "Data Lengkap" pada
  `Integrasi_PR_PO_SparePart.xlsx` TANPA mengubah database maupun halaman web.
  - **Satuan** HANYA diambil dari kolom `UN` laporan stok gudang (G001); kolom
    `sp_pantau.uom` TIDAK lagi dipakai sebagai cadangan
    (`SparepartExportTrait::nilaiBaris()`), jadi material di luar G001
    bersatuan KOSONG seperti sel legacy-nya. Bukti 09/10/2026: 449/449 cocok,
    0 beda, 39 sel kosong di kedua berkas (sebelumnya `30004876` berisi "Pcs"
    dari UOM daftar pantau).
  - **Stok Tersedia pada BERKAS ditulis `0`** bila material tidak punya baris
    G001 (`m_g_baris` = 0 dan nilainya NULL) mengikuti rumus dashboard lama.
    `sp_material.stok_tersedia` TETAP NULL dan halaman web tetap menampilkan
    tanda hubung (`font-medium text-slate-800">-</td>`), jadi berkas export
    dan tabel keadaan memang sengaja berbeda pada kolom itu (kolom I berkas =
    `0`, HTML = `-`, DB = NULL, ketiganya diperiksa test baru).
  - **Kolom tambahan** supaya setara legacy: `Requisitioner`, `Jml PO`,
    `Semua No PO`, `Purchaser PO Terakhir`, `Vendor PO Terakhir` (`HEADER_SP`
    bertambah dan `KOLOM_TEKS_SP` kini `[1, 12, 18, 19, 20, 28, 33]` karena
    `Semua No PO` juga nomor dokumen yang wajib teks). Sejak 19/10/2026 kolom
    MRP juga tersedia di akhir berkas: `Stock by SAP (MRP)`, `Safety Stk (MRP)`,
    `Stok Min (MRP)`, `Unit (MRP)`, `Tipe MRP`, `Min Monitoring vs MRP`, dan
    `MRP Diperbarui`. Kolom lama tidak digeser (Lokasi G001 tetap AN).
  - **Pembaca satuan ikut diperbaiki** (`SparepartReader::JUDUL_STOK_OPSIONAL
    = ['UN']`): kolom UN tidak wajib ada, tetapi wajib dipetakan bila ada.
    Sebelumnya `$kolom['UN'] ?? 0` membuat pembacaan jatuh ke kolom 0
    (`S. Loc`) sehingga satuan berisi kode LOKASI (tertangkap lewat test, bukan
    dari peninjauan kode).
  - Bukti paritas 09/10/2026 di DEV (berkas `/sparepart/export` yang baru
    diunduh dibandingkan sheet `Data Lengkap` legacy yang sudah diekstrak
    menjadi `%TEMP%\legacy-sparepart\Data-Lengkap.csv`; 449 baris vs 449 baris):
    `Stok Min` 449 cocok / 0 beda; `Stok Tersedia` 449 cocok / 0 beda;
    `Sisa G001` 410 cocok + 39 kosong di KEDUA berkas / 0 beda; `Satuan`
    410 cocok + 39 kosong di KEDUA berkas / 0 beda (sebelum perbaikan kolom UN
    ada sel bersatuan "Pcs" dari UOM daftar pantau). Skrip pembanding sementara
    (`tmp-banding-sparepart.php`) sudah dihapus lagi setelah dipakai.
  - Kolom tambahan yang bersandar pada PR/PO (Requisitioner, Jml PO, Purchaser
    PO Terakhir, Vendor PO Terakhir) MASIH berbeda dengan legacy karena berkas
    sumber yang diimport di DEV adalah CUPLIKAN Oktober 2026, bukan ekspor
    sejarah penuh seperti dashboard lama (lihat "CATATAN CAKUPAN BERKAS" modul
    ini). Jadi bedanya soal CAKUPAN BERKAS, bukan logika kolomnya.
- **Peringatan cakupan berkas sumber (09/10/2026, commit `ecedb50`, sudah ada
  kodenya; BELUM naik ke PROD)** — supaya angka kolom Aksi dari berkas cuplikan
  tidak dibaca sebagai keadaan sebenarnya.
  - Analisa tiap import mencatat cakupan tiap berkas sumber terhadap daftar
    pantau di `analisa.pantau.sumber.{stok,pr,po}` (`punya`, `tanpa`, `persen`);
    berkas yang TIDAK diunggah bernilai `null` dan TIDAK dinilai nol.
  - Cakupan di bawah `SparepartImportService::AMBANG_CAKUPAN_SEMPIT` (50%)
    menghasilkan catatan berawalan `PERINGATAN` yang menyebut berkas itu
    kemungkinan cuplikan sebagian bulan (bukan ekspor rentang penuh). Nama
    berkas pada kalimat itu diambil dari `LABEL_BERKAS`
    ("laporan stok gudang" / "EXPORT PR" / "EXPORT PO").
  - `SpImpor::peringatanCakupan()` menyaring catatan berawalan `PERINGATAN` dan
    dipakai DUA tempat: kotak peringatan di halaman Sparepart
    (`resources/views/sparepart/partials/_peringatan-cakupan.blade.php`,
    di-include dari `index.blade.php`) dan lencana `CAKUPAN SEMPIT` pada kolom
    Label di `resources/views/sparepart/riwayat.blade.php`. Keduanya hanya
    tampilan; datanya tetap dari analisa import.
  - Uji: `tests/Feature/SparepartCakupanImportTest.php` (6 test). Uji mutasi
    09/10/2026: mengubah awalan penyaring di `peringatanCakupan()` menjadi
    `PERINGATANX` membuat 2 test GAGAL (lencana riwayat + kotak halaman
    Sparepart), lalu dikembalikan dan hijau lagi (26 test sparepart, 174
    assertions).
- **Uji**: `tests/Feature/SparepartImportTest.php` (9 test) dan
  `tests/Feature/SparepartPantauHalamanTest.php` (10 test, termasuk penjaga
  paritas Satuan/Stok Tersedia berkas export); fixture bersama di
  `tests/Pest.php` (`spUjiBerkas()`, `spUjiXlsx()`, `spUjiFolder()`). Seluruh
  suite **527 test hijau (2.481 assertions)** dan `php artisan view:cache`
  sukses. Verifikasi nyata DEV: `/sparepart`, `/sparepart/impor`,
  `/sparepart/pantau`, `/sparepart/riwayat`, `/sparepart/export` semua 200;
  `/sparepart?status=reorder` memuat 50 baris (seluruhnya berlabel REORDER).

- **Keterangan kartu Aksi + penjelasan cuplikan berkas (diputuskan 12/10/2026,
  sudah ada kodenya; BELUM naik ke PROD; TIDAK ada migrasi baru)**: latar
  keluhan user "angka Belum PO = 51 pada dashboard lama tidak cocok".
  Hasil verifikasi 12/10/2026: LOGIKA modul BENAR — dengan berkas rentang penuh,
  uji import (DEV import id 12) menghasilkan 149 REORDER -> KEJAR 25,
  BUAT PO 51, CEK 45, BUAT PR 28, identik sheet "Daftar Aksi"; tetapi DATA AKTIF
  di DEV `oleochemical_dev` DAN PROD `oleochemical` sama-sama hanya berisi
  berkas cuplikan Oktober 2026 sehingga `pr_terbuka > 0` = 0 (dibuktikan skrip
  sementara `tmp-cek-sparepart.php`, sudah dihapus) dan kolom aksi berbunyi
  KEJAR 31 / BUAT PO 0 / CEK 20 / BUAT PR 398 dari 449 baris pantau (kedua
  database IDENTIK, termasuk `po_uniq > 0` = 140 dan `pr_no` terisi 26).
  Jebakan tafsir yang ditemukan: pada data aktif itu **51 JUSTRU = jumlah
  material yang SUDAH punya nomor PO** (`sp_material.po_no` terisi 51 baris) —
  KEBALIKAN arti "Belum PO" pada dashboard lama yang juga bernilai 51.
  - `SpMaterial::keteranganAksi()` (method statis BARU) memberi satu kalimat
    penjelas per nilai aksi; `SparepartController::kartuAksi()` meneruskannya
    sebagai kunci `keterangan`, dan kartu di
    `resources/views/sparepart/index.blade.php` menampilkannya di bawah angka.
    Keterangan AKSI_BUAT_PO SENGAJA memuat kata "Belum PO" supaya pengguna
    menghubungkannya dengan istilah dashboard lama.
  - `resources/views/sparepart/partials/_peringatan-cakupan.blade.php`
    menambah penjelasan: bila berkas PR/PO hanya cuplikan bulan berjalan,
    material ber-PR-terbuka JATUH ke "Buat PR" (bukan "Buat PO") sehingga angka
    Buat PO tampak nol walaupun ada PR yang menunggu dibuat PO.
  - Uji: `tests/Feature/SparepartLabelAksiTest.php` (4 test). Uji mutasi
    12/10/2026: mengosongkan `keterangan` pada controller DAN mengubah kalimat
    peringatan membuat 2 test GAGAL (2 failed, 2 passed); dikembalikan lalu
    hijau lagi. Seluruh suite **537 test hijau (2.546 assertions)** dan
    `php artisan view:cache` sukses (165 berkas view ter-cache).
  - Catatan deploy: PROD SUDAH di commit `171ddb4`, jadi peringatan cakupan +
    lencana CAKUPAN SEMPIT sudah terpasang (terbukti dari riwayat import PROD
    id 3 bertanda CAKUPAN SEMPIT); yang BELUM di PROD hanya perubahan
    keterangan kartu ini.
  - Berkas rentang penuh yang dipakai dashboard lama DITEMUKAN KEMBALI
    12/10/2026 di `D:\RYAN\Dasboard\EPE\2024 - 07 Okt 26\` (`EXPORT PR.XLSX`
    1,8 MB + `EXPORT PO.XLSX` 1,2 MB, bertanggal 07/10/2026; di folder yang sama
    ada `EXPORT MRP.XLSX` dan `01. LAPORAN STOK GUDANG September 2026.xlsx`),
    jadi catatan lama yang menyebut berkas itu "TIDAK ADA lagi" sudah tidak
    berlaku. Untuk mengembalikan angka 25/51/45/28, cukup unggah `EXPORT PR.XLSX`
    + `EXPORT PO.XLSX` itu lewat `/sparepart/impor` (laporan stok tidak perlu
    karena stok/REORDER sudah setara); `EXPORT.XLSX` di folder Downloads
    (12.782 baris, ME5A 02/09/2026) BUKAN berkas dashboard lama.
- **Kartu Aksi difokuskan ke 149 item REORDER (diputuskan user 12/10/2026,
  sudah ada kodenya; BELUM naik ke PROD; TIDAK ada migrasi baru)**: keluhan user
  "Buat PO dashboard lama 51, di modul 0". Sebelumnya
  `SparepartController::kartuAksi()` menghitung SELURUH baris pantau (449),
  sehingga angka kartu Aksi tidak sebanding dengan sheet "Daftar Aksi" dashboard
  lama yang hanya memuat 149 item REORDER (kejar 25 / buat PO 51 / cek 45 /
  buat PR 28). Sejak perubahan ini syarat `->where('reorder', true)` ditambahkan
  pada rekap aksi, jadi kartu Aksi = sebaran 149 item REORDER saja; kartu
  ringkasan keadaan ("material dipantau / REORDER / aman / belum") TETAP
  menghitung semua baris pantau.
  - `resources/views/sparepart/index.blade.php`: di atas grid kartu ditambah
    judul lingkup "Rincian tindakan <n> item REORDER (fokus tindakan)" +
    keterangan "Sama seperti sheet \"Daftar Aksi\" dashboard lama; material yang
    stoknya masih aman tidak dihitung", dan tautan tiap kartu kini memuat
    `status=reorder` (`['aksi' => ..., 'status' => 'reorder']`) supaya jumlah
    baris daftar yang terbuka SAMA dengan angka kartunya (dulu tautan hanya
    menyaring kolom aksi sehingga daftar yang terbuka bisa lebih panjang daripada
    angka kartu - salah satu sebab kartu dianggap "tidak cocok").
  - Bukti data nyata DEV 12/10/2026 (data aktif masih cuplikan Oktober): kartu
    Aksi SEBELUM = kejar 31 / buat PO 0 / cek 20 / buat PR 398 dari 449 baris;
    SESUDAH = kejar 23 / buat PO 0 / cek 1 / buat PR 125 dari 149 item REORDER;
    kartu ringkasan tetap total 449 / REORDER 149 / aman 300 (skrip sementara
    `tmp-oleo\cek-kartu-aksi.php`, sudah dihapus). Angka 23/0/1/125 adalah
    sebaran 149 REORDER pada DATA CUPLIKAN, jadi belum 25/51/45/28: karena
    paritas aturan aksi sudah terbukti 149/149 dengan berkas rentang penuh
    (butir sebelumnya), mengunggah `EXPORT PR.XLSX` + `EXPORT PO.XLSX` rentang
    penuh akan langsung menghasilkan 25/51/45/28 pada kartu ini.
  - Uji BARU `tests/Feature/SparepartKartuAksiReorderTest.php` (4 test) memakai
    helper `spAngkaKartuAksi()` (membaca angka kartu dari HTML) dan
    `spHalamanSparepart()` (import fixture + render halaman); yang diperiksa:
    angka kartu hanya dari item REORDER, kartu ringkasan tetap 5/3/2 pada
    fixture, tautan kartu memuat `status=reorder`, dan jumlah baris daftar saat
    kartu dibuka = angka kartunya (buat PR 1 baris, cek 0 baris). Uji mutasi
    12/10/2026: mencabut `->where('reorder', true)` membuat 2 test GAGAL
    (cek 1 bukan 0; buat PR 2 bukan 1), lalu dikembalikan dan hijau lagi.
    Seluruh suite **541 test hijau (2.567 assertions)** dan
    `php artisan view:cache` sukses.
- **Uji coba import LEWAT SISTEM + angka Buat PO 51 di DEV (12/10/2026, atas
  permintaan user "coba anda import dari system apakah bisa?")**: jalurnya ADA
  dan bekerja. Perintah (jalur service yang SAMA dengan halaman unggah
  `/sparepart/impor`, hanya berbeda pintu masuk):
  `php artisan sparepart:import --pr="D:\RYAN\Dasboard\EPE\2024 - 07 Okt 26\EXPORT PR.XLSX"
  --po="D:\RYAN\Dasboard\EPE\2024 - 07 Okt 26\EXPORT PO.XLSX"
  --label="Ekspor rentang penuh s.d. 07 Okt 2026 (uji coba agent di DEV)" --oleh=1`.
  Hasil di DEV `oleochemical_dev`:
  - **Impor #14 sukses**: PR 14.557 baris dibaca (889 diabaikan, 1.664 material
    di berkas; 1.312 di antaranya di luar daftar pantau), PO 14.835 baris (1.109
    diabaikan, 1.521 material; 1.209 di luar pantau), `--stok` TIDAK diunggah ->
    blok stok disalin dari baris lama. Keadaan: baru 0 / diperbarui 353 / tetap
    96; status stok tetap REORDER 149 + aman 300 (stok toh tidak berubah).
  - **Kartu Aksi `/sparepart` SESUDAH import = Kejar PO 25 / Buat PO 51 /
    Cek-Buat PR 45 / Buat PR 28** dari 149 item REORDER — PERSIS sebaran sheet
    "Daftar Aksi" `Integrasi_PR_PO_SparePart.xlsx` (25/51/45/28). Keluhan user
    "Buat PO 51 vs 0" tuntas di DEV: kartu kini berbunyi 51.
  - Cakupan berkas menjadi 78% (PR: 352/449) dan 69% (PO: 312/449) sehingga
    kotak PERINGATAN CAKUPAN SEMPIT hilang dari halaman Sparepart.
  - JEBAKAN yang wajib diingat: keluaran perintah `sparepart:import` mencetak
    sebaran aksi untuk SELURUH 449 baris pantau (BUAT PO 58 / BUAT PR 97 /
    CEK 261 / KEJAR 33), BUKAN 149 item REORDER seperti kartu halaman. Jangan
    membandingkan angka keluaran perintah itu langsung dengan Ringkasan dashboard
    lama; yang setara adalah kartu halaman. Penyempurnaan berikutnya (belum
    dikerjakan): perintah mencetak sebaran aksi dua kali (semua baris dan khusus
    item REORDER) supaya tidak salah baca.
  - Rollback: backup tabel `sp_material` + `sp_impor` DEV SEBELUM import ada di
    `C:\laragon\backup-oleochemical\dev\sp-dev-sebelum-import-rentang-penuh-20261012-103602.sql`
    (218 KB); memulihkan: `mysql -u root oleochemical_dev < berkas.sql`.
    Alternatif tanpa backup: unggah ulang `EXPORT Okt 2026.XLSX` +
    `EXPORT PO Okt 2026.XLSX` dari folder `07 Okt 26`. Keadaan DEV sesudah sesi
    ini = data rentang penuh (impor #14), jadi angka kartu DEV kini sama dengan
    dashboard lama. Skrip pemeriksa sementara `tmp-oleo\cek-kartu-aksi.php`
    sudah dihapus.
  - PROD BELUM disentuh: angkanya masih 31/0/20/398 sampai (a) perubahan kode
    ini di-deploy dan (b) dua berkas rentang penuh itu diunggah pada halaman
    `/sparepart/impor` PROD.

## SEMUA PR, MRP, DAN LABEL BARU — 19/10/2026

Latar: user melaporkan satu material punya lebih dari satu PR baru, tetapi
dashboard hanya menampilkan satu nomor. Pemeriksaan DEV `oleochemical_dev`
(sebelum perubahan): 183 material sudah punya lebih dari satu baris PR dan
`sp_material.pr_nums` sudah memuat semua nomornya. Jadi datanya sudah masuk;
yang kurang adalah TAMPILAN. Contoh nyata `30003838`: 3 baris PR, 2 PR terbuka
(`1000004103`, `1000004108`), tetapi kolom tabel hanya menampilkan `pr_no`
terpilih (`1000004108`).

Perubahan yang dikerjakan:

- **Semua nomor PR/PO terbuka disimpan dan ditampilkan.** Migrasi
  `2026_10_19_000001_add_mrp_and_open_numbers_to_sparepart` menambah
  `sp_material.pr_open_nums` dan `po_open_nums`. `pr_nums`/`po_nums` tetap
  memuat seluruh nomor; `*_open_nums` memuat yang masih terbuka. Kolom utama
  menampilkan seluruh nomor PR/PO (nomor terbuka ditandai terpisah), dan panel
  rincian memuat daftar lengkapnya.
- **Import MRP (SAP MD07).** Berkas `EXPORT MRP.XLSX` sekarang diterima lewat
  form (`mrp`) maupun perintah `sparepart:import --mrp=...`. Nilai yang diambil:
  `Plnt stock` -> `mrp_stock`, `Safety Stk` -> `mrp_safety`, `Reorder Pt` ->
  `mrp_reorder`, `Unit`, `MRP Type`, dan pembanding `mrp_cmp` (Sama/Beda).
  `sp_impor.jumlah_mrp` mencatat jumlah baris MRP. MRP TIDAK mengubah aturan
  REORDER; status tetap dari stok minimum daftar pantau vs stok tersedia G001.
- **Label filter/kartu.** `KEJAR KEDATANGAN (PO OPEN)` tetap nilai internal,
  label tampilan menjadi **Kejar Delivery**. `BUAT PO` tetap nilai internal,
  label tampilan menjadi **Kejar PO**. `Cek / Buat PR` dan `Buat PR` tidak
  berubah. Semua dropdown, kartu, riwayat, export, dan peringatan memakai label
  baru; URL lama dengan nilai internal tetap berfungsi.
- **Tabel dirapikan.** Satu baris per material dengan 11 kolom, header lengket,
  zebra/hover, badge status/aksi, kolom Purchaser/Vendor dan MRP, filter
  `punya=mrp`, serta panel rincian empat kartu (PR, PO, stok G001, MRP). Kartu
  tindakan tetap hanya menghitung 149 item REORDER agar sebanding dengan sheet
  "Daftar Aksi".

Bukti DEV 19/10/2026 (import #16 dari
`D:\RYAN\Dasboard\EPE\IMPORT`):
stok 3.328 baris/2.775 material, PR 14.557 baris/1.664 material, PO 14.835
baris/1.521 material, MRP 566 baris/566 material; 449 material pantau
diperbarui. Kartu `Kejar Delivery` 25 / `Kejar PO` 51 / `Cek-Buat PR` 45 /
`Buat PR` 28 — sama dengan dashboard lama 25/51/45/28. `30003838` sekarang
menampilkan `pr_open_nums=1000004103; 1000004108`. Halaman `/sparepart`,
`/sparepart/impor`, `/sparepart/riwayat`, dan `/sparepart?punya=mrp` semuanya
HTTP 200. Uji sparepart: 38 test hijau. Seluruh suite 669 test: 666 hijau,
3 gagal karena test man hour memakai `now()->subDay()` yang pada 19/10/2026
jatuh hari Minggu (18/10/2026) — bukan akibat perubahan ini.
