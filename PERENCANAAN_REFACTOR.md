# PERENCANAAN REFACTOR — Project BOT
> Dibuat: 2026-06-27 | Fokus: Pemecahan file panjang + pembersihan emoji dari kode

---

## 1. RINGKASAN FITUR

Ini bukan pengembangan fitur baru, melainkan **refactor struktural** untuk meningkatkan
keterbacaan dan maintainability kode. Ada dua sasaran utama:

1. **Pemecahan file yang terlalu panjang** — file melebihi 400 baris dipecah menjadi
   kelas atau trait yang lebih kecil dengan tanggung jawab tunggal (Single Responsibility).
2. **Pembersihan emoji** — emoji yang tersebar di string pesan bot, komentar, dan label
   tombol keyboard dipindahkan ke konstanta terpusat atau dihapus dari kode PHP murni.

### Hasil audit panjang file (hanya yang > 400 baris, kecuali `composer.lock`):

| File | Baris | Lokasi |
|------|------:|--------|
| `ReportWizardService.php` | 1.549 | `app/Services/Telegram/` |
| `ClarificationService.php` | 797 | `app/Services/Telegram/` |
| `AiService.php` | 714 | `app/Services/` |
| `PollTelegramUpdates.php` | 608 | `app/Console/Commands/` |
| `AssetController.php` | 523 | `app/Http/Controllers/` |
| `TechIdentSearchService.php` | 415 | `app/Services/` |
| `PhotoStorageService.php` | 320 | `app/Services/Telegram/` — *batas bawah, tidak wajib dipecah* |

### File mengandung emoji yang perlu dibersihkan:

| File | Jumlah baris emoji |
|------|-----------------:|
| `ReportWizardService.php` | 39 |
| `ClarificationService.php` | 15 |
| `TelegramWebhookController.php` | 6 |
| `TelegramService.php` | 1 |
| `BotController.php` | 1 |

**Strategi emoji:** semua string pesan ke Telegram (yang memang ditampilkan ke pengguna)
boleh tetap mengandung emoji — yang dilarang adalah emoji di nama variabel, komentar,
dan nama method. String pesan yang berulang dipindahkan ke konstanta kelas.

---

## 2. ROADMAP SESI

---

### SESI 1 — Pecah ReportWizardService (Bagian 1): Step Handler

```
Tujuan   : Ekstrak logika handler per step wizard menjadi trait terpisah
           sehingga ReportWizardService turun dari 1.549 ke ~400 baris.
Perbaikan:
  - Buat WizardStepHandlerTrait.php — menampung semua protected method
    handler per step (Step 2 equipment clarify, Step 4 duration,
    Step 5 root cause, Step 6-7 foto).
  - Buat WizardCallbackHandlerTrait.php — menampung handleCallback(),
    handleConfirmationCallback(), handleHierarchyCancel(),
    handleEquipmentCandidateCallback(), handleClarificationCallback(),
    handleClarificationText(), handleClarificationAutoSelect(),
    finalizeClarificationSelection(), handleConfirmationCallback().
  - ReportWizardService menjadi orkestrator murni: hanya startWizard(),
    handleTextInput(), handlePhotoInput(), state management, dan
    method pembantu kecil (errorResponse, createInitialState, dsb).
  - Hapus semua emoji dari komentar dan docblock di ketiga file.
```

File yang harus dilampirkan di sesi ini:

| No | Path File | Keterangan |
|----|-----------|------------|
| 1 | `app/Services/Telegram/ReportWizardService.php` | File utama yang dipecah |

File baru yang akan dibuat:

| No | Path File Baru | Isi |
|----|----------------|-----|
| 1 | `app/Services/Telegram/Traits/WizardStepHandlerTrait.php` | Handler Step 2, 4, 5, 6, 7 |
| 2 | `app/Services/Telegram/Traits/WizardCallbackHandlerTrait.php` | Handler semua callback wizard |

---

### SESI 2 — Pecah ReportWizardService (Bagian 2): Save & Utilities

```
Tujuan   : Ekstrak logika penyimpanan laporan dan utilitas menjadi
           trait/kelas terpisah; ReportWizardService selesai di ~350 baris.
Perbaikan:
  - Buat WizardReportSaverTrait.php — menampung saveReport(),
    buildConfirmationSummary(), isValidLocalPhotoPath(),
    filterValidLocalPhotoPaths(), generateReportCode().
  - Buat WizardUtilityTrait.php — menampung parseDurationToMinutes(),
    formatDuration(), equipmentLabel(), buildCandidateKeyboard(),
    buildWorkDurationPrompt(), buildRootCausePrompt(),
    buildPhotoDocumentationPrompt(), buildPhotoHygienePrompt(),
    buildDoneMessage-equivalent di wizard.
  - Buat WizardPhotoAddonTrait.php — menampung addPhotoToReport(),
    addPhotoToStep(), detectPhotoTypeFromCaption(), extractReportCode().
  - Pastikan ReportWizardService use semua trait tersebut.
  - Tidak ada perubahan logika, hanya reorganisasi.
```

File yang harus dilampirkan di sesi ini:

| No | Path File | Keterangan |
|----|-----------|------------|
| 1 | `app/Services/Telegram/ReportWizardService.php` | Versi hasil Sesi 1 |
| 2 | `app/Services/Telegram/Traits/WizardStepHandlerTrait.php` | Hasil Sesi 1 |
| 3 | `app/Services/Telegram/Traits/WizardCallbackHandlerTrait.php` | Hasil Sesi 1 |

File baru yang akan dibuat:

| No | Path File Baru | Isi |
|----|----------------|-----|
| 1 | `app/Services/Telegram/Traits/WizardReportSaverTrait.php` | Logika simpan laporan |
| 2 | `app/Services/Telegram/Traits/WizardUtilityTrait.php` | Format & builder pesan |
| 3 | `app/Services/Telegram/Traits/WizardPhotoAddonTrait.php` | Tambah foto post-submit |

---

### SESI 3 — Pecah ClarificationService

```
Tujuan   : ClarificationService turun dari 797 ke ~300 baris dengan
           mengekstrak builder keyboard hierarki FuncLoc.
Perbaikan:
  - Buat ClarificationKeyboardBuilderTrait.php — menampung semua
    private method buildXxxSelection() (Company, Department, Area,
    SubArea, Section, Type, Equipment) serta fetchEquipmentCandidates()
    dan nextLevelAfterAreaOrSubArea().
  - Buat ClarificationMessageBuilderTrait.php — menampung
    buildCurrentMessage() dan buildDoneMessage().
  - ClarificationService menjadi: getOrCreateSession(), processSelection(),
    destroySession(), getSession(), saveSession() — ~300 baris.
  - Bersihkan 15 baris emoji dari komentar dan docblock (bukan string
    pesan ke Telegram).
```

File yang harus dilampirkan di sesi ini:

| No | Path File | Keterangan |
|----|-----------|------------|
| 1 | `app/Services/Telegram/ClarificationService.php` | File utama |
| 2 | `app/Services/Telegram/FuncLocParser.php` | Dependency langsung |

File baru yang akan dibuat:

| No | Path File Baru | Isi |
|----|----------------|-----|
| 1 | `app/Services/Telegram/Traits/ClarificationKeyboardBuilderTrait.php` | Builder keyboard hierarki |
| 2 | `app/Services/Telegram/Traits/ClarificationMessageBuilderTrait.php` | Builder pesan & navigasi |

---

### SESI 4 — Pecah AiService

```
Tujuan   : AiService turun dari 714 ke ~280 baris dengan mengekstrak
           logika parsing keyword dan deteksi asset.
Perbaikan:
  - Buat AiKeywordParserTrait.php — menampung analyzeWithKeywords(),
    detectArea(), detectAssetByTechIdent(), firstMatch(),
    detectReportType(), checkAliases(), parseWorkDurationMinutes(),
    parseRootCauseHint().
  - Buat AiProviderCallerTrait.php — menampung analyzeWithAi(),
    callGroq(), stripJsonFence(), getBestProvider().
  - AiService menjadi: analyzeReportText() + konstanta prompt + DI
    constructor saja (~200 baris).
  - Tidak ada perubahan logika atau interface publik.
```

File yang harus dilampirkan di sesi ini:

| No | Path File | Keterangan |
|----|-----------|------------|
| 1 | `app/Services/AiService.php` | File utama |
| 2 | `app/Models/AiProvider.php` | Dependency model |
| 3 | `app/Models/AiAlias.php` | Dependency model |
| 4 | `app/Models/AiUsageLog.php` | Dependency model |

File baru yang akan dibuat:

| No | Path File Baru | Isi |
|----|----------------|-----|
| 1 | `app/Services/Traits/AiKeywordParserTrait.php` | Deteksi keyword, area, asset |
| 2 | `app/Services/Traits/AiProviderCallerTrait.php` | Panggil API provider AI |

---

### SESI 5 — Pecah PollTelegramUpdates

```
Tujuan   : PollTelegramUpdates turun dari 608 ke ~280 baris dengan
           mengekstrak helper Telegram API dan handler pesan.
Perbaikan:
  - Buat TelegramSenderTrait.php — menampung sendMessage(),
    sendMessageWithKeyboard(), sendChatAction(), answerCallbackQuery(),
    editMessageText(), editMessageTextSimple().
  - Buat TelegramMessageHandlerTrait.php — menampung processUpdate(),
    handlePhotoMessage(), handleWizardText(), handleWizardCallback(),
    dispatchWizardResponse(), handleStart(), handleNikRegistration(),
    handleReport().
  - PollTelegramUpdates menjadi: handle(), startPolling() (loop utama),
    state management file (getLastOffset, saveLastOffset, updateLock,
    showStatus, stopPolling), processCallbackQuery() — ~250 baris.
```

File yang harus dilampirkan di sesi ini:

| No | Path File | Keterangan |
|----|-----------|------------|
| 1 | `app/Console/Commands/PollTelegramUpdates.php` | File utama |
| 2 | `app/Services/Telegram/ReportWizardService.php` | Hasil Sesi 1+2 |

File baru yang akan dibuat:

| No | Path File Baru | Isi |
|----|----------------|-----|
| 1 | `app/Console/Commands/Traits/TelegramSenderTrait.php` | Kirim pesan ke Telegram API |
| 2 | `app/Console/Commands/Traits/TelegramMessageHandlerTrait.php` | Routing & handler pesan masuk |

---

### SESI 6 — Pecah TechIdentSearchService + Pecah AssetController

```
Tujuan   : TechIdentSearchService turun dari 415 ke ~250 baris;
           AssetController turun dari 523 ke ~300 baris.
Perbaikan (TechIdentSearchService):
  - Buat TechIdentTokenizerTrait.php — menampung extractTokens(),
    normalize(), extractSectionCode(), extractTypeSuffixAndSequence(),
    detectInstrumentPrefixes().
  - TechIdentSearchService menjadi: search() (logika 3-pass), candidateAssets(),
    autoAccept(), confirm(), noMatch(), formatCandidates() — ~250 baris.
Perbaikan (AssetController):
  - Buat AssetExportTrait.php — menampung exportExcel() dan exportCsv().
  - Buat AssetTechnicianTrait.php — menampung getAssignedTechnicians(),
    assignTechnician(), removeTechnician(), broadcastToTechnicians(),
    listTechnicians().
  - Buat AssetLocationTrait.php — menampung getDepartments(), getAreas(),
    getSubAreas().
  - AssetController menjadi: index(), show(), create(), store(), edit(),
    update(), destroy() — ~200 baris.
```

File yang harus dilampirkan di sesi ini:

| No | Path File | Keterangan |
|----|-----------|------------|
| 1 | `app/Services/TechIdentSearchService.php` | File utama search |
| 2 | `app/Http/Controllers/AssetController.php` | File utama controller asset |
| 3 | `app/Models/Asset.php` | Dependency model |

File baru yang akan dibuat:

| No | Path File Baru | Isi |
|----|----------------|-----|
| 1 | `app/Services/Traits/TechIdentTokenizerTrait.php` | Tokenisasi & normalisasi teks |
| 2 | `app/Http/Controllers/Traits/AssetExportTrait.php` | Export Excel & CSV asset |
| 3 | `app/Http/Controllers/Traits/AssetTechnicianTrait.php` | Manajemen penugasan teknisi |
| 4 | `app/Http/Controllers/Traits/AssetLocationTrait.php` | Lookup hierarki lokasi |

---

### SESI 7 — Bersihkan Emoji dari Semua File

```
Tujuan   : Hapus emoji dari komentar, docblock, dan string non-UI
           di seluruh codebase. String pesan ke pengguna Telegram
           (yang memang tampil di chat) boleh dipertahankan.
Perbaikan:
  - ReportWizardService (+ trait hasil Sesi 1-2): hapus emoji dari
    komentar dan docblock. String tombol keyboard tetap boleh ada emoji
    karena itu UI Telegram yang dilihat teknisi.
  - ClarificationService (+ trait hasil Sesi 3): sama.
  - TelegramWebhookController: hapus emoji dari 6 baris string respons
    (ganti dengan teks biasa — WebhookController sudah deprecated,
    logika dipindah ke PollTelegramUpdates).
  - TelegramService: hapus 1 baris emoji di string HTML broadcast.
  - BotController: hapus 1 baris emoji di string success message.
  - Verifikasi ulang semua file hasil refactor Sesi 1-6.
```

File yang harus dilampirkan di sesi ini:

| No | Path File | Keterangan |
|----|-----------|------------|
| 1 | `app/Http/Controllers/TelegramWebhookController.php` | 6 baris emoji |
| 2 | `app/Services/Telegram/TelegramService.php` | 1 baris emoji |
| 3 | `app/Http/Controllers/BotController.php` | 1 baris emoji |
| 4 | Semua file trait hasil Sesi 1-6 | Verifikasi bersih |

---

## 3. TABEL CEKLIST KESELURUHAN

| Sesi | Judul | File Disentuh / Dibuat | Status |
|------|-------|------------------------|--------|
| 1 | Pecah ReportWizardService Bagian 1 | `ReportWizardService.php` (diubah) | [ ] |
| 1 | | `Traits/WizardStepHandlerTrait.php` (baru) | [ ] |
| 1 | | `Traits/WizardCallbackHandlerTrait.php` (baru) | [ ] |
| 2 | Pecah ReportWizardService Bagian 2 | `ReportWizardService.php` (diubah lagi) | [ ] |
| 2 | | `Traits/WizardReportSaverTrait.php` (baru) | [ ] |
| 2 | | `Traits/WizardUtilityTrait.php` (baru) | [ ] |
| 2 | | `Traits/WizardPhotoAddonTrait.php` (baru) | [ ] |
| 3 | Pecah ClarificationService | `ClarificationService.php` (diubah) | [ ] |
| 3 | | `Traits/ClarificationKeyboardBuilderTrait.php` (baru) | [ ] |
| 3 | | `Traits/ClarificationMessageBuilderTrait.php` (baru) | [ ] |
| 4 | Pecah AiService | `AiService.php` (diubah) | [ ] |
| 4 | | `Services/Traits/AiKeywordParserTrait.php` (baru) | [ ] |
| 4 | | `Services/Traits/AiProviderCallerTrait.php` (baru) | [ ] |
| 5 | Pecah PollTelegramUpdates | `PollTelegramUpdates.php` (diubah) | [ ] |
| 5 | | `Commands/Traits/TelegramSenderTrait.php` (baru) | [ ] |
| 5 | | `Commands/Traits/TelegramMessageHandlerTrait.php` (baru) | [ ] |
| 6 | Pecah TechIdentSearchService + AssetController | `TechIdentSearchService.php` (diubah) | [ ] |
| 6 | | `Services/Traits/TechIdentTokenizerTrait.php` (baru) | [ ] |
| 6 | | `AssetController.php` (diubah) | [ ] |
| 6 | | `Controllers/Traits/AssetExportTrait.php` (baru) | [ ] |
| 6 | | `Controllers/Traits/AssetTechnicianTrait.php` (baru) | [ ] |
| 6 | | `Controllers/Traits/AssetLocationTrait.php` (baru) | [ ] |
| 7 | Bersihkan Emoji | `TelegramWebhookController.php` (diubah) | [ ] |
| 7 | | `TelegramService.php` (diubah) | [ ] |
| 7 | | `BotController.php` (diubah) | [ ] |
| 7 | | Semua trait baru (verifikasi) | [ ] |

---

## ATURAN WAJIB UNTUK SEMUA SESI PENGERJAAN

```
=== ATURAN PENGERJAAN (WAJIB DIIKUTI DI SETIAP SESI) ===

PENULISAN KODE:
- Kode ditulis rapi, terstruktur, dan konsisten dengan gaya kode yang sudah ada di project
- Tidak ada emoji di dalam kode, komentar, string konstanta, maupun nama variabel
- Komentar ditulis dalam Bahasa Indonesia yang jelas
- Setiap method baru wajib memiliki docblock singkat (parameter + return)
- Hapus kode yang tidak dipakai, jangan di-comment out tanpa alasan
- Gunakan early return untuk menghindari nesting yang dalam

TAMPILAN (VIEW / BLADE / FRONTEND):
- Tampilan baru harus menyesuaikan tema visual yang sudah ada (warna, spacing, komponen, class naming)
- Gunakan komponen yang sudah ada di project, jangan buat ulang yang setara
- Tidak ada inline style kecuali untuk nilai dinamis

ALUR KERJA PER SESI:
- Sebutkan ulang daftar file yang akan disentuh di awal sesi beserta alasannya
- Setiap file yang diubah ditulis ulang secara lengkap, bukan hanya snippet
- Di akhir sesi, berikan ringkasan perubahan dan instruksi deploy jika ada
- Jika ada file yang dibutuhkan tapi belum dilampirkan, minta terlebih dahulu sebelum menulis kode
- Jangan mengarang isi file yang belum dilihat

KHUSUS REFACTOR INI:
- Tidak boleh ada perubahan logika — hanya pemindahan kode ke lokasi baru
- Semua method yang dipindahkan ke trait harus tetap dapat diakses dengan visibilitas
  yang sama (public tetap public, protected tetap protected)
- Setiap trait baru wajib memiliki blok komentar di atas class yang menjelaskan
  method apa saja yang ada di dalamnya dan mengapa dikelompokkan bersama
- Setelah memindahkan method ke trait, verifikasi tidak ada circular dependency
- String pesan yang tampil ke pengguna Telegram (teks chat, label tombol keyboard)
  boleh tetap mengandung emoji — yang dilarang adalah emoji di komentar, docblock,
  nama variabel, dan nama method
=================================================
```
