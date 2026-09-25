# PROMPT — Oleochemical Pro: Maintenance Management System

## Konteks Project

Sistem manajemen pemeliharaan industri berbasis web untuk perusahaan oleochemical bernama **Oleochemical Pro**. Sistem ini mencakup:

1. **Asset Management** — Database equipment/mesin dari SAP ZPM export (Excel)
2. **Teknisi Management** — Registrasi, profil, assignment, dan broadcast Telegram
3. **Report Management** — Laporan kerja harian teknisi via Telegram Bot (wizard 8-step)
4. **AI Analysis Engine** — Analisa laporan menggunakan Groq LLM dengan fallback multi-provider
5. **Telegram Bot** — Interface utama teknisi via long polling (PollTelegramUpdates)
6. **Admin Panel** — Dashboard web untuk admin/supervisor

---

## Stack Teknis (WAJIB DIIKUTI)

```
Backend  : PHP 8.2 + Laravel 11
Frontend : Blade + Tailwind CSS + Alpine.js + Vanilla JS
Database : MySQL 8.0
Bot      : Telegram Bot API (Long Polling via PollTelegramUpdates artisan command)
AI       : Groq API (nama model TIDAK dipatok manual - dideteksi dari GET /models via
           App\Services\AiModelDiscoveryService, kolom model boleh dikosongkan),
           Ollama (local fallback), OpenAI (fallback)
           Kuota harian direset otomatis oleh `php artisan ai:reset-quota` (harian 00:05)
Import   : PhpSpreadsheet / rap2hpoutre/fast-excel
Queue    : Laravel Queue (database driver)
```

> **TIDAK menggunakan Vue, React, atau frontend framework apapun.**
> **TIDAK menggunakan starter kit (Jetstream, Breeze, dll.).**
> Semua UI dibangun dengan Blade + Tailwind CSS murni.

---

## Struktur Database (kondisi aktual)

### Tabel Inti

#### `companies`
```sql
id, code (EPE/EPCO), name, created_at, updated_at
```

#### `departments`
```sql
id, company_id, code, name, created_at, updated_at
```

#### `areas`
```sql
id, department_id, code, name, created_at, updated_at
```

#### `sub_areas`
```sql
id, area_id, code, name, created_at, updated_at
```

#### `assets`
```sql
id, equipment_no, description, tech_ident_no, object_type,
functional_loc, company_id, department_id, area_id, sub_area_id,
manufacturer, model_number, construct_year,
status (active/inactive/needs_review),
has_equipment_no (boolean), data_source (import_excel/manual),
imported_at, created_at, updated_at
```

#### `asset_technician` (pivot — ditambah Mei 2026)
```sql
id, asset_id, technician_id, note, assigned_by (user_id),
assigned_at, created_at, updated_at
-- UNIQUE: asset_id + technician_id
```

#### `asset_import_logs`
```sql
id, filename, imported_by (user_id), total_rows, success_count,
duplicate_count, no_equip_no_count, error_count,
action_taken (replace/keep_flag/cancel), detail_json, created_at
```

#### `technicians`
```sql
id, telegram_id, telegram_username, name, nik, department_id,
area_ids (JSON), group (reguler/grub_a/grub_b/grub_c),
section (mekanik/ei/it/sipil/fabrikasi/general/lainnya/cm_pm),
status (pending/active/suspended), approved_by (user_id),
approved_at, last_active_at, created_at, updated_at
```

#### `reports`
```sql
id, report_code (unique, nullable),
technician_id, report_date, work_description,
work_duration_minutes (nullable), root_cause (nullable),
photo_documentation (JSON array path lokal, nullable),
photo_hygiene_clearance (JSON array path lokal, nullable),
area_id (nullable), asset_id (nullable),
report_type (equipment_repair/area_work/general),
ai_analyzed (boolean), ai_confidence (float), ai_suggestion_json (JSON),
status (draft/needs_review/completed), completed_at,
telegram_message_id, wizard_started_at, submitted_at,
created_at, updated_at
```

#### `report_ai_suggestions`
```sql
id, report_id, suggestion_type (area/equipment),
suggested_area_id, suggested_asset_id,
confidence, reasoning, accepted (boolean), created_at
```

#### `ai_providers`
```sql
id, name, provider_type (groq/ollama/openai),
api_key_encrypted, model, endpoint_url, priority,
monthly_token_limit, daily_token_limit,
tokens_used_today, tokens_used_month, request_count_24h,
last_used_at, last_health_check,
status (healthy/exhausted/error/disabled),
created_at, updated_at
```

#### `ai_usage_logs`
```sql
id, provider_id, report_id, tokens_used, request_type,
response_time_ms, status, error_message, created_at
```

#### `ai_aliases`
```sql
id, alias_text, asset_id (nullable), area_id,
technician_id, source (user/ai_learned), confidence,
usage_count, status (pending/confirmed/rejected),
confirmed_by (user_id), created_at, updated_at
```

#### `bot_registrations`
```sql
id, telegram_id, telegram_username, name, nik,
requested_at, status (pending/approved/rejected),
processed_by, processed_at
```

#### `bot_unknown_assets`
```sql
id, report_id, keyword_mentioned, created_at
```

---

## Arsitektur Telegram Bot (kondisi aktual)

Bot berjalan via **long polling**, bukan webhook. Entry point utama:

```
php artisan telegram:poll
```

File utama yang terlibat:

| File | Namespace | Peran |
|------|-----------|-------|
| `PollTelegramUpdates.php` | `App\Console\Commands` | Artisan command, loop polling, router semua update |
| `ReportWizardService.php` | `App\Services\Telegram` | Orchestrator wizard 8-step, state di Laravel Cache |
| `ClarificationService.php` | `App\Services\Telegram` | Keyboard hierarki Company→Dept→Area→SubArea→Equipment |
| `PhotoStorageService.php` | `App\Services\Telegram` | Download foto dari Telegram API, simpan ke storage/public |
| `TechIdentSearchService.php` | `App\Services` | Pencarian TechIdentNo 3-pass (exact/normalized/section+type) |
| `TelegramService.php` | `App\Services` | sendMessage, editMessageText, broadcastToTechnicians, dll. |
| `TelegramWebhookController.php` | `App\Http\Controllers\Api` | Webhook alternatif (tidak aktif dipakai) |

### Wizard 8-Step (ReportWizardService)

```
Step 1: Terima laporan awal   → TechIdentSearch 3-pass
Step 2: Klarifikasi equipment → keyboard kandidat / tulis ulang
Step 3: Akselerasi FuncLoc   → ClarificationService & FuncLocParser
Step 4: Waktu pengerjaan     → parse durasi (menit) atau tanya manual
Step 5: Root cause           → input teks bebas, min 3 karakter
Step 6: Foto dokumentasi     → multi-foto opsional (PhotoStorageService)
Step 7: Foto hygiene clearance → multi-foto opsional (PhotoStorageService)
Step 8: Konfirmasi & Simpan  → ringkasan, simpan ke DB jika OK
```

State wizard disimpan di **Laravel Cache** per `chat_id` dengan TTL 2 jam.
Laporan hanya dibuat di DB pada Step 8 (pendekatan "Create at End").

**Pool equipment tidak dibatasi jenis pekerjaan** (revisi): `WizardUtilityTrait::isItPool()` sekarang
SELALU `false`, jadi teknisi dengan jenis pekerjaan **IT tetap bisa memilih seluruh equipment**
(aset industri EPE/NPA/CES maupun perangkat IT). Sebelumnya pilihan IT membatasi pool ke baris cermin
perangkat IT (`data_source = 'it_sync'`) sehingga aset industri lain tidak muncul. Method itu dipertahankan
sebagai satu-satunya tempat keputusan supaya pencarian TechIdentNo, keyboard tipe/area, dan penjelajahan
FuncLoc berubah bersamaan.

**Kalau sebuah alat tidak muncul di wizard**, periksa dua hal:
1. Apakah alat itu ada di tabel `assets` dengan `tech_ident_no` terisi (pencarian memakai kolom itu).
2. Untuk perangkat IT: baris cermin di `assets` hanya dibuat bila perangkat punya `funcloc_id`.
   Perangkat IT tanpa lokasi TIDAK punya cermin sehingga tidak bisa dicari siapa pun. Lengkapi
   lokasinya di halaman IT Assets lalu jalankan `php artisan it:sync-assets`
   (`--dry-run` untuk melihat "N belum bisa dipetakan" tanpa menyimpan).

**Hirarki pemilihan equipment** (`WizardFuncLocPickerTrait`) tidak lagi mensyaratkan kolom
`equipment_type` terisi: alat tanpa Equipment Type ikut ditampilkan dan dikelompokkan pada label
`TANPA TIPE` (`equipmentTypeOf()`), karena seluruh equipment harus bisa dipilih. Sebelumnya alat tanpa
type tersembunyi (di data nyata: 2.643 dari 4.247 alat terlihat, sekarang 4.243 — sisa 4 alat belum
punya Tech Ident No sehingga tombolnya tidak bisa diberi kode).

**PENTING — bot Telegram berjalan sebagai proses panjang** (`php artisan telegram:poll --action=start`,
loop `while (true)` di dalam proses). Setelah mengubah kode Telegram, proses itu WAJIB dijalankan ulang
(`--action=stop` lalu `--action=start`), kalau tidak ia masih memakai kelas lama di memori dan perilaku
baru tidak akan terlihat di chat.

Foto disimpan di `storage/app/public/reports/{chat_id}/{uniqid}.jpg`
dan diakses via `Storage::disk('public')->url($path)`.

---

## Services Penting

### AiService
- `analyzeReport(Report $report)` — pilih provider, call Groq, parse JSON response
- Fallback otomatis: Groq → Ollama → OpenAI
- Log ke `ai_usage_logs`, update token counter provider
- Return: `{type, confidence, candidates[], area_id, reasoning, new_alias_suggestion}`

### AiModelDiscoveryService
- Ambil daftar model yang BENAR-BENAR tersedia di akun provider (`GET {base}/models`)
- `pickChatModel()` memilih model chat terbaik (model audio/penjaga prompt disaring)
- `detectAndSave(AiProvider)` mengoreksi/menyinkronkan nama model secara otomatis

**Catatan UI panel AI: field "Model" TIDAK ADA LAGI di form.** Admin hanya mengisi
Nama, Tipe, API Key, Endpoint (opsional), prioritas, dan batas token. Model dan kuota
diambil otomatis dari API provider saat provider disimpan atau diuji
(`AiProviderController::syncProviderWithApi()`), lalu ditampilkan read-only pada
kartu provider (model di header kartu, kuota pada blok "Kuota per menit (menurut API)").

### AiQuotaService
- Sumber kuota = header respons API (`x-ratelimit-*`), karena Groq TIDAK punya endpoint sisa kuota
- `recordFromHeaders()` menyimpan batas/sisa per menit dari setiap panggilan sukses
- `applyRateLimit()` menyimpan masa tunggu (cooldown) dari `retry-after` saat kena HTTP 429
- `resetCounterIfNewPeriod()` mereset penghitung harian/bulanan otomatis saat periode berganti
- Kolom provider terkait: `quota_*_per_minute`, `quota_checked_at`, `cooldown_until`, `quota_day`
- `daily_token_limit`/`monthly_token_limit` hanya penjaga opt-in (`config('ai.quota.enforce_local_limit')`, bawaan false)

### AssetInfoService (asisten /info di bot Telegram)
- Berlaku untuk SEMUA aset (perangkat IT maupun aset industri), bukan hanya IT
- Hirarki mengikuti wizard laporan: Plant -> Departemen -> Area -> Sub Area -> Section -> Tipe alat -> Alat
  (level Section hanya muncul bila alat > 10, level Tipe selalu lebih dulu sebelum daftar alat)
- Pintasan "Tanpa Area" untuk aset yang belum punya area (mis. sebagian perangkat IT)
- Setiap keyboard punya tombol "Kembali" dan "Tutup" (`info:close`, tombol langsung dihapus dari pesan)
- Detail aset industri: lokasi lengkap, teknisi terakhir, kerusakan/pekerjaan terakhir,
  pengecekan PM terakhir, Condition Monitoring terakhir (`cm_readings.kondisi` + `max_vibration`/`max_temp`),
  laporan terakhir, link web `/assets/{id}`
- Tombol "Buat Laporan" (`info:report:<asset_id>`) membuka `ReportWizardService::startWizardForAsset()`
  dengan alat sudah terkunci, sehingga `processEquipmentSearch()` dilewati dan teknisi langsung
  mengisi klasifikasi/jenis pekerjaan serta data lainnya
- Callback diarahkan di `PollTelegramUpdates::processCallbackQuery()` (prefix `info:`) sebelum wizard laporan

### CollaborationService (kolaborasi otomatis per kelompok)
- Kelompok kolaborasi disimpan di `collaboration_groups` + `collaboration_group_members`
  (terpisah dari kolom `technicians.group` supaya susunan anggota bisa disesuaikan bebas)
- `autoCollaboratorsFor($teknisi)` -> rekan satu kelompok (aktif + otomatis) di luar dirinya sendiri
- `gabungKolaborator($pelapor, $manualNiks, $manualNames)` -> gabungan manual + otomatis tanpa duplikat, maks 5
  (selaras `WizardStepHandlerTrait::MAX_COLLABORATORS`)
- `selaraskanDariGrupTeknisi($group)` -> isi anggota dari teknisi yang `group`-nya sama dengan kode kelompok
- `sinkronkanKelompokGrupTeknisi()` -> dipakai perintah `php artisan collab:sync-groups`
  (membuat Grub A samapi D dari grup teknisi; Reguler tidak dibuat kecuali opsi `--with-reguler`)
- Dipakai wizard Telegram: Step 8a otomatis mencantumkan rekan kelompok (`terapkanTimOtomatis()` di
  `WizardStepHandlerTrait`, sekali saja per laporan lewat penanda `collab_auto_applied`) dan
  `WizardReportSaverTrait::saveReport()` menggabungkan ulang sebelum membuat salinan kolaborasi
- Menu "Sesuaikan" pada Step 8a (`collab_adjust`, `collab_hapus:<index>`, `collab_adjust_selesai`)
  memungkinkan menghapus satu nama kolaborator untuk laporan itu saja

### TechIdentSearchService
- Pencarian 3-pass: exact match → normalized → section+type
- Input: teks laporan teknisi
- Output: array kandidat asset dengan skor relevansi

### FuncLocParser
- Parse `EPE-PROD-BD02-6153` → `{company, department, area, subArea}`
- Dipakai saat import Excel dan wizard klarifikasi

### ImportService
- `analyzeFile(UploadedFile)` → klasifikasi tiap baris (baru/duplikat/no-equip-no)
- `executeImport(array, string $action)` → eksekusi dengan pilihan replace/flag/skip/cancel

---

## Modul Admin Panel

### Halaman Laporan (`GET /reports`) — tampilan fokus pekerjaan

- Kolom utama: **Pekerjaan** (deskripsi + kode laporan + penyebab), lalu **Teknisi** di sampingnya,
  disusul Jenis (badge + klasifikasi ZPM), Lokasi/Alat, dan Waktu & Foto.
- **Urutan: yang terbaru di paling atas** (`report_date` desc, lalu `created_at` desc, lalu `id` desc)
  sehingga laporan yang baru saja masuk muncul paling atas pada tanggal tersebut.
- **Per tanggal**: default hari ini; tombol hari sebelumnya / berikutnya melompat ke tanggal yang
  punya laporan, ada input `tanggal` ("Buka") dan tombol "Hari Ini", plus chip 10 tanggal terakhir
  beserta jumlah pekerjaannya.
- **Dua tampilan**: `tampilan=reguler` (default) memuat pekerjaan teknisi grup `reguler`
  (termasuk yang grupnya belum diisi); `tampilan=shift` memuat pekerjaan teknisi grub A/B/C/D dan
  bisa dipersempit dengan `grub=grub_a..grub_d` (lihat `Technician::GROUPS`).
- **Kolaborasi dikonsolidasi**: hanya laporan induk (`collaborator_of` NULL) yang tampil, dengan
  keterangan "Berkolaborasi dengan: ..." dari relasi `collaboratorReports` — sehingga satu pekerjaan
  tidak muncul berulang untuk tiap teknisi.
- Filter lanjutan (deskripsi/kode laporan/kode alat/rentang tanggal/area/teknisi/jenis/klasifikasi/tipe)
  ada di panel `<details>` di bawah tabel; `GET /reports/export/csv` memakai filter yang sama.
- Halaman ini memakai form + tautan biasa (tanpa Alpine.js) agar sederhana dan sesuai aturan vanilla JS.

### Halaman Kolaborasi (`GET /collaboration`)

- Mengatur kelompok kolaborasi (bawaan: Grub A sampai D dari `php artisan collab:sync-groups`)
  beserta anggotanya; anggota bisa ditambah/dihapus bebas tanpa mengubah `technicians.group`.
- Setiap kelompok punya saklar **Aktif** dan **Otomatis ikut andil**. Anggota pada kelompok yang
  keduanya menyala akan otomatis tercantum sebagai kolaborator ketika salah satu anggotanya
  membuat laporan di bot (tahap kolaborasi Step 8a), dan tetap bisa dihapus per laporan lewat
  menu "Sesuaikan".
- Tombol **Selaraskan dari grup teknisi** mengisi anggota dari teknisi yang `group`-nya sama dengan
  kode kelompok (anggota manual yang sudah ada tidak dihapus).
- Catatan: laporan yang dibuat admin lewat web (`/reports/create`) tidak punya teknisi pelapor,
  jadi otomasi kolaborasi hanya berlaku untuk laporan dari bot Telegram.

### Export & Import Aset (putaran lengkap: export → sesuaikan → import)

- **Export**: `GET /assets/export/excel` dan `GET /assets/export/csv` (`AssetExportTrait`, filter sama dengan
  halaman index). Kolom: **No**, Description, Tech Ident No, Equipment Type, Functional Loc,
  Company, Department, Area, Sub Area, Manufacturer, Model Number, Construct Year, Status, Data Source.
  **Equipment No sengaja TIDAK diekspor** (nomornya dikelola sistem), dan **baris cermin perangkat IT
  (`data_source = 'it_sync'`) juga tidak disertakan** — total pada blok keterangan menulis
  "(perangkat IT / it_sync tidak disertakan)". Data perangkat IT diputar lewat
  `GET /it-assets/import` di modul IT Assets, bukan lewat export/import asset industri.
- **Header file export profesional**: blok judul di baris 1-5 (judul "DAFTAR ASSET — ASSET MANAGEMENT",
  nama sistem, waktu ekspor + siapa yang mengekspor, **ringkasan filter yang aktif**, dan jumlah asset),
  baris 6 kosong, lalu baris tabel (header kolom) di **baris 7** dengan gaya teal tebal berbingkai,
  baris zebra, `freeze pane`, `auto filter`, dan pengaturan cetak lanskap (muat 1 halaman lebar,
  judul tabel diulang tiap halaman). Format CSV memuat blok keterangan yang sama.
- **Urutan data export**: selalu dirapikan menurut lokasi — **PT (Company) → Departemen → Area → Sub Area
  → Functional Loc → Tech Ident No** (baris tanpa PT diletakkan paling akhir), sehingga data satu lokasi
  berkumpul dan mudah dibaca/diedit sebelum diimport kembali. Filter kolom (`auto filter`) tetap tersedia
  di header tabel Excel.
- **Import**: `GET /assets/import` → analisa file → pilih aksi → eksekusi (`ImportService`).
  Baris header **dicari otomatis** pada 20 baris pertama, sehingga file berblok judul (hasil export)
  tetap terbaca; kolom yang dikenali mencakup nama SAP **dan** nama kolom export.
- **KUNCI Pencocokan = Tech Ident No** (keputusan bisnis: satu aset = satu Tech Ident No, tidak boleh dobel).
  Equipment No **tidak dibaca sama sekali** dari file: nomor adalah id internal yang dibuat sistem.
  Bucket hasil analisa: `clean` = **data baru**, `duplicate` = **akan diperbarui** (plus key `perubahan`
  berisi daftar field yang berubah: field, lama, baru), `no_equip` = baris tanpa Tech Ident No,
  `bad_funcloc` = tanpa Functional Loc. (funcloc_id wajib), `ambigu` = Tech Ident No dobel di file
  atau dipakai >1 aset di database (dilewati + alasan ditampilkan), `it_sync` = baris cermin perangkat IT
  (**tidak diproses import asset**: barisnya dicocokkan ke aset `data_source = 'it_sync'` lalu dilaporkan
  terpisah, karena datanya dikelola di modul IT Assets lewat `GET /it-assets/import` +
  `php artisan it:sync-assets`). Total semua bucket selalu sama dengan jumlah baris yang dibaca.
- **Halaman preview membedakan "berubah" dan "cocok tanpa perubahan"**: kartu ringkasan (Data Baru /
  **Data Berubah** / Tanpa Tech Ident No / Tanpa Functional Loc. / Perlu Diperiksa / Perangkat IT
  (dilewati) / Total Baris) — kartu "Data Berubah" menghitung baris yang benar-benar punya perbedaan
  nilai, dengan catatan kecil "N cocok data lama". Daftarnya dipisah menjadi **"Data yang Akan
  Diperbarui"** (hanya baris dengan `perubahan`) dan **"Cocok Data Lama Tanpa Perubahan"** (isi file sama
  dengan database, tetap diproses tanpa mengubah nilai). Daftar dibatasi 200/50 baris pertama agar
  halaman tetap ringan. Hasil eksekusi melaporkan `unchanged_count` ("N di antaranya cocok dengan data
  lama tanpa perubahan nilai").
- **Nomor equipment otomatis** (`App\Services\AssetNumberService`): SEMUA baris baru — baik dari import
  maupun form Tambah Asset manual — dibuatkan nomor 8 digit mengikuti seri milik PT bersangkutan
  (angka pertama nomor yang sudah ada; cadangan di `config('assets.equipment_no.awalan_pt')`,
  contoh nyata EPE=1, CES=6, NPA=7) dan dilanjutkan dari nomor terbesar pada seri itu (nomor yang sudah
  dipakai otomatis dilewati). Nomor aset lama **tidak pernah** diubah oleh import maupun form edit.
- **Tambah/Edit Asset**: Equipment No hanya ditampilkan sebagai informasi (tidak ada input-nya), karena
  nomor dibuat sistem. Nilai `equipment_no` yang tetap dikirim ke request diabaikan controller.
  Pada form Tambah, begitu lokasi dipilih, nomor yang akan dipakai ditampilkan lewat
  `GET /assets/locations/next-equipment-no?funcloc_id=` (PT diambil dari relasi node, atau dari awalan
  kode FuncLoc bila node belum punya `company_id`).
- **Penjaga nilai Tech Ident No yang terpotong Excel/Google Sheets**: nilai seperti `6153E1` dianggap
  angka oleh spreadsheet (`is_numeric('6153E1')` = true) sehingga di file bisa berubah menjadi `61530`
  atau `6.163E+23`. Sebelum baris dianggap data baru, nilai angka itu dibandingkan dengan aset yang
  Tech Ident No-nya berbentuk `<angka>E<pangkat>` memakai bentuk digit kanonik (`digitKanonik()`, tanpa
  float): `61530`, `6153E1`, dan `6.153E+4` semuanya menjadi `61530`. Hasilnya:
  tepat 1 kandidat -> baris dicocokkan ke aset itu (masuk daftar "Akan Diperbarui", key
  `tech_ident_terpotong` berisi nilai file + nilai database) dan **kolom Tech Ident No di database TIDAK
  diubah**; lebih dari 1 kandidat -> masuk "Perlu Diperiksa"; tanpa kandidat -> tetap data baru.
  Jumlah baris yang dicocokkan dengan cara ini dilaporkan (`truncated_matched_count`).
  Pencegahan di sisi file: format kolom TechIdentNo. sebagai Teks biasa (Plain text) sebelum mengisi,
  lalu simpan sebagai .xlsx (CSV tidak menyimpan tipe sel).
- **Kolom yang ikut diperbarui**: Description, Tech Ident No, Equipment Type, Functional Loc (+ `funcloc_id`),
  Company/Department/Area/Sub Area (diresolusi dari kode kolom; kode tak dikenal = baris dilewati),
  Manufacturer, Model Number, Construct Year, dan Status (nilai kosong/tak dikenal tidak menimpa).
- Baris cermin perangkat IT (`data_source = it_sync`) **dilewati** saat import (dikelola modul IT Assets
  lewat `php artisan it:sync-assets`) dan dilaporkan jumlahnya pada pesan hasil import.
- Baris cermin perangkat IT (`data_source = it_sync`) **dilewati** saat import (dikelola modul IT Assets
  lewat `php artisan it:sync-assets`) dan dilaporkan jumlahnya pada pesan hasil import.

### Routes yang Ada (web.php)

| Route | Controller | Keterangan |
|-------|-----------|------------|
| `GET /dashboard` | DashboardController@index | Overview stats |
| `GET /assets` | AssetController@index | Daftar asset + filter |
| `GET /assets/import` | ImportController@showImport | Upload Excel |
| `POST /assets/import/preview` | ImportController@previewImport | Preview pra-import |
| `POST /assets/import/execute` | ImportController@executeImport | Eksekusi import |
| `GET /assets/export/excel` | AssetController@exportExcel | Export Excel |
| `GET /assets/export/csv` | AssetController@exportCsv | Export CSV |
| `POST /assets/{asset}/technicians/assign` | AssetController@assignTechnician | Assign teknisi ke asset |
| `DELETE /assets/{asset}/technicians/{technician}` | AssetController@removeTechnician | Lepas assignment |
| `POST /assets/{asset}/technicians/broadcast` | AssetController@broadcastToTechnicians | Kirim pesan ke teknisi asset |
| `resource /assets` | AssetController | CRUD lengkap |
| `resource /technicians` | TechnicianController | CRUD + approve/suspend/reactivate/broadcast |
| `POST /technicians/bulk-approve` | TechnicianController@bulkApprove | Approve massal |
| `resource /reports` | ReportController | CRUD laporan |
| `POST /reports/{report}/update-status` | ReportController@updateStatus | Ubah status laporan |
| `GET /reports/export/csv` | ReportController@exportCsv | Export laporan CSV |
| `GET /ai-providers` | AiProviderController@index | Panel AI Provider |
| `POST /ai-providers/{id}/test` | AiProviderController@test | Test provider |
| `POST /ai-providers/test-all` | AiProviderController@testAll | Test semua provider |
| `POST /ai-providers/reset-quota` | AiProviderController@resetQuota | Reset quota harian |
| `POST /ai-providers/aliases/{alias}/confirm` | AiProviderController@confirmAlias | Konfirmasi alias |
| `POST /ai-providers/aliases/{alias}/reject` | AiProviderController@rejectAlias | Tolak alias |
| `GET /bot` | BotController@index | Panel Bot Telegram |
| `POST /bot/polling/start` | BotController@startPolling | Mulai long polling |
| `POST /bot/polling/stop` | BotController@stopPolling | Stop long polling |
| `GET /bot/polling/status` | BotController@pollingStatus | Status polling |

### Komponen Blade yang Sudah Ada

```
resources/views/components/
  alert.blade.php
  data-table.blade.php
  modal.blade.php
  progress-bar.blade.php
  provider-card.blade.php
  stat-card.blade.php
  status-badge.blade.php

resources/views/layouts/
  app.blade.php
```

---

## Catatan Penting

1. Semua teks UI dalam **Bahasa Indonesia**
2. Timestamp dalam WIB (Asia/Jakarta)
3. API key AI provider di-encrypt dengan `encrypt()` Laravel sebelum disimpan
4. Foto laporan disimpan di `storage/app/public/reports/` — wajib jalankan `php artisan storage:link`
5. Bot berjalan via long polling artisan command, bukan webhook
6. State wizard di Cache — jika cache flush, sesi wizard teknisi yang aktif hilang
7. `PhotoStorageService` wajib ada sebelum bot dijalankan
8. `TelegramWebhookController` masih ada tapi tidak aktif dipakai (legacy)
