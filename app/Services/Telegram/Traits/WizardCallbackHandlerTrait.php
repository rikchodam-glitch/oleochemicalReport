<?php

namespace App\Services\Telegram\Traits;

use App\Models\Area;
use App\Models\Asset;
use Illuminate\Support\Facades\Log;

/**
 * WizardCallbackHandlerTrait
 *
 * Menangani semua callback inline keyboard dalam wizard laporan:
 *   - handleCallback()                  : Router utama semua callback wizard
 *   - handleConfirmationCallback()      : Navigasi antar step via callback konfirmasi
 *   - handleHierarchyCancel()           : Batalkan wizard dari dalam navigasi hierarki
 *   - buildEquipmentConfirmKeyboard()   : Bangun keyboard konfirmasi equipment terdeteksi
 *   - handleEquipmentConfirmCallback()  : Proses jawaban Ya/Tidak untuk equipment
 *   - handleWorkTypeCallback()          : Proses pilihan jenis pekerjaan (equipment/area)
 *   - handleEquipmentCandidateCallback(): Proses pilihan kandidat dari keyboard
 *   - handleClarificationCallback()     : Teruskan callback ke ClarificationService
 *   - handleClarificationText()         : Tangani teks saat ClarificationService aktif
 *   - handleClarificationAutoSelect()   : Auto-select saat hanya ada 1 opsi di hierarki
 *   - finalizeClarificationSelection()  : Kunci hasil pilihan dari ClarificationService
 *
 * Trait ini bergantung pada method berikut dari kelas pemakai:
 *   - errorResponse(string $message): array
 *   - saveState(string $chatId, array $state): void
 *   - destroyWizard(string $chatId): void
 *   - lockEquipmentAndAdvance(string $chatId, Asset $asset, array $state): array
 *   - startClarificationHierarchy(string $chatId, array $state): array
 *   - advanceToWorkDuration(string $chatId, array $state): array
 *   - buildRootCausePrompt(array $state): array
 *   - buildPhotoDocumentationPrompt(array $state): array
 *   - advanceFromPhotoStep(string $chatId, array $state, string $photoStep): array
 *   - buildConfirmationSummary(array $state): array
 *   - saveReport(string $chatId, array $state): array
 *   - $clarificationService: ClarificationService
 */
trait WizardCallbackHandlerTrait
{
    /**
     * Router utama semua callback inline keyboard wizard.
     * Dipanggil dari handleCallback() entry point di ReportWizardService.
     *
     * @param  string $chatId       Chat ID Telegram
     * @param  string $callbackData Data callback dari Telegram
     * @param  array  $state        State wizard saat ini
     * @return array  Respons
     */
    protected function routeCallback(string $chatId, string $callbackData, array $state): array
    {
        // Callback konfirmasi Step 8
        if (str_starts_with($callbackData, 'wizard:confirm:')) {
            return $this->handleConfirmationCallback($chatId, $callbackData, $state);
        }

        // Pembatalan wizard dari mana saja
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

        // Callback konfirmasi equipment terdeteksi otomatis (confidence >= 95%)
        if (str_starts_with($callbackData, 'equipment_confirm:')) {
            return $this->handleEquipmentConfirmCallback($chatId, $callbackData, $state);
        }

        // Callback pilihan jenis pekerjaan saat no_match
        if (str_starts_with($callbackData, 'work_type:')) {
            return $this->handleWorkTypeCallback($chatId, $callbackData, $state);
        }

        // Masuk ke hierarki ClarificationService (Sub-alur C)
        if ($callbackData === 'equipment:hierarchy') {
            return $this->startClarificationHierarchy($chatId, $state);
        }

        // Tombol "Batal" dari dalam navigasi hierarki
        if ($callbackData === 'hierarchy:cancel') {
            return $this->handleHierarchyCancel($chatId, $state);
        }

        // Callback dari ClarificationService saat hierarki aktif
        if ($state['step'] === self::STEP_EQUIPMENT_CLARIFY && !empty($state['using_clarification_service'])) {
            return $this->handleClarificationCallback($chatId, $callbackData, $state);
        }

        return $this->errorResponse('Callback tidak dikenali.');
    }

    /**
     * Navigasi antar step melalui callback konfirmasi wizard:confirm:<action>.
     * Menangani transisi dari Step 4 (durasi), Step 5 (root cause),
     * Step 6-7 (foto), hingga Step 8 (simpan/batalkan).
     *
     * @param  string $chatId       Chat ID Telegram
     * @param  string $callbackData Data callback wizard:confirm:<action>
     * @param  array  $state        State wizard saat ini
     * @return array  Respons
     */
    protected function handleConfirmationCallback(string $chatId, string $callbackData, array $state): array
    {
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

            // Step 6 — foto dokumentasi: minta foto tambahan
            case 'photo_doc_more':
                return [
                    'message'  => "Silakan kirim foto tambahan:",
                    'keyboard' => [
                        ['text' => 'Selesai', 'callback_data' => 'wizard:confirm:photo_doc_done'],
                    ],
                ];

            // Step 6 — foto dokumentasi selesai / skip
            case 'photo_doc_done':
            case 'photo_doc_skip':
                return $this->advanceFromPhotoStep($chatId, $state, 'documentation');

            // Step 7 — foto hygiene selesai / skip
            case 'photo_hygiene_done':
            case 'photo_hygiene_skip':
                return $this->advanceFromPhotoStep($chatId, $state, 'hygiene');

            // Step 8 — simpan laporan
            case 'save_report':
                return $this->saveReport($chatId, $state);

            // Step 8 — batalkan laporan
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
     * Batalkan seluruh wizard saat teknisi menekan "Batal" di dalam navigasi
     * hierarki (Sub-alur C). Sesi ClarificationService juga dihapus agar tidak
     * ada state yang tertinggal.
     *
     * @param  string $chatId Chat ID Telegram
     * @param  array  $state  State wizard saat ini (tidak dipakai, dikirim untuk konsistensi)
     * @return array  Respons
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

    /**
     * Bangun keyboard konfirmasi untuk equipment yang terdeteksi otomatis.
     * Selalu ditampilkan sebelum mengunci equipment, berapapun nilai confidence-nya,
     * agar teknisi bisa mengoreksi jika deteksi keliru.
     *
     * @param  array $candidate  Satu entri dari formatCandidates() — berisi id, tech_ident_no, description, functional_loc
     * @param  int   $confidence Nilai confidence dari TechIdentSearch
     * @return array Respons dengan message dan keyboard dua tombol
     */
    protected function buildEquipmentConfirmKeyboard(array $candidate, int $confidence): array
    {
        $ti   = $candidate['tech_ident_no'] ?? '?';
        $fl   = $candidate['functional_loc'] ?? '';
        $desc = $candidate['description'] ?? '';

        $label = "*{$ti}*";
        if ($fl) {
            $label .= "\n_{$fl}_";
        }
        if ($desc) {
            $label .= "\n{$desc}";
        }

        $confidenceNote = $confidence < 100
            ? "\n_(Kemiripan: {$confidence}%)_"
            : '';

        return [
            'message'  => "Equipment terdeteksi:{$confidenceNote}\n\n{$label}\n\nApakah ini equipment yang dimaksud?",
            'keyboard' => [
                ['text' => 'Ya, betul',          'callback_data' => 'equipment_confirm:yes'],
                ['text' => 'Bukan, ganti alat',  'callback_data' => 'equipment_confirm:no'],
                ['text' => 'Batalkan Laporan',    'callback_data' => 'wizard:cancel_wizard'],
            ],
        ];
    }

    /**
     * Proses jawaban Ya/Tidak dari keyboard konfirmasi equipment.
     * State yang dibutuhkan (sudah disimpan oleh processEquipmentSearch / handleEquipmentRetype):
     *   - pending_equipment_id
     *   - pending_equipment_ident
     *   - pending_equipment_funcloc
     *
     * @param  string $chatId       Chat ID Telegram
     * @param  string $callbackData Data callback equipment_confirm:yes|no
     * @param  array  $state        State wizard saat ini
     * @return array  Respons
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
            // Hapus pending dan kembalikan ke mode pencarian ulang
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
                    ['text' => 'Pilih dari Hierarki', 'callback_data' => 'equipment:hierarchy'],
                ],
            ];
        }

        return $this->errorResponse('Pilihan tidak dikenali.');
    }

    /**
     * Proses pilihan jenis pekerjaan setelah no_match dari TechIdentSearch.
     * Dua pilihan: perbaikan equipment tertentu, atau pekerjaan area/section.
     *
     * @param  string $chatId       Chat ID Telegram
     * @param  string $callbackData Data callback work_type:equipment|area
     * @param  array  $state        State wizard saat ini
     * @return array  Respons
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
                    ['text' => 'Pilih dari Hierarki', 'callback_data' => 'equipment:hierarchy'],
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
     * Proses pilihan kandidat equipment dari keyboard Step 2.
     * Jika teknisi memilih "Tulis Ulang", tampilkan prompt input teks.
     * Jika memilih kandidat valid, langsung kunci equipment.
     *
     * @param  string $chatId       Chat ID Telegram
     * @param  string $callbackData Data callback equipment_candidate:<id|retype>
     * @param  array  $state        State wizard saat ini
     * @return array  Respons
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
                    ['text' => 'Pilih dari Hierarki', 'callback_data' => 'equipment:hierarchy'],
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
     * Teruskan callback ke ClarificationService saat hierarki aktif di Step 2.
     * Jika ClarificationService sudah selesai (level 'done'), finalisasi pilihan.
     *
     * @param  string $chatId       Chat ID Telegram
     * @param  string $callbackData Data callback dari Telegram
     * @param  array  $state        State wizard saat ini
     * @return array  Respons
     */
    protected function handleClarificationCallback(string $chatId, string $callbackData, array $state): array
    {
        $result = $this->clarificationService->processSelection($chatId, $callbackData);
        if (!$result['success']) {
            return $this->errorResponse($result['error'] ?? 'Terjadi kesalahan di klarifikasi.');
        }

        $clarifySession = $result['session'];
        $msgData        = $this->clarificationService->buildCurrentMessage($clarifySession);

        if (!empty($msgData['done'])) {
            return $this->finalizeClarificationSelection($chatId, $msgData, $state);
        }

        return $this->handleClarificationAutoSelect($chatId, $msgData, $clarifySession, $state);
    }

    /**
     * Tangani input teks saat ClarificationService aktif.
     * ClarificationService berbasis keyboard, sehingga teks diabaikan dan
     * keyboard terakhir ditampilkan ulang.
     *
     * @param  string $chatId Chat ID Telegram
     * @param  string $text   Input teks dari teknisi
     * @param  array  $state  State wizard saat ini
     * @return array  Respons
     */
    protected function handleClarificationText(string $chatId, string $text, array $state): array
    {
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
     * Handle auto-select dari ClarificationService.
     * Dipanggil ketika hanya ada satu opsi di level hierarki tertentu,
     * sehingga pemilihan dilakukan otomatis tanpa interaksi teknisi.
     * Mendukung auto-select berantai (rekursif) jika perlu.
     *
     * @param  string $chatId         Chat ID Telegram
     * @param  array  $msgData        Data pesan dari ClarificationService->buildCurrentMessage()
     * @param  array  $clarifySession Sesi ClarificationService saat ini
     * @param  array  $state          State wizard saat ini
     * @return array  Respons
     */
    protected function handleClarificationAutoSelect(
        string $chatId,
        array $msgData,
        array $clarifySession,
        array $state
    ): array {
        if (!empty($msgData['auto_select'])) {
            $autoCallback = $msgData['auto_level'] . ':select:' . $msgData['auto_id'];
            $result       = $this->clarificationService->processSelection($chatId, $autoCallback);
            if ($result['success']) {
                $clarifySession = $result['session'];
                $msgData        = $this->clarificationService->buildCurrentMessage($clarifySession);

                if (!empty($msgData['done'])) {
                    return $this->finalizeClarificationSelection($chatId, $msgData, $state);
                }

                // Rekursi untuk auto-select berantai
                return $this->handleClarificationAutoSelect($chatId, $msgData, $clarifySession, $state);
            }
        }

        // Handle skip: tidak ada data di level ini — ClarificationService sudah
        // maju ke level berikutnya secara internal, cukup rebuild pesan
        if (!empty($msgData['skip'])) {
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
     * Mengunci equipment atau area ke state wizard, lalu maju ke Step 4.
     *
     * @param  string $chatId  Chat ID Telegram
     * @param  array  $msgData Data 'done' dari ClarificationService->buildCurrentMessage()
     * @param  array  $state   State wizard saat ini
     * @return array  Respons
     */
    protected function finalizeClarificationSelection(string $chatId, array $msgData, array $state): array
    {
        $assetId    = $msgData['selected_asset_id'] ?? null;
        $isAreaWork = !empty($msgData['is_area_work']);

        if ($isAreaWork) {
            $state['equipment_id']    = null;
            $state['equipment_ident'] = null;
            $state['is_area_work']    = true;
            $state['area_id']         = $msgData['selected_area_id'] ?? null;

            if ($state['area_id']) {
                $area               = Area::find($state['area_id']);
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
                $area               = $asset->area;
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
}
