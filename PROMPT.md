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
AI       : Groq API (llama-3.3-70b-versatile), Ollama (local fallback), OpenAI (fallback)
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
section (mekanik/electrical/it/instrumentasi/sipil/welding/general/lainnya),
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

Foto disimpan di `storage/app/public/reports/{chat_id}/{uniqid}.jpg`
dan diakses via `Storage::disk('public')->url($path)`.

---

## Services Penting

### AiService
- `analyzeReport(Report $report)` — pilih provider, call Groq, parse JSON response
- Fallback otomatis: Groq → Ollama → OpenAI
- Log ke `ai_usage_logs`, update token counter provider
- Return: `{type, confidence, candidates[], area_id, reasoning, new_alias_suggestion}`

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
