<?php

namespace App\Services\Telegram;

use App\Models\Area;
use App\Models\Asset;
use App\Models\FunctionalLocation;
use App\Services\AiService;
use App\Services\TechIdentSearchService;
use App\Services\Telegram\CollaboratorSearchService;
use App\Services\Telegram\Traits\WizardCallbackHandlerTrait;
use App\Services\Telegram\Traits\WizardFuncLocPickerTrait;
use App\Services\Telegram\Traits\WizardPhotoAddonTrait;
use App\Services\Telegram\Traits\WizardReportSaverTrait;
use App\Services\Telegram\Traits\WizardStepHandlerTrait;
use App\Services\Telegram\Traits\WizardUtilityTrait;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * ReportWizardService
 *
 * Orkestrator utama wizard 9-step laporan via Telegram Bot.
 * Pendekatan "Create at End" — laporan hanya disimpan ke DB setelah
 * teknisi mengonfirmasi di Step 8. Semua state disimpan di Laravel Cache
 * per chat_id.
 *
 * Step 1 : Terima Laporan Awal     -> jalankan TechIdentSearch 3-pass, deteksi tanggal laporan
 * Step 2 : Klarifikasi Equipment   -> keyboard kandidat / tulis ulang / hierarki
 * Step 3 : Akselerasi FuncLoc      -> ditangani ClarificationService & FuncLocParser
 * Step 4 : Waktu Pengerjaan        -> parse durasi atau tanya ke teknisi
 * Step 5 : Root Cause              -> input teks bebas, wajib min 3 karakter
 * Step 6 : Foto Dokumentasi        -> multi-foto opsional, bisa skip
 * Step 7 : Foto Hygiene Clearance  -> multi-foto opsional, bisa skip
 * Step 8a: Kolaborator (opsional)  -> input NIK rekan kerja, bisa dilewati
 * Step 8 : Konfirmasi & Simpan     -> tampilkan ringkasan, simpan ke DB jika OK
 *
 * Tanggung jawab class ini (orkestrator):
 *   - Entry point publik: startWizard, handleTextInput, handlePhotoInput, handleCallback
 *   - Step 1: processEquipmentSearch + buildCandidateKeyboard
 *   - Step 2: handleEquipmentRetype + startClarificationHierarchy + lockEquipmentAndAdvance
 *   - State management: getState, saveState, destroyWizard, hasActiveWizard
 *
 * Yang BUKAN tanggung jawab class ini:
 *   - Pencarian TechIdentNo presisi (TechIdentSearchService)
 *   - Keyboard hierarki Area/Section/Tipe (ClarificationService)
 *   - Download & simpan foto dari Telegram API (PhotoStorageService)
 *   - Polling Telegram & dispatch pesan masuk (PollTelegramUpdates)
 *
 * Trait yang digunakan:
 *   - WizardStepHandlerTrait    : handler per-step (4, 5, 6, 7, 8a, konfirmasi teks)
 *   - WizardCallbackHandlerTrait: handler semua callback inline keyboard
 *   - WizardReportSaverTrait    : simpan laporan ke DB, validasi foto, generate kode
 *   - WizardUtilityTrait        : format durasi/tanggal, label equipment, state awal, error response
 *   - WizardPhotoAddonTrait     : tambah foto ke laporan tersimpan via report_code
 *   - WizardFuncLocPickerTrait  : keyboard hierarki FuncLoc untuk alur laporan area/section
 *
 * NAVIGASI KEMBALI/BATAL (step_history):
 * State wizard (dibuat via WizardUtilityTrait::createInitialState()) menyimpan
 * array 'step_history' berisi urutan step yang pernah dilalui. Setiap kali wizard
 * maju ke step berikutnya, step yang ditinggalkan wajib di-push ke riwayat lewat
 * WizardUtilityTrait::pushStepHistory() sebelum $state['step'] diubah. Tombol
 * Kembali (callback_data 'wizard:back') ditangani WizardCallbackHandlerTrait::
 * handleBackNavigation(), yang mem-pop riwayat dan membangun ulang prompt step
 * tujuan lewat rebuildPromptForStep() — tanpa menghapus data yang sudah diisi
 * teknisi. Tombol Batalkan Laporan (callback_data 'wizard:cancel_wizard') tetap
 * ditangani terpisah di routeCallback() seperti sebelumnya. Kedua tombol ini
 * wajib disertakan konsisten di setiap step lewat
 * WizardUtilityTrait::navigationButtons().
 *
 * Step equipment (Step 1/2/3 — pencarian, klarifikasi, FuncLoc) diselaraskan
 * di Sesi 5: buildCandidateKeyboard() dan buildEquipmentConfirmKeyboard() kini
 * juga memakai navigationButtons(), ClarificationKeyboardBuilderTrait
 * menyertakan tombol Batalkan Laporan konsisten di setiap level hierarki, dan
 * WizardFuncLocPickerTrait mendapat kemampuan naik satu level (callback
 * 'funcloc_back') selain tombol Batalkan Laporan di setiap level FuncLoc.
 * Kembali dari Step 4 menuju step equipment (rebuildPromptForStep) menjalankan
 * ulang pencarian dari teks laporan awal, bukan mereka-reka tampilan sementara
 * yang datanya sudah dibersihkan saat equipment/area dikunci.
 *
 * PERBAIKAN SESI 6 (uji menyeluruh & rapikan konsistensi akhir):
 * Ditemukan bug pada WizardStepHandlerTrait::advanceToWorkDuration() — gerbang
 * tunggal dari step equipment menuju Step 4 — yang sebelumnya TIDAK memanggil
 * pushStepHistory() sebelum mengubah $state['step'], sehingga step equipment
 * tidak pernah tercatat di riwayat dan tombol Kembali tidak pernah tampil saat
 * teknisi baru pertama kali sampai di Step 4 (baru muncul mulai Step 5 dan
 * seterusnya). Sudah diperbaiki dengan menambahkan pushStepHistory($state,
 * $state['step']) di awal method tersebut, sehingga otomatis berlaku untuk
 * semua jalur penguncian equipment (lock langsung, hierarki klarifikasi,
 * FuncLoc). Diverifikasi juga: tidak ada duplikasi foto pada back-lalu-maju
 * berulang di Step 6/7 (dijaga oleh in_array pada prepend foto awal dan
 * append murni pada addPhotoToStep), dan data kolaborator dari hierarki tetap
 * terjaga saat back berulang (hanya wizard:cancel_wizard yang mereset state).
 *
 * KESADARAN LOKASI PADA PENCARIAN EQUIPMENT (Sesi 4 — perbaikan pencarian
 * equipment berbasis lokasi):
 * processEquipmentSearch() dan handleEquipmentRetype() kini mengirim area_id
 * yang sudah diketahui di $state (jika ada, mis. hasil lockEquipmentAndAdvance()
 * atau confirmAiLocationSuggestion() pada percakapan sebelumnya) ke
 * TechIdentSearchService::search($text, $areaId) — parameter yang sudah lama
 * tersedia di service tersebut tapi sebelumnya tidak pernah diisi. Jika
 * area_id belum diketahui (paling umum: saat Step 1 pertama kali berjalan),
 * $areaId bernilai null dan TechIdentSearchService sendiri yang mencoba
 * mendeteksi area dari kode/nama lokasi yang disebut eksplisit di teks
 * (lihat TechIdentSearchService::resolveAreaIdForRanking()). buildCandidateKeyboard()
 * tidak perlu logika pengurutan tambahan di sini — urutan $candidates yang
 * diterima dari TechIdentSearchService::search() sudah diprioritaskan
 * berdasarkan lokasi sebelum dipotong ke 4 kandidat teratas, dan
 * array_slice() di method ini mempertahankan urutan tersebut apa adanya.
 */
class ReportWizardService
{
    use WizardStepHandlerTrait;
    use WizardCallbackHandlerTrait;
    use WizardFuncLocPickerTrait;
    use WizardReportSaverTrait;
    use WizardUtilityTrait;
    use WizardPhotoAddonTrait;

    const CACHE_PREFIX = 'report_wizard:';
    const CACHE_TTL    = 7200; // 2 jam — cukup untuk satu shift kerja

    /** Step ID — digunakan sebagai konstanta agar tidak typo. */
    const STEP_INITIAL             = 'initial';
    const STEP_WORK_CLASSIFICATION = 'work_classification';
    const STEP_EQUIPMENT_SEARCH    = 'equipment_search';
    const STEP_EQUIPMENT_CLARIFY   = 'equipment_clarify';
    const STEP_WORK_DURATION       = 'work_duration';
    const STEP_ROOT_CAUSE          = 'root_cause';
    const STEP_PHOTO_DOCUMENTATION = 'photo_documentation';
    const STEP_PHOTO_HYGIENE       = 'photo_hygiene';
    const STEP_COLLABORATOR        = 'collaborator';
    const STEP_CONFIRMATION        = 'confirmation';
    const STEP_DONE                = 'done';

    /** Confidence minimum untuk auto-accept hasil pencarian TechIdentNo. */
    const CONFIDENCE_AUTO_ACCEPT = 95;

    /** Batas minimum karakter untuk field root_cause. */
    const ROOT_CAUSE_MIN_LENGTH = 3;

    /** Confidence minimum AI untuk dianggap sebagai kandidat valid. */
    const AI_CONFIDENCE_MIN = 60;

    protected TechIdentSearchService $techIdentSearch;
    protected AiService $aiService;
    protected ClarificationService $clarificationService;
    protected CollaboratorSearchService $collaboratorSearch;

    public function __construct(
        TechIdentSearchService $techIdentSearch,
        AiService $aiService,
        ClarificationService $clarificationService,
        CollaboratorSearchService $collaboratorSearch
    ) {
        $this->techIdentSearch      = $techIdentSearch;
        $this->aiService            = $aiService;
        $this->clarificationService = $clarificationService;
        $this->collaboratorSearch   = $collaboratorSearch;
    }

    // =========================================================
    // ENTRY POINTS — dipanggil dari PollTelegramUpdates
    // =========================================================

    /**
     * Mulai wizard baru dari teks laporan awal teknisi.
     * Jika ada wizard aktif sebelumnya, wizard lama di-reset.
     *
     * PENTING: Parameter $photoFileId harus berisi path lokal hasil
     * PhotoStorageService::store() (contoh: "reports/2026/07/03/123456/photo_abc.jpg"),
     * BUKAN raw file_id Telegram (contoh: "AgACAgIAAxkBAAI...").
     * Download ke storage lokal wajib dilakukan sebelum memanggil method ini.
     * Path yang tidak mengandung "/" akan diabaikan dan tidak disimpan ke state.
     *
     * @param  string      $chatId      Chat ID Telegram
     * @param  string      $text        Teks laporan dari teknisi
     * @param  string|null $photoFileId Path lokal foto awal (opsional)
     * @return array       Respons yang harus dikirim ke teknisi
     */
    public function startWizard(string $chatId, string $text, ?string $photoFileId = null): array
    {
        $this->destroyWizard($chatId);

        $state = $this->createInitialState($chatId, $text);

        if ($photoFileId) {
            // Guard: tolak raw file_id Telegram yang belum di-download.
            // Path lokal selalu mengandung "/" sebagai pemisah direktori.
            if (!str_contains($photoFileId, '/')) {
                Log::warning("startWizard: photoFileId ditolak karena bukan path lokal.", [
                    'chat_id'      => $chatId,
                    'photo_file_id' => $photoFileId,
                ]);
            } else {
                $state['initial_photo_file_id'] = $photoFileId;
            }
        }

        // Analisa teks awal dengan AI untuk ekstrak area, equipment,
        // durasi, root cause — hasil digunakan di step-step berikutnya
        $analysis             = $this->aiService->analyzeReportText($text);
        $state['ai_analysis'] = $analysis;

        // Deteksi tanggal laporan dari teks awal (mendukung "kemarin", format
        // numerik, dan nama bulan Bahasa Indonesia). 'date' bernilai null jika
        // tidak terdeteksi atau di luar rentang valid — saveReport() akan
        // fallback ke hari ini. 'status' dipakai appendDateConfirmationNote()
        // untuk menentukan notifikasi apa yang perlu ditampilkan ke teknisi.
        $dateResult                  = $this->parseDateFromText($text);
        $state['report_date']        = $dateResult['date'];
        $state['report_date_status'] = $dateResult['status'];

        if (!empty($analysis['parsed_duration_minutes'])) {
            $state['work_duration_minutes'] = (int) $analysis['parsed_duration_minutes'];
        }
        if (!empty($analysis['parsed_root_cause'])) {
            $state['root_cause'] = $analysis['parsed_root_cause'];
        }

        // Step paling awal wizard: teknisi wajib memilih klasifikasi pekerjaan
        // (CM/PM/dCM/Fabrikasi/Sipil) sebelum pencarian equipment dijalankan.
        // Teks laporan ($state['text']) sudah tersimpan dan akan dipakai oleh
        // processEquipmentSearch() setelah klasifikasi dipilih
        // (lihat WizardCallbackHandlerTrait::handleWorkClassificationCallback()).
        $state['step'] = self::STEP_WORK_CLASSIFICATION;
        $this->saveState($chatId, $state);

        return $this->buildWorkClassificationPrompt($state);
    }

    /**
     * Proses teks input dari teknisi di tengah wizard yang sedang berjalan.
     *
     * @param  string $chatId Chat ID Telegram
     * @param  string $text   Teks yang dikirim teknisi
     * @return array  Respons yang harus dikirim ke teknisi
     */
    public function handleTextInput(string $chatId, string $text): array
    {
        $state = $this->getState($chatId);

        if (!$state) {
            return $this->errorResponse('Tidak ada sesi laporan aktif. Silakan mulai dengan mengirim laporan Anda.');
        }

        switch ($state['step']) {
            case self::STEP_EQUIPMENT_CLARIFY:
                return $this->handleEquipmentRetype($chatId, $text, $state);

            case self::STEP_WORK_DURATION:
                return $this->handleDurationInput($chatId, $text, $state);

            case self::STEP_ROOT_CAUSE:
                return $this->handleRootCauseInput($chatId, $text, $state);

            case self::STEP_PHOTO_DOCUMENTATION:
                return $this->handlePhotoCommand($chatId, $text, $state, 'documentation');

            case self::STEP_PHOTO_HYGIENE:
                return $this->handlePhotoCommand($chatId, $text, $state, 'hygiene');

            case self::STEP_COLLABORATOR:
                return $this->handleCollaboratorInput($chatId, $text, $state);

            case self::STEP_CONFIRMATION:
                return $this->handleConfirmation($chatId, $text, $state);

            default:
                return $this->errorResponse(
                    "Perintah tidak dikenali di step ini. Ikuti instruksi bot ya."
                );
        }
    }

    /**
     * Proses foto yang dikirim teknisi di tengah wizard.
     *
     * @param  string $chatId  Chat ID Telegram
     * @param  string $fileId  File ID foto dari Telegram
     * @param  string $caption Caption foto (opsional)
     * @return array  Respons
     */
    public function handlePhotoInput(string $chatId, string $fileId, string $caption = ''): array
    {
        $state = $this->getState($chatId);

        if (!$state) {
            return $this->errorResponse('Tidak ada sesi laporan aktif.');
        }

        switch ($state['step']) {
            case self::STEP_PHOTO_DOCUMENTATION:
                return $this->addPhotoToStep($chatId, $fileId, $state, 'documentation');

            case self::STEP_PHOTO_HYGIENE:
                return $this->addPhotoToStep($chatId, $fileId, $state, 'hygiene');

            default:
                return $this->errorResponse(
                    "Foto diterima, tapi sekarang bukan saatnya upload foto.\n" .
                    "Ikuti instruksi bot untuk melanjutkan laporan."
                );
        }
    }

    /**
     * Proses pilihan inline keyboard (callback dari Telegram).
     * Routing didelegasikan ke WizardCallbackHandlerTrait::routeCallback().
     *
     * @param  string $chatId       Chat ID Telegram
     * @param  string $callbackData Data callback dari Telegram
     * @return array  Respons
     */
    public function handleCallback(string $chatId, string $callbackData): array
    {
        $state = $this->getState($chatId);

        if (!$state) {
            return $this->errorResponse('Sesi tidak ditemukan. Silakan mulai laporan baru.');
        }

        return $this->routeCallback($chatId, $callbackData, $state);
    }

    // =========================================================
    // STEP 1 — EQUIPMENT SEARCH
    // Jalankan pencarian TechIdentNo 3-pass via TechIdentSearchService.
    // =========================================================

    /**
     * Jalankan TechIdentSearch dan putuskan alur berikutnya berdasarkan
     * nilai confidence dan jumlah kandidat yang ditemukan.
     *
     * Sinyal lokasi (Sesi 4): area_id yang sudah diketahui di $state (jika
     * ada) dikirim ke TechIdentSearchService::search() sebagai parameter
     * kedua, supaya kandidat di area tersebut diprioritaskan sebelum
     * kandidat dipotong ke 4 teratas. Jika belum diketahui (umum terjadi
     * di Step 1 pertama kali), nilainya null dan TechIdentSearchService
     * akan mencoba mendeteksi sendiri dari teks laporan.
     *
     * @param  string $chatId Chat ID Telegram
     * @param  array  $state  State wizard saat ini
     * @return array  Respons
     */
    protected function processEquipmentSearch(string $chatId, array $state): array
    {
        $text    = $state['text'];
        $areaId  = $state['area_id'] ?? null;
        $results = $this->techIdentSearch->search($text, $areaId);

        Log::info("WizardService: TechIdentSearch result for chat {$chatId}", [
            'confidence'  => $results['confidence'] ?? 0,
            'match_count' => count($results['candidates'] ?? []),
        ]);

        $confidence = $results['confidence'] ?? 0;
        $candidates = $results['candidates'] ?? [];
        $exactMatch = $results['exact_match'] ?? null;

        // Confidence >= 95% dan hanya satu kandidat: tampilkan keyboard konfirmasi
        // (tidak langsung lock — teknisi harus bisa mengoreksi jika deteksi keliru)
        if ($confidence >= self::CONFIDENCE_AUTO_ACCEPT && $exactMatch) {
            $state['pending_equipment_id']      = $exactMatch['id'] ?? null;
            $state['pending_equipment_ident']   = $exactMatch['tech_ident_no'] ?? null;
            $state['pending_equipment_funcloc'] = $exactMatch['functional_loc'] ?? null;
            $state['search_confidence']         = $confidence;
            $state['step']                      = self::STEP_EQUIPMENT_CLARIFY;
            $this->saveState($chatId, $state);

            $response = $this->buildEquipmentConfirmKeyboard($exactMatch, $confidence, $state);
            return $this->appendDateConfirmationNote($response, $state);
        }

        // Confidence 80-94%: ada kandidat ambigu -> tampilkan keyboard kandidat (Step 2)
        if (!empty($candidates)) {
            $state['step']            = self::STEP_EQUIPMENT_CLARIFY;
            $state['search_results']  = $results;
            $state['retype_attempts'] = 0;
            $this->saveState($chatId, $state);

            $response = $this->buildCandidateKeyboard($candidates, $results, $state);
            return $this->appendDateConfirmationNote($response, $state);
        }

        // Regex no_match — coba AI sebagai lapis kedua sebelum tanya manual.
        // AI mencari berdasarkan deskripsi alat (bukan hanya kode),
        // dengan data yang sudah di-pre-filter agar hemat token.
        $aiResult = $this->tryAiEquipmentSearch($chatId, $state, $text, $results);
        if ($aiResult !== null) {
            return $aiResult;
        }

        // AI equipment juga tidak menemukan — coba sebagai laporan lokasi/area
        // (Sesi 3) sebelum jatuh ke opsi manual.
        $aiLocationResult = $this->tryAiLocationSearch($chatId, $state, $text);
        if ($aiLocationResult !== null) {
            return $aiLocationResult;
        }

        // AI equipment maupun lokasi sama-sama tidak menemukan — tanya jenis
        // pekerjaan secara manual seperti alur sebelumnya.
        $state['step']               = self::STEP_EQUIPMENT_CLARIFY;
        $state['search_results']     = $results;
        $state['retype_attempts']    = 0;
        $state['awaiting_work_type'] = true;
        $this->saveState($chatId, $state);

        return $this->appendDateConfirmationNote($this->buildManualWorkTypePrompt($state), $state);
    }

    /**
     * Coba cari equipment via AI (lapis kedua setelah regex no_match).
     *
     * Memanggil AiService::searchEquipment() yang sudah menggunakan
     * pre-filter keyword-based dan prompt yang disetel untuk mencocokkan
     * description (bukan hanya tech_ident_no).
     *
     * Jika AI menemukan kandidat dengan confidence memadai, state wizard
     * akan diperbarui dan keyboard kandidat ditampilkan ke teknisi.
     * Jika tidak, mengembalikan null agar pemanggil bisa lanjut ke
     * fallback berikutnya.
     *
     * @param  string $chatId       Chat ID Telegram
     * @param  array  $state        State wizard saat ini
     * @param  string $text         Teks laporan dari teknisi
     * @param  array  $regexResults Hasil pencarian regex sebelumnya
     * @return array|null Respons (message + keyboard) atau null jika AI tidak menemukan
     */
    protected function tryAiEquipmentSearch(string $chatId, array $state, string $text, array $regexResults): ?array
    {
        $areaId = $state['area_id'] ?? null;
        $aiResult = $this->aiService->searchEquipment($text, $areaId);

        if ($aiResult === null) {
            Log::info("WizardService: AI equipment search returned null for chat {$chatId}", [
                'reason' => 'no_provider_or_low_confidence_or_empty_candidates',
            ]);
            return null;
        }

        $aiCandidates  = $aiResult['candidates'] ?? [];
        $aiConfidence  = $aiResult['confidence'] ?? 0;
        $aiStatus      = $aiResult['status'] ?? 'confirm';
        $aiReasoning   = $aiResult['ai_message'] ?? $aiResult['reasoning'] ?? null;

        if (empty($aiCandidates)) {
            Log::info("WizardService: AI equipment search returned zero candidates for chat {$chatId}");
            return null;
        }

        Log::info("WizardService: AI equipment search found candidates for chat {$chatId}", [
            'ai_confidence'  => $aiConfidence,
            'candidate_count' => count($aiCandidates),
            'ai_status'       => $aiStatus,
        ]);

        $aiSearchResults = [
            'status'     => $aiStatus,
            'pass'       => $aiResult['pass'] ?? 'ai_description',
            'confidence' => $aiConfidence,
            'candidates' => $aiCandidates,
        ];

        if ($aiStatus === 'auto_accept' && count($aiCandidates) === 1) {
            $candidate = $aiCandidates[0];
            $state['pending_equipment_id']      = $candidate['id'] ?? null;
            $state['pending_equipment_ident']   = $candidate['tech_ident_no'] ?? null;
            $state['pending_equipment_funcloc'] = $candidate['functional_loc'] ?? null;
            $state['search_confidence']         = $aiConfidence;
            $state['search_results']            = $aiSearchResults;
            $state['ai_suggestion_source']      = true;
            $state['ai_suggestion_type']        = 'equipment';
            $state['ai_suggestion_reasoning']   = $aiReasoning;
            $state['step']                      = self::STEP_EQUIPMENT_CLARIFY;
            $this->saveState($chatId, $state);

            $response = $this->buildEquipmentConfirmKeyboard($candidate, $aiConfidence, $state);
            $response['message'] = "_AI menemukan kandidat berdasarkan deskripsi alat._\n\n" . $response['message'];
            return $this->appendDateConfirmationNote($response, $state);
        }

        $state['step']                     = self::STEP_EQUIPMENT_CLARIFY;
        $state['search_results']           = $aiSearchResults;
        $state['retype_attempts']          = 0;
        $state['ai_suggestion_source']      = true;
        $state['ai_suggestion_type']        = 'equipment';
        $state['ai_suggestion_reasoning']   = $aiReasoning;
        $this->saveState($chatId, $state);

        $response = $this->buildCandidateKeyboard($aiCandidates, $aiSearchResults, $state);
        $note = $aiReasoning ?: 'AI mencari berdasarkan deskripsi alat.';
        $response['message'] = "_AI menemukan kandidat: {$note}_\n\n" . $response['message'];
        return $this->appendDateConfirmationNote($response, $state);
    }

    /**
     * Tambahkan catatan singkat tentang tanggal laporan ke pesan respons Step 1.
     * Tidak menambah step baru — hanya menyisipkan catatan di bawah pesan
     * Step 1 (equipment search), dengan isi tergantung status parseDateFromText():
     *   - 'ok'      : konfirmasi tanggal yang akan dipakai
     *   - 'future'  : tanggal ditolak karena ada di masa depan
     *   - 'too_old' : tanggal ditolak karena melewati batas hari mundur
     *   - lainnya   : tidak ada tanggal terdeteksi di teks, tidak perlu catatan
     *                 (laporan akan pakai tanggal hari ini seperti biasa)
     *
     * @param  array $response Respons yang akan dikirim (berisi key 'message')
     * @param  array $state    State wizard saat ini
     * @return array           Respons dengan catatan tanggal ditambahkan (jika ada)
     */
    protected function appendDateConfirmationNote(array $response, array $state): array
    {
        $status = $state['report_date_status'] ?? 'not_detected';

        if ($status === 'ok' && !empty($state['report_date'])) {
            $formattedDate = $this->formatIndonesianDate($state['report_date']);
            $response['message'] .= "\n\n_Tanggal laporan terdeteksi: {$formattedDate}. " .
                "Ini yang akan dipakai kecuali Anda ubah nanti._";
            return $response;
        }

        if ($status === 'future') {
            $response['message'] .= "\n\n_Catatan: tanggal yang Anda tulis ada di masa depan, " .
                "jadi tidak bisa dipakai. Laporan akan memakai tanggal hari ini._";
            return $response;
        }

        if ($status === 'too_old') {
            $maxDays = self::REPORT_DATE_MAX_BACKDATE_DAYS;
            $response['message'] .= "\n\n_Catatan: tanggal yang Anda tulis lebih dari {$maxDays} hari " .
                "yang lalu, jadi tidak bisa dipakai (maksimal mundur {$maxDays} hari). " .
                "Laporan akan memakai tanggal hari ini._";
            return $response;
        }

        return $response;
    }

    // =========================================================
    // SESI 3 — PENCARIAN LOKASI VIA AI (functional_locations)
    // Lapis ketiga, dijalankan setelah regex DAN AI equipment sama-sama
    // tidak menemukan hasil.
    // =========================================================

    /**
     * Coba cari lokasi/area kerja via AI (lapis ketiga — dijalankan setelah
     * pencarian equipment via regex maupun AI sama-sama tidak menemukan hasil).
     *
     * Method ini khusus menangani laporan yang menyebut nama lokasi/pekerjaan
     * area (mis. "penggantian lantai lab") tanpa merujuk ke alat tertentu.
     * Berbeda dengan tryAiEquipmentSearch() yang bisa menampilkan beberapa
     * kandidat sekaligus, di sini hanya kandidat teratas yang ditawarkan,
     * karena lokasi tidak memiliki kode presisi seperti TechIdentNo untuk
     * membedakan beberapa opsi.
     *
     * Sesuai kesepakatan: hasil AI TIDAK langsung dikunci — teknisi tetap
     * harus konfirmasi Ya/Tidak lewat buildLocationConfirmKeyboard() sebelum
     * report_type/area_id/funcloc_id disimpan ke state.
     *
     * CATATAN INTEGRASI (Sesi 4): callback 'ai_location:confirm' dan
     * 'ai_location:reject' yang dipasang pada keyboard di sini belum
     * dirutekan ke confirmAiLocationSuggestion()/rejectAiLocationSuggestion().
     * Routing tersebut wajib ditambahkan di
     * WizardCallbackHandlerTrait::routeCallback() pada Sesi 4, karena trait
     * itu belum termasuk file yang disentuh di Sesi 3.
     *
     * @param  string $chatId Chat ID Telegram
     * @param  array  $state  State wizard saat ini
     * @param  string $text   Teks laporan dari teknisi
     * @return array|null Respons (message + keyboard) atau null jika AI tidak menemukan lokasi
     */
    protected function tryAiLocationSearch(string $chatId, array $state, string $text): ?array
    {
        $areaId   = $state['area_id'] ?? null;
        $aiResult = $this->aiService->searchLocation($text, $areaId);

        if ($aiResult === null) {
            Log::info("WizardService: AI location search returned null for chat {$chatId}", [
                'reason' => 'no_provider_or_low_confidence_or_empty_candidates',
            ]);
            return null;
        }

        $aiCandidates = $aiResult['candidates'] ?? [];
        $aiConfidence = $aiResult['confidence'] ?? 0;

        if (empty($aiCandidates)) {
            Log::info("WizardService: AI location search returned zero candidates for chat {$chatId}");
            return null;
        }

        // Hanya kandidat teratas yang ditawarkan — lihat catatan di docblock.
        $topCandidate = $aiCandidates[0];

        Log::info("WizardService: AI location search found candidate for chat {$chatId}", [
            'ai_confidence' => $aiConfidence,
            'funcloc_id'    => $topCandidate['id'] ?? null,
        ]);

        $state['pending_location_funcloc_id']  = $topCandidate['id'] ?? null;
        $state['pending_location_area_id']     = $topCandidate['area_id'] ?? null;
        $state['pending_location_sub_area_id'] = $topCandidate['sub_area_id'] ?? null;
        $state['pending_location_label']       = $topCandidate['name'] ?? ($topCandidate['code'] ?? 'Lokasi');
        $state['search_confidence']            = $aiConfidence;
        $state['ai_suggestion_source']         = true;
        $state['ai_suggestion_type']           = 'area';
        $state['ai_suggestion_reasoning']      = $aiResult['reasoning'] ?? $aiResult['ai_message'] ?? null;
        $state['step']                         = self::STEP_EQUIPMENT_CLARIFY;
        $this->saveState($chatId, $state);

        $response = $this->buildLocationConfirmKeyboard($topCandidate, $aiConfidence, $state);
        return $this->appendDateConfirmationNote($response, $state);
    }

    /**
     * Bangun pesan konfirmasi Ya/Tidak untuk satu kandidat lokasi/area hasil AI.
     *
     * Berbeda dari buildCandidateKeyboard() (equipment, bisa menampilkan
     * beberapa pilihan sekaligus), lokasi hanya menawarkan satu kandidat
     * teratas karena tidak ada kode presisi untuk membedakan beberapa opsi.
     *
     * @param  array $candidate  Kandidat lokasi dari AiService::searchLocation()
     * @param  int   $confidence Confidence AI (0-100)
     * @param  array $state      State wizard saat ini (untuk navigationButtons)
     * @return array Respons dengan message dan keyboard konfirmasi
     */
    protected function buildLocationConfirmKeyboard(array $candidate, int $confidence, array $state): array
    {
        $name       = $candidate['name'] ?? '-';
        $code       = $candidate['code'] ?? '-';
        $levelLabel = $candidate['level_label'] ?? 'Lokasi';
        $areaCode   = $candidate['area_code'] ?? null;

        $detailLine = "{$levelLabel}: {$name} ({$code})";
        if ($areaCode) {
            $detailLine .= " — Area {$areaCode}";
        }

        $keyboard = array_merge(
            [
                ['text' => 'Ya, ini lokasinya',      'callback_data' => 'ai_location:confirm'],
                ['text' => 'Bukan, cari cara lain',  'callback_data' => 'ai_location:reject'],
            ],
            $this->navigationButtons($state)
        );

        return [
            'message'  => "_AI mendeteksi laporan ini sebagai pekerjaan area/lokasi (bukan alat tertentu)._\n\n" .
                          "{$detailLine}\n_(Kemiripan: {$confidence}%)_\n\n" .
                          "Apakah ini lokasi yang dimaksud?",
            'keyboard' => $keyboard,
        ];
    }

    /**
     * Bangun pesan default saat regex maupun AI (equipment & lokasi) sama-sama
     * tidak menemukan hasil — tanya jenis pekerjaan secara manual ke teknisi.
     *
     * Diekstrak menjadi method terpisah (sebelumnya inline di
     * processEquipmentSearch()) agar bisa dipakai ulang oleh
     * rejectAiLocationSuggestion() ketika teknisi menolak saran lokasi AI.
     *
     * @param  array $state State wizard saat ini
     * @return array Respons dengan message dan keyboard pilihan jenis pekerjaan
     */
    protected function buildManualWorkTypePrompt(array $state): array
    {
        return [
            'message'  => "Tidak ditemukan kode equipment atau lokasi dari laporan Anda.\n\n" .
                          "Ini jenis pekerjaan apa?",
            'keyboard' => array_merge(
                [
                    ['text' => 'Perbaikan alat tertentu', 'callback_data' => 'work_type:equipment'],
                    ['text' => 'Pekerjaan area/section',   'callback_data' => 'work_type:area'],
                ],
                $this->navigationButtons($state)
            ),
        ];
    }

    /**
     * Bangun pesan keyboard dari kandidat hasil TechIdentSearch (confidence 80-94%).
     *
     * Tidak melakukan pengurutan ulang di sini (Sesi 4): urutan $candidates
     * yang diterima sudah diprioritaskan berdasarkan lokasi oleh
     * TechIdentSearchService::search() (segmen digit pembeda lokasi +
     * area yang terdeteksi/diketahui) sebelum dipotong ke 4 kandidat
     * teratas di sana. array_slice() di bawah ini hanya menjaga agar
     * tidak menampilkan lebih dari 4 tombol, urutannya tetap dipertahankan.
     * Label tombol selalu menyertakan functional_loc ($fl) agar teknisi
     * bisa membedakan kandidat yang mirip tapi beda lokasi (mis. BD01 vs
     * BD02) dan mengoreksi manual bila deteksi lokasi ternyata masih ambigu.
     *
     * @param  array $candidates Daftar kandidat dari TechIdentSearch
     * @param  array $results    Hasil lengkap TechIdentSearch (termasuk confidence)
     * @param  array $state      State wizard saat ini (untuk navigationButtons)
     * @return array Respons dengan message dan keyboard pilihan kandidat
     */
    protected function buildCandidateKeyboard(array $candidates, array $results, array $state): array
    {
        $keyboard = [];
        foreach (array_slice($candidates, 0, 4) as $candidate) {
            $ti   = $candidate['tech_ident_no'] ?? '';
            $fl   = $candidate['functional_loc'] ?? '';
            $desc = $candidate['description'] ?? '';

            $label = $ti;
            if ($fl) {
                $label .= " | {$fl}";
            }
            if ($desc) {
                $label .= " — {$desc}";
            }
            if (strlen($label) > 64) {
                $label = substr($label, 0, 61) . '...';
            }
            $keyboard[] = [
                'text'          => $label,
                'callback_data' => 'equipment_candidate:' . $candidate['id'],
            ];
        }

        $keyboard[] = ['text' => 'Tulis Ulang Kode',    'callback_data' => 'equipment_candidate:retype'];
        $keyboard[] = ['text' => 'Pilih dari Hierarki', 'callback_data' => 'equipment:hierarchy'];

        foreach ($this->navigationButtons($state) as $navButton) {
            $keyboard[] = $navButton;
        }

        $confidenceNote = '';
        if (!empty($results['confidence'])) {
            $confidenceNote = "\n_(Kemiripan: {$results['confidence']}%)_";
        }

        return [
            'message'  => "Equipment tidak ditemukan secara pasti. Pilih yang paling sesuai:{$confidenceNote}",
            'keyboard' => $keyboard,
        ];
    }

    // =========================================================
    // STEP 2 — KLARIFIKASI EQUIPMENT (handler teks + hierarki)
    // =========================================================

    /**
     * Teknisi mengetik ulang kode equipment (Sub-alur B, maks 2 kali).
     * Jika ClarificationService aktif, teks diteruskan ke sana.
     *
     * Sinyal lokasi (Sesi 4): sama seperti processEquipmentSearch(),
     * area_id yang sudah diketahui di $state (jika ada) dikirim ke
     * TechIdentSearchService::search() supaya kandidat tulis-ulang juga
     * diprioritaskan berdasarkan lokasi.
     *
     * @param  string $chatId Chat ID Telegram
     * @param  string $text   Input teks dari teknisi
     * @param  array  $state  State wizard saat ini
     * @return array  Respons
     */
    protected function handleEquipmentRetype(string $chatId, string $text, array $state): array
    {
        if (!empty($state['using_clarification_service'])) {
            return $this->handleClarificationText($chatId, $text, $state);
        }

        $attempts                 = ($state['retype_attempts'] ?? 0) + 1;
        $state['retype_attempts'] = $attempts;

        $areaId     = $state['area_id'] ?? null;
        $results    = $this->techIdentSearch->search($text, $areaId);
        $confidence = $results['confidence'] ?? 0;
        $candidates = $results['candidates'] ?? [];
        $exactMatch = $results['exact_match'] ?? null;

        // Exact match >= 95%: konfirmasi dulu, jangan langsung lock
        if ($confidence >= self::CONFIDENCE_AUTO_ACCEPT && $exactMatch) {
            $state['pending_equipment_id']      = $exactMatch['id'] ?? null;
            $state['pending_equipment_ident']   = $exactMatch['tech_ident_no'] ?? null;
            $state['pending_equipment_funcloc'] = $exactMatch['functional_loc'] ?? null;
            $state['search_confidence']         = $confidence;
            $this->saveState($chatId, $state);

            return $this->buildEquipmentConfirmKeyboard($exactMatch, $confidence, $state);
        }

        // Ada kandidat: tampilkan keyboard
        if (!empty($candidates)) {
            $state['search_results'] = $results;
            $this->saveState($chatId, $state);
            return $this->buildCandidateKeyboard($candidates, $results, $state);
        }

        // Gagal regex — coba AI sebelum hierarki manual.
        // AI berguna saat teknisi menulis ulang dengan deskripsi (bukan kode).
        $aiResult = $this->tryAiEquipmentSearch($chatId, $state, $text, $results);
        if ($aiResult !== null) {
            return $aiResult;
        }

        // AI equipment juga gagal — coba sebagai laporan lokasi/area (Sesi 3)
        // sebelum jatuh ke hierarki manual.
        $aiLocationResult = $this->tryAiLocationSearch($chatId, $state, $text);
        if ($aiLocationResult !== null) {
            return $aiLocationResult;
        }

        // Gagal regex + AI (equipment & lokasi), dan sudah 2 kali: masuk hierarki (Sub-alur C)
        if ($attempts >= 2) {
            $state['retype_attempts'] = $attempts;
            $this->saveState($chatId, $state);
            return $this->startClarificationHierarchy($chatId, $state);
        }

        $remaining               = 2 - $attempts;
        $state['search_results'] = $results;
        $this->saveState($chatId, $state);

        return [
            'message'  => "Equipment masih tidak ditemukan. Coba ketik ulang lagi ({$remaining}x kesempatan tersisa):\n" .
                          "Atau pilih dari hierarki.",
            'keyboard' => array_merge(
                [
                    ['text' => 'Pilih dari Hierarki', 'callback_data' => 'equipment:hierarchy'],
                ],
                $this->navigationButtons($state)
            ),
        ];
    }

    /**
     * Mulai hierarki ClarificationService (Sub-alur C).
     * Delegasi sepenuhnya ke ClarificationService untuk bangun sesi + keyboard awal.
     *
     * @param  string $chatId Chat ID Telegram
     * @param  array  $state  State wizard saat ini
     * @return array  Respons
     */
    protected function startClarificationHierarchy(string $chatId, array $state): array
    {
        $text     = $state['text'];
        $analysis = $state['ai_analysis'] ?? [];

        $clarifySession = $this->clarificationService->getOrCreateSession($chatId, $text, $analysis);

        $state['using_clarification_service'] = true;
        $state['step']                        = self::STEP_EQUIPMENT_CLARIFY;
        $this->saveState($chatId, $state);

        $msgData = $this->clarificationService->buildCurrentMessage($clarifySession);

        return $this->handleClarificationAutoSelect($chatId, $msgData, $clarifySession, $state);
    }

    /**
     * Lock equipment yang dipilih dan maju ke Step 4.
     * Juga membersihkan sesi ClarificationService jika aktif.
     *
     * @param  string $chatId Chat ID Telegram
     * @param  Asset  $asset  Model asset yang dipilih
     * @param  array  $state  State wizard saat ini
     * @return array  Respons
     */
    protected function lockEquipmentAndAdvance(string $chatId, Asset $asset, array $state): array
    {
        $state['equipment_id']      = $asset->id;
        $state['equipment_ident']   = $asset->tech_ident_no;
        $state['equipment_funcloc'] = $asset->functional_loc;

        if ($asset->area_id) {
            $state['area_id']   = $asset->area_id;
            $area               = Area::find($asset->area_id);
            $state['area_code'] = $area ? $area->code : null;
        }

        if (!empty($state['using_clarification_service'])) {
            $this->clarificationService->destroySession($chatId);
            $state['using_clarification_service'] = false;
        }

        return $this->advanceToWorkDuration($chatId, $state);
    }

    /**
     * Teknisi mengonfirmasi (Ya) kandidat lokasi/area hasil AI (Sesi 3).
     *
     * Mengunci is_area_work=true beserta funcloc_id/funcloc_code/funcloc_name
     * dan area_id/area_code dari kandidat yang tersimpan di state
     * (equipment_id tetap kosong/NULL), lalu maju ke Step 4 (durasi kerja)
     * seperti alur normal penguncian equipment.
     *
     * PERBAIKAN SESI 4: versi Sesi 3 sebelumnya mengisi $state['report_type'],
     * padahal WizardReportSaverTrait::saveReport() menentukan report_type dari
     * key 'is_area_work' (bukan 'report_type') -- akibatnya laporan hasil
     * konfirmasi lokasi AI akan tersimpan sebagai 'general', bukan 'area_work'.
     * funcloc_code/funcloc_name (dipakai buildConfirmationSummary() dan pesan
     * sukses di saveReport()) juga belum pernah diisi sebelumnya. Kedua hal
     * ini diperbaiki di sini dengan mengambil ulang model FunctionalLocation
     * dari funcloc_id yang tersimpan, sama seperti lockEquipmentAndAdvance()
     * mengambil ulang model Area.
     *
     * Sugesti AI dicatat lewat logAiSuggestionDecision() (WizardCallbackHandlerTrait)
     * sebagai diterima (accepted) sebelum key sementaranya dibersihkan, untuk
     * dipersis ke tabel report_ai_suggestions saat laporan disimpan.
     *
     * Dipanggil dari callback 'ai_location:confirm' (WizardCallbackHandlerTrait::routeCallback()).
     *
     * @param  string $chatId Chat ID Telegram
     * @param  array  $state  State wizard saat ini
     * @return array  Respons
     */
    protected function confirmAiLocationSuggestion(string $chatId, array $state): array
    {
        $funcLocId = $state['pending_location_funcloc_id'] ?? null;
        $areaId    = $state['pending_location_area_id']    ?? null;

        if (!$funcLocId) {
            Log::warning("WizardService: confirmAiLocationSuggestion dipanggil tanpa pending_location_funcloc_id untuk chat {$chatId}");
            return $this->errorResponse('Data lokasi tidak ditemukan. Silakan mulai ulang laporan.');
        }

        $funcLoc = FunctionalLocation::find($funcLocId);
        if (!$funcLoc) {
            Log::warning("WizardService: FunctionalLocation id {$funcLocId} tidak ditemukan saat confirmAiLocationSuggestion untuk chat {$chatId}");
            return $this->errorResponse('Data lokasi tidak ditemukan. Silakan mulai ulang laporan.');
        }

        $state['is_area_work']    = true;
        $state['equipment_id']    = null;
        $state['equipment_ident'] = null;
        $state['funcloc_id']      = $funcLoc->id;
        $state['funcloc_code']    = $funcLoc->code;
        $state['funcloc_name']    = $funcLoc->name;
        $state['area_id']         = $areaId;

        if ($areaId) {
            $area                = Area::find($areaId);
            $state['area_code']  = $area ? $area->code : null;
        }

        $state = $this->logAiSuggestionDecision($state, 'area', null, $areaId, true);

        unset(
            $state['pending_location_funcloc_id'],
            $state['pending_location_area_id'],
            $state['pending_location_sub_area_id'],
            $state['pending_location_label']
        );

        return $this->advanceToWorkDuration($chatId, $state);
    }

    /**
     * Teknisi menolak (Tidak) kandidat lokasi/area hasil AI (Sesi 3).
     *
     * Bersihkan data pending, catat sugesti AI sebagai ditolak lewat
     * logAiSuggestionDecision(), lalu tampilkan opsi jenis pekerjaan manual
     * seperti biasa (buildManualWorkTypePrompt()).
     *
     * Dipanggil dari callback 'ai_location:reject' (WizardCallbackHandlerTrait::routeCallback()).
     *
     * @param  string $chatId Chat ID Telegram
     * @param  array  $state  State wizard saat ini
     * @return array  Respons
     */
    protected function rejectAiLocationSuggestion(string $chatId, array $state): array
    {
        $areaId = $state['pending_location_area_id'] ?? null;
        $state  = $this->logAiSuggestionDecision($state, 'area', null, $areaId, false);

        unset(
            $state['pending_location_funcloc_id'],
            $state['pending_location_area_id'],
            $state['pending_location_sub_area_id'],
            $state['pending_location_label']
        );

        $state['step']               = self::STEP_EQUIPMENT_CLARIFY;
        $state['awaiting_work_type'] = true;
        $this->saveState($chatId, $state);

        return $this->buildManualWorkTypePrompt($state);
    }

    // =========================================================
    // STATE MANAGEMENT
    // =========================================================

    /**
     * Ambil state wizard dari cache.
     *
     * @param  string     $chatId Chat ID Telegram
     * @return array|null State wizard, atau null jika tidak ada
     */
    public function getState(string $chatId): ?array
    {
        return Cache::get(self::CACHE_PREFIX . $chatId);
    }

    /**
     * Simpan state wizard ke cache.
     *
     * @param  string $chatId Chat ID Telegram
     * @param  array  $state  State wizard yang akan disimpan
     * @return void
     */
    public function saveState(string $chatId, array $state): void
    {
        Cache::put(self::CACHE_PREFIX . $chatId, $state, self::CACHE_TTL);
    }

    /**
     * Hapus state wizard dari cache.
     *
     * @param  string $chatId Chat ID Telegram
     * @return void
     */
    public function destroyWizard(string $chatId): void
    {
        Cache::forget(self::CACHE_PREFIX . $chatId);
    }

    /**
     * Cek apakah ada wizard aktif untuk chat_id ini.
     *
     * @param  string $chatId Chat ID Telegram
     * @return bool
     */
    public function hasActiveWizard(string $chatId): bool
    {
        return Cache::has(self::CACHE_PREFIX . $chatId);
    }
}
