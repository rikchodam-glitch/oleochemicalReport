<?php

namespace App\Services\Telegram\Traits;

use App\Models\Report;
use Illuminate\Support\Facades\Log;

/**
 * WizardReportSaverTrait
 *
 * Menangani penyimpanan laporan ke database dan helper terkait foto:
 *   - saveReport()                  : Simpan laporan ke DB setelah konfirmasi Step 8
 *   - buildConfirmationSummary()    : Bangun pesan ringkasan Step 8
 *   - generateReportCode()          : Generate kode RPT-YYYYMMDD-XXXX unik per hari
 *   - isValidLocalPhotoPath()       : Validasi apakah nilai adalah path lokal foto
 *   - filterValidLocalPhotoPaths()  : Filter array foto, buang yang bukan path lokal
 *
 * Trait ini bergantung pada method berikut dari kelas pemakai:
 *   - equipmentLabel(array $state): string
 *   - formatDuration(int $minutes): string
 *   - destroyWizard(string $chatId): void
 *   - errorResponse(string $message): array
 */
trait WizardReportSaverTrait
{
    // =========================================================
    // STEP 8 — KONFIRMASI & SIMPAN
    // =========================================================

    /**
     * Bangun pesan ringkasan konfirmasi untuk Step 8.
     * Menampilkan semua data yang akan disimpan agar teknisi bisa verifikasi.
     *
     * @param  array $state State wizard
     * @return array Respons dengan pesan ringkasan dan keyboard Ya/Batalkan
     */
    protected function buildConfirmationSummary(array $state): array
    {
        $equipmentLabel = $this->equipmentLabel($state);
        $duration       = $this->formatDuration($state['work_duration_minutes'] ?? 0);
        $rootCause      = $state['root_cause'] ?? '-';
        $photoDocCount  = count($state['photo_documentation'] ?? []);
        $photoHygCount  = count($state['photo_hygiene_clearance'] ?? []);

        $msg  = "*Step 8/8* — Konfirmasi Laporan\n\n";
        $msg .= "Periksa ringkasan berikut sebelum disimpan:\n\n";
        $msg .= "*Equipment* : {$equipmentLabel}\n";
        $msg .= "*Durasi*    : {$duration}\n";
        $msg .= "*Root Cause*: {$rootCause}\n";
        $msg .= "*Foto Dok*  : {$photoDocCount} foto\n";
        $msg .= "*Foto HC*   : {$photoHygCount} foto\n\n";
        $msg .= "Simpan laporan ini?";

        return [
            'message'  => $msg,
            'keyboard' => [
                ['text' => 'Ya, Simpan', 'callback_data' => 'wizard:confirm:save_report'],
                ['text' => 'Batalkan',   'callback_data' => 'wizard:confirm:cancel_report'],
            ],
        ];
    }

    /**
     * Simpan laporan ke DB (pendekatan "Create at End").
     * Hanya dipanggil setelah teknisi mengonfirmasi di Step 8.
     * Foto yang bukan path lokal (belum diproses PhotoStorageService) dibuang.
     *
     * @param  string $chatId Chat ID Telegram
     * @param  array  $state  State wizard saat ini
     * @return array  Respons sukses atau error
     */
    protected function saveReport(string $chatId, array $state): array
    {
        try {
            $technician = \App\Models\Technician::where('telegram_id', $chatId)->first();
            if (!$technician) {
                return $this->errorResponse(
                    "Akun teknisi tidak ditemukan untuk chat ini.\n" .
                    "Hubungi admin untuk mendaftarkan Telegram ID kamu."
                );
            }

            $reportType = 'general';
            if (!empty($state['is_area_work'])) {
                $reportType = 'area_work';
            } elseif (!empty($state['equipment_id'])) {
                $reportType = 'equipment_repair';
            }

            $reportCode = $this->generateReportCode();

            // Filter foto: hanya path lokal hasil PhotoStorageService->store() yang masuk DB.
            // Path lokal selalu mengandung tanda "/" (format reports/YYYY/MM/DD/{chat_id}/{file}.jpg).
            // File ID Telegram mentah tanpa "/" dibuang dan dicatat ke log.
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

            $msg  = "*Laporan Berhasil Disimpan!*\n\n";
            $msg .= "Kode Laporan: `{$reportCode}`\n";
            $msg .= "Equipment: {$equipmentLabel}\n";
            $msg .= "Durasi: {$duration}\n\n";
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
    // HELPER KODE LAPORAN & VALIDASI FOTO
    // =========================================================

    /**
     * Generate kode laporan RPT-YYYYMMDD-XXXX.
     * XXXX adalah sequence 4-digit unik per hari, dimulai dari 0001.
     *
     * @return string Kode laporan baru
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
     * Cek apakah sebuah nilai foto adalah path lokal hasil PhotoStorageService->store().
     * Path lokal selalu mengandung tanda "/" (format: reports/YYYY/MM/DD/{chat_id}/{filename}.jpg).
     * File ID Telegram asli tidak pernah mengandung "/".
     *
     * @param  mixed $value Nilai yang akan dicek
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
     * @param  array $photos Array path atau file_id foto
     * @return array Array yang hanya berisi path lokal valid
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
}
