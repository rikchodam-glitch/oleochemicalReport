<?php

namespace App\Services\Telegram\Traits;

/**
 * WizardStepHandlerTrait
 *
 * Menangani logika handler untuk setiap step wizard laporan:
 *   - buildWorkDurationPrompt()   : Bangun prompt Step 4 (input durasi)
 *   - handleDurationInput()       : Proses teks durasi yang diketik teknisi
 *   - advanceToWorkDuration()     : Transisi ke Step 4 dari step sebelumnya
 *   - buildRootCausePrompt()      : Bangun prompt Step 5 (input root cause)
 *   - handleRootCauseInput()      : Proses teks root cause dari teknisi
 *   - buildPhotoDocumentationPrompt() : Bangun prompt Step 6 (foto dokumentasi)
 *   - buildPhotoHygienePrompt()   : Bangun prompt Step 7 (foto hygiene clearance)
 *   - handlePhotoCommand()        : Proses perintah teks di step foto
 *   - addPhotoToStep()            : Tambah file ID foto ke state wizard
 *   - advanceFromPhotoStep()      : Transisi keluar dari step foto
 *   - handleConfirmation()        : Proses teks konfirmasi di Step 8
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
 *   - buildConfirmationSummary(array $state): array
 *   - saveReport(string $chatId, array $state): array
 */
trait WizardStepHandlerTrait
{
    // =========================================================
    // STEP 4 — WAKTU PENGERJAAN
    // =========================================================

    /**
     * Transisi ke Step 4 dan tampilkan prompt durasi.
     *
     * @param  string $chatId Chat ID Telegram
     * @param  array  $state  State wizard saat ini
     * @return array  Respons
     */
    protected function advanceToWorkDuration(string $chatId, array $state): array
    {
        $state['step'] = self::STEP_WORK_DURATION;
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
            $keyboard  = [
                ['text' => "Ya, {$formatted}", 'callback_data' => 'wizard:confirm:duration_ok'],
                ['text' => 'Ubah Durasi',      'callback_data' => 'wizard:confirm:duration_change'],
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
                'keyboard' => [
                    ['text' => 'Gunakan ini', 'callback_data' => 'wizard:confirm:rootcause_ok'],
                    ['text' => 'Ubah',        'callback_data' => 'wizard:confirm:rootcause_change'],
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

    /**
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
                    "Tambah foto lagi, atau lanjutkan?",
                'keyboard' => [
                    ['text' => 'Cukup, Lanjutkan',  'callback_data' => 'wizard:confirm:photo_doc_done'],
                    ['text' => 'Tambah Foto Lagi',   'callback_data' => 'wizard:confirm:photo_doc_more'],
                    ['text' => 'Skip (Tanpa Foto)',   'callback_data' => 'wizard:confirm:photo_doc_skip'],
                ],
            ];
        }

        if ($currentPhotos > 0) {
            return [
                'message'  => "*Step 6/8* — Foto Dokumentasi\n\n" .
                    "{$currentPhotos} foto sudah diterima.\n" .
                    "Kirim foto lagi, atau lanjutkan:",
                'keyboard' => [
                    ['text' => 'Cukup, Lanjutkan', 'callback_data' => 'wizard:confirm:photo_doc_done'],
                    ['text' => 'Skip Sisa Foto',    'callback_data' => 'wizard:confirm:photo_doc_skip'],
                ],
            ];
        }

        return [
            'message'  => "*Step 6/8* — Foto Dokumentasi\n\n" .
                "Kirim foto dokumentasi pekerjaan (opsional, bisa lebih dari 1).\n" .
                "Atau skip jika tidak ada:",
            'keyboard' => [
                ['text' => 'Skip (Tanpa Foto)', 'callback_data' => 'wizard:confirm:photo_doc_skip'],
            ],
        ];
    }

    // =========================================================
    // STEP 7 — FOTO HYGIENE CLEARANCE
    // =========================================================

    /**
     * Bangun pesan prompt Step 7 (foto hygiene clearance).
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
                    "Kirim foto lagi, atau lanjutkan ke konfirmasi:",
                'keyboard' => [
                    ['text' => 'Cukup, Lanjutkan', 'callback_data' => 'wizard:confirm:photo_hygiene_done'],
                    ['text' => 'Skip Sisa',         'callback_data' => 'wizard:confirm:photo_hygiene_skip'],
                ],
            ];
        }

        return [
            'message'  => "*Step 7/8* — Foto Hygiene Clearance\n\n" .
                "Kirim foto hygiene clearance (opsional).\n" .
                "Atau skip untuk langsung ke konfirmasi:",
            'keyboard' => [
                ['text' => 'Skip (Tanpa Foto)', 'callback_data' => 'wizard:confirm:photo_hygiene_skip'],
            ],
        ];
    }

    // =========================================================
    // HANDLER FOTO (STEP 6 & 7)
    // =========================================================

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

        return [
            'message'  => "*Step {$stepNum}/8* — {$currentCount} foto diterima.\n" .
                "Kirim foto berikutnya, atau ketik *selesai* untuk lanjut.",
            'keyboard' => [
                ['text' => 'Selesai, Lanjutkan', 'callback_data' => 'wizard:confirm:photo_' . $photoStep . '_done'],
                ['text' => 'Skip',                'callback_data' => 'wizard:confirm:photo_' . $photoStep . '_skip'],
            ],
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

        return [
            'message'  => "Foto {$count} diterima.\n" .
                "Kirim foto berikutnya, atau tekan *Selesai* untuk lanjut.",
            'keyboard' => [
                ['text' => 'Selesai, Lanjutkan', 'callback_data' => 'wizard:confirm:photo_' . $photoStep . '_done'],
                ['text' => 'Skip Sisa',           'callback_data' => 'wizard:confirm:photo_' . $photoStep . '_skip'],
            ],
        ];
    }

    /**
     * Transisi keluar dari step foto ke step berikutnya.
     * Dari Step 6 (dokumentasi) → Step 7 (hygiene).
     * Dari Step 7 (hygiene)     → Step 8 (konfirmasi).
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
            $state['step'] = self::STEP_PHOTO_HYGIENE;
            $this->saveState($chatId, $state);
            return $this->buildPhotoHygienePrompt($state);
        }

        // Dari hygiene → Step 8 konfirmasi
        $state['step'] = self::STEP_CONFIRMATION;
        $this->saveState($chatId, $state);
        return $this->buildConfirmationSummary($state);
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
