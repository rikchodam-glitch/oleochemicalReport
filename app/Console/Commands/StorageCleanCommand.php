<?php

namespace App\Console\Commands;

use App\Services\StorageService;
use Illuminate\Console\Command;

/**
 * Perintah `storage:clean`
 *
 * Menghapus snapshot CCTV yang lebih tua dari batas retensi (default 30 hari
 * dari config/storage.php) supaya pemakaian disk tidak tumbuh terus. Aplikasi
 * hanya merujuk snapshot TERBARU tiap perangkat (last_snapshot_path), jadi
 * berkas lama murni arsip.
 *
 * Aman secara bawaan: tanpa `--force` perintah ini hanya menghitung (dry run),
 * sehingga penghapusan data selalu merupakan tindakan yang disengaja.
 */
class StorageCleanCommand extends Command
{
    protected $signature = 'storage:clean
        {--days= : Batas retensi dalam hari (default dari config/storage.php)}
        {--force : Benar-benar menghapus berkas (tanpa ini hanya menghitung)}';

    protected $description = 'Hapus snapshot CCTV yang lebih tua dari batas retensi (default 30 hari)';

    /**
     * Jalankan pembersihan snapshot lama.
     *
     * @param  StorageService  $storage
     * @return int
     */
    public function handle(StorageService $storage): int
    {
        $days = $this->option('days') !== null
            ? max(1, (int) $this->option('days'))
            : null;

        $dryRun = !(bool) $this->option('force');
        $result = $storage->cleanSnapshots($days, $dryRun);

        $this->info($dryRun
            ? 'UJI COBA (dry run) - tidak ada berkas yang dihapus.'
            : 'Pembersihan dijalankan.');

        $this->line('  Lokasi        : ' . $result['root']);
        $this->line('  Retensi       : ' . $result['retention_days'] . ' hari');
        $this->line('  Berkas ' . ($dryRun ? 'kandidat' : 'terhapus') . ' : ' . number_format($result['deleted_files']));

        $this->line('  Ukuran ' . ($dryRun ? 'kandidat' : 'terhapus') . ' : '
            . number_format($result['deleted_bytes'] / 1048576, 1) . ' MB');

        if (!$dryRun) {
            $this->line('  Folder kosong : ' . number_format($result['removed_dirs']));
        }

        if ($result['failed'] > 0) {
            $this->warn('  Gagal dihapus : ' . number_format($result['failed']) . ' berkas (mungkin sedang dipakai).');
        }

        if ($dryRun && $result['deleted_files'] > 0) {
            $this->newLine();
            $this->warn('Jalankan ulang dengan --force untuk menghapus berkas di atas.');
        }

        return self::SUCCESS;
    }
}
