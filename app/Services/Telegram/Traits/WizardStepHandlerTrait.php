<?php

namespace App\Services\Telegram\Traits;

use App\Models\Technician;

/**
 * WizardStepHandlerTrait
 *
 * Menangani logika handler untuk setiap step wizard laporan:
 *   - buildWorkClassificationPrompt(): Bangun prompt step paling awal (pilih klasifikasi pekerjaan)
 *   - buildWorkDurationPrompt()     : Bangun prompt Step 4 (input durasi)
 *   - handleDurationInput()         : Proses teks durasi yang diketik teknisi
 *   - advanceToWorkDuration()       : Transisi ke Step 4 dari step sebelumnya
 *   - buildRootCausePrompt()        : Bangun prompt Step 5 (input root cause)
 *   - handleRootCauseInput()        : Proses teks root cause dari teknisi
 *   - buildPhotoDocumentationPrompt(): Bangun prompt Step 6 (foto dokumentasi)
 *   - buildPhotoHygienePrompt()     : Bangun prompt Step 7 (foto hygiene clearance)
 *   - handlePhotoCommand()          : Proses perintah teks di step foto
 *   - addPhotoToStep()              : Tambah file ID foto ke state wizard
 *   - advanceFromPhotoStep()        : Transisi keluar dari step foto
 *   - buildCollaboratorPrompt()     : Bangun prompt Step 8a (input NIK/nama kolaborator)
 *   - handleCollaboratorInput()     : Proses teks NIK/nama kolaborator dari teknisi
 *   - buildCollabCandidateKeyboard(): Bangun keyboard pilihan kandidat hasil pencarian nama (termasuk navigationButtons)
 *   - handleConfirmation()          : Proses teks konfirmasi di Step 8
 *   - photoCallbackKey()            : Peta $photoStep ke kunci callback_data pendek
 *   - photoDoneButtonLabel()        : Label tombol "selesai" yang sesuai step foto
 *
 * Trait ini bergantung pada method berikut dari kelas pemakai:
 *   - parseDurationToMinutes(string $text): ?int
 *   - formatDuration(int $minutes): string
 *   - equipmentLabel(array $state): string
 *   - saveState(string $chatId, array $state): void
 *   - destroyWizard(string $chatId): void
 *   - buildRootCausePrompt(array $state): array  -- dipakai oleh handleDurationInput
 *   - buildPhotoDocumentationPrompt(array $state): array
 *   - buildPhotoHygienePrompt(array $state): array
 *   - buildCollaboratorPrompt(array $state): array
 *   - buildConfirmationSummary(array $state): array
 *   - saveReport(string $chatId, array $state): array
 *   - pushStepHistory(array $state, string $currentStep): array
 *   - navigationButtons(array $state, bool $includeBack = true): array
 *
 * Trait ini juga bergantung pada properti berikut dari kelas pemakai:
 *   - $this->collaboratorSearch (App\Services\Telegram\CollaboratorSearchService)
 *     dipakai oleh handleCollaboratorInput() untuk pencarian NIK/nama kolaborator.
 *
 * CATATAN — Navigasi Kembali/Batal (Step 4, 5, 6, 7, 8a):
 * pushStepHistory() WAJIB dipanggil dengan step YANG SEDANG DITINGGALKAN
 * sesaat sebelum $state['step'] diubah ke step berikutnya (Step 4 -> 5,
 * Step 5 -> 6, Step 6 -> 7, Step 7 -> 8a, Step 8a -> 8).
 * Push Step 8a dilakukan di WizardCallbackHandlerTrait::handleConfirmationCallback()
 * pada case collab_skip dan collab_done — bukan di sini — karena transisi ke
 * STEP_CONFIRMATION hanya terjadi via callback, bukan via input teks.
 * Hanya dipanggil saat wizard benar-benar MAJU ke step baru — bukan saat
 * hanya menampilkan ulang prompt di step yang sama (mis. aksi "Ubah Durasi"/
 * "Ubah" pada root cause, atau saat menerima foto tambahan di step yang sama).
 * Setiap keyboard yang dibangun di Step 4-8a wajib memakai navigationButtons()
 * agar tombol Kembali/Batal konsisten.
 *
 * PENTING — Konsistensi callback_data foto:
 * Semua callback_data untuk step foto WAJIB menggunakan format pendek:
 *   wizard:confirm:photo_doc_done      wizard:confirm:photo_doc_skip
 *   wizard:confirm:photo_hygiene_done  wizard:confirm:photo_hygiene_skip
 * BUKAN 'photo_documentation_done' (bentuk panjang $photoStep). Switch-case
 * di WizardCallbackHandlerTrait::handleConfirmationCallback() hanya mengenali
 * bentuk pendek ini. Gunakan photoCallbackKey() setiap kali membangun
 * callback_data foto agar tidak terulang lagi bug ini.
 *
 * PERBAIKAN SESI 6 (uji menyeluruh & rapikan konsistensi):
 * advanceToWorkDuration() sebelumnya TIDAK memanggil pushStepHistory()
 * sebelum memindahkan $state['step'] ke STEP_WORK_DURATION, sehingga step
 * equipment (Step 1/2/3) tidak pernah tercatat di riwayat dan tombol Kembali
 * tidak pernah muncul di Step 4 saat teknisi pertama kali sampai di sana.
 * Karena advanceToWorkDuration() adalah gerbang tunggal dari semua jalur
 * penguncian equipment (lock langsung, finalisasi ClarificationService,
 * FuncLoc picker), perbaikan push di method ini otomatis berlaku untuk
 * seluruh jalur tersebut — lihat docblock advanceToWorkDuration() untuk detail.
 */
trait WizardStepHandlerTrait
{
    // Batas maksimum kolaborator per laporan
    const MAX_COLLABORATORS = 5;

    // =========================================================
    // STEP AWAL — KLASIFIKASI PEKERJAAN
    // =========================================================

    /**
     * Bangun pesan prompt step paling awal wizard: pilih klasifikasi
     * pekerjaan (ZPM 1/3/6/5/7) sebelum pencarian equipment
     * dijalankan. Callback_data mengikuti pola 'classification:<kode>',
     * ditangani WizardCallbackHandlerTrait::handleWorkClassificationCallback().
     *
     * @param  array $state State wizard saat ini
     * @return array Respons dengan message dan keyboard 5 pilihan klasifikasi
     */
    protected function buildWorkClassificationPrompt(array $state): array
    {
        $keyboard = [
            ['text' => 'ZPM 1 - Corrective Maintenance', 'callback_data' => 'classification:zpm1'],
            ['text' => 'ZPM 3 - Preventive Maintenance', 'callback_data' => 'classification:zpm3'],
            ['text' => 'ZPM 6 - Planned Maintenance',    'callback_data' => 'classification:zpm6'],
            ['text' => 'ZPM 5 - Fabrikasi',              'callback_data' => 'classification:zpm5'],
            ['text' => 'ZPM 7 - Sipil',                  'callback_data' => 'classification:zpm7'],
        ];

        foreach ($this->navigationButtons($state) as $navButton) {
            $keyboard[] = $navButton;
        }

        return [
            'message'  => "Laporan diterima.\n\nSebelum mencari equipment, pilih klasifikasi pekerjaan untuk laporan ini:",
            'keyboard' => $keyboard,
        ];
    }

    /**
     * Bangun pesan prompt pilihan jenis pekerjaan EI vs Mekanik.
     * Ditampilkan setelah teknisi memilih klasifikasi ZPM 1 atau ZPM 6.
     * Callback_data mengikuti pola 'jenis_pekerjaan:<kode>', ditangani
     * WizardCallbackHandlerTrait::handleJenisPekerjaanCallback().
     *
     * @param  array $state State wizard saat ini
     * @return array Respons dengan message dan keyboard EI/Mekanik
     */
    protected function buildJenisPekerjaanPrompt(array $state): array
    {
        $keyboard = [
            ['text' => 'EI', 'callback_data' => 'jenis_pekerjaan:EI'],
            ['text' => 'Mekanik', 'callback_data' => 'jenis_pekerjaan:Mekanik'],
        ];

        foreach ($this->navigationButtons($state) as $navButton) {
            $keyboard[] = $navButton;
        }

        return [
            'message'  => "Pilih jenis pekerjaan untuk laporan ini:",
            'keyboard' => $keyboard,
        ];
    }

    // =========================================================
    // STEP 4 — WAKTU PENGERJAAN
    // =========================================================

    /**
     * Transisi ke Step 4 dan tampilkan prompt durasi.
     *
     * PERBAIKAN SESI 6: sebelumnya method ini langsung mengubah $state['step']
     * ke STEP_WORK_DURATION tanpa memanggil pushStepHistory() lebih dulu,
     * sehingga step equipment (STEP_EQUIPMENT_SEARCH/STEP_EQUIPMENT_CLARIFY)
     * tidak pernah tercatat di riwayat dan tombol Kembali tidak pernah muncul
     * saat teknisi pertama kali sampai di Step 4 — padahal ini titik gerbang
     * tunggal dari SEMUA jalur penguncian equipment (lock langsung,
     * finalisasi hierarki ClarificationService, dan FuncLoc picker untuk
     * pekerjaan area), jadi push di sini otomatis berlaku untuk semua jalur.
     *
     * @param  string $chatId Chat ID Telegram
     * @param  array  $state  State wizard saat ini
     * @return array  Respons
     */
    protected function advanceToWorkDuration(string $chatId, array $state): array
    {
        $state          = $this->pushStepHistory($state, $state['step']);
        $state['step']  = self::STEP_WORK_DURATION;
        $this->saveState($chatId, $state);

        return $this->buildWorkDurationPrompt($state, autoDetected: !empty($state['work_duration_minutes']));
    }

    /**
     * Bangun pesan prompt Step 4 (durasi pengerjaan).
     * Jika AI sudah mendeteksi durasi, tampilkan keyboard konfirmasi.
     *
     * @param  array $state        State wizard
     * @param  bool  $autoDetected Apakah durasi sudah terdeteksi oleh AI
     * @return array Respons
     */
    protected function buildWorkDurationPrompt(array $state, bool $autoDetected = false): array
    {
        $equipmentLabel = $this->equipmentLabel($state);

        if ($autoDetected && !empty($state['work_duration_minutes'])) {
            $formatted = $this->formatDuration($state['work_duration_minutes']);
            $keyboard  = array_merge(
                [
                    ['text' => "Ya, {$formatted}", 'callback_data' => 'wizard:confirm:duration_ok'],
                    ['text' => 'Ubah Durasi',      'callback_data' => 'wizard:confirm:duration_change'],
                ],
                $this->navigationButtons($state)
            );
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
                "Ketik rentang waktu, contoh:\n" .
                "`08:00 sampai 09:00`, `08:00-10:00`\n" .
                "Atau rentang santai: `Jam 9 - Jam 10`, `pukul 09 sd 10.30`, `9 sampai 10`",
            'keyboard' => $this->navigationButtons($state),
        ];
    }

    /**
     * Proses teks durasi yang diketik teknisi di Step 4.
     *
     * @param  string $chatId Chat ID Telegram
     * @param  string $text   Input teks dari teknisi
     * @param  array  $state  State wizard saat ini
     * @return array  Respons
     */
    protected function handleDurationInput(string $chatId, string $text, array $state): array
    {
        $range = $this->parseWorkTimeRange($text);

        if ($range === null) {
            return [
                'message'  => "Rentang waktu tidak dikenali. Coba format lain:\n" .
                    "`08:00 sampai 09:00`, `08:00-10:00`\n" .
                    "Atau rentang santai: `Jam 9 - Jam 10`, `pukul 09 sd 10.30`, `9 sampai 10`",
                'keyboard' => $this->navigationButtons($state),
            ];
        }

        $state                          = $this->pushStepHistory($state, self::STEP_WORK_DURATION);
        $state['work_duration_minutes'] = $range['minutes'];
        $state['start_time']            = $range['start'];
        $state['end_time']              = $range['end'];
        $state['step']                  = self::STEP_ROOT_CAUSE;
        $this->saveState($chatId, $state);

        return $this->buildRootCausePrompt($state);
    }

    // =========================================================
    // STEP 5 — ROOT CAUSE
    // =========================================================

    /**
     * Bangun pesan prompt Step 5 (root cause).
     * Jika AI sudah mendeteksi root cause, tampilkan keyboard konfirmasi.
     *
     * @param  array $state State wizard
     * @return array Respons
     */
    protected function buildRootCausePrompt(array $state): array
    {
        $equipmentLabel = $this->equipmentLabel($state);
        $duration       = $this->formatDuration($state['work_duration_minutes'] ?? 0);

        if (!empty($state['root_cause'])) {
            $existing = $state['root_cause'];
            return [
                'message'  => "*Step 5/8* — Root Cause\n\n" .
                    "Root cause yang terdeteksi dari laporan:\n_{$existing}_\n\n" .
                    "Gunakan root cause ini atau ketik yang baru:",
                'keyboard' => array_merge(
                    [
                        ['text' => 'Gunakan ini', 'callback_data' => 'wizard:confirm:rootcause_ok'],
                        ['text' => 'Ubah',        'callback_data' => 'wizard:confirm:rootcause_change'],
                    ],
                    $this->navigationButtons($state)
                ),
            ];
        }

        return [
            'message'  => "*Step 5/8* — Root Cause\n\n" .
                "Equipment: *{$equipmentLabel}*\n" .
                "Durasi: *{$duration}*\n\n" .
                "Ketik *root cause* (penyebab kerusakan/pekerjaan):",
            'keyboard' => $this->navigationButtons($state),
        ];
    }

    /**
     * Proses teks root cause yang diketik teknisi di Step 5.
     *
     * @param  string $chatId Chat ID Telegram
     * @param  string $text   Input teks dari teknisi
     * @param  array  $state  State wizard saat ini
     * @return array  Respons
     */
    protected function handleRootCauseInput(string $chatId, string $text, array $state): array
    {
        $trimmed = trim($text);

        if (mb_strlen($trimmed) < self::ROOT_CAUSE_MIN_LENGTH) {
            return [
                'message'  => "Root cause terlalu pendek (minimal " . self::ROOT_CAUSE_MIN_LENGTH . " karakter).\n" .
                    "Deskripsikan penyebab kerusakan/pekerjaan:",
                'keyboard' => $this->navigationButtons($state),
            ];
        }

        $state                = $this->pushStepHistory($state, self::STEP_ROOT_CAUSE);
        $state['root_cause']  = $trimmed;
        $state['step']        = self::STEP_PHOTO_DOCUMENTATION;
        $this->saveState($chatId, $state);

        return $this->buildPhotoDocumentationPrompt($state);
    }

    // =========================================================
    // STEP 6 — FOTO DOKUMENTASI
    // =========================================================

    /**
     * CATATAN — Navigasi Kembali di step foto (Step 6 & 7):
     * Tombol Kembali TIDAK menghapus foto yang sudah terupload ke
     * 'photo_documentation'/'photo_hygiene_clearance'. buildPhotoDocumentationPrompt()
     * dan buildPhotoHygienePrompt() hanya membaca ulang array foto yang ada di
     * state saat dipanggil oleh WizardCallbackHandlerTrait::rebuildPromptForStep(),
     * sehingga teknisi bisa mundur dan maju lagi tanpa kehilangan foto yang
     * sudah dikirim. Hanya "Batalkan Laporan" yang mereset seluruh state.
     *
     * Bangun pesan prompt Step 6 (foto dokumentasi).
     * Menyesuaikan pesan berdasarkan jumlah foto yang sudah masuk
     * dan apakah ada foto awal dari Step 1.
     *
     * @param  array $state State wizard
     * @return array Respons
     */
    protected function buildPhotoDocumentationPrompt(array $state): array
    {
        $hasInitialPhoto = !empty($state['initial_photo_file_id']);
        $currentPhotos   = count($state['photo_documentation'] ?? []);

        if ($hasInitialPhoto && $currentPhotos === 0) {
            return [
                'message'  => "*Step 6/8* — Foto Dokumentasi\n\n" .
                    "Sudah ada 1 foto yang dikirim bersama laporan awal.\n" .
                    "Kirim foto tambahan jika ada, atau lanjutkan:",
                'keyboard' => array_merge(
                    [
                        ['text' => 'Cukup, Lanjutkan', 'callback_data' => 'wizard:confirm:photo_doc_done'],
                        ['text' => 'Skip (Tanpa Foto)',   'callback_data' => 'wizard:confirm:photo_doc_skip'],
                    ],
                    $this->navigationButtons($state)
                ),
            ];
        }

        if ($currentPhotos > 0) {
            return [
                'message'  => "*Step 6/8* — Foto Dokumentasi\n\n" .
                    "{$currentPhotos} foto sudah diterima.\n" .
                    "Kirim foto lagi, atau lanjutkan:",
                'keyboard' => array_merge(
                    [
                        ['text' => 'Cukup, Lanjutkan', 'callback_data' => 'wizard:confirm:photo_doc_done'],
                        ['text' => 'Skip Sisa Foto',    'callback_data' => 'wizard:confirm:photo_doc_skip'],
                    ],
                    $this->navigationButtons($state)
                ),
            ];
        }

        return [
            'message'  => "*Step 6/8* — Foto Dokumentasi\n\n" .
                "Kirim foto dokumentasi pekerjaan (opsional, bisa lebih dari 1).\n" .
                "Atau skip jika tidak ada:",
            'keyboard' => array_merge(
                [
                    ['text' => 'Skip (Tanpa Foto)', 'callback_data' => 'wizard:confirm:photo_doc_skip'],
                ],
                $this->navigationButtons($state)
            ),
        ];
    }

    // =========================================================
    // STEP 7 — FOTO HYGIENE CLEARANCE
    // =========================================================

    /**
     * Bangun pesan prompt Step 7 (foto hygiene clearance).
     * Setelah step ini selesai, wizard menuju Step 8a (Kolaborator) terlebih dahulu
     * sebelum masuk ke Step 8 (konfirmasi & simpan).
     *
     * @param  array $state State wizard
     * @return array Respons
     */
    protected function buildPhotoHygienePrompt(array $state): array
    {
        $currentPhotos = count($state['photo_hygiene_clearance'] ?? []);

        if ($currentPhotos > 0) {
            return [
                'message'  => "*Step 7/8* — Foto Hygiene Clearance\n\n" .
                    "{$currentPhotos} foto sudah diterima.\n" .
                    "Kirim foto lagi, atau lanjutkan ke step berikutnya:",
                'keyboard' => array_merge(
                    [
                        ['text' => $this->photoDoneButtonLabel('hygiene'), 'callback_data' => 'wizard:confirm:photo_hygiene_done'],
                        ['text' => 'Skip Sisa',                            'callback_data' => 'wizard:confirm:photo_hygiene_skip'],
                    ],
                    $this->navigationButtons($state)
                ),
            ];
        }

        return [
            'message'  => "*Step 7/8* — Foto Hygiene Clearance\n\n" .
                "Kirim foto hygiene clearance (opsional).\n" .
                "Atau skip untuk lanjut ke step berikutnya:",
            'keyboard' => array_merge(
                [
                    ['text' => 'Skip (Tanpa Foto)', 'callback_data' => 'wizard:confirm:photo_hygiene_skip'],
                ],
                $this->navigationButtons($state)
            ),
        ];
    }

    // =========================================================
    // HANDLER FOTO (STEP 6 & 7)
    // =========================================================

    /**
     * Peta $photoStep ('documentation'/'hygiene') ke kunci pendek yang dipakai
     * di callback_data ('doc'/'hygiene'). Wajib dipakai setiap kali membangun
     * callback_data foto agar konsisten dengan switch-case di
     * WizardCallbackHandlerTrait::handleConfirmationCallback().
     *
     * @param  string $photoStep Tipe step: 'documentation' atau 'hygiene'
     * @return string            Kunci pendek untuk callback_data
     */
    private function photoCallbackKey(string $photoStep): string
    {
        return $photoStep === 'documentation' ? 'doc' : 'hygiene';
    }

    /**
     * Label tombol "selesai" untuk step foto (dokumentasi maupun hygiene).
     * Parameter $photoStep dipertahankan (bukan dihapus) agar tanda tangan
     * method tetap stabil untuk seluruh titik pemanggilan dan agar label
     * bisa dibedakan lagi di kemudian hari tanpa perlu mengubah pemanggil.
     *
     * RAPIKAN SESI 6: sebelumnya method ini memakai ternary yang mengembalikan
     * string sama persis di kedua cabang ('documentation' dan 'hygiene') —
     * disederhanakan menjadi konstanta tunggal.
     *
     * @param  string $photoStep Tipe step: 'documentation' atau 'hygiene'
     * @return string            Label tombol
     */
    private function photoDoneButtonLabel(string $photoStep): string
    {
        return 'Selesai, Lanjutkan';
    }

    /**
     * Proses perintah teks di step foto ("selesai", "skip", dll).
     * Dipanggil dari handleTextInput ketika step adalah foto.
     *
     * @param  string $chatId    Chat ID Telegram
     * @param  string $text      Input teks dari teknisi
     * @param  array  $state     State wizard saat ini
     * @param  string $photoStep Tipe step: 'documentation' atau 'hygiene'
     * @return array  Respons
     */
    protected function handlePhotoCommand(string $chatId, string $text, array $state, string $photoStep): array
    {
        $text = strtolower(trim($text));

        if (in_array($text, ['selesai', 'done', 'lanjut', 'skip', 'next'])) {
            return $this->advanceFromPhotoStep($chatId, $state, $photoStep);
        }

        $currentCount = count($state['photo_' . ($photoStep === 'documentation' ? 'documentation' : 'hygiene_clearance')] ?? []);
        $stepNum      = $photoStep === 'documentation' ? '6' : '7';
        $callbackKey  = $this->photoCallbackKey($photoStep);

        return [
            'message'  => "*Step {$stepNum}/8* — {$currentCount} foto diterima.\n" .
                "Kirim foto berikutnya, atau ketik *selesai* untuk lanjut.",
            'keyboard' => array_merge(
                [
                    ['text' => $this->photoDoneButtonLabel($photoStep), 'callback_data' => 'wizard:confirm:photo_' . $callbackKey . '_done'],
                    ['text' => 'Skip',                                  'callback_data' => 'wizard:confirm:photo_' . $callbackKey . '_skip'],
                ],
                $this->navigationButtons($state)
            ),
        ];
    }

    /**
     * Tambah file ID foto ke state wizard (dipanggil dari handlePhotoInput).
     *
     * @param  string $chatId    Chat ID Telegram
     * @param  string $fileId    File ID foto dari Telegram
     * @param  array  $state     State wizard saat ini
     * @param  string $photoStep Tipe step: 'documentation' atau 'hygiene'
     * @return array  Respons
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

        $callbackKey = $this->photoCallbackKey($photoStep);

        return [
            'message'  => "Foto {$count} diterima.\n" .
                "Kirim foto berikutnya, atau tekan *Selesai* untuk lanjut.",
            'keyboard' => array_merge(
                [
                    ['text' => $this->photoDoneButtonLabel($photoStep), 'callback_data' => 'wizard:confirm:photo_' . $callbackKey . '_done'],
                    ['text' => 'Skip Sisa',                             'callback_data' => 'wizard:confirm:photo_' . $callbackKey . '_skip'],
                ],
                $this->navigationButtons($state)
            ),
        ];
    }

    /**
     * Transisi keluar dari step foto ke step berikutnya.
     * Dari Step 6 (dokumentasi) → Step 7 (hygiene).
     * Dari Step 7 (hygiene)     → Step 8a (kolaborator).
     * Jika ada foto awal dari Step 1, di-prepend ke photo_documentation.
     *
     * @param  string $chatId    Chat ID Telegram
     * @param  array  $state     State wizard saat ini
     * @param  string $photoStep Tipe step yang sedang diselesaikan
     * @return array  Respons
     */
    protected function advanceFromPhotoStep(string $chatId, array $state, string $photoStep): array
    {
        if ($photoStep === 'documentation' && !empty($state['initial_photo_file_id'])) {
            if (empty($state['photo_documentation'])) {
                $state['photo_documentation'] = [];
            }
            // Prepend foto awal jika belum masuk ke array
            if (!in_array($state['initial_photo_file_id'], $state['photo_documentation'])) {
                array_unshift($state['photo_documentation'], $state['initial_photo_file_id']);
            }
        }

        if ($photoStep === 'documentation') {
            $state          = $this->pushStepHistory($state, self::STEP_PHOTO_DOCUMENTATION);
            $state['step']  = self::STEP_PHOTO_HYGIENE;
            $this->saveState($chatId, $state);
            return $this->buildPhotoHygienePrompt($state);
        }

        // Dari hygiene → Step 8a kolaborator (opsional sebelum konfirmasi)
        $state          = $this->pushStepHistory($state, self::STEP_PHOTO_HYGIENE);
        $state['step']  = self::STEP_COLLABORATOR;
        $this->saveState($chatId, $state);
        return $this->buildCollaboratorPrompt($state);
    }

    // =========================================================
    // STEP 8a — KOLABORATOR (opsional)
    // =========================================================

    /**
     * Bangun pesan prompt Step 8a (input NIK/nama kolaborator).
     * Step ini opsional — teknisi bisa langsung skip ke konfirmasi.
     * Daftar kolaborator yang sudah diinput ditampilkan dengan nama jika tersedia.
     *
     * @param  array $state State wizard
     * @return array Respons
     */
    protected function buildCollaboratorPrompt(array $state): array
    {
        $collabNiks  = $state['collaborator_niks'] ?? [];
        $collabNames = $state['collaborator_names'] ?? [];

        if (!empty($collabNiks)) {
            // Bangun label "Nama (NIK)" atau hanya "NIK" jika nama tidak tersedia
            $labels = [];
            foreach ($collabNiks as $index => $nik) {
                $nama     = $collabNames[$index] ?? '';
                $labels[] = $nama !== '' ? "{$nama} ({$nik})" : $nik;
            }
            $labelList = implode(', ', $labels);

            return [
                'message'  => "*Step 8a/8* — Kolaborator\n\n" .
                    "Kolaborator yang sudah ditambahkan: *{$labelList}*\n\n" .
                    "Tambah kolaborator lain (ketik NIK atau nama), atau lanjut ke konfirmasi:",
                'keyboard' => array_merge(
                    [
                        ['text' => 'Selesai, Lanjut Konfirmasi',      'callback_data' => 'wizard:confirm:collab_done'],
                        ['text' => 'Lewati (Tanpa Kolaborator)',       'callback_data' => 'wizard:confirm:collab_skip'],
                        ['text' => 'Pilih dari Hierarki Dept/Section', 'callback_data' => 'collab_hierarchy:dept'],
                    ],
                    $this->navigationButtons($state)
                ),
            ];
        }

        return [
            'message'  => "*Step 8a/8* — Kolaborator (Opsional)\n\n" .
                "Apakah ada rekan yang ikut mengerjakan pekerjaan ini?\n" .
                "Ketik NIK atau nama rekan (bisa lebih dari satu, pisah dengan koma).\n\n" .
                "Contoh: `12345, 67890` atau `Ahmad, Budi`\n\n" .
                "Atau lewati jika tidak ada kolaborator:",
            'keyboard' => array_merge(
                [
                    ['text' => 'Lewati (Tanpa Kolaborator)',       'callback_data' => 'wizard:confirm:collab_skip'],
                    ['text' => 'Pilih dari Hierarki Dept/Section', 'callback_data' => 'collab_hierarchy:dept'],
                ],
                $this->navigationButtons($state)
            ),
        ];
    }

    /**
     * Proses teks NIK atau nama kolaborator yang diketik teknisi di Step 8a.
     * Input dipisah dengan koma, titik koma, atau baris baru. Setiap token
     * dicari via CollaboratorSearchService (NIK exact match dulu, lalu nama
     * fuzzy match). Teknisi pengirim laporan sendiri otomatis dikecualikan.
     *
     * Guard maks 5 kolaborator: jika state sudah mencapai batas MAX_COLLABORATORS,
     * input baru ditolak dan teknisi diminta lanjut ke konfirmasi.
     *
     * Jika sebuah token menghasilkan lebih dari satu kandidat nama, method
     * ini berhenti memproses token berikutnya dan langsung menampilkan
     * keyboard pilihan kandidat (Telegram hanya bisa menampilkan satu
     * keyboard per respons). Token yang belum diproses bisa diketik ulang
     * setelah teknisi menuntaskan pilihan kandidat yang sedang tampil.
     *
     * @param  string $chatId Chat ID Telegram
     * @param  string $text   Input NIK/nama dari teknisi
     * @param  array  $state  State wizard saat ini
     * @return array  Respons
     */
    protected function handleCollaboratorInput(string $chatId, string $text, array $state): array
    {
        // Guard: tolak input baru jika sudah mencapai batas maksimum kolaborator
        $collabSudahAda = $state['collaborator_niks'] ?? [];
        if (count($collabSudahAda) >= self::MAX_COLLABORATORS) {
            return [
                'message'  => "Maksimum " . self::MAX_COLLABORATORS . " kolaborator per laporan sudah tercapai.\n\n" .
                    "Kolaborator saat ini: *" . implode(', ', $collabSudahAda) . "*\n\n" .
                    "Lanjut ke konfirmasi atau batalkan laporan:",
                'keyboard' => array_merge(
                    [
                        ['text' => 'Selesai, Lanjut Konfirmasi', 'callback_data' => 'wizard:confirm:collab_done'],
                    ],
                    $this->navigationButtons($state)
                ),
            ];
        }

        // Pisah input berdasarkan koma, titik koma, atau baris baru
        $rawQueries = preg_split('/[\s,;]+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);

        if (empty($rawQueries)) {
            return $this->buildCollaboratorPrompt($state);
        }

        $ditemukan  = [];
        $namaDitemukan = [];
        $tidakAda   = [];
        $nikSendiri = null;

        // Ambil NIK teknisi pengirim untuk mencegah self-assign sebagai kolaborator
        $teknisiPengirim = Technician::where('telegram_id', $chatId)->first();
        if ($teknisiPengirim) {
            $nikSendiri = $teknisiPengirim->nik;
        }

        // Hitung slot yang masih tersedia agar tidak melewati batas maksimum
        $slotTersisa = self::MAX_COLLABORATORS - count($collabSudahAda);

        foreach ($rawQueries as $query) {
            // Hentikan loop jika slot sudah habis
            if (count($ditemukan) >= $slotTersisa) {
                break;
            }

            $query = trim($query);

            // Abaikan NIK/nama yang sama persis dengan NIK pengirim sendiri
            if ($nikSendiri && $query === $nikSendiri) {
                continue;
            }

            $hasil = $this->collaboratorSearch->search($query, $nikSendiri);

            if ($hasil['count'] === 1 && $hasil['exact']) {
                $nik  = $hasil['exact']->nik;
                $nama = $hasil['exact']->name;
                if (!in_array($nik, $ditemukan, true)) {
                    $ditemukan[]   = $nik;
                    $namaDitemukan[] = $nama;
                }
                continue;
            }

            if ($hasil['count'] > 1) {
                // Kandidat ambigu: simpan ke state, tampilkan keyboard pilihan.
                // Kolaborator yang sudah ditemukan dari token sebelumnya tetap disimpan.
                $collabSebelumnya             = $state['collaborator_niks'] ?? [];
                $namaSebelumnya               = $state['collaborator_names'] ?? [];
                $state['collaborator_niks']   = array_values(array_unique(array_merge($collabSebelumnya, $ditemukan)));
                $state['collaborator_names']  = array_merge($namaSebelumnya, $namaDitemukan);

                $state['collab_search_candidates'] = array_map(fn ($t) => [
                    'id'   => $t->id,
                    'nik'  => $t->nik,
                    'name' => $t->name,
                ], $hasil['candidates']);
                $this->saveState($chatId, $state);

                return $this->buildCollabCandidateKeyboard($query, $hasil['candidates'], $state);
            }

            // Tidak ada kandidat sama sekali untuk token ini
            $tidakAda[] = $query;
        }

        // Gabungkan dengan NIK dan nama kolaborator yang sudah ada di state (hindari duplikat NIK)
        $collabSebelumnya   = $state['collaborator_niks'] ?? [];
        $namaSebelumnya     = $state['collaborator_names'] ?? [];
        $gabungan           = [];
        $gabunganNama       = [];

        // Pertahankan entri lama yang belum duplikat
        foreach ($collabSebelumnya as $i => $nik) {
            $gabungan[]     = $nik;
            $gabunganNama[] = $namaSebelumnya[$i] ?? '';
        }

        // Tambahkan entri baru yang tidak duplikat
        foreach ($ditemukan as $i => $nik) {
            if (!in_array($nik, $gabungan, true)) {
                $gabungan[]     = $nik;
                $gabunganNama[] = $namaDitemukan[$i] ?? '';
            }
        }

        $state['collaborator_niks']  = array_values($gabungan);
        $state['collaborator_names'] = array_values($gabunganNama);
        $this->saveState($chatId, $state);

        // Susun pesan umpan balik berdasarkan hasil pencarian
        $msg = '';

        if (!empty($ditemukan)) {
            $msg .= count($ditemukan) . ' kolaborator ditambahkan: *' . implode(', ', $ditemukan) . "*\n";
        }

        if (!empty($tidakAda)) {
            $msg .= 'Tidak ditemukan: ' . implode(', ', $tidakAda) . "\n";
        }

        // Peringatan jika ada query yang terpotong karena slot penuh
        $sisaQuery = count($rawQueries) - (count($ditemukan) + count($tidakAda));
        if ($sisaQuery > 0 && count($state['collaborator_niks']) >= self::MAX_COLLABORATORS) {
            $msg .= "_Batas " . self::MAX_COLLABORATORS . " kolaborator tercapai. Input berikutnya diabaikan._\n";
        }

        if (empty($state['collaborator_niks'])) {
            // Semua token tidak valid dan tidak ada yang tersimpan
            return [
                'message'  => $msg . "\nTidak ada kolaborator valid yang ditemukan. " .
                    "Coba ketik ulang NIK/nama, pilih dari hierarki, atau lewati:",
                'keyboard' => array_merge(
                    [
                        ['text' => 'Lewati (Tanpa Kolaborator)',       'callback_data' => 'wizard:confirm:collab_skip'],
                        ['text' => 'Pilih dari Hierarki Dept/Section', 'callback_data' => 'collab_hierarchy:dept'],
                    ],
                    $this->navigationButtons($state)
                ),
            ];
        }

        // Bangun label "Nama (NIK)" untuk tampilan total kolaborator
        $collabNiks  = $state['collaborator_niks'];
        $collabNames = $state['collaborator_names'] ?? [];
        $labels      = [];
        foreach ($collabNiks as $i => $nik) {
            $nama     = $collabNames[$i] ?? '';
            $labels[] = $nama !== '' ? "{$nama} ({$nik})" : $nik;
        }
        $semuaLabel = implode(', ', $labels);

        return [
            'message'  => $msg . "\nTotal kolaborator sekarang: *{$semuaLabel}*\n\n" .
                "Tambah kolaborator lain, atau lanjut ke konfirmasi:",
            'keyboard' => array_merge(
                [
                    ['text' => 'Selesai, Lanjut Konfirmasi', 'callback_data' => 'wizard:confirm:collab_done'],
                    ['text' => 'Lewati (Tanpa Kolaborator)',  'callback_data' => 'wizard:confirm:collab_skip'],
                ],
                $this->navigationButtons($state)
            ),
        ];
    }

    /**
     * Bangun keyboard pilihan kandidat hasil pencarian nama kolaborator yang ambigu.
     * Setiap tombol memakai callback_data 'collab_candidate:<technician_id>'.
     *
     * @param  string $query      Query nama yang diketik teknisi (ditampilkan di pesan)
     * @param  array  $candidates Daftar objek Technician kandidat (maks CollaboratorSearchService::MAX_CANDIDATES)
     * @param  array  $state      State wizard saat ini (untuk navigationButtons)
     * @return array  Respons dengan message dan keyboard pilihan kandidat
     */
    protected function buildCollabCandidateKeyboard(string $query, array $candidates, array $state): array
    {
        $keyboard = [];
        foreach ($candidates as $candidate) {
            $label = "{$candidate->name} ({$candidate->nik})";
            if (strlen($label) > 64) {
                $label = substr($label, 0, 61) . '...';
            }
            $keyboard[] = [
                'text'          => $label,
                'callback_data' => 'collab_candidate:' . $candidate->id,
            ];
        }

        // Tombol navigasi: Kembali (jika ada riwayat) + Batalkan Laporan
        foreach ($this->navigationButtons($state) as $navButton) {
            $keyboard[] = $navButton;
        }

        return [
            'message'  => "Ditemukan beberapa teknisi dengan nama mirip *\"{$query}\"*. Pilih salah satu:",
            'keyboard' => $keyboard,
        ];
    }

    // =========================================================
    // STEP 8 — KONFIRMASI (handler teks)
    // =========================================================

    /**
     * Proses teks konfirmasi ("ya"/"tidak") yang diketik teknisi di Step 8.
     *
     * @param  string $chatId Chat ID Telegram
     * @param  string $text   Input teks dari teknisi
     * @param  array  $state  State wizard saat ini
     * @return array  Respons
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

        // Input tidak dikenali — tampilkan ulang ringkasan konfirmasi
        return $this->buildConfirmationSummary($state);
    }
}
