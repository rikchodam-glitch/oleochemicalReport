# AGENTS.md — Oleochemical Pro

Dokumen ini mendefinisikan **agent tasks** untuk Continue AI di VS Code.
Setiap section adalah instruksi spesifik untuk satu sesi pengerjaan.

Baca `PROMPT.md` terlebih dahulu sebelum mengerjakan agent manapun
untuk memahami stack, struktur database, dan arsitektur yang sudah ada.

---

## ATURAN WAJIB (berlaku untuk semua agent)

```
PENULISAN KODE:
- Kode ditulis rapi, terstruktur, dan konsisten dengan gaya kode yang sudah ada
- Tidak ada emoji di dalam kode, komentar, string konstanta, maupun nama variabel
- Komentar ditulis dalam Bahasa Indonesia yang jelas
- Setiap method baru wajib memiliki docblock singkat (parameter + return)
- Hapus kode yang tidak dipakai, jangan di-comment out tanpa alasan
- Gunakan early return untuk menghindari nesting yang dalam

TAMPILAN (VIEW / BLADE):
- Tampilan baru harus menyesuaikan tema visual yang sudah ada
  (warna slate/blue Tailwind, komponen yang ada, spacing, class naming)
- Gunakan komponen yang sudah ada: x-status-badge, x-stat-card,
  x-alert, x-modal, x-progress-bar, x-provider-card, x-data-table
- Tidak ada inline style kecuali untuk nilai dinamis

ALUR KERJA:
- Sebutkan ulang daftar file yang akan disentuh di awal sesi
- Setiap file yang diubah ditulis ulang secara lengkap
- Di akhir sesi berikan ringkasan perubahan dan instruksi deploy
- Jika ada file yang dibutuhkan tapi belum dilampirkan, minta terlebih dahulu
- Jangan mengarang isi file yang belum dilihat
```

---

## Urutan Pengerjaan yang Disarankan

```
1. fix-photo-storage    → kritis: bot crash tanpa PhotoStorageService
2. fix-report-module    → tampilan laporan belum menampilkan field wizard
3. fix-ai-provider      → health check palsu, method alias belum ada
4. fix-bot-polling      → stopPolling WMIC tidak jalan di Linux
5. enhance-wizard       → state wizard di cache, risiko hilang saat restart
6. dashboard-analytics  → grafik dan statistik belum real
7. alias-learning       → alias learning otomatis belum diimplementasikan penuh
8. notification-system  → notifikasi ke admin saat laporan masuk
```

---

## AGENT: fix-photo-storage
> Buat PhotoStorageService dan perbaiki alur penyimpanan foto laporan

**Masalah:** `PhotoStorageService` dipanggil di `PollTelegramUpdates` tapi
filenya tidak ada. Bot crash setiap kali teknisi mengirim foto.
Foto di DB tersimpan sebagai file_id Telegram, bukan path lokal,
sehingga tidak bisa ditampilkan di admin panel.

```
Buat dan perbaiki:

1. app/Services/Telegram/PhotoStorageService.php (FILE BARU):
   - store(string $fileId, string $chatId): ?string
     Langkah: getFile dari Telegram API → download konten → simpan ke
     storage/app/public/reports/{chatId}/{uniqid}.jpg → return path relatif
   - delete(string $path): void
   - url(string $path): string
   - static isTelegramFileId(string $value): bool
     (deteksi backward-compat: apakah nilai adalah file_id lama atau path baru)

2. Pastikan PollTelegramUpdates.php sudah inject PhotoStorageService dengan benar
   dan memanggil store() sebelum path dimasukkan ke state wizard

3. Jalankan: php artisan storage:link (dokumentasikan di instruksi deploy)

File yang harus dilampirkan:
- app/Console/Commands/PollTelegramUpdates.php
- app/Services/Telegram/ReportWizardService.php
```

---

## AGENT: fix-report-module
> Perbaiki halaman laporan agar menampilkan semua field wizard

**Masalah:** View `reports/show.blade.php` tidak menampilkan field baru dari
wizard: `report_code`, `work_duration_minutes`, `root_cause`,
`photo_documentation`, `photo_hygiene_clearance`, `wizard_started_at`.
View `reports/index.blade.php` tidak menampilkan kolom foto dan durasi.
`ReportController::exportCsv()` juga belum memasukkan field baru ke CSV.

```
Perbaiki:

1. resources/views/reports/show.blade.php:
   - Tampilkan report_code sebagai badge di header
   - Tampilkan durasi (konversi menit ke jam+menit)
   - Tampilkan root_cause di panel tersendiri
   - Tampilkan foto_documentation dan photo_hygiene_clearance sebagai grid gambar
     dengan fallback placeholder jika path tidak valid
   - Tambahkan wizard_started_at dan submitted_at di timeline
   - Sidebar: ringkasan cepat semua field
   - Lightbox sederhana untuk klik foto (Vanilla JS)

2. resources/views/reports/index.blade.php:
   - Tambah kolom: Kode, Foto (count), Durasi
   - Search cakup report_code dan root_cause
   - Tampilkan root_cause hint di baris deskripsi

3. app/Http/Controllers/ReportController.php:
   - index(): tambah search by report_code
   - exportCsv(): tambah kolom report_code, root_cause, work_duration_minutes,
     jumlah foto_documentation, jumlah photo_hygiene_clearance, submitted_at
   - destroy(): hapus file foto dari storage saat laporan dihapus

File yang harus dilampirkan:
- app/Http/Controllers/ReportController.php
- resources/views/reports/show.blade.php
- resources/views/reports/index.blade.php
- app/Models/Report.php
```

---

## AGENT: fix-ai-provider
> Perbaiki health check palsu dan buat method alias yang hilang

**Masalah:**
- `AiProviderController::test()` dan `testAll()` tidak benar-benar call API,
  langsung set status `healthy` tanpa verifikasi
- Route `ai-providers.aliases.confirm` dan `.reject` sudah terdaftar di `web.php`
  tapi method `confirmAlias()` dan `rejectAlias()` tidak ada di controller

```
Perbaiki:

1. app/Http/Controllers/AiProviderController.php:
   - test(AiProvider): benar-benar call endpoint provider, ukur response time,
     update status berdasarkan hasil aktual, return JSON {status, response_time_ms, error}
   - testAll(): loop semua provider aktif, panggil test() per provider
   - confirmAlias(AiAlias): set status=confirmed, confirmed_by=auth()->id()
   - rejectAlias(AiAlias): set status=rejected, confirmed_by=auth()->id()

File yang harus dilampirkan:
- app/Http/Controllers/AiProviderController.php
- app/Models/AiProvider.php
- app/Models/AiAlias.php
- app/Services/AiService.php
```

---

## AGENT: fix-bot-polling
> Perbaiki stopPolling agar berjalan di Linux/production

**Masalah:** `BotController::stopPolling()` menggunakan perintah WMIC
yang hanya berjalan di Windows. Di server Linux (production) perintah ini gagal.

```
Perbaiki:

1. app/Http/Controllers/BotController.php — method stopPolling():
   Ganti WMIC dengan mekanisme stop file:
   - Buat file storage/app/telegram_poll.stop
   - PollTelegramUpdates akan cek keberadaan file ini di setiap loop
   - Jika file ada → break loop → hapus file
   Method startPolling() juga harus hapus file .stop jika ada sebelum mulai

2. app/Console/Commands/PollTelegramUpdates.php:
   Pastikan loop mengecek file stop di awal setiap iterasi

File yang harus dilampirkan:
- app/Http/Controllers/BotController.php
- app/Console/Commands/PollTelegramUpdates.php
```

---

## AGENT: enhance-wizard
> Tambah persistensi state wizard agar tahan restart

**Masalah:** State wizard disimpan di Laravel Cache (volatile).
Jika server restart atau cache flush, semua sesi wizard teknisi yang aktif
hilang tanpa notifikasi — teknisi tidak tahu harus mulai ulang.

```
Perbaiki:

1. Buat migration: add `wizard_state` JSON nullable ke tabel `reports`
   Laporan draft yang sedang diisi wizard disimpan ke DB bukan hanya cache
   Sehingga saat cache flush, state bisa di-recover dari DB

2. app/Services/Telegram/ReportWizardService.php:
   - Ubah saveState() agar juga persist ke report draft di DB (jika sudah ada)
   - Ubah loadState() agar fallback ke DB jika cache miss
   - Tambah recoverSession(string $chatId): bool
     (cek apakah ada report draft aktif untuk chat_id ini, restore ke cache)

3. app/Console/Commands/PollTelegramUpdates.php:
   Saat user mengirim pesan tapi tidak ada state di cache,
   coba recover session dari DB sebelum memulai wizard baru

File yang harus dilampirkan:
- app/Services/Telegram/ReportWizardService.php
- app/Console/Commands/PollTelegramUpdates.php
- app/Models/Report.php
```

---

## AGENT: dashboard-analytics
> Buat dashboard analytics yang real

**Masalah:** Dashboard menampilkan data tapi grafik dan tren belum
menggunakan data aktual dari DB secara optimal.

```
Kembangkan:

1. app/Http/Controllers/DashboardController.php:
   - Laporan per area (7 hari terakhir) → chart data
   - Laporan per teknisi top 5 (bulan ini)
   - Tren laporan harian (30 hari terakhir)
   - Rata-rata durasi pekerjaan per report_type
   - Distribusi status laporan (draft/needs_review/completed)
   - Provider AI paling sering dipakai
   - Unknown assets yang belum di-mapping

2. resources/views/dashboard/index.blade.php:
   - Grafik bar laporan per hari (30 hari) — Chart.js via CDN
   - Grafik donut distribusi status laporan
   - Tabel top 5 teknisi aktif bulan ini
   - Alert card: pending registrasi, unknown assets, laporan perlu review

File yang harus dilampirkan:
- app/Http/Controllers/DashboardController.php
- resources/views/dashboard/index.blade.php
```

---

## AGENT: alias-learning
> Implementasikan alias learning otomatis dari laporan yang dikonfirmasi

**Masalah:** `new_alias_suggestion` dari response AI sudah ada di JSON
tapi tidak pernah disimpan ke tabel `ai_aliases` secara otomatis.

```
Implementasikan:

1. app/Services/AiService.php:
   Setelah analisa berhasil dan ada new_alias_suggestion di response,
   simpan ke ai_aliases dengan status=pending, source=ai_learned

2. app/Services/Telegram/ReportWizardService.php — saveReport():
   Saat teknisi mengonfirmasi laporan di Step 8, jika ada alias baru
   dari AI yang pending, simpan atau update usage_count-nya

3. app/Http/Controllers/AiProviderController.php:
   - confirmAlias(): tambah logic increment usage_count
   - Panel alias di view sudah menampilkan alias pending — pastikan
     data ter-load dengan benar dari DB

File yang harus dilampirkan:
- app/Services/AiService.php
- app/Services/Telegram/ReportWizardService.php
- app/Http/Controllers/AiProviderController.php
- app/Models/AiAlias.php
- resources/views/ai-providers/index.blade.php
```

---

## AGENT: notification-system
> Kirim notifikasi ke admin saat laporan masuk

**Masalah:** Saat laporan baru tersimpan dari wizard, tidak ada notifikasi
ke admin/supervisor. Admin hanya bisa tahu jika aktif membuka panel.

```
Buat:

1. app/Services/NotificationService.php (FILE BARU):
   - notifyNewReport(Report $report): void
     Kirim pesan Telegram ke semua user admin/supervisor yang punya telegram_id
     Format: ringkasan laporan (kode, teknisi, area, equipment, durasi)
   - notifyPendingRegistration(BotRegistration $reg): void
     Kirim notifikasi ke admin saat ada teknisi baru mendaftar

2. app/Services/Telegram/ReportWizardService.php — saveReport():
   Setelah report berhasil disimpan, dispatch NotificationService::notifyNewReport()

3. app/Models/User.php:
   Tambah kolom telegram_id dan telegram_username ke fillable dan migration baru
   Tambah scope: scopeNotifiable() → admin/supervisor yang punya telegram_id

4. Buat migration: add telegram_id, telegram_username ke tabel users

File yang harus dilampirkan:
- app/Services/Telegram/ReportWizardService.php
- app/Services/TelegramService.php
- app/Models/User.php
- app/Http/Controllers/TechnicianController.php (untuk notif registrasi baru)
```

---

## AGENT: asset-mapping (ASSET_MAPPING.md)
> Lihat file ASSET_MAPPING.md untuk detail mapping TechIdentNo dan FuncLoc

Referensi untuk pengembangan yang melibatkan:
- Parsing Functional Location
- Pencarian TechIdentNo
- Struktur hierarki Company → Dept → Area → SubArea → Asset
