# Rencana Pengembangan — Modul IT Assets
### Sumber data: `List Perangkat IT Oleochemicals.xlsx`

Dokumen ini adalah panduan pengerjaan bertahap. Setiap sesi dirancang cukup kecil
agar bisa dikerjakan sampai tuntas oleh Claude paket free (scope terbatas, bisa
diverifikasi di akhir sesi, tidak lompat-lompat antar file).

## Status Pengerjaan (terverifikasi 27/08/2026)

| Sesi | Fokus | Status |
|---|---|---|
| 1 | Migration + Model | Selesai, migration sudah dijalankan |
| 2 | Import Service: PC / Laptop / Printer | Selesai, 156 record ter-import |
| 3 | Import Service: CCTV / NVR / Access Door / WiFi / Network / UPS | Selesai |
| 4 | Import UI: Controller + Routes + Menu | Selesai (route `it-assets.show`, `.snapshot`, `.snapshot-settings` ditambahkan saat audit) |
| 5 | Daftar IT Assets: Index + Filter + Badge Ping | Selesai (tombol Detail sudah aktif) |
| 6 | Fitur Ping | Selesai, teruji offline/online |
| 7 | Detail + CCTV Snapshot | Selesai (disk `cctv_snapshots` ditambahkan, migration kolom snapshot dijalankan) |
| 8 | Input Manual + Edit/Hapus | Selesai, form tambah/edit + hapus aktif |
| 9 | Notifikasi dalam aplikasi | Selesai, badge + halaman notifikasi (offline, ping tinggi, kembali online, error snapshot) |
| 10 | Ping otomatis terjadwal | Selesai, command `it:ping` terjadwal tiap 5 menit |
| 11 | Snapshot berkala CCTV/NVR | Selesai, command `it:snapshot` terjadwal tiap 60 menit |

Catatan audit:

- `config/filesystems.php`: disk `cctv_snapshots` sudah ditambahkan (root `storage/app/public`, url `/storage`).
- Migration `2026_08_27_000001_add_snapshot_credentials_to_it_assets_table` sudah dijalankan.
- `snapshot_password` disimpan terenkripsi (cast `encrypted`), tidak pernah plaintext.
- Sisa pekerjaan opsional: mengisi `snapshot_url` + kredensial kamera per perangkat CCTV/NVR lewat halaman detail.

---

## 1. Tujuan

Membangun modul IT Assets yang terpisah dari Asset Management (equipment industri),
dengan kemampuan:

1. Menyimpan data perangkat IT lengkap beserta pemetaan IP.
2. Ping untuk perangkat yang aktif (cek online/offline + latency).
3. CCTV (Hikvision) dengan tangkapan gambar terakhir (snapshot).
4. Import dari file `List Perangkat IT Oleochemicals.xlsx` (15 sheet).

---

## 2. Ringkasan Sumber Data (sudah dibaca dari file Excel)

File berisi 15 sheet. Berikut hasil pembacaan struktur:

| # | Sheet | Rows | Kolom penting |
|---|-------|------|----------------|
| 1 | PC | 80 | No, PT, Dept, Jabatan, Name, Tag-Name, Type Monitor, S/N Monitor, Type PC, S/N PC, IP, OS, Type Access, Antivirus, JCloud, Mail, Remote, Nas, CPU, RAM, Type RAM, Slot RAM, Bandwidth RAM, TIPE DISK, Kapasitas |
| 2 | Cek MIcrosoft | 80 | No, PT, Dept, Jabatan, Name, Tag-Name, IP, Pengecekan Ms.Office |
| 3 | Antivirus PC | 75 | No, PT, Dept, Rename, Nama Device, Windows, RAM, TIPE RAM, DISK, TIPE DISK, LAGING, Antivirus, JCloud, RustDesk, AnyDesk, IP, Remote |
| 4 | LAPTOP | 1000 | No, LAPTOP, USERS, S/N, IP, Letak, Lokasi, Mail |
| 5 | PRINTER | 984 | No, PRINTER, MILIK, S/N, IP, IP HOST, LETAK, LOKASI |
| 6 | Sheet23 | 1 | (kosong, diabaikan) |
| 7 | CCTV | 1000 | No, Tag-Name, Header, Brand, S/N, Model, Plant, Location, Application, Connect To, IP Address, PIC, NVR, Chanel |
| 8 | Print CCTV | 999 | mirip sheet CCTV (versi cetak, diabaikan jika duplikat) |
| 9 | WIFI | 14 | perangkat WiFi (IP, model, lokasi) |
| 10 | NVR | 11 | NVR (Tag-Name, Brand, Model, IP, Channel) |
| 11 | UPS | 993 | Penggunaan, Header, PT, Dept, Location, Brand & Model, S/N, Capacity |
| 12 | Access Door & Absensi | 18 | PT, Tag-Name, Location, Hardware, S/N, Type, IP |
| 13 | MODUL JARINGAN | 1012 | No, Lokasi, Perangkat, Location, Model, IP |
| 14 | BARANG DAN ALAT | 15 | No, Nama, Kondisi, Brand, Type, Jumlah (multi-kolom berulang) |
| 15 | CCTV APPROVED | 986 | No, Tag Name, Func Lock, Brand, Type, Plant, Location, Application, Connect to, IP, PIC, Remarks, Status, Header |

Catatan penting hasil pembacaan:

- `CCTV APPROVED` (986 baris) adalah daftar CCTV final/approved. Pakai sebagai sumber
  utama CCTV. Sheet `CCTV` dan `Print CCTV` cenderung duplikat/mentah.
- `Cek Microsoft` dan `Antivirus PC` adalah pemeriksaan pada PC (bukan perangkat baru).
  Di sesi pertama, datanya digabung ke spesifikasi PC atau diabaikan dulu.
- Kolom `IP` tersebar di hampir semua sheet, menjadi fondasi fitur Ping.
- CCTV & Access Door dominan Hikvision (Brand = Hikvision/HIK).

---

## 3. Keputusan Arsitektur (final, jangan diubah tanpa instruksi)

1. Tabel baru `it_assets`, terpisah dari tabel `assets` (equipment industri).
2. Tabel `it_ping_logs` untuk riwayat ping per perangkat.
3. `device_type` sebagai kategori utama: `PC`, `LAPTOP`, `PRINTER`, `CCTV`, `NVR`,
   `ACCESS_DOOR`, `WIFI`, `NETWORK`, `UPS`, `OTHER`.
4. Spesifikasi beragam per tipe (CPU/RAM/Disk/OS untuk PC, Channel/NVR untuk CCTV,
   Capacity untuk UPS, dst.) disimpan di kolom JSON `spec`.
5. IP disimpan di kolom `ip_address` (string, indexed), dasar pemetaan IP + ping.
6. Ping: ICMP via `exec("ping -n 1 -w 1000 <ip>")` (Windows), fallback HTTP check
   `fsockopen`. Hasil disimpan ke `it_assets.last_ping_*` dan log ke `it_ping_logs`.
7. CCTV snapshot (Hikvision): `GET http://{ip}/ISAPI/Streaming/channels/101/picture`
   dengan Basic Auth dari config (bukan hardcode). Simpan file lokal + path/waktu.
8. Import: sync seperti `ImportService` asset, pakai `setReadDataOnly(true)` +
   `setLoadSheetsOnly()` per sheet, `unset()` + `gc_collect_cycles()`, `memory_limit 512M`.
9. Tampilan mengikuti sidebar/layout existing (menu aktif `bg-blue-600`, Tailwind,
   Alpine.js + vanilla JS). Jangan membuat komponen setara yang sudah ada.

---

## 4. Pembagian Sesi

### Sesi 1 — Fondasi: Migration + Model
Tujuan: buat struktur database dan model dasar (belum ada tampilan/import).

File yang disentuh:
- `database/migrations/2026_08_25_000001_create_it_assets_table.php` (baru)
- `database/migrations/2026_08_25_000002_create_it_ping_logs_table.php` (baru)
- `app/Models/ItAsset.php` (baru)
- `app/Models/ItPingLog.php` (baru)

Langkah:
1. Migration `it_assets`: `id`, `device_type` (string, index), `tag_name` (nullable),
   `name` (nullable), `brand` (nullable), `model` (nullable), `serial_number` (nullable),
   `ip_address` (nullable, index), `plant` (nullable), `department` (nullable),
   `location` (nullable), `application` (text nullable), `status` (default `active`),
   `spec` (json nullable), `last_ping_status` (string nullable),
   `last_ping_latency` (int nullable), `last_ping_at` (timestamp nullable),
   `last_snapshot_path` (string nullable), `last_snapshot_at` (timestamp nullable),
   `data_source` (string default `import_excel`), `imported_at` (timestamp nullable),
   `timestamps`.
2. Migration `it_ping_logs`: `id`, `it_asset_id` (FK -> it_assets, cascade delete),
   `status` (string), `latency_ms` (int nullable), `checked_at` (timestamp), `timestamps`.
3. Model `ItAsset`: fillable sesuai kolom, cast `spec` => array, datetime casts,
   relasi `pingLogs`.
4. Model `ItPingLog`: fillable, cast `checked_at` => datetime, relasi `itAsset`.
5. Jalankan `php artisan migrate`.

Kriteria selesai: migrate sukses, `model:show ItAsset` menampilkan kolom sesuai,
`php -l` model lolos.

---

### Sesi 2 — Import Service bagian 1: Komputer/Office
Tujuan: parsing & import sheet komputer/office ke `it_assets`.

File yang disentuh:
- `app/Services/ItAssetImportService.php` (baru)

Langkah:
1. Buat `readExcel(UploadedFile): array` membaca semua sheet (`setReadDataOnly(true)`),
   return array per sheet.
2. Buat mapping header per sheet (normalisasi case/spasi).
3. Implement import 3 sheet dulu:
   - PC -> `device_type=PC`: `tag_name`=Tag-Name, `name`=Name, `plant`=PT,
     `department`=Dept, `brand`=Type PC, `serial_number`=S/N PC, `ip_address`=IP,
     `location`=Jabatan, `spec`={os, type_monitor, sn_monitor, cpu, ram, type_ram,
     slot_ram, bandwidth_ram, disk_type, disk_capacity, antivirus, jcloud, mail,
     remote, nas, type_access}.
   - LAPTOP -> `device_type=LAPTOP`: `tag_name`=LAPTOP, `name`=USERS,
     `serial_number`=S/N, `ip_address`=IP, `location`=Letak/Lokasi, `spec`={mail}.
   - PRINTER -> `device_type=PRINTER`: `tag_name`=PRINTER, `name`=MILIK,
     `serial_number`=S/N, `ip_address`=IP (atau IP HOST), `location`=LETAK/LOKASI.
4. `import(UploadedFile): array` menjalankan 3 sheet, return ringkasan
   (per sheet: total, inserted, skipped).
5. Baris kosong/IP kosong -> skipped (bukan error). Duplikat per `device_type` +
   `serial_number` (atau `tag_name`) -> update, bukan insert ganda.

Kriteria selesai: test terisolasi terhadap file Excel asli menghasilkan jumlah baris
masuk akal untuk PC/Laptop/Printer tanpa error.

---

### Sesi 3 — Import Service bagian 2: Infrastruktur
Tujuan: lanjutkan import untuk sheet infrastruktur.

File yang disentuh:
- `app/Services/ItAssetImportService.php` (tulis ulang lengkap)

Langkah:
1. Tambah mapping + import untuk:
   - CCTV APPROVED -> `device_type=CCTV`: `tag_name`=Tag Name, `brand`=Brand,
     `model`=Type, `plant`=Plant, `location`=Location, `application`=Application,
     `ip_address`=IP, `department`=Func Lock, `spec`={connect_to, pic, nvr, remarks, status}.
   - NVR -> `device_type=NVR`: Tag-Name, Brand, Model, IP, Channel -> spec.
   - ACCESS DOOR (Access Door & Absensi) -> `device_type=ACCESS_DOOR`:
     Tag-Name, Location, Hardware, S/N, Type, IP.
   - WIFI -> `device_type=WIFI`.
   - MODUL JARINGAN -> `device_type=NETWORK`: Lokasi, Perangkat, Model, IP.
   - UPS -> `device_type=UPS`: Brand & Model, S/N, Capacity, Location, PT, Dept.
2. `BARANG DAN ALAT` dan `Sheet23` diabaikan dulu (atau masuk `OTHER` bila mudah).
3. Pastikan tiap sheet memakai `setLoadSheetsOnly()` + `unset()` + `gc_collect_cycles()`.

Kriteria selesai: test import menghasilkan data CCTV/NVR/AccessDoor/Network/UPS tanpa
error; total row tercatat seimbang (dibaca = inserted + updated + skipped).

---

### Sesi 4 — Import UI: Controller + Routes + Menu
Tujuan: admin bisa upload Excel dari web dan menjalankan import.

File yang disentuh:
- `app/Http/Controllers/ItAssetController.php` (baru)
- `resources/views/it-assets/import.blade.php` (baru)
- `routes/web.php` (tambah routes)
- `resources/views/layouts/app.blade.php` (tambah menu sidebar)

Langkah:
1. Buat `ItAssetController`:
   - `index()` -> daftar (placeholder dulu, isi penuh di Sesi 5).
   - `showImport()` -> form upload.
   - `import()` -> terima UploadedFile, panggil `ItAssetImportService::import()`,
     redirect dengan flash hasil.
2. Tambah routes (WAJIB sebelum resource wildcard bila pakai resource):
   `GET /it-assets`, `GET /it-assets/import`, `POST /it-assets/import`.
3. Buat `import.blade.php` mengikuti gaya form existing (upload file + tombol submit).
4. Tambah menu sidebar "IT Assets" di grup menu yang sesuai (di bawah Asset Management).

Kriteria selesai: `route:list` menampilkan route baru; halaman import bisa diakses;
upload file asli menghasilkan redirect sukses dan data masuk DB.

---

### Sesi 5 — Daftar IT Assets: Index + Filter + Badge Ping
Tujuan: tampilkan daftar perangkat IT lengkap dengan IP dan status ping.

File yang disentuh:
- `app/Http/Controllers/ItAssetController.php` (tulis ulang, isi `index()`)
- `resources/views/it-assets/index.blade.php` (baru)

Langkah:
1. `index()`: query `ItAsset` dengan filter `device_type`, `search` (tag/name/IP),
   dan `status`. Paginate 20. Hitung ringkasan per `device_type` untuk summary card.
2. Buat `index.blade.php`:
   - Summary cards per device_type.
   - Tabel: Tipe, Tag/Name, Brand/Model, IP Address, Lokasi, Status Ping, Aksi.
   - Badge ping: online (hijau) / offline (merah) / belum dicek (abu).
   - Filter: device_type + pencarian teks.
3. Gunakan komponen/badge existing, jangan bikin setara baru.

Kriteria selesai: index menampilkan data import, filter berfungsi, badge ping tampil
(masih "belum dicek" karena fitur ping di Sesi 6).

---

### Sesi 6 — Fitur Ping
Tujuan: ping perangkat aktif dan tampilkan hasilnya.

File yang disentuh:
- `app/Services/ItPingService.php` (baru)
- `app/Http/Controllers/ItAssetController.php` (tambah endpoint ping)
- `resources/views/it-assets/index.blade.php` (tambah tombol + JS)
- `routes/web.php` (tambah route ping)

Langkah:
1. Buat `ItPingService`:
   - `ping(string $ip): array{status, latency_ms}`.
   - Pakai `exec("ping -n 1 -w 1000 ...")` deteksi `TTL=`; fallback `fsockopen`.
   - Validasi IP sebelum eksekusi shell.
2. Tambah `ItAssetController::ping(ItAsset $asset)` -> JSON `{status, latency}`.
   Simpan ke `it_assets.last_ping_*` + `ItPingLog::create`.
3. Tambah route `POST /it-assets/{itAsset}/ping`.
4. Di index, tambah tombol "Ping" per baris (AJAX via fetch, hasil inline).

Kriteria selesai: tombol ping menghasilkan respons online/offline, hasil tersimpan ke
`it_ping_logs`, dan badge di index ikut ter-update.

---

### Sesi 7 — Detail + CCTV Snapshot
Tujuan: halaman detail perangkat + snapshot CCTV terakhir.

File yang disentuh:
- `app/Http/Controllers/ItAssetController.php` (tambah `show()` + endpoint snapshot)
- `resources/views/it-assets/show.blade.php` (baru)
- `app/Services/CctvSnapshotService.php` (baru)
- `routes/web.php` (tambah route snapshot)

Langkah:
1. Buat `show(ItAsset $asset)` -> detail (field + spec + riwayat ping).
2. Buat `CctvSnapshotService::capture(ItAsset $asset)`:
   - Hanya untuk `device_type=CCTV`.
   - `GET http://{ip}/ISAPI/Streaming/channels/101/picture` (Basic Auth dari config
     `it.cctv.username` / `it.cctv.password`).
   - Simpan ke `storage/app/public/it-assets/snapshots/{id}.jpg`.
   - Update `last_snapshot_path` + `last_snapshot_at`.
3. Tambah route `POST /it-assets/{itAsset}/snapshot`.
4. Di `show.blade.php`, tampilkan snapshot terakhir + tombol "Ambil Snapshot".

Kriteria selesai: halaman detail tampil; tombol snapshot (bila kredensial Hikvision
tersedia) menyimpan & menampilkan gambar. Bila kredensial belum ada, tampilkan pesan
error jelas, bukan crash.

---

### Sesi 8 — Input Manual + Edit/Hapus (CRUD)

Tujuan: perangkat IT tidak hanya bisa masuk lewat import, tapi juga ditambah,
diedit, dan dihapus manual lewat web.

1. Route baru: `it-assets.create`, `.store`, `.edit`, `.update`, `.destroy`.
2. `ItAssetController`: `create()`, `store()`, `edit()`, `update()`, `destroy()`
   dengan validasi terpusat `validateForm()` (device_type wajib, ip_address
   divalidasi, snapshot_url regex rtsp/http(s), spec JSON opsional).
3. View `form.blade.php` dipakai bersama create & edit (field device_type,
   status, tag, nama, brand, model, serial, IP, plant, dept, lokasi,
   aplikasi, spec JSON, dan bagian CCTV Snapshot yang tampil otomatis untuk
   CCTV/NVR via JS).
4. Tombol "Tambah Manual" + aksi Edit/Hapus di tabel index; tombol
   "Edit Perangkat" di halaman detail.
5. `data_source = 'manual'` untuk membedakan data buatan tangan vs import.

### Sesi 9 — Notifikasi dalam aplikasi

Tujuan: admin tahu perangkat offline / ping tinggi / kembali online / error
snapshot lewat badge lonceng dan daftar notifikasi di web (tanpa integrasi
eksternal).

1. Migration `it_notifications` (it_asset_id, type, message, is_read, read_at).
2. `ItMonitorService`: pusat logika monitoring — ping, simpan hasil, lalu buat
   notifikasi hanya pada peristiwa relevan. Tipe: `offline`, `high_latency`,
   `recovered`, `snapshot_error`.
3. Anti-spam cooldown (config `it.notification.cooldown_minutes`, default 30)
   — perangkat yang sudah dapat notifikasi sejenis tidak dibanjiri ulang.
4. Halaman `/it-assets/notifications`: filter belum dibaca, tandai dibaca,
   tandai semua dibaca. Badge lonceng dengan jumlah belum dibaca di index.

### Sesi 10 — Ping otomatis terjadwal

Tujuan: status online/offline ter-update otomatis tanpa klik manual.

1. Command `it:ping` — ping semua perangkat aktif yang punya IP, simpan ke
   `last_ping_*` + `it_ping_logs`, dan lewatkan ke `ItMonitorService` untuk
   notifikasi.
2. Penjadwalan di `routes/console.php`: `cron('*/5 * * * *')` dengan
   `withoutOverlapping()` (interval bisa diubah via `config/it.php` /
   `IT_PING_INTERVAL_MINUTES`).
3. Jalankan `php artisan schedule:work` (atau cron `* * * * * php artisan
   schedule:run`) supaya penjadwalan aktif.

### Sesi 11 — Snapshot berkala CCTV/NVR

Tujuan: tangkapan gambar terakhir CCTV/NVR ter-update otomatis.

1. Command `it:snapshot` — ambil snapshot semua CCTV/NVR aktif yang punya
   `snapshot_url` (rtsp via ffmpeg / http via ISAPI), update
   `last_snapshot_*`, dan buat notifikasi `snapshot_error` bila gagal.
2. Penjadwalan di `routes/console.php`: `cron('0 * * * *')` tiap jam
   (`IT_SNAPSHOT_INTERVAL_MINUTES`).
3. Isi `snapshot_url` + kredensial lewat form tambah/edit perangkat untuk
   mengaktifkan fitur ini per kamera.

---

## 5. Aturan Pengerjaan (Wajib diulang di awal setiap sesi)

1. Sebutkan ulang daftar file yang akan disentuh beserta alasannya.
2. Baca ulang file dari disk sebelum mengubahnya. Jangan mengarang isi file.
3. Setiap file diubah -> tulis ulang lengkap, bukan hanya snippet/diff parsial.
4. Kode:
   - Rapi, konsisten gaya project.
   - Tanpa emoji di kode/komentar/string/variabel.
   - Komentar Bahasa Indonesia.
   - Setiap method baru wajib docblock (parameter + return).
   - Early return, hapus kode tak terpakai.
   - Null-safe (`?->`, `??`) untuk akses relasi/property yang bisa null.
5. Tampilan: ikuti tema existing (Tailwind, komponen existing, tidak inline style
   kecuali nilai dinamis).
6. Di akhir sesi: ringkasan perubahan + cara verifikasi + instruksi deploy bila ada.
7. Kalau ada file/dependency yang belum jelas (mis. kredensial CCTV, struktur sheet
   yang belum terbaca), minta dulu, jangan menebak.
8. Verifikasi sebelum bilang selesai: `php -l` untuk PHP, `php artisan migrate` untuk
   migration, dan test terisolasi untuk service (boleh file `.php` temp lalu hapus).
9. Commit per sesi setelah verifikasi stabil (jangan menumpuk banyak sesi tanpa commit).

---

## 6. Sesi 12 — Sinkronisasi IT Assets ke tabel assets + pilihan IT di wizard

Tujuan: lokasi perangkat IT memakai Functional Location yang sama dengan asset
industri, dan wizard laporan Telegram mendapat pilihan jenis pekerjaan **IT**
selain EI dan Mekanik (khusus klasifikasi ZPM 1 dan ZPM 6).

### Skema

1. `it_assets.funcloc_id` (FK nullable ke `functional_locations`) — lokasi
   perangkat IT memakai hierarki PT -> Departemen -> Area -> Section.
2. `assets.it_asset_id` (FK nullable ke `it_assets`, ON DELETE CASCADE) —
   jembatan ke baris cermin. Baris cermin ditandai `data_source = 'it_sync'`.
3. ENUM `assets.data_source` ditambah nilai `it_sync`; ENUM `assets.status`
   ditambah nilai `spare`; ENUM `reports.jenis_pekerjaan` ditambah nilai `IT`.

### Aturan pemetaan lokasi

Aturan ada di `config/it.php` bagian `sync.funcloc_rules` (plant + kata kunci
`department` -> funcloc level 1) dan `sync.device_fallback_rules` (cadangan per
tipe perangkat bila departemen kosong). Perangkat yang belum bisa dipetakan
TIDAK dipaksa masuk; barisnya tetap dikelola di halaman IT Assets.

### Perintah

```
php artisan it:map-funcloc --dry-run   # lihat hasil pemetaan tanpa menyimpan
php artisan it:map-funcloc             # simpan hasil pemetaan
php artisan it:sync-assets --dry-run   # lihat rencana sinkronisasi
php artisan it:sync-assets             # petakan + sinkronkan ke tabel assets
```

### Hasil verifikasi (23/09/2026)

| Pemeriksaan | Hasil |
|---|---|
| Perangkat IT punya funcloc | 242 dari 265 |
| Baris cermin di `assets` | 242 (219 baru, 23 CCTV adopsi baris lama sehingga `equipment_no` dan tautan laporan lama tetap utuh) |
| Perangkat belum bisa dipetakan | 23 (CCTV/access door NPA tanpa dept sudah tertangani oleh aturan cadangan; sisa: 6 PC spare, 7 MCFA, 4 laptop tanpa plant, 3 PABX, 1 PC plant KAS, `SERVER-FIRE-ALARM`, `WS-EPE-MTC-IT`) |
| Baris legacy dihapus dari `assets` | 43 (`PC-EPE-*`; 23 `CCTV-EPE-*` diadopsi, bukan dihapus) |
| Cadangan sebelum penghapusan | `D:\storage\app\private\backup\legacy-it-assets-20260923-140908.json` |
| Pencarian tagname IT di `TechIdentSearchService` | `CCTV-EPE-12`, `WS-CES-PRO-Novila`, `ALD-NPA-Packing` -> `auto_accept` pass1 confidence 100 |
| Filter pool saat jenis IT dipilih | tag IT ditemukan, tag industri -> `no_match` |
| Keyboard step jenis pekerjaan | EI, Mekanik, IT |

### Sesi 13 — Perangkat jaringan (Switch Managed, Access Point/Radio) dan Connect To

1. Daftar perangkat jaringan ada di `config/it.php` bagian `network_devices`
   (19 Switch Managed + 8 Radio sebagai Access Point), dijalankan lewat
   `php artisan it:seed-network` (idempoten, aman diulang setelah config
   direvisi). Perintah sekaligus memetakan funcloc dan menyinkronkan ke
   `assets`.
2. Radio dari sheet MODUL JARINGAN dipetakan sebagai `ACCESS_POINT`
   (perangkat nirkabel), sehingga total Access Point menjadi 11.
3. Form IT Assets kini punya dropdown **Connect To** (partial
   `_connect-to-select.blade.php`) yang isinya perangkat bertipe Switch Managed,
   Switch PoE Unmanaged, dan Access Point. Nilai lama hasil import yang belum
   terdaftar tetap ditampilkan sebagai opsi cadangan `(belum terdaftar)` agar
   tidak hilang saat form disimpan.
4. Nilai `connect_to` pada CCTV yang mengandung baris baru (sisa sel Excel)
   sudah dinormalkan: 12 baris diperbaiki, 95 kamera kini cocok dengan
   perangkat jaringan terdaftar. Sisa yang masih berupa jalur radio:
   `Radio - Switch Utility` (2 kamera) dan `Radio - Switch Unloading`
   (4 kamera) — sengaja dibiarkan karena informasinya menyatakan kamera
   terhubung lewat radio.
5. Catatan: dropdown Connect To dirender lewat partial `_location-select`
   (yang sudah di-include di form) agar tidak perlu menyunting
   `form.blade.php`; letaknya di blok identitas/lokasi dan hanya tampil untuk
   tipe CCTV/NVR. Bila ingin dipindah ke dalam kotak spesifikasi CCTV, cukup
   tambahkan satu baris `@include('it-assets.partials._connect-to-select')` di
   dalam `#cctvSpecSection` dan hapus blok `connectToWrapper`.

### Catatan sisa pekerjaan

1. `resources/views/reports/edit.blade.php` belum memuat pilihan `IT` pada peta
   opsi JS `syncJenisPekerjaan()`. Pengaman sementara ada di
   `ReportController::update()` (klasifikasi IT yang sudah ada tidak dihapus bila
   form menyimpan nilai kosong). Menambah opsi di file itu butuh overwrite penuh
   sesuai aturan edit file blade.
2. Filter pool IT sekarang berlaku di SEMUA jalur pencarian: tagname
   (`TechIdentSearchService`), hierarki Functional Location
   (`assetsUnderNode`/`countAssetsUnderNode`), dan pencarian jenis alat
   (`startTypeSearch`/`buildTypeAreaAssetsKeyboard`/`type_area_back`).
   EI dan Mekanik tetap melihat seluruh asset (termasuk perangkat IT).
3. `resources/views/assets/show.blade.php` sudah menampilkan label
   "Sinkron IT Assets" untuk `data_source = 'it_sync'`.
4. Import PRINTER dan MODUL JARINGAN kini menerima baris tanpa IP
   (`requireIp: false`) — perangkat tetap dicatat selama tagname/lokasinya ada.
5. 23 perangkat IT tanpa funcloc menunggu kelengkapan data plant/departemen
   (daftar tagname: 4 laptop `WS-EPE-ACC-ALVIN`, `WS-EPE-PRO-YATNO`,
   `WS-EPE-QC-DIAN`, `WS-NPA-PRO-CCR`; 7 `MCFA *`; 3 `PABX`;
   `SERVER-FIRE-ALARM`; `WS-EPE-MTC-IT`; `WS-KAS-MTC-STEAM`;
   6 `WS-EPE--Spare`/`WS-NPA--Spare`).




