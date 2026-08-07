<?php

namespace App\Jobs;

use App\Models\ImportLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * SyncGoogleSheetsJob
 *
 * Menarik data Condition Monitoring langsung dari spreadsheet online
 * (Google Sheets) lalu menjadwalkan import ke database. Spreadsheet
 * di-download sebagai file .xlsx via URL export publik, kemudian diproses
 * lewat jalur queued job yang SAMA dengan upload manual
 * (ProcessCmExcelImport / ProcessCmFindingsImport) — termasuk balance
 * check, cached value formula Status CM, dan rebuild monitoring bulanan.
 *
 * Sumber spreadsheet dikonfigurasi di config('cm.google_sheets.*')
 * (GOOGLE_SHEETS_DATA_CM_URL & GOOGLE_SHEETS_FINDING_CM_URL di .env).
 * Link harus berstatus "Anyone with the link can view" agar bisa
 * di-download tanpa Google Cloud API.
 *
 * Dipanggil oleh:
 *   - php artisan cm:sync-sheets (sinkron, output ke terminal)
 *   - Tombol "Sync Google Sheets" di halaman Overview CM
 */
class SyncGoogleSheetsJob implements ShouldQueue
{
    use Dispatchable, Queueable, InteractsWithQueue, SerializesModels;

    /**
     * Jalankan sync sebagai queued job (dipakai saat tombol web men-dispatch).
     *
     * @return void
     */
    public function handle(): void
    {
        $results = self::runSync();

        Log::info('Sync Google Sheets selesai', $results);
    }

    /**
     * Download & jadwalkan import semua spreadsheet yang dikonfigurasi.
     * Dapat dipanggil sinkron (command/controller) maupun via queue.
     *
     * @return array Hasil per sumber: ['data_cm' => ['status' => ..., 'message' => ...], ...]
     */
    public static function runSync(): array
    {
        $sources = [
            'data_cm' => [
                'url'            => config('cm.google_sheets.data_cm_url'),
                'tipe_import'    => 'cm_excel',
                'import_job'     => ProcessCmExcelImport::class,
                'required_sheets' => ['Data AppSheet', 'Status CM'],
            ],
            'finding_cm' => [
                'url'            => config('cm.google_sheets.finding_cm_url'),
                'tipe_import'    => 'cm_findings',
                'import_job'     => ProcessCmFindingsImport::class,
                'required_sheets' => ['Finding CM'],
            ],
        ];

        $results = [];

        foreach ($sources as $key => $source) {
            if (empty($source['url'])) {
                $results[$key] = [
                    'status'  => 'skipped',
                    'message' => 'URL belum dikonfigurasi di .env',
                ];
                continue;
            }

            try {
                $path = self::downloadSpreadsheet($source['url'], $key);
                self::validateSheets($path, $source['required_sheets']);

                $importLog = ImportLog::create([
                    'nama_file'       => 'google-sync-' . $key . '-' . now()->format('Ymd-His') . '.xlsx',
                    'tipe_import'     => $source['tipe_import'],
                    'total_baris'     => 0,
                    'insert_baru'     => 0,
                    'update_existing' => 0,
                    'gagal'           => 0,
                    'unregistered'    => 0,
                    'status'          => 'processing',
                    'uploaded_by'     => self::systemUserId(),
                ]);

                $jobClass = $source['import_job'];
                dispatch(new $jobClass($path, $importLog->id, self::systemUserId()));

                $results[$key] = [
                    'status'  => 'ok',
                    'message' => 'Download OK, import dijadwalkan (log #' . $importLog->id . ')',
                ];
            } catch (\Exception $e) {
                Log::error("Sync Google Sheets {$key} gagal: " . $e->getMessage());

                $results[$key] = [
                    'status'  => 'error',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Download spreadsheet Google Sheets sebagai file xlsx ke storage.
     *
     * @param  string $url  Link spreadsheet (format edit/gid apa pun)
     * @param  string $key  Kunci sumber (data_cm|finding_cm) untuk nama file
     * @return string       Path absolut file xlsx hasil download
     *
     * @throws \RuntimeException Jika URL invalid, download gagal, atau file bukan xlsx
     */
    protected static function downloadSpreadsheet(string $url, string $key): string
    {
        if (!preg_match('/\/spreadsheets\/d\/([a-zA-Z0-9_-]+)/', $url, $m)) {
            throw new \RuntimeException('URL spreadsheet Google tidak valid (ID spreadsheet tidak ditemukan).');
        }

        $spreadsheetId = $m[1];
        $exportUrl     = "https://docs.google.com/spreadsheets/d/{$spreadsheetId}/export?format=xlsx";

        $response = Http::timeout(180)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
            ->get($exportUrl);

        if (!$response->successful()) {
            throw new \RuntimeException(
                'Gagal download spreadsheet (HTTP ' . $response->status() . '). ' .
                'Pastikan link di-set "Anyone with the link can view".'
            );
        }

        $content = $response->body();

        if (strlen($content) < 1000 || !str_starts_with($content, "PK\x03\x04")) {
            throw new \RuntimeException(
                'Hasil download bukan file xlsx valid (spreadsheet kemungkinan di-restrict).'
            );
        }

        $filename = 'cm-imports/google-sync-' . $key . '-' . now()->format('Ymd-His') . '-' . uniqid() . '.xlsx';
        Storage::disk('local')->put($filename, $content);

        return Storage::disk('local')->path($filename);
    }

    /**
     * Validasi nama sheet di dalam file xlsx sesuai kebutuhan import.
     * Jika sheet wajib tidak ada, file dihapus dan error dilempar.
     *
     * @param  string $path           Path file xlsx
     * @param  array  $requiredSheets Daftar nama sheet yang wajib ada
     * @return void
     *
     * @throws \RuntimeException Jika file rusak atau sheet wajib hilang
     */
    protected static function validateSheets(string $path, array $requiredSheets): void
    {
        $zip = new \ZipArchive();

        if ($zip->open($path) !== true) {
            throw new \RuntimeException('File xlsx hasil download tidak bisa dibaca.');
        }

        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $zip->close();

        $foundSheets = [];
        if ($workbookXml) {
            $xml = simplexml_load_string($workbookXml);
            foreach ($xml->sheets->sheet as $sheet) {
                $attrs = $sheet->attributes();
                $foundSheets[] = (string) $attrs['name'];
            }
        }

        $missing = array_diff($requiredSheets, $foundSheets);

        if (!empty($missing)) {
            @unlink($path);
            throw new \RuntimeException('Sheet wajib tidak ditemukan: ' . implode(', ', $missing));
        }
    }

    /**
     * Cari ID user sistem (admin pertama) untuk dicatat sebagai uploaded_by.
     * Karena sync dipicu tanpa login, dipakai user admin pertama sebagai
     * penanda "sync otomatis".
     *
     * @return int ID user sistem
     */
    protected static function systemUserId(): int
    {
        $userId = \App\Models\User::where('role', 'admin')
            ->orderBy('id')
            ->value('id');

        if (!$userId) {
            $userId = \App\Models\User::orderBy('id')->value('id');
        }

        if (!$userId) {
            throw new \RuntimeException('Tidak ada user di database untuk dicatat sebagai pelaku sync.');
        }

        return (int) $userId;
    }
}
