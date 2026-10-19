# Keputusan Arsitektur 18-23

> Bagian dari dokumentasi proyek di folder `docs`.
> Peta dokumen: `AGENTS.md` di akar repo.

18. **Halaman EDIT laporan dimaksimalkan (diputuskan 05/10/2026, sudah ada
   kodenya — jangan diubah tanpa instruksi user).** Semua perubahan ada di copy
   DEV `C:\laragon\www\oleochemicalReport`; 6 commit berurutan `623cf34`,
   `bca2b66`, `65c9f55`, `dd24d9d`, `af2afe7`, `103d768` (semuanya sudah di
   `hub/main`).
   - **Jam kerja:** form edit kini punya kolom `start_time` & `end_time`
     (regex `^([01]?\d|2[0-3]):[0-5]\d$`, pesan galat Bahasa Indonesia). Durasi
     dihitung otomatis dari rentang jam bila kolom durasi dikosongkan
     (`Report::durasiDariJam()`); **jam selesai lebih kecil dari jam mulai
     dianggap melewati tengah malam dan ditambah 24 jam**
     (`Report::lintasTengahMalam()`, mis. 22:00-06:00 = 480 menit). Durasi yang
     diketik manual TIDAK ditimpa; durasi kosong + jam kosong = nilai lama
     DIPERTAHANKAN (kolom dikeluarkan dari data update) supaya angka man hour
     tidak hilang karena kolom terlewat; isi 0 bila memang ingin dinolkan.
     **Form edit TIDAK punya isian durasi (diputuskan 05/10/2026): hanya
     meminta Jam Mulai & Jam Selesai**, jadi form tidak mengirim kolom
     durasi sama sekali dan angkanya selalu dihitung ulang server dari rentang
     jam; durasi tersimpan hanya dipertahankan bila kedua jam dikosongkan.
     Partial `resources/views/reports/partials/_waktu-kerja.blade.php` berisi
     dua kolom jam + tombol "Kosongkan Jam" + peringatan lintas tengah malam
     (tanpa kotak durasi apa pun); KEDUA inputnya memakai atribut
     `form="form-edit-laporan"` karena dipasang di luar elemen `<form>` pada
     halaman detail (lihat poin 19). Halaman `create.blade.php` masih memakai
     isian durasi manual dan belum diseragamkan.
   - **Pencarian lokasi/alat:** `App\Http\Controllers\Traits\ReportLocationTrait`
     menerima parameter `q` pada `getFuncLocsByArea()`/`getAssetsByArea()`
     (hasil dibatasi `BATAS_CARI_LOKASI` = 50 baris saat `q` diisi) dan method
     baru `cariAsset()` (rute `reports.locations.cari-alat`) untuk pencarian
     ALAT LINTAS AREA (minimal 2 huruf; kode alat/nomor equipment/deskripsi/
     kode lokasi; membawa `area_id` + `funcloc_id` + `area_label`). Kotak
     pencarian memilih hasil lewat method Alpine baru
     `terapkanAset()`/`kosongkanLokasi()` pada komponen `reportDetail()` halaman
     detail (partial `partials/_cari-alat.blade.php`; skripnya mencari komponen
     lewat `[x-data="reportLocationManager()"], [x-data="reportDetail()"]`).
   - **Kolaborasi:** `App\Http\Controllers\ReportCollaborationController` +
     rute `reports.collaborators.store|destroy`; baris salinan dibuat oleh
     `App\Services\ReportCopyService::buatSalinanKolaborasi()` (detail pekerjaan
     meniru jalur wizard, kode laporan baru, `creator_id` = pembuat laporan
     induk). Hanya laporan INDUK yang boleh diubah
     (`collaborator_of`/`multi_of` harus NULL), pelaksana sendiri & teknisi
     kembar ditolak, batas `CollaborationService::MAX_KOLABORATOR` (5).
   - **Jalan masuk pengelolaan (diperbarui 05/10/2026):** tombol "Edit Laporan"
     pada kartu Aksi Admin `reports/show.blade.php` mengaktifkan mode edit, dan
     kartu Kolaborasi Teknisi + Alat/Lokasi Tambahan hanya tampak pada mode itu.
     Partial `_tombol-edit-lengkap.blade.php` dan halaman edit terpisah SUDAH
     DIHAPUS (lihat poin 19).
   - **Alat/lokasi tambahan (multi item):**
     `App\Http\Controllers\ReportMultiItemController` + rute
     `reports.multi-items.store|destroy`, memakai
     `ReportCopyService::buatSalinanMulti()` (area dihitung dari alat/node
     lokasi lewat `areaUntukItem()`, `report_type` equipment_repair/area_work,
     `multi_of` = induk). Alat induk / item kembar ditolak; baris salinan multi
     TIDAK dihitung di man hour. Label item memakai accessor
     `Report::multi_item_label` supaya tampilan server & balasan JSON sama.
   - **Foto:** `ReportController::addPhoto()` menerima BANYAK berkas
     (`photos[]`, selain bentuk lama `photo` tunggal yang tetap didukung) dan
     membalas JSON berisi daftar URL terbaru; `deletePhoto()` tetap seperti
     semula. UI foto ada di `reports/show.blade.php` (grid Alpine
     `photoGroups` + `unggahFoto()`/`deletePhoto()`, lihat poin 19).
     **Unggah/hapus foto HANYA boleh dari laporan INDUK** (`penjagaFotoInduk()`
     di `ReportController`) karena baris salinan memakai PATH FOTO YANG SAMA —
     menghapus dari salinan akan ikut menghapus berkas milik induk.
   - **Tipe & status:** form edit menambah `report_type` & `status`
     (`partials/_pelengkap.blade.php`). Keduanya nullable supaya pemanggil lama
     tetap kompatibel; `status` diisi -> `completed_at` ikut disetel (completed =
     `completed_at ?? now()`, selain itu NULL); `status` kosong -> kolom itu
     tidak disentuh.
   - **PENTING (bootstrap/app.php):** `shouldRenderJsonWhen()` dulu HANYA
     `api/*`, sehingga galat VALIDASI pada endpoint fetch halaman laporan
     berbalas 302 + HTML (JavaScript-nya bingung). Kini diperluas menjadi
     `api/*` ATAU (`expectsJson()` DAN `reports/*`).
   - Uji: `ReportEditJamKerjaTest` (11), `ReportEditCariAlatTest` (8),
     `ReportEditKolaborasiTest` (11), `ReportEditAlatTambahanTest` (8),
     `ReportEditFotoTest` (7), `ReportEditFieldPelengkapTest` (5) — semuanya
     diuji lewat `GET /reports/{id}` (mode edit halaman detail), bukan lagi
     halaman edit. Uji mutasi dijalankan per tahap: mematikan
     `durasiDariJam()` -> 3 test GAGAL; mematikan penjaga 2 huruf + batas 50 ->
     2 test GAGAL; `collaborator_of` diisi NULL -> 5 test GAGAL; mematikan
     `sudahAda()` + `areaUntukItem()` -> 3 test GAGAL; mematikan dukungan
     `photos[]` -> 3 test GAGAL; mematikan penyelarasan `completed_at` -> 2
     test GAGAL. Seluruh suite **372 test hijau** (1631 assertions) dan
     `php artisan view:cache` sukses (05/10/2026).
   - Semua AJAX memakai `fetch` + `X-CSRF-TOKEN` supaya isian form yang belum
     disimpan tidak hilang saat kolaborator/item/foto ditambah atau dihapus.

19. **Halaman DETAIL laporan menjadi SATU-SATUNYA tempat mengedit laporan
    (diputuskan 05/10/2026, sudah ada kodenya — jangan diubah tanpa instruksi
    user).** Halaman terpisah `reports.edit` SUDAH DIHAPUS supaya pengeditan
    tidak tersebar di dua tempat: semua perubahan laporan dikerjakan di
    `resources/views/reports/show.blade.php` pada mode edit (Alpine
    `reportDetail()`, tombol "Edit Laporan" pada kartu Aksi Admin). Semua
    berkas yang disebut di bawah ini ada di copy DEV
    `C:\laragon\www\oleochemicalReport`.
    - **Rute & controller:** `routes/web.php` memakai
      `Route::resource('reports', ...)->except(['edit'])`, jadi
      `GET /reports/{id}/edit` berbalas 404. `ReportController::edit()` diganti
      method privat `dataPengelolaanLaporan(Report): array` (kunci:
      `laporanInduk`, `kolaborator`, `teknisiPilihan`, `alatTambahan`) yang
      datanya digabungkan ke `view('reports.show', ...)` oleh `show()`.
    - **Berkas yang DIHAPUS:** `resources/views/reports/edit.blade.php`,
      `resources/views/reports/partials/_tombol-edit-lengkap.blade.php`, dan
      `resources/views/reports/partials/_foto.blade.php` (UI foto khusus
      halaman edit; fungsinya pindah ke halaman detail, jangan dihidupkan
      kembali).
    - **Partial yang dipakai halaman detail:** `_pelengkap` (ZPM, jenis
      pekerjaan, `sesuai_rencana`, `tanggal_kejadian`, `equipment_tag`, tipe
      laporan, status), `_waktu-kerja` (jam mulai/selesai), `_cari-alat`,
      `_kolaborasi`, `_alat-tambahan` — semuanya di-include dari
      `show.blade.php`. Karena inputnya berada DI LUAR elemen `<form>`, semua
      isian wajib memakai atribut `form="form-edit-laporan"` (form tersembunyi
      `PUT` ke `reports.update` di kolom kanan halaman). Kolom `finding`
      (temuan) pada halaman detail TETAP memakai partial `_temuan-kartu` yang
      sudah ada (kartu tersendiri: tampilan + mode edit); partial `_temuan`
      hanya dipakai halaman `create.blade.php`.
    - **Foto:** kotak unggah halaman detail mengirim BANYAK berkas
      (`photos[]`; id `foto_berkas`/`foto_unggah`, hygiene
      `foto_berkas_hygiene`/`foto_unggah_hygiene`) lewat `fetch` (method Alpine
      `unggahFoto()`), lalu daftar foto diperbarui dari balasan JSON
      `ReportController::addPhoto()` tanpa reload; hapus foto tetap
      `deletePhoto()`. Kotak unggah DAN tombol hapus hanya dirender untuk
      laporan INDUK (`$laporanInduk->id === $report->id`, lihat
      `dataPengelolaanLaporan()`) karena baris salinan memakai berkas foto yang
      sama; pengaman sisi server tetap `penjagaFotoInduk()`.
    - **Tautan baris alat tambahan TIDAK menuju halaman baris salinan:**
      `partials/_alat-tambahan.blade.php` dan
      `ReportMultiItemController::daftarItem()` menautkan ke
      `assets.show`/`locations.show` (kode baris salinan tetap ditampilkan
      sebagai keterangan). Ini menjaga aturan lama di
      `tests/Feature/ReportMultiEquipmentTest.php` ("halaman detail laporan
      induk tidak menautkan baris salinan", satu pekerjaan bersama tetap satu
      laporan) — jangan dikembalikan ke `reports.show` tanpa mengubah aturan
      itu lebih dulu.
    - **Uji:** semua test `tests/Feature/ReportEdit*Test.php`,
      `ReportProjectWorkTypeTest`, `ExampleTest`, dan `PageSmokeTest` dialihkan
      ke `GET /reports/{id}` (test `GET /reports/{id}/edit` di PageSmokeTest
      kini mengharapkan 404). Dua test "Edit Lengkap" pada
      `ReportEditKolaborasiTest` diganti menjadi "halaman detail laporan
      menjadi satu-satunya tempat pengelolaan laporan" dan "laporan salinan
      kolaborasi mengarahkan pengelolaan ke laporan INDUKNYA". Seluruh suite
      **372 test hijau (1631 assertions)** dan `php artisan view:cache` sukses
      (05/10/2026).

20. **Ruang panel: dukung sketsa dashboard (JSON TCP + HTML), bukan hanya
    firmware resmi (diputuskan 06/10/2026, sudah ada kodenya).** Semua perubahan
    ada di copy DEV `C:\laragon\www\oleochemicalReport`.
    - Latar: perangkat nyata yang dipakai user (`192.168.41.240`) menjalankan
      **sketsa sendiri** — port 80 menyajikan dashboard HTML (`Refresh: 3`, teks
      `Suhu: xx.xx` dan `Kelembaban: xx.xx`) dan port **TCP 5000** menyajikan
      satu baris JSON
      `{"device":"ESP32_W5500","ip":"...","temperature":..,"humidity":..,"uptime_sec":..}`.
      Sketsa itu TIDAK punya `/api/status` maupun buffer, sehingga modul panel
      lama (yang hanya membaca JSON API firmware) selalu menilai perangkat
      offline. Diverifikasi LANGSUNG ke perangkat, bukan asumsi:
      `GET /api/status` menjawab HTML (jadi `Http::json()` null), dan TCP 5000
      baru membalas SETELAH klien mengirim satu byte (lihat `handleTCPClients()`
      pada sketsa) — karena itu `PanelPerangkatReader::bacaJsonTcp()` menulis
      `"\n"` lebih dulu.
    - Kolom BARU `panel_ruangan.protokol` (migration
      `2026_10_06_000001_add_protokol_to_panel_ruangan_table.php`, bawaan
      `otomatis`): `otomatis` | `api-json` | `tcp-json` | `html`. Daftarnya di
      `PanelRuangan::PROTOKOL`, label tampilan lewat `labelProtokol()`.
      Urutan `otomatis` = JSON API (paling informatif) -> JSON TCP -> dashboard
      HTML. Ruangan lama tidak perlu diubah karena bawaannya `otomatis`.
    - Pembaca mentah dipisah ke **`App\Services\PanelPerangkatReader`**
      (`bacaJsonHttp`, `bacaJsonTcp`, `bacaDashboardHtml`) supaya bisa diganti
      tiruan saat pengujian — jangan mengembalikan HTTP/TCP langsung ke dalam
      `PanelSensorService`.
    - `PanelSensorService` bercabang pada penanda `sumber` jawaban. Jalur lama
      (`json-api`) tetap memakai buffer + `id_perangkat` milik perangkat.
      Perangkat tanpa buffer (`tcp-json`/`html`) memakai jalur BARU
      `tarikDariNilaiTerkini()`: `waktu` = waktu penarikan server, `sesi_boot`
      tetap `'sk'`, `id_perangkat` naik sendiri (`max(id_perangkat)+1`).
      **Nilai yang tidak berubah hanya disimpan ulang setelah
      `panel.sketch.jeda_simpan_detik` (bawaan 60 detik); nilai yang berubah
      selalu langsung disimpan.** Port TCP diatur `panel.sketch.port_tcp`
      (bawaan 5000).
    - **Jebakan yang harus diingat:** penanda `sumber` WAJIB ikut disisipkan ke
      dalam array status (`$mentah['sumber'] = 'json-api'`), bukan hanya di
      pembungkus hasil. Versi pertama hanya menaruhnya di pembungkus sehingga
      perangkat firmware resmi salah dianggap perangkat sketsa (tersimpan 0
      pembacaan). Ketahuan dari test, bukan dari peninjauan kode.
    - **Penjaga nilai nol:** sketsa mengirim `0,00/0,00` selama sensor belum
      pernah berhasil membaca (variabelnya diawali nol, lihat `buildJSONData()`),
      jadi pasangan suhu DAN kelembapan nol dianggap "sensor belum membaca":
      ruangan ditandai online + `catatan` berisi petunjuk wiring, TANPA menyimpan
      baris pembacaan supaya grafik tidak tertipu angka nol. Penentunya
      `PanelSensorService::sensorMembaca()`.
    - Tampilan: pilihan "Cara baca perangkat" di form ruangan, nama protokol di
      kartu ruangan (`protokol_label` dari `/panel/data`), dan baris "Sumber
      jawaban" pada modal Uji Koneksi. Pesan uji koneksi perangkat tanpa buffer
      memakai cabang sendiri di `PanelRuanganController::pesanUjiKoneksi()`
      (tidak menyebut W5500/firmware karena sketsa tidak melaporkannya).
    - Uji: `tests/Feature/PanelProtokolSketchTest.php` (12 test, 45 assertions;
      memakai tiruan `PanelPerangkatReader` + `ItPingService` sehingga tidak
      membuka soket nyata). Seluruh suite **388 test hijau (1688 assertions)**
      dan `php artisan view:cache` sukses (06/10/2026). Bukti uji nyata ke
      perangkat user: `sumber=tcp-json`, ping `online` 3 ms, status ruangan
      `online`, catatan "sensor belum mengirim pembacaan", TIDAK ada baris palsu
      tersimpan, dan `bacaDashboardHtml()` membaca angka asli dari dashboard
      port 80 perangkat.
    - Naik ke PROD lewat `Deploy-Laporan.bat` (migrasi `protokol` ikut jalan).
    - **Wiring perangkat nyata (diselaraskan 06/10/2026):** blok `KONFIGURASI PIN`
      di `sensor-suhu-esp32\firmware\ethernet_dht11\ethernet_dht11.ino` kini
      `PIN_DHT 27` + `#define TIPE_SENSOR DHT22` + `PIN_W5500_RST 17`
      (`PAKAI_RESET_W5500 1`) supaya cocok dengan papan yang sudah disolder user
      dan dengan sketsa dashboard (`sketch_dashboard.ino`: DHTPIN 27, DHTTYPE
      DHT22, W5500 CS=5/SCK=18/MISO=19/MOSI=23/RST=17). Bagian W5500 memang
      sudah sama sejak awal; yang DULU berbeda hanya pin sensor (GPIO4) dan tipe
      sensor (DHT11). Pin GPIO2 tetap dipakai sebagai LED status (LED bawaan
      board). Sketsa bantu `firmware\uji_sensor_dht\uji_sensor_dht.ino` (ikut
      di-commit) mencoba beberapa pin x DHT11/DHT22 untuk memastikan kombinasi
      yang benar; JANGAN mengompilasi/mengunggah apa pun tanpa perintah user. Catatan:
      salah `TIPE_SENSOR` membuat library menolak data (format/checksum berbeda)
      sehingga pembacaan selalu gagal.


21. **Pengaturan lengkap ruang panel + ruang panel menjadi aset penuh
    (diputuskan 06/10/2026, sudah ada kodenya).** Semua perubahan ada di copy
    DEV `C:\laragon\www\oleochemicalReport`. Pemicunya: user melaporkan suhu
    sudah muncul di dashboard (dibuktikan langsung di database DEV
    `oleochemical_dev`: 110 baris `panel_pembacaan` untuk ruangan id 3 "Ruang
    Panel EPE 1", suhu 24,90-25,00 C, `sesi_boot='sk'`, `sumber='pull'`).
    - **Halaman baru**: `resources/views/panel/ruangan/form.blade.php` (tambah +
      ubah, satu berkas) dan `resources/views/panel/ruangan/show.blade.php`
      (detail + tombol aksi). Rute baru di `routes/web.php`:
      `panel.ruangan.create`, `panel.ruangan.edit`, `panel.ruangan.show`,
      `panel.ruangan.sinkron-aset`. Route `create` WAJIB didaftarkan SEBELUM
      `/panel/ruangan/{panelRuangan}` supaya kata "create" tidak terbaca
      sebagai id.
    - **Form memisahkan dua jenis sumber data secara eksplisit**: kartu
      "Dashboard Arduino (sketsa sendiri)" (`tcp-json` dengan port TCP per
      ruangan, `html`) dan kartu "Firmware proyek (JSON API)" (`api-json`),
      semuanya radio `name="protokol"`. Label `PanelRuangan::PROTOKOL` TIDAK
      diubah karena test lama memeriksanya di halaman `/panel`.
    - **Kolom baru** (migration
      `2026_10_06_000002_sambungkan_panel_ruangan_ke_assets.php`):
      `panel_ruangan.funcloc_id` (FK nullOnDelete), `kode_alat`,
      `equipment_type`, `port_tcp`; `assets.panel_ruangan_id` (FK cascade);
      ENUM `assets.data_source` ditambah `panel_sync` (perubahan ENUM dijaga
      `if (DB::getDriverName() === 'mysql')` seperti migration IT).
    - **Kode alat manual + nomor equipment otomatis** (KEPUTUSAN USER
      06/10/2026): `assets.tech_ident_no` = `panel_ruangan.kode_alat` (diketik
      manual pada form), sedangkan `assets.equipment_no` dibuat 8 digit lewat
      `AssetNumberService::generateForFuncLoc()` mengikuti seri nomor PT lokasi
      itu. Ruangan tanpa lokasi ATAU tanpa kode alat TIDAK dipaksa masuk: baris
      cerminnya dibuang dan alasan dilewatkannya ditampilkan di halaman
      form/detail.
    - **`App\Services\PanelAssetSyncService`** (pola sama dengan
      `ItAssetSyncService`): `syncOne`, `syncAll`, `hapusCermin`,
      `removeStaleMirrors`, `buildMirrorPayload`, `alasanLewatSinkron`,
      `asetCermin`. Dipanggil otomatis pada `store`/`update`, tombol
      "Sinkronkan Aset", saat `destroy`, dan perintah
      `php artisan panel:sync-assets [--dry-run]`
      (`app\Console\Commands\PanelSyncAssetsCommand.php`). Baris aset lama
      bertipe impor/manual dengan kode alat sama DIADOPSI (nomor equipment
      lamanya dipertahankan); baris cermin modul IT (`it_sync`) TIDAK boleh
      diadopsi.
    - **Satu sumber aturan lokasi**: `App\Services\AssetLocationService`
      (company_id/department_id/area_id/sub_area_id + `kolomLokasi()`);
      `ItAssetSyncService` kini mendelegasikan `resolveCompanyId()` dan
      `resolveDepartmentId()` ke service itu (import `Company`/`Department` di
      sana dihapus). Daftar Functional Location untuk form dipusatkan di
      `App\Services\FunclocOptionService::grupPerPt()` dan
      `ItAssetController::funclocOptionsGroups()` memakai service yang sama.
    - **Port TCP per ruangan**: `PanelSensorService` memakai
      `PanelRuangan::portTcpEfektif()` (kolom `port_tcp`, cadangan
      `config('panel.sketch.port_tcp')`), tidak lagi satu nilai global.
    - **Penjaga `kode_alat`**: menolak kode yang sudah dipakai baris cermin
      modul lain atau ruangan panel lain, TETAPI baris cermin milik ruangan itu
      sendiri dikecualikan. Jebakan yang sudah kena sekali: penyaring
      pengecualian WAJIB memakai `whereNull('panel_ruangan_id') orWhere(...)`,
      karena `where('panel_ruangan_id', '!=', $id)` membuang baris NULL di SQL
      sehingga cermin milik perangkat IT lolos. Bug pertama malah menolak
      menyimpan perubahan pada kode alat yang sama; ketahuan dari test, bukan
      dari peninjauan kode.
    - **Import/export aset**: `data_source` `panel_sync` diperlakukan sama
      seperti `it_sync` (dilewati `ImportService` pada tiga titik pemeriksaan,
      tidak ikut diexport di `AssetExportTrait`), dan label data_source di
      `resources/views/assets/show.blade.php` ditambah "Sinkron Ruang Panel".
    - **Dashboard `/panel` TIDAK ditulis ulang** (1.052 baris): modal lama tetap
      dipakai sebagai "tambah cepat" (setelah simpan, pengguna diarahkan ke
      halaman detail ruangan untuk melengkapi lokasi/kode alat), nama ruangan
      pada kartu menaut ke halaman detail, dan tombol Edit mengarah ke halaman
      pengaturan lewat `window.location.href`. **Modal lama BELUM dihapus**;
      kalau nanti ingin dihapus, lakukan overwrite penuh berkas itu sekali
      jalan, jangan tambal sebagian.
    - Uji: `tests/Feature/PanelPengaturanRuanganTest.php` (14 test, 73 assertions;
      mencakup port TCP per ruangan, adopsi baris lama, penolakan kode bentrok
      ruangan lain/perangkat IT, hapus ruangan menghapus cermin, dan perintah
      `panel:sync-assets --dry-run`). Test lama
      `PanelProtokolSketchTest` "ruangan panel dapat disimpan dengan protokol
      tcp-json" disesuaikan: pengalihan setelah simpan kini ke halaman detail
      ruangan, bukan `/panel`. Seluruh suite **403 test hijau (1767 assertions)**
      dan `php artisan view:cache` sukses (06/10/2026).
    - Catatan lingkungan: `php` TIDAK ada di PATH sesi terminal agent; pakai
      `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`. Saat memanggil
      `php artisan tinker --execute` dari PowerShell, tanda kutip GANDA di dalam
      argumen terpotong (muncul "Undefined constant") — pakai kutip tunggal
      ganda (`''teks''`) atau, untuk pemeriksaan rumit, skrip sementara
      `tmp-cek-*.php` yang dijalankan langsung lalu dihapus lagi.

22. **Dashboard panel diringkas, penanggung jawab panel, halaman detail
    (grafik realtime + riwayat harian + ekspor Excel), dan pengingat Telegram
    harian (diputuskan 06/10/2026, sudah ada kodenya).** Semua perubahan ada di
    copy DEV `C:\laragon\www\oleochemicalReport`; 4 commit berurutan
    **`eafd7d`** (penanggung jawab), **`c16070d`** (dashboard ringkas),
    **`8685bef`** (detail: grafik + riwayat harian + ekspor), **`3a4c4ec`**
    (pengingat Telegram); semuanya sudah di `hub/main`.
    - **Dashboard `/panel` TIDAK lagi menampilkan grafik besar dan tabel
      riwayat.** `resources/views/panel/index.blade.php` ditulis ulang: panel
      `#panelGrafik`/`#panelRiwayat` beserta seluruh JavaScript pendukungnya
      DIHAPUS (jangan dihidupkan lagi). Setiap kartu sekarang memuat: nilai
      terkini, **kurva singkat** (canvas `kurva-<id>`, Chart.js tanpa
      sumbu/label/legenda dari data `grafik` yang sudah dikirim `/panel/data`),
      **lencana kondisi** (`TERLALU PANAS` / `TERLALU DINGIN` / `SUHU NORMAL` /
      `BELUM ADA DATA` berdasar `PanelRuangan::kondisiSuhu()`), nama
      **penanggung jawab**, ambang suhu, dan tombol Detail/Uji/Tarik/Edit/Hapus.
      Kurva lama di-`destroy()` sebelum kartu digambar ulang
      (`bersihkanKurva()`) supaya objek Chart tidak menumpuk di memori.
    - **Penanggung jawab (PIC) panel**: tabel baru **`panel_penanggung_jawab`**
      (pasangan unik `panel_ruangan_id` + `technician_id`, keduanya cascade)
      lewat migration `2026_10_06_000003_tambah_penanggung_jawab_panel.php`
      yang sekaligus membuat **`panel_notifikasi_terkirim`** (unik
      `technician_id` + `tanggal`, kolom `jam_kerja`, `jumlah_ruangan`).
      Relasi: `PanelRuangan::penanggungJawab()` (belongsToMany) dan
      `Technician::panelYangDitangani()`; helper `namaPenanggungJawab()`
      mengembalikan "belum ditentukan" bila kosong. Dipilih dari data teknisi
      (dikelompokkan per Bagian; yang tanpa Telegram ID diberi tanda
      "- tanpa Telegram") pada kartu baru form ruangan
      `resources/views/panel/ruangan/partials/_penanggung-jawab.blade.php`
      (di-include satu baris dari `form.blade.php` supaya form 340 baris tidak
      perlu ditulis ulang). Validasi `penanggung_jawab[]` (array id teknisi
      yang ada) + `simpanPenanggungJawab()` (sync; daftar kosong = menghapus
      semua).

    - **Halaman detail ruangan** (`resources/views/panel/ruangan/show.blade.php`,
      ditulis ulang): kartu ringkasan (lencana kondisi ikut diperbarui
      JavaScript), **grafik realtime** Chart.js dari endpoint BARU
      `panel.ruangan.data` (polling tiap `panel.tampilan.interval_polling_detik`),
      **tabel riwayat harian** per bulan (`?bulan=YYYY-MM`, bawaan bulan
      berjalan; isian tidak sah dikembalikan ke bulan berjalan), tombol
      **Ekspor Excel** (`panel.ruangan.export-harian`, nama berkas dari nama
      ruangan + cap waktu), dan tombol **Kirim Pengingat**
      (`panel.ruangan.kirim-pengingat`, uji kirim manual oleh admin).
    - **Rekap harian**: `App\Services\PanelRiwayatService::harian()` menghitung
      MIN/MAX/AVG suhu & kelembapan serta jumlah pembacaan di luar ambang dengan
      agregasi SQL (`DATE(waktu)` + `CASE WHEN`), dibatasi
      `startOfDay()`/`endOfDay()` (jangan tanggal polos — lihat insiden #23).
      Ekspor Excel-nya di trait baru
      `App\Http\Controllers\Traits\PanelExportTrait` (gaya sama dengan
      `AssetExportTrait`: blok judul/keterangan, header teal, autofilter,
      `setCellValueExplicit`).
    - **Pengingat Telegram harian**: `App\Services\PanelNotifikasiService`
      menentukan jam masuk kerja tiap PIC (`mulaiKerja()`): non-shift memakai
      `JamKerjaService::hari()` (Sabtu/Minggu libur otomatis), pekerja shift
      memakai kode pola rotasi `ShiftPatternService::kode()` (kode `x` = OFF =
      tidak dikirimi). Pesan memuat kondisi terkini tiap ruangan yang ditangani
      (suhu, kelembapan, lencana kondisi, online/offline, tautan detail dan
      dashboard; teks dinamis di-escape `htmlspecialchars` karena Telegram
      memakai parse_mode HTML). Pengiriman hanya **sekali per hari per teknisi**
      (dijaga `panel_notifikasi_terkirim`) dan hanya di dalam **jendela 15 menit
      setelah jam masuk kerja** (`config('panel.notifikasi.jendela_menit')`,
      toleransi bila penjadwal sempat mati beberapa menit). Perintah
      `php artisan panel:kirim-pengingat` dijadwalkan `everyMinute`
      (`routes/console.php`, saklar `PANEL_NOTIFIKASI_ENABLED`), dengan opsi
      `--uji-kirim` dan `--teknisi=` untuk pengujian/manual. **Jebakan yang
      sudah kena sekali:** mode uji kirim TIDAK boleh menuntut jadwal kerja
      (teknisi yang sedang OFF tetap harus bisa dikirimi saat diuji) — karena
      itu `kirim()` punya parameter `$wajibJadwal` dan `$idTerpilih`; teknisi di
      luar daftar terpilih TIDAK dihitung pada "dilewati" supaya pesan
      ringkasannya tidak menyesatkan.
    - Uji: `PanelPenanggungJawabTest` (7), `PanelDashboardRingkasTest` (2),
      `PanelRiwayatHarianTest` (5), `PanelPengingatTelegramTest` (7). Uji mutasi
      yang sudah dijalankan: memaksa mode uji kirim tetap menuntut jam kerja
      membuat 1 test GAGAL, dan filter `$idTerpilih` yang ikut menghitung
      "dilewati" membuat pesan ringkasan salah (ketahuan dari test tombol kirim).
      Seluruh suite **424 test hijau (1860 assertions)** dan
      `php artisan view:cache` sukses (06/10/2026).
    - Dua berkas blade besar (`panel/index.blade.php` 1055 baris dan
      `panel/ruangan/show.blade.php` 325 baris) ditulis ulang dengan pola: salin
      berkas lama ke `%TEMP%`, hapus berkas aslinya, lalu tulis versi baru
      bertahap dari baris terakhir — cara ini dipakai karena
      `find_and_replace` pada berkas blade besar berulang kali meleset dan
      titik sambungannya bergeser (lihat Aturan Wajib Edit File poin 6 dan
      Riwayat Insiden #12). SETELAH menulis bertahap WAJIB memeriksa: titik
      sambungan, jumlah `<div>`/`</div>`, `<script>`/`</script>`,
      `@push`/`@endpush`, `@forelse`/`@endforelse`, `php artisan view:cache`,
      lalu mengekstrak blok `<script>` dan menjalankannya lewat
      `C:\laragon\bin\nodejs\node-v22\node.exe --check` (echo blade diganti `0`
      lebih dulu) supaya kesalahan JavaScript ketahuan tanpa membuka browser.
    - Catatan alat: `read_files` dengan rentang baris atas berkas yang SUDAH
      pernah dibaca utuh bisa dijawab `[outdated - see the latest file
      content]` tanpa isi. Jalan pintas yang dipakai saat itu: cetak rentang
      barisnya lewat PowerShell (`(Get-Content <berkas>)[a..b]` + nomor baris),
      HANYA untuk membaca — penulisan tetap memakai tool editor.
    - **Perbaikan 06/10/2026: kartu suhu dashboard menampilkan `24.4NaN`, bukan
      `24.4 C`.** Penyebabnya satu tanda plus BERLEBIH pada rangkaian
      penggabungan teks penyusun kartu (`resources/views/panel/index.blade.php`
      baris 453 sebelum diperbaiki berbunyi
      `+          + '<span class="text-sm font-semibold text-slate-400"> C</span>'`):
      tanda plus kedua adalah unary plus, sehingga JavaScript mengubah teks
      span menjadi `NaN` dan satuan C ikut hilang. Halaman detail ruangan tidak
      terpengaruh karena nilainya dirender server (`number_format`). Perbaikan:
      hapus tanda plus kedua. Bukti render browser nyata (Chrome
      `--headless=new --dump-dom` atas `http://oleochemicalReport.test/panel`):
      sebelum = `>24.5NaN`, sesudah =
      `24.4<span class="text-sm font-semibold text-slate-400"> C</span>` dan
      tidak ada `NaN` (pencarian peka huruf besar-kecil). Uji penjaga: 2 test
      baru di `tests/Feature/PanelDashboardRingkasTest.php` (uji mutasi:
      mengembalikan tanda plus ganda membuat KEDUA test GAGAL). Lihat Riwayat
      Insiden #34.
    - Catatan lingkungan 06/10/2026: copy DEV `oleochemical_dev` ternyata BELUM
      menjalankan migrasi `2026_10_06_000003_tambah_penanggung_jawab_panel`,
      sehingga `/panel/data` di DEV berbalas HTTP 500 (relasi `penanggungJawab`
      dibaca lebih dulu padahal tabel `panel_penanggung_jawab` belum ada) —
      padahal `tarikBilaJedaLewat()` sudah menarik data perangkat SEBELUM galat
      terjadi, jadi angka pembacaan tetap bertambah walau kartu halaman tampak
      gagal. Sudah diperbaiki dengan `php artisan migrate --force` di DEV. PROD
      sudah memiliki kedua tabel itu.

23. **Import/Export asset berpindah kunci ke Equipment No (diputuskan 06/10/2026, sudah
    ada kodenya).** Semua perubahan ada di copy DEV
    `C:\laragon\www\oleochemicalReport`; PROD baru ikut setelah `Deploy-Laporan.bat`
    dijalankan manusia (TIDAK ada migrasi baru, jadi deploy aman).
    - Keluhan asal: admin ingin MENGUBAH Kode Alat di file hasil export, tetapi import
      lama mencocokkan HANYA lewat Tech Ident No sehingga baris ber-kode-baru dibuat
      sebagai aset BARU (duplikat).
    - **Export** (`app\Http\Controllers\Traits\AssetExportTrait.php`): kolom
      **`Equipment No` ditambahkan di kolom B** (setelah `No`) untuk xlsx DAN csv,
      nilainya ditulis sebagai TEKS (`setCellValueExplicit`) supaya angka nol di depan
      tidak hilang. Kolom lain bergeser satu: C Description, D Tech Ident No, E
      Equipment Type, F Functional Loc, ... O Data Source (`$kolomTerakhir` = A..O).
    - **Import** (`app\Services\ImportService.php`): urutan pencocokan =
      **(1) Equipment No** (kolom unik; header `Equipment No`/`Equipment` sudah dikenali
      `buildColMap()` sejak lama, tetapi nilainya dulu dibuang/`''` di `kumpulkanBaris()`),
      **(2) Tech Ident No** sebagai cadangan bila nomor di file kosong atau belum ada di
      database, **(3) aset baru** + nomor dibuat `AssetNumberService`. Urutan ini adalah
      PILIHAN USER saat ditanya 06/10/2026 ("file lama tetap jalan tanpa perubahan").
      Baris hasil cocok diberi penanda `dicocokkan_lewat`
      (`equipment_no`/`tech_ident_no`).
    - Penjaga yang WAJIB ada (jangan dilepas):
      (a) **Kode Alat baru yang sudah dipakai aset LAIN** walau nomornya cocok -> masuk
      bucket `ambigu` (Perlu Diperiksa) dan TIDAK ditulis, karena `tech_ident_no` tidak
      berindeks unik (Riwayat Insiden #15);
      (b) **Equipment No yang muncul >1 kali di file** -> `ambigu`;
      (c) nilai Kode Alat yang sudah rusak karena konversi spreadsheet TIDAK menimpa
      database (`kodeAlatTerpotong()`: "61530" vs "6153E1") -- sebelumnya kasus ini hanya
      tertangani di jalur Tech Ident No, sekarang ikut di jalur Equipment No (ketahuan
      dari test lama "Tech Ident No angka yang terpotong Excel...");
      (d) baris **tanpa Kode Alat tetap masuk bucket `no_equip`** (tidak diproses) supaya
      sel Kode Alat kosong tidak menghapus nilai yang sudah ada;
      (e) nomor equipment aset lama TIDAK PERNAH diubah oleh import, dan nomor pada baris
      BARU tetap diabaikan (dibuat sistem).
    - **Jebakan yang sudah kena sekali:** penghitung identitas membaca
      `$baris['equipment_no']`, padahal nilai itu ditaruh di
      `$baris['data']['equipment_no']` oleh `kumpulkanBaris()`. Karena ada `?? ''` tidak
      muncul error apa pun; hanya penjaga "nomor dobel di file" yang diam-diam tidak
      pernah aktif (ketahuan dari test, BUKAN dari peninjauan kode). Karena itu kunci
      `equipment_no` kini ditulis di LEVEL ATAS array baris (sejajar
      `tech_ident_no`/`functional_loc`).
    - Berkas lain yang disesuaikan: `app\Http\Controllers\ImportController.php` (template
      Excel + lembar Petunjuk memuat kolom `Equipment No` dan urutan pencocokannya;
      `getStyle('A1:I1')`), `resources\views\assets\import.blade.php` (teks penjelasan
      pencocokan di halaman import), dan `docs/PROMPT.md` bagian "Export & Import Aset".
    - Uji: `tests\Feature\AssetImportUpdateTest.php` (32 test; 5 test baru: kode alat
      diubah tetap dicocokkan lewat Equipment No tanpa aset baru, fallback ke Kode Alat,
      Kode Alat milik aset lain ditandai perlu diperiksa, Equipment No dobel di file, dan
      export menulis Equipment No sebagai teks di kolom B). Test lama yang mengunci
      perilaku "Equipment No tidak diekspor" serta indeks kolom export (B7/C7/E7, C8,
      indeks 4/6/7/8 pada CSV) ikut disesuaikan. Seluruh suite **433 test hijau (1909
      assertions)** dan `php artisan view:cache` sukses (06/10/2026).
    - Bukti uji nyata ke database DEV `oleochemical_dev` (aset id 4302, Equipment No
      70000292, Kode Alat FBN04): file tanpa mengubah Kode Alat ->
      `duplicate=1, clean=0, dicocokkan_lewat=equipment_no, perubahan=[]`; file dengan
      Kode Alat diubah menjadi `FBN04-UJI` -> tetap `duplicate=1, clean=0,
      dicocokkan_lewat=equipment_no` dengan
      `perubahan=[{Tech Ident No: FBN04 -> FBN04-UJI}]`. Jumlah aset tidak berubah
      (4256) karena analisa tidak menulis apa pun.
