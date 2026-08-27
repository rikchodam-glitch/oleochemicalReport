<?php

namespace App\Console\Commands;

use App\Models\ItAsset;
use App\Services\CctvSnapshotService;
use App\Services\ItMonitorService;
use Illuminate\Console\Command;

class ItSnapshotAllCommand extends Command
{
    protected $signature = 'it:snapshot {--asset= : ID perangkat tertentu (opsional)}';

    protected $description = 'Ambil snapshot berkala semua CCTV/NVR aktif yang punya snapshot_url dan catat notifikasi bila gagal';

    /**
     * Jalankan pengambilan snapshot CCTV/NVR secara terjadwal.
     *
     * @param  CctvSnapshotService  $snapshot
     * @param  ItMonitorService     $monitor
     * @return int
     */
    public function handle(CctvSnapshotService $snapshot, ItMonitorService $monitor): int
    {
        $query = ItAsset::query()
            ->whereIn('device_type', ItAsset::SNAPSHOT_CAPABLE_TYPES)
            ->where('status', 'active')
            ->whereNotNull('snapshot_url')
            ->where('snapshot_url', '!=', '');

        if ($assetId = $this->option('asset')) {
            $query->whereKey($assetId);
        }

        $assets = $query->get();
        $total = $assets->count();

        if ($total === 0) {
            $this->warn('Tidak ada CCTV/NVR aktif dengan snapshot_url.');

            return self::SUCCESS;
        }

        $success = 0;
        $failed = 0;

        foreach ($assets as $asset) {
            $result = $snapshot->capture($asset);

            if ($result['success']) {
                $asset->update([
                    'last_snapshot_path'  => $result['path'],
                    'last_snapshot_at'    => now(),
                    'last_snapshot_error' => null,
                ]);
                $success++;
                $this->line(sprintf('  #%d %-30s OK -> %s', $asset->id, $asset->displayName(), $result['path']));
            } else {
                $asset->update(['last_snapshot_error' => $result['error']]);
                $monitor->notifySnapshotError($asset, (string) $result['error']);
                $failed++;
                $this->error(sprintf('  #%d %-30s GAGAL: %s', $asset->id, $asset->displayName(), $result['error']));
            }
        }

        $this->info(sprintf('Selesai: %d diproses, %d berhasil, %d gagal.', $total, $success, $failed));

        return self::SUCCESS;
    }
}
