<?php

namespace App\Services\Telegram;

use App\Models\Area;
use App\Models\Asset;
use App\Services\AiService;
use App\Services\TechIdentSearchService;
use App\Services\Telegram\Traits\WizardCallbackHandlerTrait;
use App\Services\Telegram\Traits\WizardPhotoAddonTrait;
use App\Services\Telegram\Traits\WizardReportSaverTrait;
use App\Services\Telegram\Traits\WizardStepHandlerTrait;
use App\Services\Telegram\Traits\WizardUtilityTrait;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * ReportWizardService
 *
 * Orkestrator utama wizard 8-step laporan via Telegram Bot.
 * Pendekatan "Create at End" — laporan hanya disimpan ke DB setelah
 * teknisi mengonfirmasi di Step 8. Semua state disimpan di Laravel Cache
 * per chat_id.
 *
 * Step 1: Terima Laporan Awal    -> jalankan TechIdentSearch 3-pass, deteksi tanggal laporan
 * Step 2: Klarifikasi Equipment  -> keyboard kandidat / tulis ulang / hierarki
 * Step 3: Akselerasi FuncLoc     -> ditangani ClarificationService & FuncLocParser
 * Step 4: Waktu Pengerjaan       -> parse durasi atau tanya ke teknisi
 * Step 5: Root Cause             -> input teks bebas, wajib min 3 karakter
 * Step 6: Foto Dokumentasi       -> multi-foto opsional, bisa skip
 * Step 7: Foto Hygiene Clearance -> multi-foto opsional, bisa skip
 * Step 8: Konfirmasi & Simpan    -> tampilkan ringkasan, simpan ke DB jika OK
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
 *   - WizardStepHandlerTrait    : handler per-step (4, 5, 6, 7, konfirmasi teks)
 *   - WizardCallbackHandlerTrait: handler semua callback inline keyboard
 *   - WizardReportSaverTrait    : simpan laporan ke DB, validasi foto, generate kode
 *   - WizardUtilityTrait        : format durasi/tanggal, label equipment, state awal, error response
 *   - WizardPhotoAddonTrait     : tambah foto ke laporan tersimpan via report_code
 */
class ReportWizardService
{
    use WizardStepHandlerTrait;
    use WizardCallbackHandlerTrait;
    use WizardReportSaverTrait;
    use WizardUtilityTrait;
    use WizardPhotoAddonTrait;

    const CACHE_PREFIX = 'report_wizard:';
    const CACHE_TTL    = 7200; // 2 jam — cukup untuk satu shift kerja

    /** Step ID — digunakan sebagai konstanta agar tidak typo. */
    const STEP_INITIAL             = 'initial';
    const STEP_EQUIPMENT_SEARCH    = 'equipment_search';
    const STEP_EQUIPMENT_CLARIFY   = 'equipment_clarify';
    const STEP_WORK_DURATION       = 'work_duration';
    const STEP_ROOT_CAUSE          = 'root_cause';
    const STEP_PHOTO_DOCUMENTATION = 'photo_documentation';
    const STEP_PHOTO_HYGIENE       = 'photo_hygiene';
    const STEP_CONFIRMATION        = 'confirmation';
    const STEP_DONE                = 'done';

    /** Confidence minimum untuk auto-accept hasil pencarian TechIdentNo. */
    const CONFIDENCE_AUTO_ACCEPT = 95;

    /** Batas minimum karakter untuk field root_cause. */
    const ROOT_CAUSE_MIN_LENGTH = 3;

    protected TechIdentSearchService $techIdentSearch;
    protected AiService $aiService;
    protected ClarificationService $clarificationService;

    public function __construct(
        TechIdentSearchService $techIdentSearch,
        AiService $aiService,
        ClarificationService $clarificationService
    ) {
        $this->techIdentSearch      = $techIdentSearch;
        $this->aiService            = $aiService;
        $this->clarificationService = $clarificationService;
    }

    // =========================================================
    // ENTRY POINTS — dipanggil dari PollTelegramUpdates
    // =========================================================

    /**
     * Mulai wizard baru dari teks laporan awal teknisi.
     * Jika ada wizard aktif sebelumnya, wizard lama di-reset.
     *
     * @param  string      $chatId      Chat ID Telegram
     * @param  string      $text        Teks laporan dari teknisi
     * @param  string|null $photoFileId File ID foto yang disertakan di pesan awal (opsional)
     * @return array       Respons yang harus dikirim ke teknisi
     */
    public function startWizard(string $chatId, string $text, ?string $photoFileId = null): array
    {
        $this->destroyWizard($chatId);

        $state = $this->createInitialState($chatId, $text);

        if ($photoFileId) {
            $state['initial_photo_file_id'] = $photoFileId;
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

        $this->saveState($chatId, $state);

        return $this->processEquipmentSearch($chatId, $state);
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
     * @param  string $chatId Chat ID Telegram
     * @param  array  $state  State wizard saat ini
     * @return array  Respons
     */
    protected function processEquipmentSearch(string $chatId, array $state): array
    {
        $text    = $state['text'];
        $results = $this->techIdentSearch->search($text);

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

            $response = $this->buildEquipmentConfirmKeyboard($exactMatch, $confidence);
            return $this->appendDateConfirmationNote($response, $state);
        }

        // Confidence 80-94%: ada kandidat ambigu -> tampilkan keyboard kandidat (Step 2)
        if (!empty($candidates)) {
            $state['step']            = self::STEP_EQUIPMENT_CLARIFY;
            $state['search_results']  = $results;
            $state['retype_attempts'] = 0;
            $this->saveState($chatId, $state);

            $response = $this->buildCandidateKeyboard($candidates, $results);
            return $this->appendDateConfirmationNote($response, $state);
        }

        // Tidak ada kandidat: tanya jenis pekerjaan terlebih dahulu
        // (bisa jadi pekerjaan area, bukan perbaikan alat tertentu)
        $state['step']               = self::STEP_EQUIPMENT_CLARIFY;
        $state['search_results']     = $results;
        $state['retype_attempts']    = 0;
        $state['awaiting_work_type'] = true;
        $this->saveState($chatId, $state);

        $response = [
            'message'  => "Tidak ditemukan kode equipment dari laporan Anda.\n\n" .
                          "Ini jenis pekerjaan apa?",
            'keyboard' => [
                ['text' => 'Perbaikan alat tertentu', 'callback_data' => 'work_type:equipment'],
                ['text' => 'Pekerjaan area/section',   'callback_data' => 'work_type:area'],
                ['text' => 'Batalkan Laporan',          'callback_data' => 'wizard:cancel_wizard'],
            ],
        ];

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

    /**
     * Bangun pesan keyboard dari kandidat hasil TechIdentSearch (confidence 80-94%).
     *
     * @param  array $candidates Daftar kandidat dari TechIdentSearch
     * @param  array $results    Hasil lengkap TechIdentSearch (termasuk confidence)
     * @return array Respons dengan message dan keyboard pilihan kandidat
     */
    protected function buildCandidateKeyboard(array $candidates, array $results): array
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
        $keyboard[] = ['text' => 'Batalkan Laporan',    'callback_data' => 'wizard:cancel_wizard'];

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

        $results    = $this->techIdentSearch->search($text);
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

            return $this->buildEquipmentConfirmKeyboard($exactMatch, $confidence);
        }

        // Ada kandidat: tampilkan keyboard
        if (!empty($candidates)) {
            $state['search_results'] = $results;
            $this->saveState($chatId, $state);
            return $this->buildCandidateKeyboard($candidates, $results);
        }

        // Gagal dan sudah 2 kali: masuk hierarki (Sub-alur C)
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
            'keyboard' => [
                ['text' => 'Pilih dari Hierarki', 'callback_data' => 'equipment:hierarchy'],
            ],
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
