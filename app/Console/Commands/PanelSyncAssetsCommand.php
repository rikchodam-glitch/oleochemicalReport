<?php

namespace App\Console\Commands;

use App\Services\PanelAssetSyncService;
use Illuminate\Console\Command;

class PanelSyncAssetsCommand extends Command
{
    protected $signature = 'panel:sync-assets
        {--dry-run : Hitung saja, tidak menyimpan perubahan}';

    protected $description = 'Sinkronkan ruang panel ke tabel assets (kode alat, nomor equipment otomatis, dan lokasi untuk daftar equipment laporan)';

    /**
     * Sinkronkan seluruh ruangan panel ke tabel assets: buat/ubah baris
     * cermin, adopsi baris aset lama berkode sama, dan hapus baris cermin yang
     * ruangannya belum punya lokasi/kode alat.
     *
     * @param  PanelAssetSyncService  $sync
     * @return int
     */
    public function handle(PanelAssetSyncService $sync): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Mode dry-run: tidak ada perubahan yang disimpan.');
        }

        $hasil = $sync->syncAll($dryRun);

        $this->info(sprintf(
            'Sinkronisasi ruang panel: %d dibuat, %d diadopsi, %d diperbarui, %d dilewati, %d cermin dihapus.',
            $hasil['created'],
            $hasil['adopted'],
            $hasil['updated'],
            $hasil['skipped'],
            $hasil['deleted']
        ));

        foreach ($hasil['dilewati'] as $lewati) {
            $this->line('  - dilewati: ' . $lewati['nama'] . ' (' . $lewati['alasan'] . ')');
        }

        return self::SUCCESS;
    }
}
