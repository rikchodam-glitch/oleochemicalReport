<?php

namespace App\Console\Commands;

use App\Models\ItAsset;
use App\Services\ItAssetSyncService;
use Illuminate\Console\Command;

class ItSeedNetworkDevicesCommand extends Command
{
    protected $signature = 'it:seed-network {--dry-run : Tampilkan rencana tanpa menyimpan}';

    protected $description = 'Tambahkan perangkat jaringan (Switch Managed dan Access Point/Radio) dari config/it.php ke it_assets lalu sinkronkan ke assets';

    /**
     * Buat perangkat jaringan yang belum ada di it_assets dari daftar
     * config('it.network_devices'), lalu petakan Functional Location dan
     * sinkronkan hasilnya ke tabel assets. Perintah ini idempoten: perangkat
     * yang sudah ada (dicocokkan dari tag_name) dilewati, jadi aman dijalankan
     * ulang setelah daftar di config direvisi.
     *
     * @param  ItAssetSyncService  $sync
     * @return int
     */
    public function handle(ItAssetSyncService $sync): int
    {
        $dryRun  = (bool) $this->option('dry-run');
        $daftar  = (array) config('it.network_devices', []);
        $dibuat  = 0;
        $dilewati = 0;
        $diselaraskan = 0;

        if ($dryRun) {
            $this->warn('Mode dry-run: tidak ada perubahan yang disimpan.');
        }

        foreach ($daftar as $baris) {
            $tag = trim((string) ($baris['tag_name'] ?? ''));

            if ($tag === '') {
                continue;
            }

            $asset = ItAsset::where('tag_name', $tag)->first();

            if ($asset !== null) {
                $dilewati++;

                // Perangkat yang sudah ada tetap diselaraskan nilai Connect To
                // dari config (mis. pemetaan radio -> switch), tanpa menyentuh
                // kolom lain agar perubahan manual tidak ikut tertimpa.
                if (!$dryRun && !empty($baris['connect_to'])) {
                    $spec = $asset->spec ?? [];

                    if (($spec['connect_to'] ?? null) !== $baris['connect_to']) {
                        $spec['connect_to'] = $baris['connect_to'];
                        $asset->update(['spec' => $spec]);
                        $diselaraskan++;
                    }
                }

                continue;
            }

            if (!$dryRun) {
                ItAsset::create([
                    'device_type'  => $baris['device_type'] ?? 'OTHER',
                    'tag_name'     => $tag,
                    'name'         => $baris['name'] ?? $tag,
                    'brand'        => $baris['brand'] ?? null,
                    'model'        => $baris['model'] ?? null,
                    'ip_address'   => $baris['ip_address'] ?? null,
                    'plant'        => $baris['plant'] ?? null,
                    'department'   => $baris['department'] ?? null,
                    'location'     => $baris['location'] ?? null,
                    'status'       => 'active',
                    'data_source'  => 'manual',
                    'imported_at'  => now(),
                ]);
            }

            $dibuat++;
        }

        $this->info(sprintf(
            'Perangkat jaringan: %d ditambahkan, %d sudah ada, %d nilai Connect To diselaraskan.',
            $dibuat,
            $dilewati,
            $diselaraskan
        ));

        if ($dryRun) {
            return self::SUCCESS;
        }

        $pemetaan = $sync->mapAllFunclocs();
        $sinkron  = $sync->syncAll();

        $this->info(sprintf(
            'Pemetaan funcloc: %d dipetakan, %d plant/department diselaraskan, %d sudah sesuai, %d belum bisa dipetakan.',
            $pemetaan['mapped'],
            $pemetaan['aligned'],
            $pemetaan['already'],
            $pemetaan['unmapped']
        ));

        $this->info(sprintf(
            'Sinkronisasi assets: %d dibuat, %d diadopsi, %d diperbarui, %d dilewati (belum punya lokasi), %d cermin dihapus.',
            $sinkron['created'],
            $sinkron['adopted'],
            $sinkron['updated'],
            $sinkron['skipped'],
            $sinkron['deleted']
        ));

        return self::SUCCESS;
    }
}
