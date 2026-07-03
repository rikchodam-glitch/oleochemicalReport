<?php

namespace App\Services\Telegram\Traits;

use App\Models\Report;
use Illuminate\Support\Facades\Log;

/**
 * WizardPhotoAddonTrait
 *
 * Menangani penambahan foto ke laporan yang sudah tersimpan di DB
 * (fitur "tambah foto post-submit via kode laporan"):
 *   - extractReportCode()         : Ekstrak kode RPT-... dari teks caption
 *   - addPhotoToReport()          : Tambah foto ke laporan tersimpan via report_code
 *   - detectPhotoTypeFromCaption(): Deteksi tipe foto (dokumentasi/hygiene) dari caption
 *
 * Alur penggunaan:
 *   1. PollTelegramUpdates menerima foto dengan caption
 *   2. Panggil extractReportCode(caption) — jika ada kode RPT, ini foto post-submit
 *   3. Panggil addPhotoToReport(reportCode, localPath, caption)
 *      (localPath sudah diproses PhotoStorageService->store() sebelum masuk ke sini)
 *
 * Trait ini bergantung pada method berikut dari kelas pemakai:
 *   - isValidLocalPhotoPath(mixed $value): bool
 *   - errorResponse(string $message): array
 */
trait WizardPhotoAddonTrait
{
    /**
     * Ekstrak kode laporan RPT-YYYYMMDD-XXXX dari teks caption foto.
     * Dipanggil dari PollTelegramUpdates untuk memutuskan apakah foto
     * masuk ke wizard aktif atau ke laporan lama yang sudah tersimpan.
     *
     * @param  string      $text Teks caption yang akan dicek
     * @return string|null       Kode laporan jika ditemukan, null jika tidak ada
     */
    public function extractReportCode(string $text): ?string
    {
        if (preg_match('/\bRPT-\d{8}-\d{4}\b/i', $text, $m)) {
            return strtoupper($m[0]);
        }

        return null;
    }

    /**
     * Tambahkan foto ke laporan yang sudah tersimpan di DB via report_code.
     * Dipanggil dari PollTelegramUpdates saat foto memiliki caption berisi RPT-...
     *
     * Parameter $fileId di sini harus sudah berupa path lokal hasil
     * PhotoStorageService->store() — bukan file_id Telegram mentah.
     * Path lokal selalu mengandung "/" (format: reports/YYYY/MM/DD/{chat_id}/{file}.jpg).
     *
     * Tipe foto (dokumentasi/hygiene) ditentukan dari caption —
     * bukan tanggung jawab PollTelegramUpdates.
     *
     * @param  string $reportCode Kode laporan RPT-YYYYMMDD-XXXX
     * @param  string $fileId     Path lokal foto hasil PhotoStorageService->store()
     * @param  string $caption    Caption asli foto — dipakai untuk deteksi tipe foto
     * @return array  Respons sukses atau error
     */
    public function addPhotoToReport(string $reportCode, string $fileId, string $caption = ''): array
    {
        $report = Report::where('report_code', $reportCode)->first();

        if (!$report) {
            return $this->errorResponse("Laporan dengan kode *{$reportCode}* tidak ditemukan.");
        }

        // Tolak nilai yang bukan path lokal — artinya PhotoStorageService->store()
        // belum dipanggil oleh caller sebelum memanggil method ini.
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

        // Bangun pesan balasan yang informatif: sebutkan total foto setelah penambahan
        // dan ingatkan format caption jika teknisi ingin menambah foto lagi.
        $successMessage  = "Foto {$field} berhasil ditambahkan ke laporan `{$reportCode}`.\n";
        $successMessage .= "Total foto {$field} sekarang: {$count}.\n\n";
        $successMessage .= "_Untuk menambah foto lagi ke laporan ini, kirim foto dengan caption:_\n";

        if ($type === 'hygiene') {
            $successMessage .= "`{$reportCode} hygiene`";
        } else {
            $successMessage .= "`{$reportCode}`";
        }

        return [
            'message'  => $successMessage,
            'keyboard' => [],
        ];
    }

    /**
     * Tentukan tipe foto dari caption.
     * Mengembalikan 'hygiene' jika caption menyebut kata "hygiene" (case-insensitive),
     * selain itu dianggap 'documentation' (default).
     *
     * @param  string $caption Caption foto dari Telegram
     * @return string 'hygiene' atau 'documentation'
     */
    protected function detectPhotoTypeFromCaption(string $caption): string
    {
        return stripos($caption, 'hygiene') !== false ? 'hygiene' : 'documentation';
    }
}
