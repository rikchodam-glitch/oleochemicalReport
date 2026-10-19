# Keputusan Arsitektur 24-30

> Bagian dari dokumentasi proyek di folder `docs`.
> Peta dokumen: `AGENTS.md` di akar repo.

24. **Tata letak halaman DETAIL laporan disesuaikan (diputuskan 06/10/2026, sudah ada
    kodenya).** Semua perubahan ada di copy DEV
    `C:\laragon\www\oleochemicalReport`, berkas
    `resources/views/reports/show.blade.php` (1.258 baris setelah perubahan).
    - **Temuan dipindah KE ATAS Root Cause** (include
      `reports.partials._temuan-kartu` di baris 372, di atas kartu Root Cause),
      mengikuti urutan wizard Step 5a temuan lalu 5b root cause (lihat item 14).
      Jangan dikembalikan ke bawah tanpa instruksi user.
    - **Analisa AI dan Timeline TIDAK lagi tampil langsung di halaman.** Kedua kartu
      lama (yang dulu berada di ujung kolom kiri) kini ada di dalam popup: satu kartu
      baru "Analisa AI dan Timeline" berisi dua tombol
      (`@click="bukaPopup('analisa')"` dan `@click="bukaPopup('timeline')"`), dan dua
      blok `<template x-teleport="body">` memuat kartu lamanya. Popup ditutup lewat
      tombol "Tutup", klik luar (`@click.self`), atau tombol Escape
      (`@keydown.escape.window`); overlaynya `z-50` (sidebar layout `z-40`) sehingga
      tidak tertutup sidebar. State Alpine `popup` (`null` | `'analisa'` |
      `'timeline'`) beserta `bukaPopup()` dan `tutupPopup()` ada di `reportDetail()`.
      Isi popup Analisa AI tetap memakai
      `@if($report->is_manually_edited) ... @elseif($report->ai_analyzed)`, ditambah
      pemberitahuan "Belum ada analisa AI untuk laporan ini." bila keduanya tidak
      berlaku (sebelumnya bagian itu kosong).
    - **Kartu Aksi Admin tidak lagi punya tombol pintas status**: tombol "Tandai
      Selesai" dan "Tandai Perlu Review" DIHAPUS 06/10/2026; status laporan diubah
      lewat pilihan "Status Pekerjaan" pada mode edit (`partials/_pelengkap`). Route
      `reports.update-status` dan test `tests/Feature/ReportDraftRemovalTest.php`
      tetap ada; test itu kini memastikan KEDUA label tombol TIDAK muncul lagi dan
      `Status Pekerjaan` tetap ada sebagai penggantinya.
    - Cara menyunting berkas 1.181 baris ini TANPA menulis ulang seluruhnya: semua
      perubahan dikerjakan sebagai SISIPAN (`insert_line`) dari BAWAH ke ATAS (baris
      775, lalu 733, lalu 678, terakhir 372) supaya nomor baris di atasnya tidak
      bergeser, ditambah dua `find_and_replace` pada string unik satu/beberapa baris
      (include `_temuan-kartu` lama, dan blok 3 baris `enableEditMode()`). Kartu lama
      TIDAK dihapus: pembungkus popup disisipkan mengelilinginya. Sisa alami: satu
      include diganti komentar penanda dan ada dua baris kosong ganda (tidak
      berpengaruh ke tampilan).
    - Uji: `tests/Feature/ReportDetailTampilanTest.php` (4 test: urutan Temuan -> Root
      Cause lewat `assertSeeInOrder`, tombol popup + `x-show="popup === ..."` + tepat
      3 `x-teleport="body"` sejak galeri foto ditambahkan 08/10/2026 (dulu 2),
      pemberitahuan "belum ada analisa AI", dan kartu Aksi
      Admin tanpa tombol status). Uji mutasi 06/10/2026: mematikan include temuan dan
      mengubah nama popup analisa membuat 2 test GAGAL. Seluruh suite **438 test hijau
      (1932 assertions)**, `php artisan view:cache` sukses, dan blok `<script>` hasil
      ekstraksi lolos `node --check`.

25. **Mode edit halaman detail laporan dimaksimalkan (diputuskan 08/10/2026 atas
    permintaan user, sudah ada kodenya).** Semua perubahan ada di copy DEV
    `C:\laragon\www\oleochemicalReport`; PERUBAHAN INI BELUM NAIK KE PROD (menunggu
    `Deploy-Laporan.bat`). Lima perbaikan sekaligus, semuanya menyentuh halaman detail
    agar pengeditan tidak lagi "kurang rapi":
    - **(1) Edit alat/lokasi memakai hierarki PT -> Departemen -> Area -> Section ->
      Alat**, bukan lagi tiga dropdown datar (Area -> FuncLoc -> Kode Alat) yang
      memaksa admin tahu Area lebih dulu. Endpoint BARU
      `GET /reports/locations/hirarki` (`reports.locations.hirarki`,
      `ReportLocationTrait::anakLokasi()`) mengembalikan SATU paket: `induk` (ringkasan
      node induk + `area_id` dari `AssetLocationService::areaId()`), `nodes` (anak
      langsung yang `active()` saja), `assets` (alat pada node itu, DIBATASI 50 baris
      `BATAS_CARI_LOKASI`), dan `jumlah_alat` (jumlah SELURUH alat node itu). Tanpa
      `parent_id` berarti daftar PT (level 0); alat bisa disaring dengan `q`.
      **Bukti data nyata DEV 08/10/2026:** section `EPE-PROD-TF01-6010` (id 93) memuat
      **426 alat**; sebelum dibatasi endpoint mengirim seluruh 426 baris (payload
      >40 KB), sesudah dibatasi mengirim 50 baris + `jumlah_alat=426`.
    - **(2) Widget pemilih lokasi dipakai BERSAMA** supaya logika hierarki tidak
      ditulis dua kali: `reports\partials\_pilih-lokasi-hirarki-badan.blade.php`
      (markup; elemennya ditandai `data-peran`: status/pt/departemen/area/section/
      cari/alat/pesan) + `reports\partials\_pilih-lokasi-hirarki-script.blade.php`
      (definisi TUNGGAL `window.pilihLokasiHirarki`, dibungkus `@once` +
      `@push('scripts')` supaya tidak berulang walau partial-nya di-include dua kali).
      Pembungkusnya cuma dua: `_pilih-lokasi-hirarki.blade.php` (`data-mode="laporan"`,
      mengisi Area + FuncLoc + Kode Alat laporan lewat komponen Alpine `reportDetail()`
      yang kini punya method `terapkanLokasi()`) dan panel alat tambahan
      (`data-mode="item"`, mem-POST ke `reports.multi-items.store`). Pemilih menyimpan
      `data-jalur` (JSON id root -> node terpilih dari
      `FunctionalLocation::getAncestors()`) supaya pilihan lama terbuka kembali saat
      halaman dimuat. Kotak "Cari Alat (semua area)" pada `_cari-alat` DIPERTAHANKAN
      sebagai jalan pintas.
    - **(3) Alat/lokasi tambahan dikelompokkan jadi SATU alur**:
      `_alat-tambahan.blade.php` ditulis ulang. Dua jalur lama (kotak cari alat +
      dropdown FuncLoc per Area dengan tombol "Tambah Lokasi") DIHAPUS, diganti satu
      panel hierarki (mode item) + kotak pencarian cepat. Daftar item DIKELOMPOKKAN per
      lokasi (Section) dengan lencana ALAT/LOKASI, dan
      `ReportMultiItemController::daftarItem()` kini ikut mengirim `grup` (kode
      Section: `functionalLocation?->code ?? asset?->functional_loc`) dan `jenis`
      (`alat`/`lokasi`) supaya tampilan Blade dan daftar hasil fetch memakai
      pengelompokan yang sama. `window.gambarDaftarAlatTambahan` dipakai pemilih
      hierarki mode item untuk memperbarui daftar tanpa memuat ulang halaman.
    - **(4) Popup galeri foto**: partial BARU `_galeri-foto.blade.php` (di-include
      SEKALI dari `show.blade.php`, di dalam komponen Alpine `reportDetail()`, dan
      menerima `$bolehHapusFoto`). Isinya foto besar + tombol sebelumnya/berikutnya +
      panah kiri-kanan keyboard (`@keydown.arrow-left/right.window`) + geser/swipe
      (`@touchstart`/`@touchend`, ambang 40 piksel) + penghitung posisi + strip
      thumbnail + tombol pindah galeri Dokumentasi/Hygiene + tombol Hapus (HANYA
      laporan induk). State & method-nya ada di `reportDetail()`
      (`galeri: {buka, jenis, indeks, geserDari}`, `galeriBuka()`, `galeriTutup()`,
      `galeriJenisBuka()`, `galeriFoto()`, `galeriPosisi()`, `galeriSebelum()`,
      `galeriBerikut()`, `galeriKe()`, `galeriGeserMulai()`, `galeriGeserSelesai()`,
      `galeriHapus()`). Thumbnail grid memakai
      `@click.prevent="galeriBuka('...', idx)"` (preventDefault pada <img> sekaligus
      membatalkan navigasi <a> induknya) dan tiap kartu foto dapat tombol "Lihat semua".
    - **(5) Kolom kanan memuat kartu "Teknisi Pelaksana"**: service BARU
      `app\Services\TechnicianKinerjaService.php` (`ringkasUntuk(array $idTeknisi)`,
      agregasi SQL sekali untuk semua teknisi: hitungan per status, deret 12 bulan,
      komposisi jenis pekerjaan) + partial BARU `_kartu-teknisi.blade.php` (komponen
      Alpine `kartuKinerja`, Chart.js 4.4.0 dari CDN seperti halaman Teknisi). Kartu
      menampilkan avatar inisial, nama, NIK, Bagian/Group, status, total/selesai/belum
      selesai/bulan ini, grafik batang 12 bulan, dan komposisi jenis pekerjaan. **Bila
      laporan berkolaborasi, seluruh teknisi muncul sebagai chip yang bisa diklik**
      (ganti profil + grafik tanpa memuat ulang) dan ada tautan ke halaman detail
      teknisi. Data disiapkan `ReportController::siapkanKartuTeknisi()` (pelaksana
      induk -> pelaksana baris -> seluruh kolaborator; kartu disembunyikan bila tidak
      ada data teknisi). ATURAN HITUNGAN: baris salinan multi alat (`multi_of`) TIDAK
      dihitung, baris salinan kolaborasi DIHITUNG untuk kolaboratornya — sama seperti
      daftar laporan dan man hour. Palet warna jenis pekerjaan kini SATU SUMBER:
      `TechnicianKinerjaService::WARNA_JENIS_PEKERJAAN`, dan
      `ResolvesReportAnalytics::$warnaJenisPekerjaan` merujuk konstanta itu (nilainya
      tidak berubah, hanya dipusatkan).
    - **(Jam & durasi konsisten)**: `_waktu-kerja.blade.php` ditulis ulang: JAM MULAI +
      JAM SELESAI (tetap satu-satunya yang bisa diedit) + kolom **Durasi Man Hour
      read-only** yang berubah langsung saat jam diubah (`labelDurasi()`), memakai
      aturan SERVER yang sama: jam selesai < jam mulai = melewati tengah malam
      (+24 jam, lihat `Report::durasiDariJam()`), dan format label sama dengan accessor
      `Report::duration_label` ("X Jam Y Menit"). Form edit TETAP tidak mengirim kolom
      durasi (keputusan 05/10/2026 tidak diubah). Mode lihat kini juga menampilkan
      rentang jam (`Report::time_range`) di samping durasi, dan angkanya memakai
      accessor yang sama (dulu hitungan Blade sendiri).
    - Berkas yang disentuh: `routes/web.php` (1 rute baru),
      `app\Http\Controllers\Traits\ReportLocationTrait.php`,
      `app\Http\Controllers\ReportController.php` (data kartu teknisi),
      `app\Http\Controllers\ReportMultiItemController.php` (grup + jenis),
      `app\Http\Controllers\Concerns\ResolvesReportAnalytics.php` (palet dipusatkan),
      `app\Services\TechnicianKinerjaService.php` (baru),
      `resources\views\reports\show.blade.php` (penyisipan include + method galeri;
      TIDAK ditulis ulang — pola `insert_line` dari bawah ke atas seperti item 24),
      5 partial laporan (`_waktu-kerja` dan `_alat-tambahan` ditulis ulang;
      `_pilih-lokasi-hirarki*` dari 1 menjadi 3 berkas), 4 test fitur baru, dan 2 test
      lama disesuaikan (`ReportDetailTampilanTest` jumlah `x-teleport` 2 -> 3;
      `ReportEditAlatTambahanTest` penanda `item_tambah_lokasi` -> `data-pilih-hirarki`).
    - Uji: `ReportEditLokasiHirarkiTest` (7), `ReportGaleriFotoTest` (4),
      `ReportKartuTeknisiTest` (5), `ReportDurasiKonsistenTest` (5). **Uji mutasi
      08/10/2026 (nyata, dijalankan satu per satu lalu dikembalikan):**
      (a) mengubah `whereNull('parent_id')` menjadi `whereNotNull('parent_id')` pada
      daftar PT membuat 1 test GAGAL; (b) mengubah `if (lewatTengahMalam)` menjadi
      `if (false)` membuat 1 test GAGAL; (c) memutus penghitung posisi galeri
      (`x-text="galeriPosisi()"` diganti `galeriTutup()`) membuat 1 test GAGAL; (d)
      melepas `whereNull('multi_of')` pada service kinerja membuat 1 test GAGAL
      (total terbaca 2 padahal seharusnya 1). Pelajaran dari (c): penjaga markup WAJIB
      memakai bentuk MARKUP (`x-text="..."`, `@click="..."`), bukan nama fungsi, karena
      nama fungsi juga ada di blok `<script>` sehingga mutasi markup bisa lolos.
      Seluruh suite **470 test hijau (2088 assertions)**, `php artisan view:cache`
      sukses, semua blok `<script>` baru lolos `node --check` (echo Blade diganti `0`
      lebih dulu), dan halaman diperiksa lewat HTTP nyata di DEV (`/debug-login` +
      `curl` atas `/reports/775` dan `/reports/locations/hirarki?parent_id=93`).
    - CATATAN: klaim "selesai" di sini TIDAK mencakup uji klik di peramban; yang sudah
      dibuktikan adalah markup/endpoint/logika server + test otomatis + respons HTTP
      nyata. Karena `.bat`/pintasan tidak berubah, deploy cukup lewat `Deploy-Laporan.bat`
      seperti biasa (tidak ada migrasi baru).

26. **Mode edit halaman detail dirapikan lagi: satu input lokasi, daftar alat
    dikelompokkan per tipe + digulir, panel alat tambahan pindah ke bawah
    lokasi utama (diputuskan 08/10/2026 atas permintaan user, sudah ada kodenya).**
    Semua perubahan ada di copy DEV `C:\laragon\www\oleochemicalReport`; BELUM naik
    ke PROD (tunggu `Deploy-Laporan.bat`; TIDAK ada migrasi baru).
    - Keluhan asal user (screenshot halaman detail mode edit): (a) "kenapa inputan
      lokasinya ada dua?", (b) daftar equipment terlalu panjang bila satu area
      memuat banyak alat, (c) minta pengelompokan alat per tipe termasuk yang tanpa
      tipe dengan mode collapse, (d) panel "Lokasi & Alat Tambahan" diminta berada
      di bawah alat/lokasi utama.
    - **(a) Satu input lokasi.** Penyebab duanya: kolom lama Area / Functional
      Location / Kode Alat (tiga dropdown datar) MASIH dirender di samping pemilih
      hierarki baru, padahal keduanya mengisi field yang sama. Sekarang pembungkus
      tiga dropdown itu diberi kelas `hidden`
      (`<div class="hidden grid grid-cols-1 sm:grid-cols-3 gap-3">`) sehingga hanya
      TERSEMBUNYI (tetap dirender, tetap dikirim form lewat
      `form="form-edit-laporan"`, dan tetap jadi sasaran `x-model` + `isiManual()`
      pemilih hierarki). Penggantinya satu kartu ringkasan "Lokasi & Alat Terpilih"
      dengan `x-text="namaArea(areaId)"` / `namaFuncLoc(funcLocId)` /
      `namaAlat(assetId)`. Pesan galat `@error('area_id' | 'funcloc_id' |
      'asset_id')` DIPINDAH ke kartu ringkasan (yang lama di dalam kolom tersembunyi
      dibuang supaya tidak ganda), dan catatan lama "Pilih Area terlebih dahulu..."
      diganti petunjuk memakai hierarki/kotak pencarian. Method Alpine baru:
      `cariLabelLokasi()`, `namaArea()`, `namaFuncLoc()`, `namaAlat()`; data baru:
      `areaDaftar`, `labelLokasiAwal`, `equipmentTagAwal`.
      **JEBAKAN BLADE yang sudah kena sekali:** `@json([...])` TIDAK boleh diberi
      array bersarang/berkoma karena `JsonDirective` memecah argumen pada KOMA
      PERTAMA (sisanya dianggap opsi) sehingga muncul
      `syntax error, unexpected token ";" expecting "]"` dan SELURUH suite merah.
      Karena itu `$areaDaftar` dan `$labelLokasiAwal` disiapkan di blok `@php`
      sebelum `@push('scripts')`, lalu hanya `@json($variabel)` yang ditulis.
    - **(b) Daftar alat digulir:** `gambarAlat()`
      (`_pilih-lokasi-hirarki-script`) menggambar
      `gulir.className = 'max-h-64 overflow-y-auto divide-y divide-slate-100'`.
      Kepala daftar (nama lokasi + tombol "Pakai lokasi ini") dan baris jumlah
      ("Menampilkan 50 dari 426 alat...") tetap DI LUAR area gulir.
    - **(c) Pengelompokan per Equipment Type + collapse:** endpoint
      `reports.locations.hirarki` (`ReportLocationTrait::anakLokasi()`) kini ikut
      mengirim `equipment_type`, dan di layar baris alat dimasukkan ke kotak
      `kotakTipe[tipeAlat(item)]` (bukan lagi datar ke `<div data-peran="alat">`).
      Kelompok = Equipment Type; yang kosong masuk kelompok `TANPA TIPE` (mengikuti
      penamaan `WizardFuncLocPickerTrait::TANPA_TIPE_LABEL`, dan TANPA TIPE selalu
      diletakkan paling akhir). Judul kelompok = tombol `aria-expanded` yang
      membuka/menutup isi (`class="hidden"`), dan SEMUA kelompok tertutup saat
      daftar pertama kali digambar.
    - **(d) Posisi panel alat/lokasi tambahan:** include `_alat-tambahan` DIPINDAH
      dari kartu sendiri (dulu di bawah, setelah Kolaborasi Teknisi) ke dalam kartu
      "Deskripsi Pekerjaan" pada blok mode edit, tepat setelah include
      `_pilih-lokasi-hirarki`, sehingga pembatas `border-t` milik partial itu
      sekarang berfungsi sebagai pemisah sub-bagian.
    - Uji: `ReportEditLokasiHirarkiTest` bertambah 3 test (payload
      `equipment_type`, ringkasan satu lokasi + kolom lama tersembunyi, dan
      pengelompokan/collapse/gulir) serta `ReportEditAlatTambahanTest` bertambah 1
      test urutan (`assertSeeInOrder(['Telusuri Hierarki Lokasi', 'Alat / Lokasi
      Tambahan'])` dan `['Alat / Lokasi Tambahan', 'Root Cause</h3>']`). Seluruh
      suite **474 test hijau (2108 assertions)**, `php artisan view:cache` sukses,
      dan blok `<script>` widget hierarki lolos `node --check`.
    - Bukti render NYATA (Chrome headless `--headless=new --dump-dom` atas
      `http://oleochemicalReport.test/reports/775` sesudah `/debug-login`): ringkasan
      berbunyi "TF01 - TF01", "EPE-PROD-TF01-6010 - Storage & Pump Area TF", dan
      "P101 - Pompa Input Feed RBDPO T101/T102"; daftar alat memuat 3 kelompok
      (`AC` 7 alat, `BREATHER VALVE` 30 alat, `TANPA TIPE` 13 alat) yang semuanya
      `aria-expanded="false"` dengan isi `class="hidden"`, di dalam wadah
      `max-h-64 overflow-y-auto`, disertai catatan "Menampilkan 50 dari 426 alat";
      urutan markup Telusuri Hierarki Lokasi (61181) < Alat / Lokasi Tambahan
      (63615) < Root Cause (68767); tag `<div>` buka/tutup seimbang 178/178.
    - CATATAN: klik judul kelompok untuk membuka daftarnya BELUM diuji lewat klik
      peramban nyata; yang dibuktikan adalah status DOM awal (tertutup) dan
      penjaga kode `classList.toggle` + `aria-expanded`.

27. **Daftar laporan: kode laporan diganti Temuan + Penyebab, baris tabel
    ditandai `data-laporan-id` (diputuskan 08/10/2026, sudah ada kodenya).**
    Semua perubahan ada di copy DEV `C:\laragon\www\oleochemicalReport`; BELUM
    naik ke PROD (tunggu `Deploy-Laporan.bat`; TIDAK ada migrasi baru). Commit
    `194d5dc` (sudah di `hub/main`).
    - `resources/views/reports/index.blade.php` kolom Pekerjaan TIDAK lagi
      menampilkan `report_code` (pemisah `|` yang dulu mengiringinya ikut
      hilang). Yang tampil di bawah deskripsi pekerjaan sekarang: lencana
      `LANJUTAN` (bila `continued_from_id` terisi), lalu
      `Temuan: <finding>` dan `Penyebab: <root_cause>`, masing-masing dipotong
      80 karakter (`Str::limit`), HANYA bila kolomnya terisi. Kode laporan tetap
      tersimpan di kolom `report_code` dan tetap dipakai untuk tautan/arsip serta
      kotak filter "Cari kode laporan" — yang dihapus hanya TAMPILANNYA di tabel.
    - Karena kode laporan tidak lagi tampil, baris tabel diberi penanda
      `data-laporan-id="{{ $report->id }}"` pada tag `<tr>`. SELURUH test yang
      dulu mencocokkan kode laporan kini memakai penanda itu
      (`LocationDetailPageTest`, `ReportContinueStatusTest`,
      `ReportIndexViewTest`, `ReportMultiEquipmentTest`,
      `ReportProjectWorkTypeTest`, `ReportTeknisiKolaborasiTest`). Untuk test
      berikutnya: JANGAN kembali memakai teks kode laporan sebagai penanda baris
      di halaman daftar laporan.
    - Penjaga baru: `tests/Feature/ReportIndexTemuanTest.php` (6 test) memastikan
      urutan deskripsi -> Temuan -> Penyebab, laporan tanpa temuan/penyebab tidak
      menampilkan labelnya, pemotongan 80 karakter, lencana LANJUTAN tetap ada,
      dan baris membawa `data-laporan-id` tanpa kode laporan. Uji mutasi
      08/10/2026: melepas penanda `data-laporan-id` pada `<tr>` membuat 6 test
      GAGAL (di `ReportIndexTemuanTest` + `ReportTeknisiKolaborasiTest` +
      `ReportMultiEquipmentTest`).
    - Seluruh suite **480 test hijau (2138 assertions)** dan
      `php artisan view:cache` sukses (08/10/2026).

28. **Export laporan profesional: popup rentang tanggal + jenis pekerjaan, Excel
    tiga lembar, dan CSV berblok judul (diputuskan 08/10/2026 atas permintaan
    user, sudah ada kodenya).** Semua perubahan ada di copy DEV
    `C:\laragon\www\oleochemicalReport`; BELUM naik ke PROD (tunggu
    `Deploy-Laporan.bat`; TIDAK ada migrasi baru).
    - **Aturan baris (KEPUTUSAN USER, jangan diubah tanpa instruksi):** satu
      laporan = satu baris. Salinan kolaborasi TIDAK dibuatkan baris sendiri,
      nama kolaborator cukup dicantumkan pada kolom `Kolaborator`; salinan multi
      alat juga tidak menjadi baris (`multi_of`). Sama dengan tabel halaman
      Laporan (lihat keputusan 17).
    - **Trait BARU** `app\Http\Controllers\Traits\ReportExportTrait.php`
      (dipakai `ReportController`, bersama `ReportLocationTrait`). Isinya:
      `exportExcel()` (3 lembar), `exportCsv()`, `ambilLaporanExport()`,
      `barisExportLaporan()`, `lokasiExportLaporan()`, `kolomExportLaporan()`,
      `keteranganKolomExport()`, helper label/jam/durasi, dan penulis lembar
      `tulisSheetRingkasan()` + `tulisSheetPetunjuk()`.
      Dipakai bersama karena trait dikompilasi ke dalam kelas, jadi ia boleh
      memanggil method privat `ReportController` (`buildReportQuery`,
      `resolveGrub`, `resolveTanggal`) — pola yang sama dengan
      `AssetExportTrait` di `AssetController`.
    - **Tata letak Excel:** blok judul A1 (teal `FF0E9E8E`) + baris 2-6
      keterangan (sistem, `Periode:`, `Diekspor:`, `Filter:`, `Jumlah:`),
      header tabel di **baris 7**, 35 kolom A..AI, border, zebra, autofilter,
      freeze pane, dan pengaturan cetak landscape (baris 1-7 diulang).
      Nilai ditulis `setCellValueExplicit` sebagai TEKS (kode alat/nomor
      equipment tidak boleh berubah). **Jangan memakai `chr(64 + n)` untuk
      kolom** seperti export asset (15 kolom): di sini kolom melewati Z sehingga
      WAJIB `Coordinate::stringFromColumnIndex()`.
    - **Kolom lokasi/alat dibuat eksplisit** (permintaan user "lokasi serta alat
      yang jelas"): PT, Departemen, Area, Sub Area, Section (Lokasi), Kode Alat,
      Deskripsi Alat, Equipment No, ditambah kolom `Alat / Lokasi Tambahan`.
      Sumbernya berurutan `report->functionalLocation` -> `report->asset` ->
      `report->area` (lewat `Report::area->department->company` bila perlu),
      semua di-eager-load di `ambilLaporanExport()` supaya tidak N+1.
    - **Lembar pendukung:** `Ringkasan` (rekap jumlah + durasi + porsi per Jenis
      Pekerjaan, Status, PT, dan Teknisi, masing-masing dengan baris TOTAL) dan
      `Petunjuk` (aturan baris data + tabel arti 35 kolom). Dokumentasi inilah
      bagian "penataan dokumentasi" yang diminta user.
    - **Popup export** dipisah sebagai partial supaya `reports/index.blade.php`
      (855 baris setelah perubahan) tidak perlu ditulis ulang:
      `resources/views/reports/partials/_popup-export.blade.php` (markup,
      `@push('modals')`) + `_popup-export-script.blade.php` (JavaScript biasa,
      `@push('scripts')`, mengikuti popup kolaborasi halaman itu — BUKAN
      Alpine). Isinya: format berkas (Excel/CSV), rentang tanggal + tombol cepat
      (hari ini, 7 hari, bulan ini, bulan lalu, seluruh data), checkbox
      `tanpa_batas`, checklist `jenis_pekerjaan_multi[]` (boleh banyak), lalu
      tampilan/grub, status, tipe, klasifikasi ZPM, area, dan teknisi yang
      diisi awal dari filter halaman. Tombol "Export Laporan" ditambahkan lewat
      `insert_line` di `header-actions` (tautan "Export CSV" lama TETAP ada).
    - **Kunci request khusus export** (halaman Laporan tidak pernah
      mengirimnya, jadi perilaku halaman utuh): `jenis_pekerjaan_multi[]`
      (banyak jenis; diterapkan `whereIn` di `ambilLaporanExport()`) dan
      `tanpa_batas=1` (mengabaikan batas tanggal). Yang terakhir dibaca di
      `ReportController::buildReportQuery()` — jangan hapus pemeriksaan itu,
      kalau tidak centang "Seluruh data" tetap tersaring satu hari.
    - **Rute:** `GET /reports/export/excel` -> `reports.export-excel` (BARU) dan
      `GET /reports/export/csv` -> `reports.export-csv` (tetap ada; format CSV
      sekarang berblok judul + BOM dan memuat kolom `Kolaborator` seperti
      sebelumnya supaya `ReportIndexViewTest` tetap hijau). Dua rute statis itu
      WAJIB di atas wildcard `{report}` milik `Route::resource`.
      `ReportController::exportCsv()` yang lama (15 kolom polos) sudah DIHAPUS
      beserta import `Illuminate\Support\Facades\Response` yang jadi tak
      terpakai — jangan dihidupkan lagi.
    - Uji: `tests/Feature/ReportExportTest.php` (9 test, 116 assertions):
      popup di halaman, blok judul + header baris 7 + 35 kolom, isi kolom lokasi
      alat/PT/section, kolaborasi tidak jadi baris (nama tetap di kolomnya),
      filter rentang tanggal, `jenis_pekerjaan_multi` (satu & dua nilai),
      `tanpa_batas`, CSV (BOM + blok judul), lembar Ringkasan/Petunjuk, dan
      export mengikuti filter `search`/`area_id`.
    - Jebakan yang sudah kena sekali: `Font::setColor()` butuh argumen —
      rantai gaya WAJIB `->getFont()->setBold(true)->getColor()->setARGB(...)`
      (sempat tertulis `setColor()` sehingga seluruh export Excel berbalas 500
      dan gejalanya hanya "Expected response status code [200] but received
      500").
    - Verifikasi 08/10/2026: seluruh suite **489 test hijau (2254 assertions)**,
      `php artisan view:cache` sukses, dan blok `<script>` partial export lolos
      `node --check` (echo blade diganti `0` lebih dulu).
    - **FOTO IKUT TEREXPORT (permintaan user 08/10/2026, sudah ada kodenya).**
      Dua kolom BARU selalu ikut: `Foto Dokumentasi (tautan)` dan
      `Foto Hygiene (tautan)` (berisi URL PUBLIK absolut, satu URL per baris
      sel; lebih dari 5 tautan diringkas "+N foto lainnya"), sehingga kolom
      jumlah foto lama ("Foto Dokumentasi"/"Foto Hygiene") tetap ada.
      Centang BARU `sertakan_foto` pada popup menambah dua kolom gambar
      (`Foto Dokumentasi (gambar)`, `Foto Hygiene (gambar)`) yang isinya
      GAMBAR DISEMATKAN (foto PERTAMA tiap jenis per laporan, tinggi 56 px,
      tinggi baris 46 pt) lewat `Drawing` PhpSpreadsheet; batas
      `BATAS_GAMBAR_EXPORT = 300` gambar per berkas, selebihnya diberi tulisan
      "lihat kolom tautan (batas gambar tercapai)". Foto yang berkasnya TIDAK
      ADA di server ini (DEV memang tidak memuat foto asli) hanya diberi
      catatan "N foto, berkasnya tidak ada di server ini" - JANGAN menggantinya
      dengan sel kosong, kalau tidak data tampak hilang. Blok judul tetap 6
      baris dan header kolom tetap di BARIS 7; dua kolom gambar ditambahkan
      SETELAH kolom terakhir supaya huruf kolom lama A..AI tidak bergeser
      (35 -> 37 kolom, atau 39 bila gambar disertakan). CSV hanya memuat kolom
      tautan (tidak bisa menyematkan gambar); kotak centangnya dimatikan
      otomatis oleh skrip popup saat format CSV dipilih. Penjaga penting:
      `berkasLokalExport()` memeriksa `exists()` + `getimagesize()` di dalam
      `try/catch` supaya disk awan, berkas rusak, atau berkas hilang TIDAK
      pernah menggagalkan seluruh export.
    - **TAMPILAN "KEDUANYA" (Reguler + Shift) pada popup export (permintaan
      user 08/10/2026).** Popup mengirim `tampilan=semua`; server
      menerjemahkannya lewat `ReportExportTrait::tampilanExport()`, dan
      `ReportController::applyGroupFilter()` LANGSUNG `return` untuk nilai itu
      (tanpa penyaringan grup sama sekali) serta `grub` DIABAIKAN. Label filter
      pada berkas berbunyi "Tampilan: Reguler + Shift (keduanya)". Halaman
      Laporan TIDAK terpengaruh karena `ReportController::index()` selalu
      memaksa 'reguler'/'shift'.
    - Uji tambahan 08/10/2026: `tests/Feature/ReportExportTest.php` menjadi
      **13 test (156 assertions)** dengan 4 test baru (sematan gambar; foto
      yang berkasnya tidak ada; CSV tetap hanya tautan; tampilan "keduanya"
      memuat reguler DAN shift sekaligus walau `grub` dikirim). Uji mutasi
      nyata: (a) `setWorksheet()` dimatikan membuat test sematan gambar GAGAL;
      (b) penjaga `tampilan === 'semua'` di `applyGroupFilter()` dimatikan
      membuat test "keduanya" GAGAL; (c) dua kolom tautan foto dihilangkan
      membuat 3 test GAGAL. Bukti nyata DEV lewat HTTP (foto contoh dipasang
      sementara ke `storage/app/public/telegram-photos`, lalu dihapus lagi):
      `?date_from=2026-09-26&date_to=2026-09-27&sertakan_foto=1` -> 200, 1
      gambar tertanam di `xl/media/`, catatan "berkasnya tidak ada di server
      ini" muncul untuk laporan yang berkasnya tidak ada; berkas yang sama
      dengan `tampilan=semua` -> 3 gambar tertanam (2 dok + 1 hygiene), baris
      data 24 -> 29, label "Reguler + Shift (keduanya)" terbaca, dan kolom
      Bagian/Group memuat "Reguler" DAN "Grub". Seluruh suite **493 test hijau
      (2294 assertions)**.

29. **Jenis pekerjaan UMUM pada wizard Telegram + pilihan IT/UMUM di form web
    (diputuskan 08/10/2026 atas permintaan user, sudah ada kodenya).** Semua
    perubahan ada di copy DEV `C:\laragon\www\oleochemicalReport`; commit
    `bc46384` (sudah di `hub/main`).
    - **Telegram:** keyboard klasifikasi wizard (`buildWorkClassificationPrompt()`)
      kini punya tombol **`UMUM`** TEPAT SETELAH `ZPM 8 - Project`. Tombol itu
      SENGAJA tidak memakai kode ZPM: `handleWorkClassificationCallback()`
      menangani nilai `umum` lebih dulu, mengisi `jenis_pekerjaan = 'UMUM'`
      sambil membiarkan `work_classification` KOSONG, lalu langsung lanjut ke
      pencarian equipment (tidak masuk step EI/Mekanik/IT). Jangan menggantinya
      menjadi kode ZPM baru tanpa instruksi user.
    - **Form web (mode edit halaman detail + halaman tambah):** pilihan jenis
      pekerjaan kini memuat **IT** dan **UMUM** (sebelumnya IT tidak ada di form
      web, sehingga laporan IT hanya bisa dipilih dari wizard Telegram).
    - **Aturan daftar jenis pekerjaan per klasifikasi ZPM (DIPUTUSKAN USER
      08/10/2026, jangan diubah tanpa instruksi):** JS `syncJenisPekerjaan()`
      di `_pelengkap.blade.php` (mode edit halaman detail) DAN `create.blade.php`
      membangun ulang daftar pilihan mengikuti klasifikasi:
      `zpm1`/`zpm6` -> **EI, Mekanik, IT** (IT ditambahkan 08/10/2026; sebelumnya
      `_pelengkap` hanya EI + Mekanik padahal `create.blade.php` sudah memuat IT
      sejak awal - itulah sebab keluhan user "Jenis Pekerjaan hanya ada Mekanik
      dan EI padahal laporannya dipilih ZPM 6"), `zpm3` -> CM/PM,
      `zpm5` -> Fabrikasi, `zpm7` -> Sipil, `zpm8` -> Project, klasifikasi kosong
      -> CM, dCM, PM, Project, UMUM, IT.
    - **Nilai lama SELALU dipertahankan** (`if (current !== '' && !list.some(...)`,
      TANPA syarat `wc === ''` seperti sebelumnya): jenis pekerjaan yang tidak ada
      di daftar ZPM-nya tetap muncul sebagai satu pilihan dan tetap terpilih.
      Syarat lama membuat baris ber-`zpm6` + `IT` berubah menjadi `EI` begitu
      halaman detail disimpan (saat itu tidak ada satupun option yang `selected`
      sehingga peramban mengirim option PERTAMA) - terbukti nyata: 43 baris
      database PROD `oleochemical` dan 10 baris DEV `oleochemical_dev` berada di
      kombinasi itu. JANGAN mengembalikan syarat `wc === ''`.
    - **Klasifikasi yang SENGAJA diganti tetap mengikuti ZPM baru:** kedua select
      klasifikasi diberi `data-value` (nilai saat halaman dimuat) dan JS membaca
      `wcAsli`; bila `wc !== '' && wc !== wcAsli` maka nilai lama TIDAK
      dipertahankan (mencegah kombinasi janggal seperti ZPM 3 + IT). MENGOSONGKAN
      klasifikasi TIDAK termasuk penggantian itu, jadi nilai lama tetap terpilih
      (perilaku lama dipertahankan).
    - **Database & tampilan:** migration
      `2026_10_08_000001_add_umum_to_reports_jenis_pekerjaan.php` menambah nilai
      `UMUM` pada ENUM `reports.jenis_pekerjaan` lewat `->change()` (aman untuk
      MySQL maupun SQLite). Halaman Laporan menambah kartu ringkasan
      (`$countUmum`), warna badge, pilihan filter, dan kotak centang export;
      palet grafik `TechnicianKinerjaService::WARNA_JENIS_PEKERJAAN` menambah
      `UMUM => #0EA5E9`; petunjuk Excel menyebut UMUM; validasi
      `ReportController` (store + update) menerima `UMUM`.
    - **Jebakan yang harus diingat:** daftar pilihan dibangun ulang per
      klasifikasi, jadi nilai `reports.jenis_pekerjaan` yang tidak ada di daftar
      ZPM-nya akan TERGANTI option PERTAMA (bukan menjadi kosong, tetapi salah
      isi) saat laporan disimpan - itulah sebabnya penjaga "nilai lama selalu
      dipertahankan" WAJIB ada, dan setiap jenis pekerjaan baru berikutnya WAJIB
      ikut ditambahkan ke KEEMPAT daftar: daftar `zpm*` + daftar kosong di
      `_pelengkap.blade.php`, hal yang sama di `create.blade.php`, filter
      `reports/index.blade.php`, dan checklist `_popup-export.blade.php`.
    - Uji: `tests/Feature/ReportUmumWorkTypeTest.php` (8 test; 2 test baru
      08/10/2026 menjaga isi daftar `zpm1`/`zpm6` + penjaga nilai lama +
      `data-value` klasifikasi pada KEDUA halaman). Bukti nyata DEV 08/10/2026:
      ENUM MySQL `oleochemical_dev` berbunyi
      `enum('CM','dCM','PM','EI','Mekanik','CM/PM','Fabrikasi','Sipil','IT','Project','UMUM')`,
      halaman `/reports` memuat `data-kartu="jenis-UMUM"` + opsi filter UMUM +
      centang export UMUM, halaman detail memuat `>UMUM</option>` dan
      `>IT</option>`.
    - Bukti render/perilaku JS 08/10/2026: DOM laporan uji DEV `/reports/766`
      (`RPT-20260926-0052`, `work_classification=zpm6`, `jenis_pekerjaan=IT`)
      dibaca dengan Chrome headless `--dump-dom` -> SEBELUM perbaikan pilihannya
      hanya `- Pilih -, EI, Mekanik` (IT hilang, tidak ada option terpilih =
      peramban menampilkan EI), SESUDAH perbaikan menjadi
      `- Pilih -, EI, Mekanik, IT`. Perilaku pemilihan diuji DUA alat: DOM tiruan
      Node dan peramban NYATA Chrome headless (halaman probe `file://` yang
      menyisipkan blok script asli hasil ekstraksi dari halaman DEV) -> A) muat
      ZPM 6 + IT: opsi `['', 'EI', 'Mekanik', 'IT']` dan nilai terpilih `IT`;
      B) ganti ke ZPM 3: `['CM/PM']` -> `CM/PM`; B2) ganti ke ZPM 8:
      `['Project']`; C) klasifikasi dikosongkan: daftar penuh dan `IT` tetap
      terpilih; D) ZPM 3 dengan jenis lama `Fabrikasi`: `['', 'CM/PM', 'Fabrikasi']`
      dan `Fabrikasi` tetap terpilih; E) halaman Buat Laporan (klasifikasi kosong)
      dengan `UMUM`: `UMUM` tetap terpilih. Semua skenario LULUS.
    - Uji mutasi 08/10/2026 (nyata, dijalankan satu per satu lalu dikembalikan):
      (a) menghapus `['IT', 'IT']` dari daftar `zpm6` di `_pelengkap.blade.php`
      membuat 1 test GAGAL; (b) mengembalikan syarat `wc === '' &&` pada penjaga
      nilai lama membuat 1 test GAGAL. Seluruh suite **507 test hijau
      (2353 assertions)**.
    - CATATAN alat: `opt.selected = true` di JavaScript TIDAK muncul sebagai
      atribut `selected` pada keluaran `chrome --headless=new --dump-dom` (diuji
      terisolasi: `document.title` bernilai `nilai=IT` membuktikan `select.value`
      benar, tetapi dump tetap menulis `<option value="IT">IT</option>`). Jadi
      pembuktian "option terpilih" harus lewat `select.value`/perilaku, JANGAN
      lewat pencarian atribut `selected` di hasil dump.

30. **Format pesan pengingat ruang panel (BC sensor suhu) diubah (diputuskan
    08/10/2026 atas permintaan user, sudah ada kodenya).** Semua perubahan ada di
    copy DEV `C:\laragon\www\oleochemicalReport`; BELUM naik ke PROD (tunggu
    `Deploy-Laporan.bat`; TIDAK ada migrasi baru untuk perubahan ini).
    - Bentuk pesan sekarang (satu blok per ruangan, contoh nyata dari data DEV):
      ```
      Halo Erik Khoirul Adam, kondisi terkini Ruang Panel Server (EPE)
      <b>Suhu Rata-rata: 25,02 C / Kelembapan: 77,91 %</b>
      Suhu Terkini: 24,00 C / Kelembapan: 70,90 %
      <b>Kondisi: SUHU NORMAL (berdasarkan suhu rata-rata)</b>
      Perangkat: offline, pembacaan terakhir 06/10/2026 12:11

      Dashboard lengkap: https://oleomtc.com/panel
      ```
      Judul lama ("Pengingat harian ruang panel - Oleochemical Maintenance"),
      penomoran ruangan, baris "Suhu:", ambang batas di dalam pesan, dan baris
      "Detail:" per ruangan SUDAH DIHAPUS sesuai format yang diminta user.
    - **`Suhu Rata-rata` = rata-rata `rata_rata_jam` jam TERAKHIR (bawaan 24
      jam)** - nilai ini DIPILIH USER saat dikonfirmasi 08/10/2026, jangan
      diganti tanpa menanyakan lagi. Diatur
      `config('panel.notifikasi.rata_rata_jam')` (env
      `PANEL_NOTIFIKASI_RATA_RATA_JAM`). **Lencana kondisi juga dinilai dari suhu
      RATA-RATA** (bukan pembacaan terakhir) supaya lonjakan sesaat tidak
      langsung dilaporkan sebagai terlalu panas; `Suhu Terkini` tetap pembacaan
      paling baru. Ruangan tanpa pembacaan dalam jendela itu memakai tanda `-`
      dengan kondisi `BELUM ADA DATA` (sengaja TIDAK jatuh ke pembacaan lama
      supaya data basi tidak tampak seperti kondisi sekarang).
    - Perhitungan agregatnya ada di method BARU
      `App\Services\PanelRiwayatService::ringkasRentang(PanelRuangan, sejak,
      sampai)` (COUNT + AVG suhu/kelembapan, batas rentang memakai objek waktu -
      lihat Riwayat Insiden #23); `PanelNotifikasiService` menyuntik service itu
      di konstruktor.
    - `susunPesan(Technician $teknisi, ?CarbonInterface $sekarang = null)`
      menerima waktu acuan supaya uji bisa memakai data historis, dan `kirim()`
      meneruskan `$waktu` ke sana - JANGAN memakai `now()` di dalam
      `susunPesan()` karena pengingat diuji dengan waktu acuan.
    - Sapaan ("Halo <nama>, kondisi terkini ...") hanya ditulis pada blok
      PERTAMA; blok berikutnya berbunyi "Kondisi terkini ..." dan pesan ditutup
      satu baris "Dashboard lengkap: <APP_URL>/panel". Nama ruangan sekaligus
      menjadi tautan `<a href="<APP_URL>/panel/ruangan/{id}">` supaya akses
      per-ruangan yang dulu ada di baris "Detail:" tidak hilang - kalau tautan
      itu tidak diinginkan, cukup ganti menjadi teks biasa (APP_URL WAJIB
      absolut; kalau kosong tautannya rusak).
    - Uji: `tests/Feature/PanelPesanPengingatTest.php` (6 test: format lengkap,
      kondisi dari rata-rata, jendela 24 jam, ruangan tanpa pembacaan, sapaan
      sekali untuk banyak ruangan, pengiriman otomatis memakai format baru).
      Uji mutasi 08/10/2026: (a) mengembalikan kondisi ke `$terakhir?->suhu`
      membuat 2 test GAGAL; (b) melebarkan jendela menjadi `subYears(10)` membuat
      2 test GAGAL. Seluruh suite **505 test hijau (2341 assertions)**. Bukti
      pesan nyata: skrip sementara atas database DEV ruangan id 3 (369 pembacaan)
      mencetak pesan sesuai format di atas, lalu data ujinya dibersihkan.
    - CATATAN: penjaga lama `tests/Feature/PanelPengingatTelegramTest.php` tetap
      hijau karena pembacaan fixture-nya (06/10/2026 07:55) masih berada di dalam
      jendela 24 jam waktu acuan uji (06/10/2026 08:00/13:30).


