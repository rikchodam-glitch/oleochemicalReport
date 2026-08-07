<?php

namespace App\Console\Commands;

use App\Jobs\SyncGoogleSheetsJob;
use Illuminate\Console\Command;

/**
 * SyncGoogleSheetsCommand
 *
 * Menarik data Condition Monitoring langsung dari spreadsheet online
 * (Google Sheets) lalu menjadwalkan import ke database — tanpa perlu
 * upload file manual.
 *
 * Penggunaan:
 *   php artisan cm:sync-sheets
 */
class SyncGoogleSheetsCommand extends Command
{
    protected $signature = 'cm:sync-sheets';

    protected $description = 'Download spreadsheet Google Sheets (Data CM & Finding CM) lalu jadwalkan import';

    /**
     * Entry point command — jalankan sync dan tampilkan hasilnya.
     *
     * @return int
     */
    public function handle(): int
    {
        $this->info('Memulai sync Google Sheets...');
        $this->newLine();

        $results = SyncGoogleSheetsJob::runSync();

        foreach ($results as $key => $result) {
            $label = $key === 'data_cm' ? 'Data CM' : 'Finding CM';
            $icon  = match ($result['status']) {
                'ok'    => '<fg=green>OK</>',
                'error' => '<fg=red>ERROR</>',
                default => '<fg=yellow>SKIP</>',
            };

            $this->line(sprintf('%-12s [%s] %s', $label, $icon, $result['message']));
        }

        $this->newLine();

        $hasError = collect($results)->contains('status', 'error');

        $this->info($hasError
            ? 'Sync selesai dengan beberapa kegagalan — cek log atau .env.'
            : 'Sync selesai. Proses import berjalan di queue (php artisan queue:work).');

        return $hasError ? Command::FAILURE : Command::SUCCESS;
    }
}
