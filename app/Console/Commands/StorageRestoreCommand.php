<?php

namespace App\Console\Commands;

use App\Services\StorageRelocationService;
use App\Services\StorageService;
use Illuminate\Console\Command;

/**
 * Perintah `storage:restore`
 *
 * Mengembalikan folder penyimpanan aplikasi (storage/app) ke dalam folder
 * project - kondisi default saat aplikasi baru dipasang - dengan melepas
 * junction ke drive lain. Data disalin lebih dulu, junction baru dilepas
 * setelah salinan terverifikasi, sehingga data di drive lama tetap aman.
 */
class StorageRestoreCommand extends Command
{
    protected $signature = 'storage:restore
        {--force : Benar-benar jalankan (tanpa ini hanya memeriksa kelayakan)}
        {--keep-old : Biarkan salinan di drive lama (tidak dihapus)}';

    protected $description = 'Kembalikan penyimpanan storage/app ke folder project (kondisi default)';

    /**
     * Periksa kelayakan lalu (bila --force) jalankan pengembalian.
     *
     * @param  StorageService            $storage
     * @param  StorageRelocationService  $relocation
     * @return int
     */
    public function handle(StorageService $storage, StorageRelocationService $relocation): int
    {
        $plan = $relocation->restorePlan();

        $this->info('RENCANA PENGEMBALIAN PENYIMPANAN KE FOLDER PROJECT');
        $this->line('  Saat ini      : ' . $plan['source'] . ($plan['is_linked'] ? '  (dipindah ke drive lain)' : ''));
        $this->line('  Akan kembali  : ' . $plan['target']);
        $this->line('  Ukuran data   : ' . number_format($plan['bytes'] / 1073741824, 2) . ' GB ('
            . number_format($plan['files']) . ' berkas)');
        $this->line('  Sisa ruang ' . $plan['drive'] . ': : ' . number_format($plan['drive_free'] / 1073741824, 1) . ' GB');

        foreach ($plan['notes'] as $note) {
            $this->warn('  Catatan: ' . $note);
        }

        if ($plan['problems'] !== []) {
            $this->newLine();

            foreach ($plan['problems'] as $problem) {
                $this->error('  - ' . $problem);
            }

            return self::FAILURE;
        }

        if ($plan['already_default']) {
            $this->newLine();
            $this->info('Tidak ada yang perlu dilakukan: penyimpanan sudah di folder project.');

            return self::SUCCESS;
        }

        if (!$this->option('force')) {
            $this->newLine();
            $this->warn('Mode periksa saja (dry run). Jalankan ulang dengan --force untuk benar-benar mengembalikan.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('Mulai mengembalikan...');

        $startedAt = microtime(true);
        $result = $relocation->restoreToProject(
            !(bool) $this->option('keep-old'),
            function (string $line): void {
                $this->line('  - ' . $line);
            }
        );

        if (!$result['success']) {
            $this->newLine();
            $this->error('Pengembalian GAGAL: ' . ($result['error'] ?? 'lihat langkah di atas'));

            foreach ($result['steps'] as $step) {
                $this->line('  ' . $step);
            }

            return self::FAILURE;
        }

        $storage->report(true); // segarkan cache laporan penyimpanan

        $this->newLine();
        $this->info('Selesai dalam ' . round(microtime(true) - $startedAt, 1) . ' detik.');
        $this->line('  Penyimpanan kembali di: ' . $result['plan']['target']);

        return self::SUCCESS;
    }
}
