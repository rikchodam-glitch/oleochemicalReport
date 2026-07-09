<?php

namespace App\Services\Telegram\Traits;

use App\Models\Technician;

/**
 * WizardStepHandlerTrait
 *
 * Menangani logika handler untuk setiap step wizard laporan:
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
 *   - buildCollaboratorPrompt()     : Bangun prompt Step 8a (input NIK kolaborator)
 *   - handleCollaboratorInput()     : Proses teks NIK kolaborator dari teknisi
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
 *
 * PENTING — Konsistensi callback_data foto:
 * Semua callback_data untuk step foto WAJIB menggunakan format pendek:
 *   wizard:confirm:photo_doc_done      wizard:confirm:photo_doc_skip
 *   wizard:confirm:photo_hygiene_done  wizard:confirm:photo_hygiene_skip
 * BUKAN 'photo_documentation_done' (bentuk panjang $photoStep). Switch-case
 * di WizardCallbackHandlerTrait::handleConfirmationCallback() hanya mengenali
 * bentuk pendek ini. Gunakan photoCallbackKey() setiap kali membangun
 * callback_data foto agar tidak terulang lagi bug ini.
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
                "Ketik durasi, contoh:\n" .
                "`2 jam`, `30 menit`, `1.5 jam`, `1:30`, `1h30m`\n" .
                "Atau rentang waktu: `08:00 sampai 09:00`, `08:00-10:00`",
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
                    "`2 jam`, `30 menit`, `1 jam 30 menit`, `1:30`, `1h30m`\n" .
                    "Atau rentang waktu: `08:00 sampai 09:00`, `08:00-10:00`",
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
                    "Kirim foto tambahan jika ada, atau lanjutkan:",
                'keyboard' => [
                    ['text' => 'Cukup, Lanjutkan', 'callback_data' => 'wizard:confirm:photo_doc_done'],
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
                'keyboard' => [
                    ['text' => $this->photoDoneButtonLabel('hygiene'), 'callback_data' => 'wizard:confirm:photo_hygiene_done'],
                    ['text' => 'Skip Sisa',                            'callback_data' => 'wizard:confirm:photo_hygiene_skip'],
                ],
            ];
        }

        return [
            'message'  => "*Step 7/8* — Foto Hygiene Clearance\n\n" .
                "Kirim foto hygiene clearance (opsional).\n" .
                "Atau skip untuk lanjut ke step berikutnya:",
            'keyboard' => [
                ['text' => 'Skip (Tanpa Foto)', 'callback_data' => 'wizard:confirm:photo_hygiene_skip'],
            ],
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
     * Label tombol "selesai" yang sesuai dengan step foto.
     * Step hygiene sekarang menuju ke step kolaborator (bukan langsung konfirmasi),
     * sehingga labelnya diperbarui menjadi "Lanjutkan".
     *
     * @param  string $photoStep Tipe step: 'documentation' atau 'hygiene'
     * @return string            Label tombol
     */
    private function photoDoneButtonLabel(string $photoStep): string
    {
        return $photoStep === 'hygiene' ? 'Selesai, Lanjutkan' : 'Selesai, Lanjutkan';
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
            'keyboard' => [
                ['text' => $this->photoDoneButtonLabel($photoStep), 'callback_data' => 'wizard:confirm:photo_' . $callbackKey . '_done'],
                ['text' => 'Skip',                                  'callback_data' => 'wizard:confirm:photo_' . $callbackKey . '_skip'],
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

        $callbackKey = $this->photoCallbackKey($photoStep);

        return [
            'message'  => "Foto {$count} diterima.\n" .
                "Kirim foto berikutnya, atau tekan *Selesai* untuk lanjut.",
            'keyboard' => [
                ['text' => $this->photoDoneButtonLabel($photoStep), 'callback_data' => 'wizard:confirm:photo_' . $callbackKey . '_done'],
                ['text' => 'Skip Sisa',                             'callback_data' => 'wizard:confirm:photo_' . $callbackKey . '_skip'],
            ],
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
            $state['step'] = self::STEP_PHOTO_HYGIENE;
            $this->saveState($chatId, $state);
            return $this->buildPhotoHygienePrompt($state);
        }

        // Dari hygiene → Step 8a kolaborator (opsional sebelum konfirmasi)
        $state['step'] = self::STEP_COLLABORATOR;
        $this->saveState($chatId, $state);
        return $this->buildCollaboratorPrompt($state);
    }

    // =========================================================
    // STEP 8a — KOLABORATOR (opsional)
    // =========================================================

    /**
     * Bangun pesan prompt Step 8a (input NIK kolaborator).
     * Step ini opsional — teknisi bisa langsung skip ke konfirmasi.
     * Daftar NIK yang sudah diinput ditampilkan jika ada.
     *
     * @param  array $state State wizard
     * @return array Respons
     */
    protected function buildCollaboratorPrompt(array $state): array
    {
        $collabNiks = $state['collaborator_niks'] ?? [];

        if (!empty($collabNiks)) {
            $nikList = implode(', ', $collabNiks);
            return [
                'message'  => "*Step 8a/8* — Kolaborator\n\n" .
                    "NIK kolaborator yang sudah ditambahkan: *{$nikList}*\n\n" .
                    "Tambah NIK lain (ketik NIK), atau lanjut ke konfirmasi:",
                'keyboard' => [
                    ['text' => 'Selesai, Lanjut Konfirmasi', 'callback_data' => 'wizard:confirm:collab_done'],
                    ['text' => 'Lewati (Tanpa Kolaborator)',  'callback_data' => 'wizard:confirm:collab_skip'],
                ],
            ];
        }

        return [
            'message'  => "*Step 8a/8* — Kolaborator (Opsional)\n\n" .
                "Apakah ada rekan yang ikut mengerjakan pekerjaan ini?\n" .
                "Ketik NIK rekan (bisa lebih dari satu, pisah dengan koma).\n\n" .
                "Contoh: `12345, 67890`\n\n" .
                "Atau lewati jika tidak ada kolaborator:",
            'keyboard' => [
                ['text' => 'Lewati (Tanpa Kolaborator)', 'callback_data' => 'wizard:confirm:collab_skip'],
            ],
        ];
    }

    /**
     * Proses teks NIK kolaborator yang diketik teknisi di Step 8a.
     * NIK dipisah dengan koma, titik koma, atau baris baru, lalu divalidasi
     * ke tabel technicians. NIK yang tidak ditemukan dilaporkan ke teknisi.
     *
     * @param  string $chatId Chat ID Telegram
     * @param  string $text   Input NIK dari teknisi
     * @param  array  $state  State wizard saat ini
     * @return array  Respons
     */
    protected function handleCollaboratorInput(string $chatId, string $text, array $state): array
    {
        // Pisah input berdasarkan koma, titik koma, atau baris baru
        $rawNiks = preg_split('/[\s,;]+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);

        if (empty($rawNiks)) {
            return $this->buildCollaboratorPrompt($state);
        }

        $ditemukan  = [];
        $tidakAda   = [];
        $nikSendiri = null;

        // Ambil NIK teknisi pengirim untuk mencegah self-assign sebagai kolaborator
        $teknisiPengirim = Technician::where('telegram_id', $chatId)->first();
        if ($teknisiPengirim) {
            $nikSendiri = $teknisiPengirim->nik;
        }

        foreach ($rawNiks as $nik) {
            $nik = trim($nik);

            // Abaikan NIK teknisi pengirim sendiri
            if ($nikSendiri && $nik === $nikSendiri) {
                continue;
            }

            $teknisi = Technician::where('nik', $nik)->first();
            if ($teknisi) {
                $ditemukan[] = $nik;
            } else {
                $tidakAda[] = $nik;
            }
        }

        // Gabungkan dengan NIK kolaborator yang sudah ada di state (hindari duplikat)
        $collabSebelumnya       = $state['collaborator_niks'] ?? [];
        $state['collaborator_niks'] = array_values(array_unique(array_merge($collabSebelumnya, $ditemukan)));
        $this->saveState($chatId, $state);

        // Susun pesan umpan balik berdasarkan hasil validasi
        $msg = '';

        if (!empty($ditemukan)) {
            $msg .= count($ditemukan) . ' kolaborator ditambahkan: *' . implode(', ', $ditemukan) . "*\n";
        }

        if (!empty($tidakAda)) {
            $msg .= 'NIK tidak ditemukan: ' . implode(', ', $tidakAda) . "\n";
        }

        if (empty($state['collaborator_niks'])) {
            // Semua NIK tidak valid dan tidak ada yang tersimpan
            return [
                'message'  => $msg . "\nTidak ada NIK valid yang ditemukan. Coba ketik ulang NIK, atau lewati:",
                'keyboard' => [
                    ['text' => 'Lewati (Tanpa Kolaborator)', 'callback_data' => 'wizard:confirm:collab_skip'],
                ],
            ];
        }

        $semuaNik = implode(', ', $state['collaborator_niks']);
        return [
            'message'  => $msg . "\nTotal kolaborator sekarang: *{$semuaNik}*\n\n" .
                "Tambah NIK lain, atau lanjut ke konfirmasi:",
            'keyboard' => [
                ['text' => 'Selesai, Lanjut Konfirmasi', 'callback_data' => 'wizard:confirm:collab_done'],
                ['text' => 'Lewati (Tanpa Kolaborator)',  'callback_data' => 'wizard:confirm:collab_skip'],
            ],
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
