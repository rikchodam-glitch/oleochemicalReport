<?php

namespace App\Services\Telegram;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Report;
use App\Services\AiService;
use App\Services\TechIdentSearchService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * ReportWizardService
 *
 * Orchestrator utama wizard 8-step laporan via Telegram Bot.
 * Pendekatan "Create at End" — laporan hanya disimpan ke DB setelah
 * teknisi mengonfirmasi di Step 8. Semua state disimpan di Laravel Cache
 * per chat_id.
 *
 * Step 1: Terima Laporan Awal  → jalankan TechIdentSearch 3-pass
 * Step 2: Klarifikasi Equipment → keyboard kandidat / tulis ulang / hierarki
 * Step 3: Akselerasi FuncLoc   → ditangani ClarificationService & FuncLocParser
 * Step 4: Waktu Pengerjaan     → parse durasi atau tanya ke teknisi
 * Step 5: Root Cause           → input teks bebas, wajib min 3 karakter
 * Step 6: Foto Dokumentasi     → multi-foto opsional, bisa skip
 * Step 7: Foto Hygiene Clearance → multi-foto opsional, bisa skip
 * Step 8: Konfirmasi & Simpan  → tampilkan ringkasan, simpan ke DB jika OK
 *
 * Tanggung jawab service ini:
 *   - Mengelola state wizard (step, data yang sudah terkumpul)
 *   - Routing antar step berdasarkan input user
 *   - Memformat pesan balasan untuk tiap step
 *   - Memanggil TechIdentSearchService untuk pencarian TechIdentNo
 *   - Memanggil AiService untuk parsing durasi & root cause dari teks awal
 *   - Menyimpan laporan ke DB di Step 8
 *
 * Yang BUKAN tanggung jawab service ini:
 *   - Pencarian TechIdentNo presisi (TechIdentSearchService)
 *   - Keyboard hierarki Area/Section/Tipe (ClarificationService)
 *   - Download & simpan foto dari Telegram API (PhotoStorageService — F5)
 *   - Polling Telegram & dispatch pesan masuk (PollTelegramUpdates — F6)
 */
class ReportWizardService
{
    const CACHE_PREFIX = 'report_wizard:';
    const CACHE_TTL    = 7200; // 2 jam — cukup untuk satu shift kerja

    /** Step ID — digunakan sebagai konstanta agar tidak typo. */
    const STEP_INITIAL            = 'initial';
    const STEP_EQUIPMENT_SEARCH   = 'equipment_search';
    const STEP_EQUIPMENT_CLARIFY  = 'equipment_clarify';
    const STEP_WORK_DURATION      = 'work_duration';
    const STEP_ROOT_CAUSE         = 'root_cause';
    const STEP_PHOTO_DOCUMENTATION = 'photo_documentation';
    const STEP_PHOTO_HYGIENE      = 'photo_hygiene';
    const STEP_CONFIRMATION       = 'confirmation';
    const STEP_DONE               = 'done';

    /** Confidence minimum untuk auto-accept hasil pencarian TechIdentNo (dokumen Bagian E). */
    const CONFIDENCE_AUTO_ACCEPT = 95;

    /** Batas minimum karakter untuk field root_cause (dokumen Bagian D Step 5). */
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
    // ENTRY POINTS — dipanggil dari PollTelegramUpdates (F6)
    // =========================================================

    /**
     * Mulai wizard baru dari teks laporan awal teknisi.
     * Jika ada wizard aktif sebelumnya, wizard lama di-reset.
     *
     * @param  string     $chatId       Chat ID Telegram
     * @param  string     $text         Teks laporan dari teknisi
     * @param  array|null $photoFileId  File ID foto yang disertakan di pesan awal (opsional)
     * @return array      Respons yang harus dikirim ke teknisi
     */
    public function startWizard(string $chatId, string $text, ?string $photoFileId = null): array
    {
        // Destroy wizard lama jika ada
        $this->destroyWizard($chatId);

        $state = $this->createInitialState($chatId, $text);

        // Simpan foto awal jika ada (akan dikonfirmasi di Step 6)
        if ($photoFileId) {
            $state['initial_photo_file_id'] = $photoFileId;
        }

        // Step 1: Analisa teks awal dengan AI untuk ekstrak area, equipment,
        // durasi, root cause — hasil akan digunakan di step-step berikutnya
        $analysis = $this->aiService->analyzeReportText($text);
        $state['ai_analysis'] = $analysis;

        // Coba parse durasi dan root cause dari teks awal
        // Field dari AiService: parsed_duration_minutes & parsed_root_cause
        if (!empty($analysis['parsed_duration_minutes'])) {
            $state['work_duration_minutes'] = (int) $analysis['parsed_duration_minutes'];
        }
        if (!empty($analysis['parsed_root_cause'])) {
            $state['root_cause'] = $analysis['parsed_root_cause'];
        }

        $this->saveState($chatId, $state);

        // Langsung masuk Step 1 (equipment_search)
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
     * @param  string $chatId     Chat ID Telegram
     * @param  string $fileId     File ID foto dari Telegram
     * @param  string $caption    Caption foto (opsional)
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
                // Foto di luar step foto — simpan ke dokumentasi jika belum confirm
                return $this->errorResponse(
                    "Foto diterima, tapi sekarang bukan saatnya upload foto.\n" .
                    "Ikuti instruksi bot untuk melanjutkan laporan."
                );
        }
    }

    /**
     * Proses pilihan inline keyboard (callback dari Telegram).
     * Dipakai untuk klarifikasi equipment Step 2.
     *
     * @param  string $chatId       Chat ID
     * @param  string $callbackData Data callback dari Telegram
     * @return array  Respons
     */
    public function handleCallback(string $chatId, string $callbackData): array
{
    $state = $this->getState($chatId);

    if (!$state) {
        return $this->errorResponse('Sesi tidak ditemukan. Silakan mulai laporan baru.');
    }

    // Callback konfirmasi Step 8
    if (str_starts_with($callbackData, 'wizard:confirm:')) {
        return $this->handleConfirmationCallback($chatId, $callbackData, $state);
    }

    // Pembatalan wizard dari mana saja (termasuk Step 1 identifikasi alat)
    if ($callbackData === 'wizard:cancel_wizard') {
        $this->clarificationService->destroySession($chatId);
        $this->destroyWizard($chatId);
        return [
            'message'  => "Laporan dibatalkan. Wizard ditutup.\nKirim laporan baru kapan saja.",
            'keyboard' => [],
        ];
    }

    // Callback klarifikasi equipment Step 2 (kandidat dari TechIdentSearch)
    if (str_starts_with($callbackData, 'equipment_candidate:')) {
        return $this->handleEquipmentCandidateCallback($chatId, $callbackData, $state);
    }

    // BARU: Callback konfirmasi equipment terdeteksi otomatis (confidence >= 95%)
    if (str_starts_with($callbackData, 'equipment_confirm:')) {
        return $this->handleEquipmentConfirmCallback($chatId, $callbackData, $state);
    }

    // BARU: Callback pilihan jenis pekerjaan saat no_match
    if (str_starts_with($callbackData, 'work_type:')) {
        return $this->handleWorkTypeCallback($chatId, $callbackData, $state);
    }

    // Callback untuk masuk ke hierarki ClarificationService (Sub-alur C)
    if ($callbackData === 'equipment:hierarchy') {
        return $this->startClarificationHierarchy($chatId, $state);
    }

    // Callback "Batal" dari dalam navigasi hierarki
    if ($callbackData === 'hierarchy:cancel') {
        return $this->handleHierarchyCancel($chatId, $state);
    }

    // Callback dari ClarificationService (hierarki Company/Dept/Area/...)
    if ($state['step'] === self::STEP_EQUIPMENT_CLARIFY && !empty($state['using_clarification_service'])) {
        return $this->handleClarificationCallback($chatId, $callbackData, $state);
    }

    return $this->errorResponse('Callback tidak dikenali.');
}
/**
 * Bangun keyboard konfirmasi equipment yang terdeteksi otomatis.
 * Selalu dipanggil sebelum mengunci equipment, berapapun nilai confidence-nya.
 *
 * @param  array $candidate  Satu entri dari formatCandidates() — berisi id, tech_ident_no, description, functional_loc
 * @param  int   $confidence Nilai confidence dari TechIdentSearch (untuk ditampilkan ke user)
 * @return array Respons dengan message dan keyboard dua tombol
 */
protected function buildEquipmentConfirmKeyboard(array $candidate, int $confidence): array
{
    $ti   = $candidate['tech_ident_no'] ?? '?';
    $fl   = $candidate['functional_loc'] ?? '';
    $desc = $candidate['description'] ?? '';

    $label = "*{$ti}*";
    if ($fl) {
        $label .= "\n📍 _{$fl}_";
    }
    if ($desc) {
        $label .= "\n📋 {$desc}";
    }

    $confidenceNote = $confidence < 100
        ? "\n_(Kemiripan: {$confidence}%)_"
        : '';

    return [
        'message'  => "Equipment terdeteksi:{$confidenceNote}\n\n{$label}\n\nApakah ini equipment yang dimaksud?",
        'keyboard' => [
            ['text' => '✅ Ya, betul',          'callback_data' => 'equipment_confirm:yes'],
            ['text' => '✏️ Bukan, ganti alat',  'callback_data' => 'equipment_confirm:no'],
            ['text' => '❌ Batalkan Laporan',    'callback_data' => 'wizard:cancel_wizard'],
        ],
    ];
}
/**
 * Teknisi menjawab keyboard konfirmasi equipment (✅ Ya / ✏️ Bukan).
 *
 * State yang dibutuhkan (sudah disimpan oleh processEquipmentSearch / handleEquipmentRetype):
 *   - pending_equipment_id
 *   - pending_equipment_ident
 *   - pending_equipment_funcloc
 */
protected function handleEquipmentConfirmCallback(string $chatId, string $callbackData, array $state): array
{
    $action = explode(':', $callbackData)[1] ?? null;

    if ($action === 'yes') {
        $assetId = $state['pending_equipment_id'] ?? null;
        $asset   = $assetId ? Asset::find($assetId) : null;

        if (!$asset) {
            return $this->errorResponse('Equipment tidak ditemukan. Silakan cari ulang.');
        }

        // Bersihkan pending state sebelum mengunci
        unset(
            $state['pending_equipment_id'],
            $state['pending_equipment_ident'],
            $state['pending_equipment_funcloc'],
            $state['awaiting_work_type']
        );

        return $this->lockEquipmentAndAdvance($chatId, $asset, $state);
    }

    if ($action === 'no') {
        // Hapus pending dan kembali ke mode pencarian ulang
        unset(
            $state['pending_equipment_id'],
            $state['pending_equipment_ident'],
            $state['pending_equipment_funcloc']
        );
        $state['retype_attempts'] = 0;
        $this->saveState($chatId, $state);

        return [
            'message'  => "Baik. Silakan ketik ulang *TechIdentNo* atau nama/deskripsi equipment:\n" .
                          "_(contoh: `LSH-2-6600V2`, `Level Switch High 6600V2`, `2-6163P4`)_",
            'keyboard' => [
                ['text' => '🗂 Pilih dari Hierarki', 'callback_data' => 'equipment:hierarchy'],
            ],
        ];
    }

    return $this->errorResponse('Pilihan tidak dikenali.');
}
/**
 * Teknisi memilih jenis pekerjaan setelah no_match:
 *   🔧 work_type:equipment → minta TechIdentNo / nama alat
 *   🏭 work_type:area      → masuk hierarki FuncLoc untuk pekerjaan area
 */
protected function handleWorkTypeCallback(string $chatId, string $callbackData, array $state): array
{
    $type = explode(':', $callbackData)[1] ?? null;

    // Bersihkan flag sementara
    unset($state['awaiting_work_type']);

    if ($type === 'equipment') {
        $state['retype_attempts'] = 0;
        $this->saveState($chatId, $state);

        return [
            'message'  => "Silakan ketik *TechIdentNo* atau nama/deskripsi alat:\n" .
                          "_(contoh: `LSH-2-6600V2`, `Level Switch High 6600V2`, `PT 6163C1`)_",
            'keyboard' => [
                ['text' => '🗂 Pilih dari Hierarki', 'callback_data' => 'equipment:hierarchy'],
            ],
        ];
    }

    if ($type === 'area') {
        $state['is_area_work'] = true;
        $this->saveState($chatId, $state);

        return $this->startClarificationHierarchy($chatId, $state);
    }

    return $this->errorResponse('Pilihan tidak dikenali.');
}
    /**
     * Batalkan seluruh wizard saat teknisi menekan "Batal" di tengah navigasi
     * hierarki (Sub-alur C). Sesi ClarificationService juga dibersihkan agar
     * tidak ada state nyangkut, lalu wizard direset total — sama seperti
     * teknisi belum pernah mulai membuat laporan.
     */
    protected function handleHierarchyCancel(string $chatId, array $state): array
    {
        $this->clarificationService->destroySession($chatId);
        $this->destroyWizard($chatId);

        Log::info("WizardService: Wizard dibatalkan dari hierarki untuk chat {$chatId}");

        return [
            'message'  => "Laporan dibatalkan. Silakan kirim pesan baru kapan saja untuk membuat laporan.",
            'keyboard' => [],
        ];
    }

    // =========================================================
    // STEP 1 — EQUIPMENT SEARCH
    // Jalankan pencarian TechIdentNo 3-pass via TechIdentSearchService.
    // =========================================================

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

    // Pass 1/2/3: confidence >= 95% dan hanya satu kandidat.
    // PERBAIKAN: tampilkan keyboard konfirmasi terlebih dahulu,
    // jangan langsung lompat ke Step 4 — teknisi harus bisa mengoreksi.
    if ($confidence >= self::CONFIDENCE_AUTO_ACCEPT && $exactMatch) {
        $state['pending_equipment_id']      = $exactMatch['id'] ?? null;
        $state['pending_equipment_ident']   = $exactMatch['tech_ident_no'] ?? null;
        $state['pending_equipment_funcloc'] = $exactMatch['functional_loc'] ?? null;
        $state['search_confidence']         = $confidence;
        $state['step']                      = self::STEP_EQUIPMENT_CLARIFY;
        $this->saveState($chatId, $state);

        return $this->buildEquipmentConfirmKeyboard($exactMatch, $confidence);
    }

    // Confidence 80–94%: ada kandidat ambigu → tampilkan keyboard kandidat (Step 2)
    if (!empty($candidates)) {
        $state['step']            = self::STEP_EQUIPMENT_CLARIFY;
        $state['search_results']  = $results;
        $state['retype_attempts'] = 0;
        $this->saveState($chatId, $state);

        return $this->buildCandidateKeyboard($candidates, $results);
    }

    // Tidak ada kandidat.
    // PERBAIKAN: tanya dulu jenis pekerjaan sebelum meminta tulis ulang TechIdentNo —
    // bisa jadi ini memang pekerjaan area, bukan perbaikan alat tertentu.
    $state['step']               = self::STEP_EQUIPMENT_CLARIFY;
    $state['search_results']     = $results;
    $state['retype_attempts']    = 0;
    $state['awaiting_work_type'] = true;
    $this->saveState($chatId, $state);

    return [
        'message'  => "Tidak ditemukan kode equipment dari laporan Anda.\n\n" .
                      "Ini jenis pekerjaan apa?",
        'keyboard' => [
            ['text' => '🔧 Perbaikan alat tertentu', 'callback_data' => 'work_type:equipment'],
            ['text' => '🏭 Pekerjaan area/section',   'callback_data' => 'work_type:area'],
            ['text' => '❌ Batalkan Laporan',          'callback_data' => 'wizard:cancel_wizard'],
        ],
    ];
}
    /**
     * Bangun pesan keyboard dari kandidat hasil TechIdentSearch (confidence 80-94%).
     */
    protected function buildCandidateKeyboard(array $candidates, array $results): array
    {
        $keyboard = [];
        foreach (array_slice($candidates, 0, 4) as $candidate) {
            // Susun label: TechIdentNo + FuncLoc (baris 1) + Description (baris 2)
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

        $keyboard[] = ['text' => '✏️ Tulis Ulang Kode', 'callback_data' => 'equipment_candidate:retype'];
        $keyboard[] = ['text' => '🗂 Pilih dari Hierarki', 'callback_data' => 'equipment:hierarchy'];
        $keyboard[] = ['text' => '❌ Batalkan Laporan',    'callback_data' => 'wizard:cancel_wizard'];

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
    // STEP 2 — KLARIFIKASI EQUIPMENT
    // =========================================================

    /**
     * Teknisi memilih salah satu kandidat dari keyboard (Step 2).
     */
    protected function handleEquipmentCandidateCallback(string $chatId, string $callbackData, array $state): array
    {
        $parts = explode(':', $callbackData);
        $id    = $parts[1] ?? null;

        if ($id === 'retype') {
            $state['retype_attempts'] = ($state['retype_attempts'] ?? 0);
            $this->saveState($chatId, $state);
            return [
                'message'  => "Silakan ketik ulang *TechIdentNo* equipment:\n_(contoh: `6163P4`, `2-6163P4`, `TCV-2-6166E2B-1`)_",
                'keyboard' => [
                    ['text' => '🗂 Pilih dari Hierarki', 'callback_data' => 'equipment:hierarchy'],
                ],
            ];
        }

        $asset = Asset::find((int) $id);
        if (!$asset) {
            return $this->errorResponse('Equipment tidak ditemukan. Silakan pilih ulang.');
        }

        return $this->lockEquipmentAndAdvance($chatId, $asset, $state);
    }

    /**
     * Teknisi mengetik ulang kode (Sub-alur B, maks 2 kali).
     */
    protected function handleEquipmentRetype(string $chatId, string $text, array $state): array
{
    // Jika sedang di mode hierarki ClarificationService, teruskan ke sana
    if (!empty($state['using_clarification_service'])) {
        return $this->handleClarificationText($chatId, $text, $state);
    }

    $attempts = ($state['retype_attempts'] ?? 0) + 1;
    $state['retype_attempts'] = $attempts;

    // Coba cari ulang dengan teks yang baru diketik
    $results    = $this->techIdentSearch->search($text);
    $confidence = $results['confidence'] ?? 0;
    $candidates = $results['candidates'] ?? [];
    $exactMatch = $results['exact_match'] ?? null;

    // PERBAIKAN: exact match >= 95% → konfirmasi dulu, jangan langsung lock.
    if ($confidence >= self::CONFIDENCE_AUTO_ACCEPT && $exactMatch) {
        $state['pending_equipment_id']      = $exactMatch['id'] ?? null;
        $state['pending_equipment_ident']   = $exactMatch['tech_ident_no'] ?? null;
        $state['pending_equipment_funcloc'] = $exactMatch['functional_loc'] ?? null;
        $state['search_confidence']         = $confidence;
        $this->saveState($chatId, $state);

        return $this->buildEquipmentConfirmKeyboard($exactMatch, $confidence);
    }

    // Ada kandidat → tampilkan keyboard
    if (!empty($candidates)) {
        $state['search_results'] = $results;
        $this->saveState($chatId, $state);
        return $this->buildCandidateKeyboard($candidates, $results);
    }

    // Gagal lagi
    if ($attempts >= 2) {
        // Sub-alur C: masuk hierarki
        $state['retype_attempts'] = $attempts;
        $this->saveState($chatId, $state);
        return $this->startClarificationHierarchy($chatId, $state);
    }

    $remaining = 2 - $attempts;
    $state['search_results'] = $results;
    $this->saveState($chatId, $state);

    return [
        'message'  => "Equipment masih tidak ditemukan. Coba ketik ulang lagi ({$remaining}x kesempatan tersisa):\n" .
                      "Atau pilih dari hierarki.",
        'keyboard' => [
            ['text' => '🗂 Pilih dari Hierarki', 'callback_data' => 'equipment:hierarchy'],
        ],
    ];
}

    /**
     * Mulai hierarki ClarificationService (Sub-alur C).
     */
    protected function startClarificationHierarchy(string $chatId, array $state): array
    {
        $text     = $state['text'];
        $analysis = $state['ai_analysis'] ?? [];

        // Delegasi ke ClarificationService untuk bangun sesi + keyboard
        $clarifySession = $this->clarificationService->getOrCreateSession($chatId, $text, $analysis);

        $state['using_clarification_service'] = true;
        $state['step'] = self::STEP_EQUIPMENT_CLARIFY;
        $this->saveState($chatId, $state);

        $msgData = $this->clarificationService->buildCurrentMessage($clarifySession);

        // Handle auto-select (misalnya hanya 1 company/department)
        return $this->handleClarificationAutoSelect($chatId, $msgData, $clarifySession, $state);
    }

    /**
     * Tangani callback dari ClarificationService saat wizard sedang di Step 2.
     */
    protected function handleClarificationCallback(string $chatId, string $callbackData, array $state): array
    {
        $result     = $this->clarificationService->processSelection($chatId, $callbackData);
        if (!$result['success']) {
            return $this->errorResponse($result['error'] ?? 'Terjadi kesalahan di klarifikasi.');
        }

        $clarifySession = $result['session'];
        $msgData        = $this->clarificationService->buildCurrentMessage($clarifySession);

        // Jika ClarificationService sudah selesai (level 'done')
        if (!empty($msgData['done'])) {
            return $this->finalizeClarificationSelection($chatId, $msgData, $state);
        }

        return $this->handleClarificationAutoSelect($chatId, $msgData, $clarifySession, $state);
    }

    /**
     * Tangani input teks saat ClarificationService aktif (biasanya tidak diperlukan,
     * tapi disiapkan untuk forward-compatibility).
     */
    protected function handleClarificationText(string $chatId, string $text, array $state): array
    {
        // ClarificationService berbasis keyboard, teks biasanya diabaikan.
        // Kembalikan pesan keyboard terakhir.
        $clarifySession = $this->clarificationService->getSession($chatId);
        if ($clarifySession) {
            $msgData = $this->clarificationService->buildCurrentMessage($clarifySession);
            return [
                'message'  => "Gunakan tombol di atas untuk memilih equipment.\n\n" . ($msgData['message'] ?? ''),
                'keyboard' => $msgData['keyboard'] ?? [],
            ];
        }

        return $this->errorResponse('Silakan gunakan tombol keyboard untuk memilih.');
    }

    /**
     * Handle auto-select dari ClarificationService (jika hanya 1 opsi di level itu).
     */
    protected function handleClarificationAutoSelect(
        string $chatId,
        array $msgData,
        array $clarifySession,
        array $state
    ): array {
        // Loop auto-select jika diperlukan (ClarificationService merespons auto_select)
        if (!empty($msgData['auto_select'])) {
            $autoCallback = $msgData['auto_level'] . ':select:' . $msgData['auto_id'];
            $result       = $this->clarificationService->processSelection($chatId, $autoCallback);
            if ($result['success']) {
                $clarifySession = $result['session'];
                $msgData        = $this->clarificationService->buildCurrentMessage($clarifySession);

                if (!empty($msgData['done'])) {
                    return $this->finalizeClarificationSelection($chatId, $msgData, $state);
                }

                // Recursive untuk auto-select berantai
                return $this->handleClarificationAutoSelect($chatId, $msgData, $clarifySession, $state);
            }
        }

        // Handle skip (tidak ada data di level ini)
        if (!empty($msgData['skip'])) {
            // Tidak ada data → maju ke level berikutnya secara otomatis
            // Ini ditangani internal ClarificationService; cukup rebuild pesan
            $clarifySession = $this->clarificationService->getSession($chatId);
            if ($clarifySession) {
                $msgData = $this->clarificationService->buildCurrentMessage($clarifySession);
            }
        }

        return [
            'message'  => $msgData['message'] ?? 'Pilih equipment:',
            'keyboard' => $msgData['keyboard'] ?? [],
        ];
    }

    /**
     * Finalisasi pilihan equipment dari ClarificationService.
     */
    protected function finalizeClarificationSelection(string $chatId, array $msgData, array $state): array
    {
        $assetId    = $msgData['selected_asset_id'] ?? null;
        $isAreaWork = !empty($msgData['is_area_work']);

        if ($isAreaWork) {
            // Pekerjaan area — tidak ada equipment spesifik
            $state['equipment_id']    = null;
            $state['equipment_ident'] = null;
            $state['is_area_work']    = true;
            $state['area_id']         = $msgData['selected_area_id'] ?? null;

            // Resolve area code untuk konfirmasi
            if ($state['area_id']) {
                $area = Area::find($state['area_id']);
                $state['area_code'] = $area ? $area->code : null;
            }
        } elseif ($assetId) {
            $asset = Asset::find($assetId);
            if (!$asset) {
                return $this->errorResponse('Equipment tidak ditemukan. Silakan mulai ulang.');
            }
            $state['equipment_id']      = $asset->id;
            $state['equipment_ident']   = $asset->tech_ident_no;
            $state['equipment_funcloc'] = $asset->functional_loc;
            if ($asset->area_id) {
                $state['area_id']   = $asset->area_id;
                $area = $asset->area;
                $state['area_code'] = $area ? $area->code : null;
            }
        } else {
            return $this->errorResponse('Pilihan equipment tidak valid. Coba ulangi.');
        }

        // Bersihkan flag ClarificationService
        $state['using_clarification_service'] = false;
        $this->clarificationService->destroySession($chatId);

        return $this->advanceToWorkDuration($chatId, $state);
    }

    /**
     * Lock equipment yang dipilih dan maju ke Step 4.
     */
    protected function lockEquipmentAndAdvance(string $chatId, Asset $asset, array $state): array
    {
        $state['equipment_id']      = $asset->id;
        $state['equipment_ident']   = $asset->tech_ident_no;
        $state['equipment_funcloc'] = $asset->functional_loc;

        if ($asset->area_id) {
            $state['area_id'] = $asset->area_id;
            $area = Area::find($asset->area_id);
            $state['area_code'] = $area ? $area->code : null;
        }

        // Bersihkan ClarificationService jika ada
        if (!empty($state['using_clarification_service'])) {
            $this->clarificationService->destroySession($chatId);
            $state['using_clarification_service'] = false;
        }

        return $this->advanceToWorkDuration($chatId, $state);
    }

    // =========================================================
    // STEP 4 — WAKTU PENGERJAAN
    // =========================================================

    protected function advanceToWorkDuration(string $chatId, array $state): array
    {
        $state['step'] = self::STEP_WORK_DURATION;
        $this->saveState($chatId, $state);

        return $this->buildWorkDurationPrompt($state, autoDetected: !empty($state['work_duration_minutes']));
    }

    protected function buildWorkDurationPrompt(array $state, bool $autoDetected = false): array
    {
        $equipmentLabel = $this->equipmentLabel($state);

        if ($autoDetected && !empty($state['work_duration_minutes'])) {
            $formatted = $this->formatDuration($state['work_duration_minutes']);
            $keyboard  = [
                ['text' => "✅ Ya, {$formatted}", 'callback_data' => 'wizard:confirm:duration_ok'],
                ['text' => '✏️ Ubah Durasi',      'callback_data' => 'wizard:confirm:duration_change'],
            ];
            return [
                'message' => "Laporan untuk *{$equipmentLabel}* diterima.\n\n" .
                    "Durasi pekerjaan terdeteksi: *{$formatted}*\n" .
                    "Sudah sesuai?",
                'keyboard' => $keyboard,
            ];
        }

        return [
            'message'  => "Equipment dikunci: *{$equipmentLabel}*\n\n" .
                "*Step 4/8* — Berapa lama pekerjaan berlangsung?\n" .
                "Ketik durasi (contoh: `2 jam`, `30 menit`, `1.5 jam`)",
            'keyboard' => [],
        ];
    }

    protected function handleDurationInput(string $chatId, string $text, array $state): array
    {
        $minutes = $this->parseDurationToMinutes($text);

        if ($minutes === null || $minutes <= 0) {
            return [
                'message'  => "Durasi tidak dikenali. Coba format lain:\n" .
                    "`2 jam`, `30 menit`, `1 jam 30 menit`, `90 menit`",
                'keyboard' => [],
            ];
        }

        $state['work_duration_minutes'] = $minutes;
        $state['step']                  = self::STEP_ROOT_CAUSE;
        $this->saveState($chatId, $state);

        return $this->buildRootCausePrompt($state);
    }

    // =========================================================
    // STEP 5 — ROOT CAUSE
    // =========================================================

    protected function buildRootCausePrompt(array $state): array
    {
        $equipmentLabel = $this->equipmentLabel($state);
        $duration       = $this->formatDuration($state['work_duration_minutes'] ?? 0);

        // Jika AI sudah mendeteksi root cause, konfirmasi dulu
        if (!empty($state['root_cause'])) {
            $existing = $state['root_cause'];
            return [
                'message'  => "*Step 5/8* — Root Cause\n\n" .
                    "Root cause yang terdeteksi dari laporan:\n_{$existing}_\n\n" .
                    "Gunakan root cause ini atau ketik yang baru:",
                'keyboard' => [
                    ['text' => '✅ Gunakan ini', 'callback_data' => 'wizard:confirm:rootcause_ok'],
                    ['text' => '✏️ Ubah',        'callback_data' => 'wizard:confirm:rootcause_change'],
                ],
            ];
        }

        return [
            'message'  => "*Step 5/8* — Root Cause\n\n" .
                "Equipment: *{$equipmentLabel}*\n" .
                "Durasi: *{$duration}*\n\n" .
                "Ketik *root cause* (penyebab kerusakan/pekerjaan):",
            'keyboard' => [],
        ];
    }

    protected function handleRootCauseInput(string $chatId, string $text, array $state): array
    {
        $trimmed = trim($text);

        if (mb_strlen($trimmed) < self::ROOT_CAUSE_MIN_LENGTH) {
            return [
                'message'  => "Root cause terlalu pendek (minimal " . self::ROOT_CAUSE_MIN_LENGTH . " karakter).\n" .
                    "Deskripsikan penyebab kerusakan/pekerjaan:",
                'keyboard' => [],
            ];
        }

        $state['root_cause'] = $trimmed;
        $state['step']       = self::STEP_PHOTO_DOCUMENTATION;
        $this->saveState($chatId, $state);

        return $this->buildPhotoDocumentationPrompt($state);
    }

    // =========================================================
    // STEP 6 — FOTO DOKUMENTASI
    // =========================================================

    protected function buildPhotoDocumentationPrompt(array $state): array
    {
        $hasInitialPhoto = !empty($state['initial_photo_file_id']);
        $currentPhotos   = count($state['photo_documentation'] ?? []);

        if ($hasInitialPhoto && $currentPhotos === 0) {
            // Ada foto dari Step 1 — konfirmasi gunakan foto itu
            return [
                'message'  => "*Step 6/8* — Foto Dokumentasi\n\n" .
                    "Sudah ada 1 foto yang dikirim bersama laporan awal.\n" .
                    "Tambah foto lagi, atau lanjutkan?",
                'keyboard' => [
                    ['text' => '✅ Cukup, Lanjutkan',  'callback_data' => 'wizard:confirm:photo_doc_done'],
                    ['text' => '📷 Tambah Foto Lagi',   'callback_data' => 'wizard:confirm:photo_doc_more'],
                    ['text' => '⏭ Skip (Tanpa Foto)',   'callback_data' => 'wizard:confirm:photo_doc_skip'],
                ],
            ];
        }

        if ($currentPhotos > 0) {
            return [
                'message'  => "*Step 6/8* — Foto Dokumentasi\n\n" .
                    "{$currentPhotos} foto sudah diterima.\n" .
                    "Kirim foto lagi, atau lanjutkan:",
                'keyboard' => [
                    ['text' => '✅ Cukup, Lanjutkan', 'callback_data' => 'wizard:confirm:photo_doc_done'],
                    ['text' => '⏭ Skip Sisa Foto',    'callback_data' => 'wizard:confirm:photo_doc_skip'],
                ],
            ];
        }

        return [
            'message'  => "*Step 6/8* — Foto Dokumentasi\n\n" .
                "Kirim foto dokumentasi pekerjaan (opsional, bisa lebih dari 1).\n" .
                "Atau skip jika tidak ada:",
            'keyboard' => [
                ['text' => '⏭ Skip (Tanpa Foto)', 'callback_data' => 'wizard:confirm:photo_doc_skip'],
            ],
        ];
    }

    /**
     * Proses perintah teks di step foto ("selesai", "skip", dll).
     */
    protected function handlePhotoCommand(string $chatId, string $text, array $state, string $photoStep): array
    {
        $text = strtolower(trim($text));

        if (in_array($text, ['selesai', 'done', 'lanjut', 'skip', 'next'])) {
            return $this->advanceFromPhotoStep($chatId, $state, $photoStep);
        }

        $currentCount = count($state['photo_' . ($photoStep === 'documentation' ? 'documentation' : 'hygiene_clearance')] ?? []);
        $stepNum      = $photoStep === 'documentation' ? '6' : '7';

        return [
            'message'  => "*Step {$stepNum}/8* — {$currentCount} foto diterima.\n" .
                "Kirim foto berikutnya, atau ketik *selesai* untuk lanjut.",
            'keyboard' => [
                ['text' => '✅ Selesai, Lanjutkan', 'callback_data' => 'wizard:confirm:photo_' . $photoStep . '_done'],
                ['text' => '⏭ Skip',                'callback_data' => 'wizard:confirm:photo_' . $photoStep . '_skip'],
            ],
        ];
    }

    /**
     * Tambah file ID foto ke state wizard.
     */
    protected function addPhotoToStep(string $chatId, string $fileId, array $state, string $photoStep): array
    {
        if ($photoStep === 'documentation') {
            $key     = 'photo_documentation';
            $stepNum = '6';
        } else {
            $key     = 'photo_hygiene_clearance';
            $stepNum = '7';
        }

        $state[$key]   = $state[$key] ?? [];
        $state[$key][] = $fileId;
        $count         = count($state[$key]);
        $this->saveState($chatId, $state);

        return [
            'message'  => "📷 Foto {$count} diterima.\n" .
                "Kirim foto berikutnya, atau tekan *Selesai* untuk lanjut.",
            'keyboard' => [
                ['text' => '✅ Selesai, Lanjutkan', 'callback_data' => 'wizard:confirm:photo_' . $photoStep . '_done'],
                ['text' => '⏭ Skip Sisa',           'callback_data' => 'wizard:confirm:photo_' . $photoStep . '_skip'],
            ],
        ];
    }

    // =========================================================
    // STEP 7 — FOTO HYGIENE CLEARANCE
    // =========================================================

    protected function buildPhotoHygienePrompt(array $state): array
    {
        $currentPhotos = count($state['photo_hygiene_clearance'] ?? []);

        if ($currentPhotos > 0) {
            return [
                'message'  => "*Step 7/8* — Foto Hygiene Clearance\n\n" .
                    "{$currentPhotos} foto sudah diterima.\n" .
                    "Kirim foto lagi, atau lanjutkan ke konfirmasi:",
                'keyboard' => [
                    ['text' => '✅ Cukup, Lanjutkan', 'callback_data' => 'wizard:confirm:photo_hygiene_done'],
                    ['text' => '⏭ Skip Sisa',         'callback_data' => 'wizard:confirm:photo_hygiene_skip'],
                ],
            ];
        }

        return [
            'message'  => "*Step 7/8* — Foto Hygiene Clearance\n\n" .
                "Kirim foto hygiene clearance (opsional).\n" .
                "Atau skip untuk langsung ke konfirmasi:",
            'keyboard' => [
                ['text' => '⏭ Skip (Tanpa Foto)', 'callback_data' => 'wizard:confirm:photo_hygiene_skip'],
            ],
        ];
    }

    // =========================================================
    // NAVIGASI ANTAR STEP (dari callback konfirmasi)
    // =========================================================

    protected function handleConfirmationCallback(string $chatId, string $callbackData, array $state): array
    {
        // Ekstrak action dari 'wizard:confirm:<action>'
        $action = str_replace('wizard:confirm:', '', $callbackData);

        switch ($action) {
            // Step 4 — durasi OK: maju ke Step 5
            case 'duration_ok':
                $state['step'] = self::STEP_ROOT_CAUSE;
                $this->saveState($chatId, $state);
                return $this->buildRootCausePrompt($state);

            // Step 4 — durasi diubah: minta input ulang
            case 'duration_change':
                $state['work_duration_minutes'] = null;
                $this->saveState($chatId, $state);
                return [
                    'message'  => "Ketik durasi pekerjaan:\n_(contoh: `2 jam`, `30 menit`, `1 jam 30 menit`)_",
                    'keyboard' => [],
                ];

            // Step 5 — root cause OK (dari deteksi AI)
            case 'rootcause_ok':
                $state['step'] = self::STEP_PHOTO_DOCUMENTATION;
                $this->saveState($chatId, $state);
                return $this->buildPhotoDocumentationPrompt($state);

            // Step 5 — root cause diubah
            case 'rootcause_change':
                $state['root_cause'] = null;
                $this->saveState($chatId, $state);
                return [
                    'message'  => "Ketik root cause (penyebab kerusakan/pekerjaan):",
                    'keyboard' => [],
                ];

            // Step 6 — foto dokumentasi selesai / skip
            case 'photo_doc_done':
            case 'photo_doc_skip':
            case 'photo_doc_more':
                if ($action === 'photo_doc_more') {
                    // Minta foto tambahan
                    return [
                        'message'  => "Silakan kirim foto tambahan:",
                        'keyboard' => [
                            ['text' => '✅ Selesai', 'callback_data' => 'wizard:confirm:photo_doc_done'],
                        ],
                    ];
                }
                return $this->advanceFromPhotoStep($chatId, $state, 'documentation');

            // Step 7 — foto hygiene selesai / skip
            case 'photo_hygiene_done':
            case 'photo_hygiene_skip':
                return $this->advanceFromPhotoStep($chatId, $state, 'hygiene');

            // Step 8 — konfirmasi akhir
            case 'save_report':
                return $this->saveReport($chatId, $state);

            case 'cancel_report':
                $this->destroyWizard($chatId);
                return [
                    'message'  => "Laporan dibatalkan. Wizard ditutup.\nKirim laporan baru kapan saja.",
                    'keyboard' => [],
                ];

            default:
                return $this->errorResponse("Callback tidak dikenal: {$action}");
        }
    }

    /**
     * Maju dari step foto ke step berikutnya.
     */
    protected function advanceFromPhotoStep(string $chatId, array $state, string $photoStep): array
    {
        // Jika ada foto awal dari Step 1, masukkan ke photo_documentation dulu
        if ($photoStep === 'documentation' && !empty($state['initial_photo_file_id'])) {
            if (empty($state['photo_documentation'])) {
                $state['photo_documentation'] = [];
            }
            // Prepend foto awal jika belum ada
            if (!in_array($state['initial_photo_file_id'], $state['photo_documentation'])) {
                array_unshift($state['photo_documentation'], $state['initial_photo_file_id']);
            }
        }

        if ($photoStep === 'documentation') {
            $state['step'] = self::STEP_PHOTO_HYGIENE;
            $this->saveState($chatId, $state);
            return $this->buildPhotoHygienePrompt($state);
        }

        // Dari hygiene → Step 8
        $state['step'] = self::STEP_CONFIRMATION;
        $this->saveState($chatId, $state);
        return $this->buildConfirmationSummary($state);
    }

    /**
     * Proses teks konfirmasi ("ya"/"tidak") di Step 8.
     */
    protected function handleConfirmation(string $chatId, string $text, array $state): array
    {
        $text = strtolower(trim($text));

        if (in_array($text, ['ya', 'yes', 'ok', 'oke', 'simpan', 'confirm'])) {
            return $this->saveReport($chatId, $state);
        }

        if (in_array($text, ['tidak', 'no', 'batal', 'cancel', 'batalkan'])) {
            $this->destroyWizard($chatId);
            return [
                'message'  => "Laporan dibatalkan. Wizard ditutup.\nKirim laporan baru kapan saja.",
                'keyboard' => [],
            ];
        }

        // Tidak dikenali — tampilkan ulang konfirmasi
        return $this->buildConfirmationSummary($state);
    }

    // =========================================================
    // STEP 8 — KONFIRMASI & SIMPAN
    // =========================================================

    protected function buildConfirmationSummary(array $state): array
    {
        $equipmentLabel = $this->equipmentLabel($state);
        $duration       = $this->formatDuration($state['work_duration_minutes'] ?? 0);
        $rootCause      = $state['root_cause'] ?? '-';
        $photoDocCount  = count($state['photo_documentation'] ?? []);
        $photoHygCount  = count($state['photo_hygiene_clearance'] ?? []);

        $msg  = "*Step 8/8* — Konfirmasi Laporan\n\n";
        $msg .= "Periksa ringkasan berikut sebelum disimpan:\n\n";
        $msg .= "🔧 *Equipment* : {$equipmentLabel}\n";
        $msg .= "⏱ *Durasi*    : {$duration}\n";
        $msg .= "🔍 *Root Cause*: {$rootCause}\n";
        $msg .= "📷 *Foto Dok*  : {$photoDocCount} foto\n";
        $msg .= "🧹 *Foto HC*   : {$photoHygCount} foto\n\n";
        $msg .= "Simpan laporan ini?";

        return [
            'message'  => $msg,
            'keyboard' => [
                ['text' => '✅ Ya, Simpan',  'callback_data' => 'wizard:confirm:save_report'],
                ['text' => '❌ Batalkan',    'callback_data' => 'wizard:confirm:cancel_report'],
            ],
        ];
    }

    /**
     * Cek apakah sebuah nilai foto adalah path lokal hasil
     * PhotoStorageService->store(), bukan file_id Telegram mentah.
     *
     * Path lokal selalu mengandung tanda "/" (format:
     * reports/YYYY/MM/DD/{chat_id}/{filename}.jpg). File_id Telegram asli
     * tidak pernah mengandung "/" sama sekali.
     *
     * @param  mixed $value
     * @return bool
     */
    protected function isValidLocalPhotoPath(mixed $value): bool
    {
        return is_string($value) && $value !== '' && str_contains($value, '/');
    }

    /**
     * Filter array foto agar hanya berisi path lokal yang valid.
     * Entri yang bukan path lokal (misal file_id Telegram mentah yang lolos
     * tanpa diproses PhotoStorageService) dibuang dan dicatat ke log.
     *
     * @param  array $photos
     * @return array
     */
    protected function filterValidLocalPhotoPaths(array $photos): array
    {
        $valid   = [];
        $invalid = [];

        foreach ($photos as $photo) {
            if ($this->isValidLocalPhotoPath($photo)) {
                $valid[] = $photo;
            } else {
                $invalid[] = $photo;
            }
        }

        if (!empty($invalid)) {
            Log::warning('WizardService: Foto tidak valid dibuang saat saveReport (bukan path lokal)', [
                'invalid_count' => count($invalid),
                'invalid_items' => $invalid,
            ]);
        }

        return $valid;
    }

    /**
     * Simpan laporan ke DB (pendekatan "Create at End").
     * Hanya dipanggil setelah konfirmasi di Step 8.
     */
    protected function saveReport(string $chatId, array $state): array
    {
        try {
            // Cari technician berdasarkan telegram_id = chatId
            $technician = \App\Models\Technician::where('telegram_id', $chatId)->first();
            if (!$technician) {
                return $this->errorResponse(
                    "Akun teknisi tidak ditemukan untuk chat ini.\n" .
                    "Hubungi admin untuk mendaftarkan Telegram ID kamu."
                );
            }

            // Tentukan report_type dari data state (enum: equipment_repair, area_work, general)
            $reportType = 'general';
            if (!empty($state['is_area_work'])) {
                $reportType = 'area_work';
            } elseif (!empty($state['equipment_id'])) {
                $reportType = 'equipment_repair';
            }

            $reportCode = $this->generateReportCode();

            // Filter foto: hanya path lokal hasil PhotoStorageService->store()
            // yang boleh masuk DB. Path lokal selalu mengandung tanda "/"
            // (format reports/YYYY/MM/DD/{chat_id}/{file}.jpg). File_id
            // Telegram mentah (tanpa "/") dibuang dan dicatat ke log — itu
            // tandanya ada caller yang lupa memanggil store() dulu.
            $photoDocumentation    = $this->filterValidLocalPhotoPaths($state['photo_documentation'] ?? []);
            $photoHygieneClearance = $this->filterValidLocalPhotoPaths($state['photo_hygiene_clearance'] ?? []);

            $report = Report::create([
                'report_code'             => $reportCode,
                'technician_id'           => $technician->id,
                'report_date'             => now()->toDateString(),
                'work_description'        => $state['text'],
                'asset_id'                => $state['equipment_id'] ?? null,
                'area_id'                 => $state['area_id'] ?? null,
                'report_type'             => $reportType,
                'ai_analyzed'             => !empty($state['ai_analysis']),
                'ai_confidence'           => $state['ai_analysis']['confidence'] ?? null,
                'work_duration_minutes'   => $state['work_duration_minutes'] ?? null,
                'root_cause'              => $state['root_cause'],
                'photo_documentation'     => $photoDocumentation,
                'photo_hygiene_clearance' => $photoHygieneClearance,
                'status'                  => 'draft',
                'wizard_started_at'       => $state['created_at'] ?? null,
                'submitted_at'            => now(),
            ]);

            $this->destroyWizard($chatId);

            Log::info("WizardService: Report saved for chat {$chatId}", [
                'report_id'       => $report->id,
                'report_code'     => $reportCode,
                'photo_doc_count' => count($photoDocumentation),
                'photo_hyg_count' => count($photoHygieneClearance),
            ]);

            $equipmentLabel = $this->equipmentLabel($state);
            $duration       = $this->formatDuration($state['work_duration_minutes'] ?? 0);

            $msg  = "✅ *Laporan Berhasil Disimpan!*\n\n";
            $msg .= "🆔 Kode Laporan: `{$reportCode}`\n";
            $msg .= "🔧 Equipment: {$equipmentLabel}\n";
            $msg .= "⏱ Durasi: {$duration}\n\n";
            $msg .= "Gunakan kode di atas untuk menambah foto ke laporan ini di kemudian hari.";

            return [
                'message'     => $msg,
                'keyboard'    => [],
                'report_code' => $reportCode,
                'report_id'   => $report->id,
                'saved'       => true,
            ];
        } catch (\Throwable $e) {
            Log::error("WizardService: Failed to save report for chat {$chatId}", [
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                "Terjadi kesalahan saat menyimpan laporan. Silakan coba lagi atau hubungi admin.\n" .
                "Error: " . $e->getMessage()
            );
        }
    }

    // =========================================================
    // TAMBAH FOTO KE LAPORAN YANG SUDAH SELESAI (via ID)
    // Dokumen Bagian F: "Tambah Foto ke Laporan yang Sudah Selesai via ID"
    // =========================================================

    /**
     * Cek apakah teks mengandung kode laporan RPT-YYYYMMDD-XXXX.
     * Dipanggil dari PollTelegramUpdates sebelum memutuskan apakah foto
     * masuk ke wizard baru atau laporan lama.
     */
    public function extractReportCode(string $text): ?string
    {
        if (preg_match('/\bRPT-\d{8}-\d{4}\b/i', $text, $m)) {
            return strtoupper($m[0]);
        }

        return null;
    }

    /**
     * Tambahkan foto ke laporan yang sudah tersimpan (via report_code).
     * Dipanggil dari PollTelegramUpdates saat foto memiliki caption dengan RPT-...
     *
     * Tipe foto (dokumentasi/hygiene) ditentukan dari caption di sini —
     * bukan tanggung jawab PollTelegramUpdates (lihat Bagian H: pemisahan
     * tanggung jawab service).
     *
     * @param  string $reportCode Kode laporan RPT-YYYYMMDD-XXXX
     * @param  string $fileId     File ID foto dari Telegram
     * @param  string $caption    Caption asli foto — dipakai untuk deteksi tipe
     * @return array  Respons
     */
    public function addPhotoToReport(string $reportCode, string $fileId, string $caption = ''): array
    {
        $report = Report::where('report_code', $reportCode)->first();

        if (!$report) {
            return $this->errorResponse("Laporan dengan kode *{$reportCode}* tidak ditemukan.");
        }

        // Validasi: nilai $fileId di sini seharusnya sudah berupa path lokal
        // hasil PhotoStorageService->store() (dipanggil PollTelegramUpdates
        // sebelum method ini dipanggil), bukan file_id Telegram mentah.
        // Path lokal selalu mengandung tanda "/" atau diawali "reports/"
        // (format reports/YYYY/MM/DD/{chat_id}/{file}.jpg).
        if (!$this->isValidLocalPhotoPath($fileId)) {
            Log::warning("WizardService: addPhotoToReport menolak nilai yang bukan path lokal", [
                'report_code' => $reportCode,
                'value'       => $fileId,
            ]);

            return $this->errorResponse(
                "Gagal menambahkan foto: format file tidak valid.\n" .
                "Foto tampaknya belum diproses sistem dengan benar. Hubungi admin jika ini terus terjadi."
            );
        }

        $type = $this->detectPhotoTypeFromCaption($caption);

        if ($type === 'hygiene') {
            $photos   = $report->photo_hygiene_clearance ?? [];
            $photos[] = $fileId;
            $report->update(['photo_hygiene_clearance' => $photos]);
            $field = 'Hygiene Clearance';
        } else {
            $photos   = $report->photo_documentation ?? [];
            $photos[] = $fileId;
            $report->update(['photo_documentation' => $photos]);
            $field = 'Dokumentasi';
        }

        $count = count($photos);

        return [
            'message'  => "📷 Foto {$field} berhasil ditambahkan ke laporan `{$reportCode}`.\n" .
                "Total foto {$field}: {$count}.",
            'keyboard' => [],
        ];
    }

    /**
     * Tentukan tipe foto dari caption: 'hygiene' jika caption menyebut kata
     * "hygiene", selain itu dianggap 'documentation' (default).
     */
    protected function detectPhotoTypeFromCaption(string $caption): string
    {
        return stripos($caption, 'hygiene') !== false ? 'hygiene' : 'documentation';
    }

    // =========================================================
    // UTILITIES
    // =========================================================

    /**
     * Parse teks durasi menjadi menit.
     * Mendukung: "2 jam", "30 menit", "1 jam 30 menit", "1.5 jam", "90"
     */
    public function parseDurationToMinutes(string $text): ?int
    {
        $text = strtolower(trim($text));

        // Format: "X jam Y menit"
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*jam\s*(\d+)\s*menit/', $text, $m)) {
            $hours   = (float) str_replace(',', '.', $m[1]);
            $minutes = (int) $m[2];
            return (int) round($hours * 60) + $minutes;
        }

        // Format: "X jam"
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*jam/', $text, $m)) {
            return (int) round((float) str_replace(',', '.', $m[1]) * 60);
        }

        // Format: "X menit"
        if (preg_match('/(\d+)\s*menit/', $text, $m)) {
            return (int) $m[1];
        }

        // Format: angka saja (anggap menit)
        if (preg_match('/^\d+$/', $text)) {
            return (int) $text;
        }

        return null;
    }

    /**
     * Format menit ke string ramah-pengguna.
     */
    protected function formatDuration(int $minutes): string
    {
        if ($minutes <= 0) {
            return '-';
        }

        $hours = intdiv($minutes, 60);
        $mins  = $minutes % 60;

        if ($hours > 0 && $mins > 0) {
            return "{$hours} jam {$mins} menit";
        }

        if ($hours > 0) {
            return "{$hours} jam";
        }

        return "{$minutes} menit";
    }

    /**
     * Label ringkas untuk equipment/area yang dikunci di state.
     */
    protected function equipmentLabel(array $state): string
    {
        if (!empty($state['is_area_work'])) {
            $areaCode = $state['area_code'] ?? 'Area';
            return "Pekerjaan Area ({$areaCode})";
        }

        $label = $state['equipment_ident'] ?? 'Equipment';
        if (!empty($state['equipment_funcloc'])) {
            $label .= ' (' . $state['equipment_funcloc'] . ')';
        }

        return $label;
    }

    /**
     * Generate kode laporan RPT-YYYYMMDD-XXXX.
     * XXXX = 4-digit sequence unik per hari (00-based, dipadding).
     */
    protected function generateReportCode(): string
    {
        $date     = now()->format('Ymd');
        $prefix   = "RPT-{$date}-";
        $lastCode = Report::where('report_code', 'like', $prefix . '%')
            ->orderByDesc('report_code')
            ->value('report_code');

        if ($lastCode) {
            $lastSeq = (int) substr($lastCode, -4);
            $seq     = $lastSeq + 1;
        } else {
            $seq = 1;
        }

        return $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Buat state awal wizard.
     */
    protected function createInitialState(string $chatId, string $text): array
    {
        return [
            'chat_id'                 => $chatId,
            'text'                    => $text,
            'step'                    => self::STEP_EQUIPMENT_SEARCH,
            'ai_analysis'             => [],
            'equipment_id'            => null,
            'equipment_ident'         => null,
            'equipment_funcloc'       => null,
            'is_area_work'            => false,
            'area_id'                 => null,
            'area_code'               => null,
            'search_confidence'       => null,
            'retype_attempts'         => 0,
            'using_clarification_service' => false,
            'work_duration_minutes'   => null,
            'root_cause'              => null,
            'initial_photo_file_id'   => null,
            'photo_documentation'     => [],
            'photo_hygiene_clearance' => [],
            'created_at'              => now()->toIso8601String(),
        ];
    }

    /**
     * Response error standar.
     */
    protected function errorResponse(string $message): array
    {
        return [
            'message'  => $message,
            'keyboard' => [],
            'error'    => true,
        ];
    }

    // =========================================================
    // STATE MANAGEMENT
    // =========================================================

    public function getState(string $chatId): ?array
    {
        return Cache::get(self::CACHE_PREFIX . $chatId);
    }

    public function saveState(string $chatId, array $state): void
    {
        Cache::put(self::CACHE_PREFIX . $chatId, $state, self::CACHE_TTL);
    }

    public function destroyWizard(string $chatId): void
    {
        Cache::forget(self::CACHE_PREFIX . $chatId);
    }

    public function hasActiveWizard(string $chatId): bool
    {
        return Cache::has(self::CACHE_PREFIX . $chatId);
    }
}
