<?php

namespace App\Console\Commands;

use App\Services\ItAssetSyncService;
use Illuminate\Console\Command;

class ItMapFunclocCommand extends Command
{
    protected $signature = 'it:map-funcloc {--dry-run : Tampilkan hasil pemetaan tanpa menyimpan}';

    protected $description = 'Petakan lokasi (Functional Location) seluruh perangkat IT sesuai aturan config/it.php';

    /**
     * Isi kolom it_assets.funcloc_id berdasarkan aturan plant + department.
     * Perangkat yang belum bisa dipetakan dilaporkan, bukan dipaksa masuk ke
     * lokasi yang salah.
     *
     * @param  ItAssetSyncService  $sync
     * @return int
     */
    public function handle(ItAssetSyncService $sync): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $hasil  = $sync->mapAllFunclocs($dryRun);

        if ($dryRun) {
            $this->warn('Mode dry-run: tidak ada perubahan yang disimpan.');
        }

        $this->info(sprintf(
            'Pemetaan funcloc selesai: %d dipetakan, %d plant/department diselaraskan, %d sudah sesuai, %d belum bisa dipetakan.',
            $hasil['mapped'],
            $hasil['aligned'],
            $hasil['already'],
            $hasil['unmapped']
        ));

        if ($hasil['unmapped'] > 0) {
            $this->newLine();
            $this->line('Perangkat yang belum bisa dipetakan (lokasi/departemen belum lengkap):');

            foreach ($hasil['unmapped_list'] as $row) {
                $this->line(sprintf(
                    '  %-12s %-26s plant=%-8s dept=%s',
                    $row['device'],
                    $row['tag'],
                    $row['plant'],
                    $row['department']
                ));
            }
        }

        return self::SUCCESS;
    }
}
