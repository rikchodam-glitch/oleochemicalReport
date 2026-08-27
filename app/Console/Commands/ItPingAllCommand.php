<?php

namespace App\Console\Commands;

use App\Models\ItAsset;
use App\Services\ItMonitorService;
use Illuminate\Console\Command;

class ItPingAllCommand extends Command
{
    protected $signature = 'it:ping {--asset= : ID perangkat tertentu yang mau dicek (opsional)}';

    protected $description = 'Ping otomatis semua perangkat IT aktif yang punya IP, simpan hasil, dan buat notifikasi offline/ping tinggi';

    /**
     * Jalankan ping terjadwal ke seluruh perangkat IT aktif yang punya IP.
     *
     * @param  ItMonitorService  $monitor
     * @return int
     */
    public function handle(ItMonitorService $monitor): int
    {
        $query = ItAsset::query()
            ->where('status', 'active')
            ->whereNotNull('ip_address')
            ->where('ip_address', '!=', '');

        if ($assetId = $this->option('asset')) {
            $query->whereKey($assetId);
        }

        $assets = $query->get();
        $total = $assets->count();

        if ($total === 0) {
            $this->warn('Tidak ada perangkat IT aktif yang punya IP untuk dicek.');

            return self::SUCCESS;
        }

        $online = 0;
        $offline = 0;
        $notifications = 0;

        foreach ($assets as $asset) {
            $result = $monitor->checkAsset($asset);

            if (!$result['checked']) {
                continue;
            }

            $notifications += count($result['notified']);

            if ($result['status'] === 'online') {
                $online++;
            } elseif ($result['status'] === 'offline') {
                $offline++;
            }

            $this->line(sprintf(
                '  #%d %-30s %-8s %s',
                $asset->id,
                $asset->displayName(),
                $result['status'] ?? 'invalid',
                $result['latency_ms'] !== null ? $result['latency_ms'] . ' ms' : ''
            ));
        }

        $this->info(sprintf(
            'Selesai: %d dicek, %d online, %d offline, %d notifikasi dibuat.',
            $total,
            $online,
            $offline,
            $notifications
        ));

        return self::SUCCESS;
    }
}
