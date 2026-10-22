# Keputusan Arsitektur 1-10

> Bagian dari dokumentasi proyek di folder `docs`.
> Peta dokumen: `AGENTS.md` di akar repo.

## KEPUTUSAN ARSITEKTUR FINAL (JANGAN TANYA ULANG, SUDAH DIPUTUSKAN)

1. Modul utama: **Condition Monitoring (CM)** — singkatan "CM" di project ini
   berarti **Condition Monitoring**, BUKAN Corrective Maintenance. Kalau ada
   kebutuhan mencatat pekerjaan perbaikan (CM/dCM/PM dalam arti maintenance),
   itu masuk konsep **"Corrective Maintenance"** yang harus ditulis lengkap
   (jangan disingkat "CM" juga) supaya tidak bentrok istilah dengan modul
   Condition Monitoring yang sudah ada.
2. Struktur data lokasi: **PT Location → Plant → Equipment Tag**. Project ini
   TIDAK punya konsep Area/Functional Location/TechIdentNo seperti project
   lain (mss-project) — jangan bawa konsep itu ke sini.
3. Equipment/reading (sesuaikan/cek ulang nama pasti via `php artisan
   model:show` — daftar berikut adalah nama yang PERNAH dipakai, WAJIB
   diverifikasi ulang sebelum dipakai lagi karena ada indikasi inkonsistensi
   penamaan antar sesi sebelumnya):
   - Equipment: tabel `cm_equipment`, kolom `equipment_tag`, `pt_location`,
     `plant`.
   - Reading: tabel `cm_readings`, kolom yang PERNAH disebut: `kondisi`
     (status ALARM/DANGER/GOOD/VISUAL BAD), `tanggal`, `max_vibration`,
     `max_temp`, `analysis`, `cm_equipment_id`. **VERIFIKASI ULANG nama-nama
     ini via `php artisan model:show CmReading` sebelum menulis query baru
     — pernah ada dugaan campur aduk dengan nama alternatif seperti
     `status_condition`/`date`, belum dipastikan mana yang benar-benar dipakai
     di database aktual.**
4. Sheet sumber data Excel (`Data_CM.xlsx`) — 5 sheet dengan tujuan berbeda:
   - `Data AppSheet` — data mentah per titik ukur, SUMBER UTAMA (bukan
     formula, angka asli).
   - `Status CM` — HASIL FORMULA (VLOOKUP + MAX + IF) yang merujuk ke
     `Data AppSheet`. Formula ini PUNYA cached value tersimpan di file
     (terverifikasi bisa dibaca dengan `setReadDataOnly(true)` di
     PhpSpreadsheet) — TIDAK PERLU dihitung ulang manual di PHP kecuali
     terbukti (lewat test terisolasi) cached value-nya benar-benar tidak
     terbaca.
   - `Master Equipment` — referensi daftar equipment per PT/Plant sekaligus
     SUMBER KEBENARAN daftar equipment yang dimonitoring. Setiap import/sync
     (`ProcessCmExcelImport` untuk upload & Google Sheets, serta
     `cm:import-readings`) memanggil `CmMasterEquipmentSyncService`: tag di
     master dibuat/diperbarui (`pt_location`, `plant`, `in_master=true`,
     `is_active=true`); tag yang tadinya ada di master lalu hilang dari sheet
     dinonaktifkan (`is_active=false`, histori reading/finding TETAP ada).
     Equipment yang dibuat otomatis dari `Data AppSheet`/Finding CM tetapi
     belum ada di master ditandai `in_master=false` dan TIDAK ikut
     dinonaktifkan. Perbandingan tag case-insensitive + spasi dirapikan
     (`SC-Rework A` = `SC-REWORK A`). Bila baris master < 10 (sheet sedang
     diisi/terpotong), deaktivasi dilewati. Sheet `Monitoring Bulanan` BUKAN
     patokan deaktivasi — isinya bisa lebih sedikit dari master (21/10/2026:
     `7822FN01` & `6400PD-2` tidak ada di sana padahal masih punya reading
     terbaru). Semua daftar/hitungan halaman CM memakai scope
     `CmEquipment::active()`.
   - `Monitoring Bulanan` — compliance tracking (Sudah/Belum) per equipment
     per bulan, header 3 baris dengan merged cells.
   - `Tabel Mon. Bulanan` — rekap agregat, bisa dihitung ulang otomatis dari
     sheet lain, tidak wajib diimport manual.
5. Threshold vibrasi tinggi: **> 4.5 mm/s**, WAJIB disimpan sebagai config
   (`config('cm.high_vibration.threshold')`), jangan hardcode di banyak
   tempat — nilai ini bisa direvisi sesuai ISO 10816 atau kebijakan internal.
6. Definisi bisnis untuk section **"Equipment Vibrasi Tinggi per PT"**
   (breakdown per kategori Analysis, card per PT) — **SUDAH DIPUTUSKAN,
   JANGAN DIUBAH TANPA INSTRUKSI EKSPLISIT USER**:
   - Pakai **LATEST READING PER EQUIPMENT** saja (1 entry per equipment,
     mewakili status TERKINI).
   - Latest = GOOD/normal → equipment TIDAK ditampilkan sama sekali.
   - Latest = ALARM/DANGER → tampilkan status TERBARU itu saja. Histori
     lama (status sebelumnya yang sudah "kalah" oleh reading lebih baru)
     TIDAK ikut ditampilkan.
   - Section **"Ranking Analisa Bulanan"** (tabel trend per bulan) —
     kemungkinan besar TETAP pakai SEMUA histori kejadian (bukan
     latest-only), supaya trend dari bulan ke bulan tetap valid untuk
     analisa historis. **INI HARUS DIKONFIRMASI ULANG ke user sebelum
     implementasi apapun yang menyentuh section ini — jangan diasumsikan.**
7. Tema visual WAJIB: warna aksen **teal `#0E9E8E`**. Vanilla JS (BUKAN
   Alpine.js `x-data`). Sidebar dua level: grup "MENU UTAMA" dan grup
   "RELIABILITY & MAINTENANCE" (berisi: Condition Monitoring, Riwayat
   Import, Reliability Dashboard).
8. Import Excel jalan sebagai **queued job** (`ProcessCmExcelImport`), BUKAN
   sinkron di request HTTP. Baca file pakai PhpSpreadsheet dengan
   `setReadDataOnly(true)` + `setLoadSheetsOnly()` per sheet (load 1 sheet
   per waktu, bukan 5 sheet sekaligus), `unset()` + `gc_collect_cycles()`
   setelah tiap sheet selesai diproses.
9. Setiap baris Excel yang diproses saat import WAJIB berakhir di salah
   satu kategori tercatat di `ImportLog`: insert / update / gagal /
   skip-duplikat / skip-kosong / unregistered. Total baris dibaca HARUS
   SAMA DENGAN jumlah semua kategori tersebut — log warning kalau tidak
   balance.

10. **Jadwal shift & man hour teknisi (sudah diputuskan 26/09/2026):**
   - Sumber: file Excel "Schedule Shift MTC" per periode **26-25 tiap bulan**.
     Blok `NAME STAF`/`NAME INTERNAL`/`NAME OS` = reguler, blok `NAME SHIFT` =
     Group A/B/C/D dengan kode `x` = OFF, `1` = 08.00-16.00,
     `2` = 16.00-24.00, `3` = 00.00-08.00.
   - Import lewat tombol di halaman Teknisi (POST `technicians.shift-schedule`)
     atau `php artisan shifts:import <file> [--execute]`; riwayat di tabel
     `shift_schedules`, detail harian di `technician_shifts`. Pencocokan
     teknisi memakai NIK dulu, baru nama (dicatat sebagai "dicocokkan lewat
     nama"); yang tetap tidak ketemu tetap tersimpan dan dilaporkan, tidak
     ditolak.
   - Target man hour: **480 menit per hari kerja** (`config('cm.man_hour')`),
     hari OFF target 0, Minggu target 0 untuk teknisi reguler. Realisasi =
     jumlah `work_duration_minutes` laporan milik teknisi (`technician_id`)
     pada tanggal itu; **baris salinan multi alat (`multi_of`) TIDAK dihitung**
     supaya satu pekerjaan tidak terhitung berkali-kali.
   - Anomali yang ditandai: bekerja pada hari OFF dan laporan yang
     `start_time`-nya di luar jendela shift hari itu (toleransi 60 menit).
   - Rentang tanggal di halaman Teknisi = periode jadwal terakhir (fallback:
     bulan berjalan). Perubahan aturan ini wajib dikonfirmasi ulang ke user.
   - **Pola rotasi (dikonfirmasi 26/09/2026):** semua group memakai siklus 20
     hari yang SAMA, hanya fasenya berbeda:
     `5 hari Shift 1 -> 2 OFF -> 5 hari Shift 3 -> 2 OFF -> 5 hari Shift 2 -> 1 OFF`.
     Rumus: `kode = siklus[(jarak_hari(tanggal, acuan) + offset[group]) mod 20]`
     dengan acuan `2026-06-26` dan offset A=2, B=12, C=17, D=7 (semuanya di
     `config('cm.shift_pattern')`). Verifikasi: 976 dari 976 hari-orang pada 4
     file jadwal (26 Juni - 25 Oktober 2026) cocok tanpa selisih. Karena itu
     jadwal periode berikutnya TIDAK perlu diupload: sistem memproyeksi sendiri
     (`ShiftPatternService`), dan bisa dipermanenkan lewat
     `php artisan shifts:generate --execute` atau tombol "Buat Jadwal Otomatis"
     di halaman Teknisi. Baris hasil upload selalu menang atas hasil proyeksi
     (untuk koreksi manual).
   - **Group non-shift ada dua (26/09/2026):**
     `reguler` = Senin-Kamis 08.00-16.00, Jumat 08.00-16.00 dengan istirahat
     2 jam (11.00-13.00), Sabtu 08.00-14.00, Minggu libur; istirahat hari biasa
     12.00-13.00.
     `reguler_staff` = Senin-Jumat 08.00-17.00, Jumat istirahat 2 jam,
     Sabtu/Minggu libur.
     Target harian = (jam selesai - jam mulai) dikurangi istirahat, hasilnya:
     reguler 420/420/420/420/360/300/0 menit dan reguler staff
     480/480/480/480/420/0/0 menit (Senin s.d. Minggu) — lihat
     `config('cm.man_hour.jam_kerja')`.
     Pekerja shift **tanpa potong istirahat** (8 jam penuh = 480 menit per hari
     kerja, dikonfirmasi 26/09/2026), jadi total mingguannya lebih besar
     (±42 jam) bukan karena salah hitung: reguler/reguler staff 39 jam/minggu,
     sedangkan pekerja shift rata-rata 15 hari kerja per 20 hari (5,25 hari/minggu).
