# Keputusan Arsitektur 11-17

> Bagian dari dokumentasi proyek di folder `docs`.
> Peta dokumen: `AGENTS.md` di akar repo.

11. **Status laporan `draft` TIDAK dipakai lagi (diputuskan 26/09/2026).**
   Laporan hanya berstatus `completed` (Done) atau `continue` (belum selesai),
   plus `needs_review` bila admin meminta pemeriksaan. Semua draft lama
   (700 laporan) sudah dipindahkan ke `completed` lewat migration
   `2026_09_26_000005` beserta `completed_at`-nya, dan nilai bawaan kolom
   `status` di database kini `completed`. Tombol "Tandai Draft", opsi filter
   Draft, dan kartu "Draft" di halaman Teknisi (diganti "Continue") sudah
   dihapus — jangan dihidupkan lagi tanpa instruksi user.

12. **Manajemen user berbasis Bagian (diputuskan 27/09/2026, sudah ada
   kodenya — jangan diubah tanpa instruksi user):**
   - Login memakai **username**; untuk akun teknisi username = **NIK** dan
     password awal = **NIK huruf kecil** (`mb_strtolower`). Akun lama tetap
     bisa login memakai email (deteksi bentuk isian ada di
     `app/Http/Controllers/LoginController.php`).
   - Role akun berisi **kode Bagian** (kolom `section` teknisi: mekanik, ei,
     it, sipil, fabrikasi, general, cm_pm, lainnya) atau `admin`/`supervisor`.
     Kolom `role` di tabel `users` karena itu diubah dari enum menjadi
     varchar(50) — **nilai bawaannya tetap `admin`** supaya akun lama dan
     akun buatan `UserFactory` tidak berubah perilakunya (perubahan default
     ini pernah hampir merusak seluruh pengujian).
   - Hak akses halaman diatur per Bagian **DAN per Group teknisi** di tabel
     `bagian_accesses` (`bagian` + `grup` unik bersama, `permissions` json);
     daftar modulnya di `config/akses.php` (label, grup menu, pola nama
     route, nama route menu sidebar, penanda `khusus_admin`). Akun
     `admin`/`supervisor` SELALU akses penuh dan tidak bisa dibatasi; modul
     bertanda `khusus_admin` (mis. Manajemen User) tidak bisa diberikan ke
     Bagian maupun Group mana pun.
   - Group sebuah akun diambil dari data teknisi yang terhubung
     (`users.technician_id` -> `technicians.group`), BUKAN dari kolom baru di
     `users` (keputusan user 01/10/2026, supaya group selalu ikut data teknisi
     dan tidak perlu dijaga manual). Urutan pencarian izin:
     (1) baris Bagian + Group, (2) baris Bagian dengan `grup` NULL sebagai
     pengaturan bawaan, (3) seluruh modul diizinkan. Jadi akun tanpa teknisi
     dan Group yang belum diatur memakai bawaan Bagian, dan baris lama dari
     migrasi `2026_09_27_000002` otomatis menjadi bawaan itu — tidak ada
     akses pengguna yang berubah saat migrasi
     `2026_10_01_000002_add_grup_to_bagian_accesses_table.php` dijalankan.
   - Layar "Hak Akses Bagian" (`resources/views/akses/index.blade.php`) punya
     DUA baris tab: tab Bagian, lalu tab Group (Bawaan Bagian + Reguler,
     Reguler Staff, Grub A-D) lengkap dengan jumlah akun/teknisi per
     kombinasi; Group yang belum diatur sendiri ditandai "mengikuti bawaan".
     `BagianAccess::untukBagian()` menerima parameter grup,
     `BagianAccess::tersimpan()` membaca baris apa adanya (untuk status
     "belum diatur" dan pengujian), dan `User::grupBagian()` /
     `User::labelBagian()` menampilkan label seperti "Mekanik - Reguler Staff"
     di daftar akun serta sidebar. Hak akses per modul di-cache per instance
     `User` (`$cacheDaftarAkses`) supaya pemeriksaan 15 modul untuk
     menyembunyikan menu tidak menanyakan database berkali-kali. Uji:
     `tests/Feature/AksesBagianGrupTest.php` (10 test; uji mutasi: membuat
     `User::grupBagian()` selalu mengembalikan null membuat 4 test GAGAL).
   - Penegakan izin ada di `App\Http\Middleware\AksesMiddleware` (alias
     `akses`, dipasang bersama `admin` pada grup route di `routes/web.php`).
     Route yang tidak terpetakan ke modul mana pun dilewatkan, karena itu
     ada test penjaga "semua route bernama punya modul hak akses" yang WAJIB
     tetap hijau setiap kali menambah route baru.
   - Sidebar menyembunyikan menu tanpa hak akses lewat partial
     `resources/views/layouts/partials/sidebar-akses.blade.php` (dipanggil
     dengan SATU baris dari `layouts/app.blade.php`, sengaja tidak mengubah
     547 baris layout). Penyembunyian ini hanya tampilan; pengamannya tetap
     AksesMiddleware di sisi server.
   - Kolom `users.email` dibuat nullable (akun teknisi tidak punya email);
     index unik `users_email_unique`, `users_username_unique`, dan
     `users_technician_id_unique` tetap dipertahankan.

13. **Mode hanya baca untuk akun non-administrator + relokasi menu sidebar
   (diputuskan 28/09/2026, sudah ada kodenya):**
   - `config('akses.role_penulis')` = `['admin']`: HANYA administrator yang
     boleh mengubah data. Semua akun lain (termasuk `supervisor` dan semua
     Bagian) hanya bisa membaca; permintaan POST/PUT/PATCH/DELETE ditolak
     `App\Http\Middleware\HanyaBacaMiddleware` (alias `hanya_baca`, dipasang
     pada grup route bersama `admin` dan `akses`). Route yang dikecualikan
     (mis. `logout` dan penanda notifikasi dibaca) ada di
     `config('akses.route_hanya_baca_bebas')`. Tambahkan `supervisor` ke
     `role_penulis` kalau supervisor juga perlu menulis — hanya itu yang
     perlu diubah.
   - Tampilan akun hanya baca: partial
     `resources/views/layouts/partials/mode-hanya-baca.blade.php` (dipanggil
     SATU baris sebelum `<main>` di `layouts/app.blade.php`) menampilkan
     penanda "Mode hanya baca" dan menyembunyikan form non-GET, tautan
     tambah/ubah/impor, dan tombol modal "Tambah...". Penyembunyian ini hanya
     tampilan; pengamannya tetap middleware.
   - **Relokasi menu sidebar JANGAN dilakukan dengan menulis ulang
     `layouts/app.blade.php` (547 baris — lihat riwayat insiden #3/#10/#12).**
     Pola yang dipakai: render menu di partial + buang tautan lama dengan
     skrip. Kolaborasi dirender `partials/sidebar-menu-utama.blade.php`
     (di-include setelah menu Laporan), Manajemen User + Hak Akses Bagian +
     Storage dirender `partials/sidebar-pengaturan.blade.php` (di-include
     sebelum judul "Sistem"), lalu `partials/sidebar-akses.blade.php`
     membuang tautan Storage/Kolaborasi posisi lama dan judul grup "Sistem"
     yang jadi kosong. Tautan baru diberi kelas `menu-pindahan` supaya tidak
     ikut terbuang. Ikuti pola ini untuk pemindahan menu berikutnya.
   - Ikon menu Kolaborasi memakai ikon "share" supaya tidak sama dengan menu
     Teknisi; Manajemen User memakai ikon kunci, Hak Akses memakai ikon
     perisai.

14. **Step Temuan (finding) + format daftar alat (diputuskan 01/10/2026;
   URUTANNYA DIBALIK 06/10/2026 atas instruksi user — jangan diubah lagi
   tanpa instruksi user):**
   - Wizard Telegram sekarang menjalankan **temuan lebih dulu (Step 5a) baru
     root cause (Step 5b)**: `ReportWizardService::STEP_FINDING` +
     `buildFindingPrompt()`, `handleFindingInput()`, `lewatiTemuan()` di
     `WizardStepHandlerTrait`. Alurnya: durasi (Step 4) -> TEMUAN (5a) ->
     ROOT CAUSE (5b) -> foto dokumentasi (Step 6). Jadi `duration_ok` menuju
     STEP_FINDING, temuan (teks atau tombol lewati) menuju STEP_ROOT_CAUSE,
     dan root cause (teks, tombol lewati, atau `rootcause_ok` dari deteksi AI)
     baru menuju STEP_PHOTO_DOCUMENTATION. Urutan lama (root cause lalu
     temuan) JANGAN dihidupkan kembali tanpa instruksi user.
     Root cause DAN temuan sama-sama **opsional**: tombol `Lewati Temuan`
     (`wizard:confirm:finding_skip`) dan `Lewati Root Cause`
     (`wizard:confirm:rootcause_skip`); teks `-`, `skip`, `lewati`, `tidak`,
     `tidak ada` juga diperlakukan sebagai lewati
     (`teksMenandakanLewati()`).
   - Uji penjaga urutan: `tests/Feature/ReportFindingStepTest.php` (18 test).
     Uji mutasi 06/10/2026: mengembalikan transisi durasi ke STEP_ROOT_CAUSE
     dan transisi temuan ke STEP_PHOTO_DOCUMENTATION membuat 3 test GAGAL.
   - Temuan disimpan di kolom BARU **`reports.finding`** (text nullable;
     migration `2026_10_01_000001_add_finding_to_reports_table.php`) dan ikut
     tersalin ke baris salinan multi alat karena dikirim lewat `$dataLaporan`
     di `WizardReportSaverTrait::saveReport()`. `root_cause` di titik itu
     memakai `?? null` supaya laporan tanpa root cause tetap bisa disimpan.
   - Form web laporan memakai partial agar blade besar tidak perlu ditulis
     ulang: `resources/views/reports/partials/_temuan.blade.php` (create/edit)
     dan `_temuan-kartu.blade.php` (detail laporan), masing-masing dipanggil
     SATU baris dari `create.blade.php`, `edit.blade.php`, `show.blade.php`.
   - Daftar alat pada prompt Step 8b dan di SEMUA layar hirarki mode multi
     memakai satu helper: `WizardUtilityTrait::daftarEquipmentState()` →
     blok `*Equipment :*` (alat utama: `KODE — Deskripsi`) lalu
     `*Equipment Tambahan :*` bernomor `1. KODE — Deskripsi`. State
     `equipment_desc` diisi saat alat dikunci (`lockEquipmentAndAdvance()`,
     `finalizeClarificationSelection()`, `startWizardForAsset()`) dengan
     fallback `Asset::find()` di `equipmentDesc()`.
   - **Label tombol daftar alat = kode alat + deskripsi SAJA**
     (`WizardUtilityTrait::tombolAlatLabel()`, dipotong 64 karakter). Kode
     Functional Location SENGAJA tidak disertakan: pada data DEV 1.185 dari
     4.252 label terpotong karena batas 64 karakter tombol Telegram (tanpa
     kode lokasi hanya 39) sehingga deskripsi alat terpotong. Jangan
     menambahkan `functional_loc` kembali ke label tombol alat, dan jangan
     menyalin logika label ini — `ClarificationKeyboardBuilderTrait` punya
     duplikat kecil (`labelTombolAlat()`) karena dipakai ClarificationService,
     bukan ReportWizardService.
   - Uji: `tests/Feature/ReportFindingStepTest.php` (14 test). Uji mutasi sudah
     dijalankan: menetralkan (a) penyimpanan temuan, (b) urutan blok daftar
     alat, (c) penghapusan kode funcloc pada label kandidat membuat 3 test
     GAGAL.

15. **Perbaikan deskripsi aset dari berkas "Master List" (diputuskan 03/10/2026,
   sudah ada kodenya):**
   - Perkakas: `php artisan assets:perbarui-deskripsi {berkas} {--sheet=}
     {--pt=} {--kode-saja} {--perbarui-kode-alat} {--laporan=} {--execute}` di
     `app\Console\Commands\AssetUpdateDescriptionCommand.php`. Kolom berkas
     dikenali dari BARIS JUDUL ("Kode Alat" dan "Description"), bukan dari
     urutan kolom, supaya revisi berkas berikutnya boleh menggeser posisi
     kolom. Tanpa `--execute` hanya simulasi; `--laporan=<path>` menulis CSV
     berisi SELURUH baris yang dibaca beserta statusnya (diperbarui / sama /
     ambigu / tidak_ketemu / kode_kosong / gagal) ditambah kolom
     `kode_alat_baru` (Kode Alat sesudah disamakan).
   - Aturan pencocokan: KODE ALAT = `assets.tech_ident_no` (huruf besar, spasi
     tepi dibuang). Bila satu Kode Alat dipakai lebih dari satu baris DI DALAM
     berkas, atau lebih dari satu aset di database, Equipment No. dipakai
     sebagai PEMUTUS (kolom `assets.equipment_no` berindeks unik). Bila Kode
     Alat di berkas tidak ada di database, baris dicocokkan lewat Equipment No.
     sebagai CADANGAN; opsi `--kode-saja` mematikan cadangan itu sehingga hanya
     Kode Alat persis yang dihitung. Pencocokan longgar lewat AKHIRAN kode alat
     DILARANG: "ESKALCY01" dan "MALCY01" sama-sama berakhiran "ALCY01" padahal
     dua mesin berbeda di lokasi yang sama.
   - Opsi `--perbarui-kode-alat` (dibuat 03/10/2026) menyamakan kolom
     `assets.tech_ident_no` dengan tulisan Kode Alat di berkas (database
     "ESKALCY01" menjadi "ALCY01"). Perubahan DIBATALKAN bila kode tujuan sudah
     dipakai aset LAIN di PT yang sama, karena `tech_ident_no` TIDAK berindeks
     unik (`database\migrations\0001_01_01_000007_create_assets_table.php` baris
     15) sehingga dua aset bisa berkode sama dan pencarian alat jadi ambigu.
     Simulasi DAN eksekusi 03/10/2026 pada database PROD `oleochemical`: 27 Kode
     Alat disamakan (aset id 3724-3751 selain 3740; mis. "ESKALCY01" -> "ALCY01",
     "ESKH05A" -> "SVH05A"), 1 dibatalkan (aset id 3740 "ESKSC04" tetap apa
     adanya karena "SC04" sudah dipakai aset id 3895), 0 gagal; total aset tetap
     4253 dan Kode Alat ganda di PT NPA tetap NIHIL. Bukti: simulasi
     `C:\laragon\backup-oleochemical\simulasi-kode-alat-npa.csv`, hasil eksekusi
     `C:\laragon\backup-oleochemical\hasil-samakan-kode-alat-npa.csv`, backup
     tabel `assets` sebelum menulis
     `C:\laragon\backup-oleochemical\assets-oleochemical-sebelum-samakan-kode-20261003-143341.sql`.
     Jebakan yang WAJIB diingat: `petaAset()` harus ikut membaca kolom
     `company_id`, kalau tidak `$aset->company_id` null dan penjaga bentrok
     tidak pernah aktif (bug ini nyata terjadi dan ketahuan dari test, bukan
     dari peninjauan kode). Database DEV `oleochemical_dev` BELUM dijalankan
     (masih 28 aset berawalan "ESK", id 3724-3751) - penyeragaman di DEV
     menunggu keputusan user.
   - Bukti pemakaian pertama: berkas `Master List NPA rev 2.xlsx` (287 baris)
     dipakai 03/10/2026 untuk PT NPA di database PROD `oleochemical`: 256 baris
     cocok lewat Kode Alat + 31 baris lewat Equipment No. (kode di berkas
     ditulis tanpa awalan "ESK"/"M"), 287 deskripsi diperbarui, 0 gagal.
     Berkas itu juga memuat Kode Alat ganda: "SC04" dipakai dua mesin berbeda
     (baris 66 = MP01-7831/70000101, baris 221 = RS01-7851/70000247) dan
     "AGT02A"/"AGT02B" masing-masing dua baris — inilah alasan pemutus
     Equipment No. WAJIB ada, bukan pilihan.
   - Cakupan berkas rev 2 DIPERIKSA 03/10/2026 memakai skrip sementara yang
     membandingkan laporan CSV hasil eksekusi dengan seluruh aset
     `assets.company_id` PT NPA (440 aset) di database PROD `oleochemical`:
     285 aset dikenali berkas, **155 aset TIDAK ada di berkas** — 86 aset
     pabrik/operasional (Line 2: `NPA-PROD-RS02-7852` 62, `MP02-7832` 12,
     `CAOT-7822` 8, forklift `WH01-7052` 4; plus 1 aset tanpa Kode Alat:
     id 4027 "Valve main line PFAD") dan 69 perangkat ICT (CCTV 35,
     workstation `WS-NPA-*` 13, radio 7, switch 6, card/face reader 5,
     SCADA 2, tablet 1). Artinya berkas rev 2 hanya mencakup Line 1; aset
     Line 2 sengaja dibiarkan (deskripsi lamanya TIDAK ikut diperbarui).
     Daftar lengkap: `C:\laragon\backup-oleochemical\daftar-aset-npa-luar-berkas-rev2-20261003.csv`
     (semua baris berkas + statusnya juga tersimpan di
     `daftar-selisih-npa-20261003.txt`, `daftar-kode-cocok-lewat-equipment-no-20261003.csv`,
     dan `daftar-baris-berkas-tanpa-aset-20261003.csv` — berkas terakhir KOSONG,
     bukti tidak ada baris berkas yang gagal ditemukan asetnya).
   - PENGINGAT: `app\Services\ImportService.php` mencocokkan aset lewat Tech
     Ident No dan mengambil `description` dari kolom Description berkas. Import
     ulang berkas master SAP yang kolom Description-nya masih berisi nama plant
     section (BLENDING, REACTIONSYSTEM, AIRSUPPLYSYSTEM, dst.) akan
     MENGEMBALIKAN deskripsi lama — perbaiki berkas sumbernya lebih dulu bila
     import ulang akan dilakukan.
   - Uji: `tests/Feature/AssetUpdateDescriptionCommandTest.php` (16 test; uji
     mutasi: memaksa penulisan saat mode simulasi membuat 1 test GAGAL,
     menetralkan penjaga bentrok membuat test "Kode Alat dibatalkan bila kode
     itu sudah dipakai aset lain" GAGAL, dan mengabaikan opsi
     `--perbarui-kode-alat` membuat test "tanpa opsi perbarui-kode-alat Kode
     Alat di database tidak diubah" GAGAL). Seluruh suite 305 test hijau
     (03/10/2026).

16. **Batas diam (idle) sesi wizard Telegram (diputuskan 04/10/2026, sudah ada
   kodenya):**
   - Latar: kejadian nyata 04/10/2026 — state wizard milik Vicky (chat
     `8827242872`, teknisi id 6) MENGANGGUR di step `work_classification` sejak
     pukul 10:01 padahal sudah hampir 2 jam, dan Dian Adi Kurniawan (chat
     `6146322700`, teknisi id 32) mengetik deskripsi pekerjaan yang sama tiga
     kali karena teks barunya selalu dibaca sebagai jawaban langkah LAMA. Batas
     keras `ReportWizardService::CACHE_TTL` = 7200 detik (2 jam) tidak pernah
     memeriksa apakah teknisi masih menjawab. (Identitas kedua teknisi
     diverifikasi langsung di database DEV `oleochemical_dev` tabel
     `technicians`, 04/10/2026.)
   - Aturan yang dipilih user: sesi yang menganggur melewati
     `config('cm.wizard.idle_timeout_menit')` (bawaan **10 menit**) dibatalkan
     otomatis **HANYA saat pesan TEKS baru datang**. Foto dan tombol SENGAJA
     TIDAK membatalkan apa pun supaya progres teknisi yang kembali dari
     lapangan (mengirim foto dokumentasi/hygiene terlambat) tidak hilang;
     keduanya tetap tunduk pada batas keras 2 jam. Isi 0 = mematikan
     pembatalan otomatis.
   - Wujud kode: `ReportWizardService::batasIdleMenit()`,
     `sesiSudahBasi(array $state)` (acuan `last_activity_at`, cadangan
     `created_at`; state tanpa kedua penanda dianggap BELUM basi demi
     melindungi sesi yang sedang berjalan saat fitur ini dipasang), dan
     `batalkanSesiBasi(string $chatId): bool` (menghapus state basi + mencatat
     log `WizardService: sesi basi dibatalkan otomatis`). `saveState()` mengisi
     `last_activity_at` pada setiap penyimpanan, dan `createInitialState()`
     ikut mengisinya. `hasActiveWizard()` TETAP memakai `Cache::has` tanpa
     pemeriksaan idle supaya jalur foto/tombol tidak berubah perilakunya.
     Pemanggil: `TelegramMessageHandlerTrait::processUpdate()` (tepat sebelum
     routing teks) yang juga mengirim pesan "Sesi laporan sebelumnya
     dibatalkan otomatis karena tidak ada jawaban lebih dari N menit.".
     Untuk tombol, pesan "Sesi laporan ini sudah tidak aktif..." yang sudah ada
     di `PollTelegramUpdates::processCallbackQuery()` tetap dipakai.
   - JANGAN memindahkan pemeriksaan idle ke `hasActiveWizard()` atau ke jalur
     foto (`handlePhotoMessage`/`handlePhotoInput`): akibatnya foto susulan
     memulai laporan BARU dan seluruh progres teknisi hilang — justru inilah
     alasan aturan "teks saja" dipilih.
   - Uji: `tests/Feature/ReportWizardIdleTimeoutTest.php` (9 test; uji mutasi:
     menetralkan pemeriksaan `sesiSudahBasi()` di `batalkanSesiBasi()` membuat
     3 test GAGAL, dan menghapus cadangan `created_at` membuat test "sesi tanpa
     penanda aktivitas memakai created_at sebagai cadangan" GAGAL). Seluruh
     suite 314 test hijau (04/10/2026).
   - **Perintah teks `/reset` (ditambahkan 05/10/2026).** Tombol "Batalkan
     Laporan" menempel pada pesan wizard, sehingga ia ikut hilang bila layar
     lama digambar ulang atau pesannya sudah tergulung jauh di riwayat chat —
     teknisi jadi tidak punya jalan keluar selain menunggu sesi basi
     dibatalkan otomatis. Karena itu `TelegramMessageHandlerTrait::processUpdate()`
     mengenali `/reset` (termasuk bentuk `/reset@namabot`) dan memanggil
     `handleResetCommand()` yang membuang state lewat
     `ReportWizardService::destroyWizard()` lalu membalas "Sesi laporan
     dibatalkan."; pesan berikutnya diproses sebagai laporan BARU, bukan
     jawaban langkah lama. Tidak ada laporan setengah jalan yang tersimpan
     karena laporan dibuat hanya setelah konfirmasi di Step 8. Letak
     pemeriksaannya SETELAH validasi teknisi aktif (bersama `/info`), dan
     hanya membuang sesi wizard chat pengirim. Uji:
     `tests/Feature/TelegramResetCommandTest.php` (4 test; uji mutasi:
     mematikan routing `/reset` membuat keempat test GAGAL). Seluruh suite 318
     test hijau (05/10/2026).

17. **Kecocokan daftar Laporan dengan Rincian Man Hour Harian (diputuskan
   05/10/2026, sudah ada kodenya):**
   - Keluhan asal (05/10/2026): "di bagian Rincian Man Hour Harian saat diklik
     harinya seharusnya menampilkan laporan kolaborasi juga". Penyebabnya:
     baris harian itu menaut ke
     `reports.index?technician_id=..&tanggal=..`, sedangkan
     `ReportController::buildBaseQuery()` SELALU membuang baris kolaborasi
     (`whereNull('collaborator_of')`) padahal `ManHourService::harian()`
     MENGHITUNG baris itu sebagai pekerjaan teknisi kolaborator — jadi kolom
     "Pekerjaan"/"Realisasi" terisi tetapi daftar yang dibuka kosong.
   - Aturan yang dipakai sekarang (**Opsi A, dipilih user**): salinan kolaborasi
     (`collaborator_of` terisi) ikut tampil HANYA bila daftar disaring ke satu
     teknisi (`technician_id` terisi). Daftar umum tetap konsolidasi (hanya
     laporan induk + keterangan "Berkolaborasi dengan: ...") supaya satu
     pekerjaan tidak berulang untuk tiap peserta. Salinan multi alat
     (`multi_of`) TIDAK PERNAH tampil di daftar, sama seperti di man hour.
     Jangan ubah definisi ini tanpa instruksi user (pelajaran insiden #8).
   - Bukti data DEV `oleochemical_dev` (05/10/2026): 129 pasangan
     teknisi-tanggal punya laporan kolaborasi; aturan lama cocok=0/beda=129,
     aturan baru cocok=129/beda=0 (skrip sementara `tmp-cek-kolaborasi.php`,
     dihapus lagi setelah dipakai, tidak masuk repo).
   - Uji: `tests/Feature/ReportTeknisiKolaborasiTest.php` (4 test). Uji mutasi:
     memaksa konsolidasi (selalu `whereNull('collaborator_of')`) membuat 2 test
     GAGAL; membalik pengecualian (konsolidasi hanya saat ada filter teknisi)
     membuat 3 test GAGAL. Test lama
     `tests/Feature/ReportIndexViewTest.php` "laporan kolaborasi tampil satu
     baris dengan daftar kolaborator" tetap hijau dan menjaga jalur
     konsolidasi. Seluruh suite 322 test hijau (05/10/2026).
   - CATATAN BELUM DIKERJAKAN (perlu keputusan user sebelum diubah): tautan
     "N laporan" di `resources/views/technicians/show.blade.php` baris 340-342
     TIDAK mengirim parameter `tampilan`, sehingga daftar selalu dibuka pada
     tab Reguler. Untuk teknisi pekerja SHIFT (grub A-D) laporannya bisa tetap
     kosong di sana karena `applyGroupFilter('reguler')` hanya memuat grup
     non-shift.
   - Lencana kolaborasi di halaman detail teknisi (05/10/2026): baris salinan
     kolaborasi pada tabel "Pekerjaan Harian"
     (`resources/views/technicians/show.blade.php`) menampilkan
     `KOLABORASI : <nama pembuat>` lewat accessor `Report::nama_pembuat`
     (dibaca dari `creator_id`; data lama yang `creator_id`-nya kosong
     ditelusuri dari laporan induk -- creator, lalu teknisi pemilik induk --
     supaya kolaborator TIDAK pernah disebut sebagai pembuat). Karena accessor
     itu dipanggil per baris, `TechnicianController::show()` memuat relasi
     `creator` + `parentReport.creator` + `parentReport.technician` agar tidak
     terjadi query berulang (N+1). Bukti data DEV `oleochemical_dev`
     (05/10/2026): 254 salinan kolaborasi, `creator_id` terisi semua (0 NULL).
     Uji: `tests/Feature/TechnicianKolaborasiBadgeTest.php` (4 test; uji
     mutasi: mengembalikan lencana menjadi "KOLABORASI" saja membuat 2 test
     GAGAL, dan memakai teknisi pemilik baris salinan sebagai cadangan membuat
     1 test GAGAL). Seluruh suite 376 test hijau (1643 assertions, 05/10/2026).
