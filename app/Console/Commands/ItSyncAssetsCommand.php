<?php

namespace App\Console\Commands;

use App\Services\ItAssetSyncService;
use Illuminate\Console\Command;

class ItSyncAssetsCommand extends Command
{
    protected $signature = 'it:sync-assets
        {--dry-run : Hitung saja, tidak menyimpan perubahan}
        {--no-map : Lewati pemetaan funcloc, langsung sinkronkan yang sudah punya lokasi}';

    protected $description = 'Sinkronkan perangkat IT Assets ke tabel assets (lokasi, tipe equipment, dan pencarian wizard report)';

    /**
     * Petakan funcloc (kecuali --no-map) lalu sinkronkan seluruh perangkat IT
     * ke tabel assets: buat/ubah baris cermin, adopsi baris lama bertag sama,
     * dan hapus baris cermin yang sumbernya tidak punya lokasi lagi.
     *
     * @param  ItAssetSyncService  $sync
     * @return int
     */
    public function handle(ItAssetSyncService $sync): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Mode dry-run: tidak ada perubahan yang disimpan.');
        }

        if (!$this->option('no-map')) {
            $pemetaan = $sync->mapAllFunclocs($dryRun);

            $this->info(sprintf(
                'Pemetaan funcloc: %d dipetakan, %d plant/department diselaraskan, %d sudah sesuai, %d belum bisa dipetakan.',
                $pemetaan['mapped'],
                $pemetaan['aligned'],
                $pemetaan['already'],
                $pemetaan['unmapped']
            ));
        }

        $hasil = $sync->syncAll($dryRun);

        $this->info(sprintf(
            'Sinkronisasi assets: %d dibuat, %d diadopsi, %d diperbarui, %d dilewati (belum punya lokasi), %d cermin dihapus.',
            $hasil['created'],
            $hasil['adopted'],
            $hasil['updated'],
            $hasil['skipped'],
            $hasil['deleted']
        ));

        return self::SUCCESS;
    }
}
