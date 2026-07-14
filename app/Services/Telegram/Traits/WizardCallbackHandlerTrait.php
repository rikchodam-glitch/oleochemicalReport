<?php

namespace App\Services\Telegram\Traits;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Department;
use App\Models\Technician;
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
 *   - handleFuncLocPick()               : Proses pilihan node FuncLoc (dari WizardFuncLocPickerTrait)
 *   - handleFuncLocConfirm()            : Konfirmasi FuncLoc tanpa turun lebih dalam (dari WizardFuncLocPickerTrait)
 *   - handleCollabCandidateCallback()   : Proses pilihan kandidat nama kolaborator (Step 8a)
 *   - handleCollabHierarchyCallback()   : Router alur hierarki Dept -> Section -> Pilih Teknisi (Step 8a)
 *   - handleCollabHierarchyPick()       : Kunci teknisi terpilih dari hierarki sebagai kolaborator
 *   - buildDeptKeyboard()               : Bangun keyboard daftar Department untuk hierarki kolaborator
 *   - buildSectionKeyboard()            : Bangun keyboard daftar Section dalam Department terpilih
 *   - buildCollabTechnicianKeyboard()   : Bangun keyboard daftar teknisi dalam Dept+Section terpilih
 *   - resolveSenderNik()                : Ambil NIK teknisi pengirim dari chat_id (untuk exclude self)
 *   - clearCollabHierarchyState()       : Bersihkan key state hierarki kolaborator
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
 *   - buildCollaboratorPrompt(array $state): array
 *   - buildConfirmationSummary(array $state): array
 *   - saveReport(string $chatId, array $state): array
 *   - $clarificationService: ClarificationService
 *
 * Method dari WizardFuncLocPickerTrait yang dipanggil oleh trait ini
 * (tersedia di kelas pemakai karena keduanya di-use di ReportWizardService):
 *
 * @method array startFuncLocPicker(string $chatId, array $state)
 * @method array handleFuncLocPick(string $chatId, string $callbackData, array $state)
 * @method array handleFuncLocConfirm(string $chatId, string $callbackData, array $state)
 *
 * CATATAN — Callback foto (photo_doc_* / photo_hygiene_*):
 * Semua callback_data foto yang tiba di handleConfirmationCallback() WAJIB
 * dalam bentuk pendek (photo_doc_done/skip, photo_hygiene_done/skip).
 * Pembentukan callback_data foto dilakukan di WizardStepHandlerTrait lewat
 * helper photoCallbackKey() — jangan bangun callback_data foto secara manual
 * di tempat lain agar tidak terjadi lagi mismatch seperti sebelumnya
 * (photo_documentation_done tidak pernah dikenali switch-case ini).
 *
 * CATATAN — Callback kolaborator (collab_*):
 * Setelah Step 7 selesai, wizard masuk ke Step 8a (kolaborator).
 * Dua callback tersedia: collab_skip (hapus collaborator_niks, maju ke konfirmasi)
 * dan collab_done (pertahankan collaborator_niks, maju ke konfirmasi).
 * Dua alur tambahan (Fitur A):
 *   - collab_candidate:<technician_id>       -> pilih satu dari keyboard kandidat nama
 *     yang ambigu (disimpan handleCollaboratorInput() ke collab_search_candidates)
 *   - collab_hierarchy:dept                  -> tampilkan daftar Department
 *   - collab_hierarchy:dept:<department_id>  -> Department dipilih, tampilkan daftar Section
 *   - collab_hierarchy:section:<section_key> -> Section dipilih, tampilkan daftar Teknisi
 *   - collab_hierarchy:pick:<technician_id>  -> Teknisi dipilih, tambahkan ke collaborator_niks
 *
 * CATATAN — Guard collab_hierarchy vs equipment:hierarchy:
 * Prefix 'collab_hierarchy:' dan 'equipment:hierarchy' tidak tumpang tindih karena
 * perbedaan karakter pemisah (titik dua vs garis bawah). Pemeriksaan
 * str_starts_with($callbackData, 'collab_hierarchy:') dan $callbackData === 'equipment:hierarchy'
 * dilakukan di routeCallback() dalam urutan yang tepat. Tidak ada risiko konflik.
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

        // Masuk ke hierarki ClarificationService (Sub-alur C).
        // Diperiksa sebelum collab_hierarchy agar tidak bentrok — prefix berbeda
        // ('equipment:hierarchy' vs 'collab_hierarchy:'), aman.
        if ($callbackData === 'equipment:hierarchy') {
            return $this->startClarificationHierarchy($chatId, $state);
        }

        // Tombol "Batal" dari dalam navigasi hierarki equipment
        if ($callbackData === 'hierarchy:cancel') {
            return $this->handleHierarchyCancel($chatId, $state);
        }

        // Callback pemilihan node FuncLoc dari FuncLoc picker (alur area/section)
        if (str_starts_with($callbackData, 'funcloc_pick:')) {
            return $this->handleFuncLocPick($chatId, $callbackData, $state);
        }

        // Konfirmasi FuncLoc tanpa turun ke level berikutnya
        if (str_starts_with($callbackData, 'funcloc_confirm:')) {
            return $this->handleFuncLocConfirm($chatId, $callbackData, $state);
        }

        // Callback pemilihan kandidat nama kolaborator yang ambigu (Step 8a).
        // Guard: hanya diproses saat step adalah STEP_COLLABORATOR agar tidak
        // keliru masuk sini dari step lain yang kebetulan mengirim callback serupa.
        if (str_starts_with($callbackData, 'collab_candidate:')) {
            if ($state['step'] !== self::STEP_COLLABORATOR) {
                return $this->errorResponse('Callback kolaborator tidak sesuai step aktif.');
            }
            return $this->handleCollabCandidateCallback($chatId, $callbackData, $state);
        }

        // Callback alur hierarki Dept -> Section -> Pilih Teknisi kolaborator (Step 8a).
        // Prefix 'collab_hierarchy:' tidak tumpang tindih dengan 'equipment:hierarchy'
        // karena prefix berbeda. Guard step STEP_COLLABORATOR ditambahkan sebagai
        // lapisan keamanan ekstra agar tidak bisa dipanggil dari step lain.
        if (str_starts_with($callbackData, 'collab_hierarchy:')) {
            if ($state['step'] !== self::STEP_COLLABORATOR) {
                return $this->errorResponse('Callback hierarki kolaborator tidak sesuai step aktif.');
            }
            return $this->handleCollabHierarchyCallback($chatId, $callbackData, $state);
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
     * Step 6-7 (foto), Step 8a (kolaborator), hingga Step 8 (simpan/batalkan).
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
                    'message'  => "Ketik durasi pekerjaan:\n_(contoh: `2 jam`, `30 menit`, `1 jam 30 menit`, `1:30`)_",
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
                return $this->advanceFromPhotoStep($chatId, $state, 'documentation');

            // Step 7 — foto hygiene selesai / skip
            case 'photo_hygiene_done':
            case 'photo_hygiene_skip':
                return $this->advanceFromPhotoStep($chatId, $state, 'hygiene');

            // Step 8a — kolaborator dilewati: hapus semua data kolaborator termasuk
            // state hierarki, lalu langsung ke Step 8 konfirmasi
            case 'collab_skip':
                unset($state['collaborator_niks'], $state['collaborator_names']);
                $state = $this->clearCollabHierarchyState($state);
                $state['step'] = self::STEP_CONFIRMATION;
                $this->saveState($chatId, $state);
                return $this->buildConfirmationSummary($state);

            // Step 8a — kolaborator selesai: pertahankan NIK dan nama yang ada,
            // bersihkan state hierarki, lalu ke Step 8 konfirmasi
            case 'collab_done':
                $state = $this->clearCollabHierarchyState($state);
                $state['step'] = self::STEP_CONFIRMATION;
                $this->saveState($chatId, $state);
                return $this->buildConfirmationSummary($state);

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
            // Alur area menggunakan FuncLoc picker hierarki (L1 -> L2 -> L3).
            // ClarificationService tidak dipakai untuk alur ini.
            return $this->startFuncLocPicker($chatId, $state);
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

    // =========================================================
    // STEP 8a — KOLABORATOR: KEYBOARD KANDIDAT NAMA
    // =========================================================

    /**
     * Proses pemilihan satu kandidat dari keyboard kandidat nama kolaborator
     * yang ambigu (disimpan sebelumnya oleh handleCollaboratorInput() ke
     * state collab_search_candidates). NIK dan nama teknisi terpilih ditambahkan
     * ke collaborator_niks dan collaborator_names, lalu prompt Step 8a ditampilkan ulang.
     *
     * @param  string $chatId       Chat ID Telegram
     * @param  string $callbackData Data callback collab_candidate:<technician_id>
     * @param  array  $state        State wizard saat ini
     * @return array  Respons
     */
    protected function handleCollabCandidateCallback(string $chatId, string $callbackData, array $state): array
    {
        $id = explode(':', $callbackData)[1] ?? null;

        if (!$id) {
            return $this->errorResponse('Pilihan kandidat kolaborator tidak valid.');
        }

        $technician = Technician::find((int) $id);
        if (!$technician) {
            return $this->errorResponse('Teknisi tidak ditemukan. Silakan pilih ulang atau ketik ulang nama.');
        }

        // Guard maks kolaborator: tolak jika sudah penuh
        $collabNiks = $state['collaborator_niks'] ?? [];
        if (count($collabNiks) >= self::MAX_COLLABORATORS) {
            $state['collab_search_candidates'] = [];
            $this->saveState($chatId, $state);
            return [
                'message'  => "Maksimum " . self::MAX_COLLABORATORS . " kolaborator per laporan sudah tercapai. " .
                    "Lanjut ke konfirmasi:",
                'keyboard' => [
                    ['text' => 'Selesai, Lanjut Konfirmasi', 'callback_data' => 'wizard:confirm:collab_done'],
                    ['text' => 'Batalkan Laporan',           'callback_data' => 'wizard:cancel_wizard'],
                ],
            ];
        }

        // Tambahkan NIK jika belum ada (hindari duplikat)
        if (!in_array($technician->nik, $collabNiks, true)) {
            $state['collaborator_niks'][]  = $technician->nik;
            $state['collaborator_names'][] = $technician->name;
        }

        // Bersihkan kandidat pencarian setelah salah satu dipilih
        $state['collab_search_candidates'] = [];
        $this->saveState($chatId, $state);

        return $this->buildCollaboratorPrompt($state);
    }

    // =========================================================
    // STEP 8a — KOLABORATOR: HIERARKI DEPT -> SECTION -> TEKNISI
    // =========================================================

    /**
     * Router alur hierarki kolaborator: collab_hierarchy:dept (daftar Department),
     * collab_hierarchy:dept:<id> (Department dipilih -> daftar Section),
     * collab_hierarchy:section:<key> (Section dipilih -> daftar Teknisi),
     * collab_hierarchy:pick:<id> (Teknisi dipilih -> kunci sebagai kolaborator).
     *
     * @param  string $chatId       Chat ID Telegram
     * @param  string $callbackData Data callback collab_hierarchy:<action>[:<value>]
     * @param  array  $state        State wizard saat ini
     * @return array  Respons
     */
    protected function handleCollabHierarchyCallback(string $chatId, string $callbackData, array $state): array
    {
        $parts  = explode(':', $callbackData);
        $action = $parts[1] ?? null;
        $value  = $parts[2] ?? null;

        $state['collab_using_hierarchy'] = true;

        // Langkah 1: tampilkan daftar Department
        if ($action === 'dept' && $value === null) {
            $this->saveState($chatId, $state);
            return $this->buildDeptKeyboard();
        }

        // Langkah 2: Department dipilih, tampilkan daftar Section
        if ($action === 'dept' && $value !== null) {
            $state['collab_hierarchy_dept_id'] = (int) $value;
            $state['collab_hierarchy_section'] = null;
            $this->saveState($chatId, $state);
            return $this->buildSectionKeyboard((int) $value);
        }

        // Langkah 3: Section dipilih, tampilkan daftar Teknisi
        if ($action === 'section' && $value !== null) {
            $deptId = $state['collab_hierarchy_dept_id'] ?? null;
            if (!$deptId) {
                return $this->errorResponse(
                    'Sesi hierarki tidak valid. Silakan mulai ulang dari tombol "Pilih dari Hierarki Dept/Section".'
                );
            }

            $state['collab_hierarchy_section'] = $value;
            $this->saveState($chatId, $state);
            return $this->buildCollabTechnicianKeyboard($chatId, (int) $deptId, $value);
        }

        // Langkah 4: Teknisi dipilih, kunci sebagai kolaborator
        if ($action === 'pick' && $value !== null) {
            return $this->handleCollabHierarchyPick($chatId, (int) $value, $state);
        }

        return $this->errorResponse('Pilihan hierarki kolaborator tidak dikenali.');
    }

    /**
     * Kunci teknisi terpilih dari hierarki Dept/Section sebagai kolaborator.
     * Menyimpan NIK dan nama ke state, membersihkan state hierarki setelah selesai,
     * lalu menampilkan ulang prompt Step 8a.
     *
     * @param  string $chatId       Chat ID Telegram
     * @param  int    $technicianId ID teknisi yang dipilih
     * @param  array  $state        State wizard saat ini
     * @return array  Respons
     */
    protected function handleCollabHierarchyPick(string $chatId, int $technicianId, array $state): array
    {
        $technician = Technician::find($technicianId);
        if (!$technician) {
            return $this->errorResponse('Teknisi tidak ditemukan. Silakan pilih ulang.');
        }

        // Guard maks kolaborator: tolak jika sudah penuh
        $collabNiks = $state['collaborator_niks'] ?? [];
        if (count($collabNiks) >= self::MAX_COLLABORATORS) {
            $state = $this->clearCollabHierarchyState($state);
            $this->saveState($chatId, $state);
            return [
                'message'  => "Maksimum " . self::MAX_COLLABORATORS . " kolaborator per laporan sudah tercapai. " .
                    "Lanjut ke konfirmasi:",
                'keyboard' => [
                    ['text' => 'Selesai, Lanjut Konfirmasi', 'callback_data' => 'wizard:confirm:collab_done'],
                    ['text' => 'Batalkan Laporan',           'callback_data' => 'wizard:cancel_wizard'],
                ],
            ];
        }

        // Tambahkan NIK dan nama jika belum ada (hindari duplikat)
        if (!in_array($technician->nik, $collabNiks, true)) {
            $state['collaborator_niks'][]  = $technician->nik;
            $state['collaborator_names'][] = $technician->name;
        }

        $state = $this->clearCollabHierarchyState($state);
        $this->saveState($chatId, $state);

        return $this->buildCollaboratorPrompt($state);
    }

    /**
     * Bangun keyboard daftar Department yang punya minimal satu teknisi aktif.
     * Hanya Department dengan teknisi aktif yang ditampilkan agar tidak ada
     * jalan buntu di hierarki.
     *
     * @return array Respons dengan message dan keyboard daftar Department
     */
    private function buildDeptKeyboard(): array
    {
        $deptIds = Technician::active()->whereNotNull('department_id')->distinct()->pluck('department_id');

        if ($deptIds->isEmpty()) {
            return [
                'message'  => "Tidak ada data departemen teknisi yang tersedia untuk hierarki kolaborator.",
                'keyboard' => [
                    ['text' => 'Batalkan Laporan', 'callback_data' => 'wizard:cancel_wizard'],
                ],
            ];
        }

        $depts = Department::whereIn('id', $deptIds)->orderBy('name')->get();

        $keyboard = [];
        foreach ($depts as $dept) {
            $keyboard[] = [
                'text'          => $dept->name,
                'callback_data' => 'collab_hierarchy:dept:' . $dept->id,
            ];
        }
        $keyboard[] = ['text' => 'Batalkan Laporan', 'callback_data' => 'wizard:cancel_wizard'];

        return [
            'message'  => "*Pilih Departemen*\n\nDepartemen tempat rekan kolaborator bekerja:",
            'keyboard' => $keyboard,
        ];
    }

    /**
     * Bangun keyboard daftar Section dalam Department terpilih.
     * Hanya Section dengan minimal satu teknisi aktif di Department tersebut
     * yang ditampilkan. Label section memakai Technician::SECTIONS jika tersedia.
     *
     * @param  int $deptId ID Department terpilih
     * @return array Respons dengan message dan keyboard daftar Section
     */
    private function buildSectionKeyboard(int $deptId): array
    {
        $sectionKeys = Technician::active()
            ->where('department_id', $deptId)
            ->whereNotNull('section')
            ->distinct()
            ->pluck('section');

        if ($sectionKeys->isEmpty()) {
            return [
                'message'  => "Tidak ada teknisi aktif di departemen ini.",
                'keyboard' => [
                    ['text' => 'Pilih Departemen Lain', 'callback_data' => 'collab_hierarchy:dept'],
                    ['text' => 'Batalkan Laporan',        'callback_data' => 'wizard:cancel_wizard'],
                ],
            ];
        }

        $sectionLabels = Technician::SECTIONS;

        $keyboard = [];
        foreach ($sectionKeys as $key) {
            $keyboard[] = [
                'text'          => $sectionLabels[$key] ?? ucfirst($key),
                'callback_data' => 'collab_hierarchy:section:' . $key,
            ];
        }
        $keyboard[] = ['text' => 'Pilih Departemen Lain', 'callback_data' => 'collab_hierarchy:dept'];
        $keyboard[] = ['text' => 'Batalkan Laporan',        'callback_data' => 'wizard:cancel_wizard'];

        return [
            'message'  => "*Pilih Section*\n\nSection tempat rekan kolaborator bekerja:",
            'keyboard' => $keyboard,
        ];
    }

    /**
     * Bangun keyboard daftar teknisi aktif dalam Department + Section terpilih.
     * Teknisi pengirim laporan dikecualikan agar tidak bisa memilih dirinya
     * sendiri sebagai kolaborator.
     *
     * @param  string $chatId     Chat ID Telegram (untuk exclude pengirim)
     * @param  int    $deptId     ID Department terpilih
     * @param  string $sectionKey Kunci Section terpilih (lihat Technician::SECTIONS)
     * @return array  Respons dengan message dan keyboard daftar teknisi
     */
    protected function buildCollabTechnicianKeyboard(string $chatId, int $deptId, string $sectionKey): array
    {
        $nikSendiri = $this->resolveSenderNik($chatId);

        $technicians = Technician::active()
            ->where('department_id', $deptId)
            ->where('section', $sectionKey)
            ->when($nikSendiri, fn ($q) => $q->where('nik', '!=', $nikSendiri))
            ->orderBy('name')
            ->get();

        if ($technicians->isEmpty()) {
            return [
                'message'  => "Tidak ada teknisi lain di section ini.",
                'keyboard' => [
                    ['text' => 'Pilih Section Lain', 'callback_data' => 'collab_hierarchy:dept:' . $deptId],
                    ['text' => 'Batalkan Laporan',    'callback_data' => 'wizard:cancel_wizard'],
                ],
            ];
        }

        $keyboard = [];
        foreach ($technicians as $technician) {
            $label = "{$technician->name} ({$technician->nik})";
            if (strlen($label) > 64) {
                $label = substr($label, 0, 61) . '...';
            }
            $keyboard[] = [
                'text'          => $label,
                'callback_data' => 'collab_hierarchy:pick:' . $technician->id,
            ];
        }
        $keyboard[] = ['text' => 'Batalkan Laporan', 'callback_data' => 'wizard:cancel_wizard'];

        return [
            'message'  => "*Pilih Teknisi*\n\nPilih rekan kolaborator:",
            'keyboard' => $keyboard,
        ];
    }

    /**
     * Ambil NIK teknisi pengirim laporan berdasarkan chat_id Telegram.
     * Dipakai untuk mengecualikan pengirim dari daftar kandidat kolaborator.
     *
     * @param  string $chatId Chat ID Telegram
     * @return string|null    NIK pengirim, atau null jika tidak ditemukan
     */
    private function resolveSenderNik(string $chatId): ?string
    {
        $technician = Technician::where('telegram_id', $chatId)->first();

        return $technician ? $technician->nik : null;
    }

    // =========================================================
    // HELPER STATE KOLABORATOR
    // =========================================================

    /**
     * Bersihkan key state yang terkait sesi hierarki kolaborator.
     * Dipanggil saat alur hierarki selesai (pick berhasil) atau saat
     * kolaborator dilewati (collab_skip / collab_done).
     * Tidak menghapus collaborator_niks dan collaborator_names.
     *
     * @param  array $state State wizard saat ini
     * @return array State wizard setelah key hierarki dibersihkan
     */
    private function clearCollabHierarchyState(array $state): array
    {
        $state['collab_using_hierarchy']   = false;
        $state['collab_hierarchy_dept_id'] = null;
        $state['collab_hierarchy_section'] = null;
        $state['collab_search_candidates'] = [];

        return $state;
    }
}
