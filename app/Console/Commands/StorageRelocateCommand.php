<?php

namespace App\Console\Commands;

use App\Services\StorageRelocationService;
use App\Services\StorageService;
use Illuminate\Console\Command;

/**
 * Perintah `storage:relocate`
 *
 * Memindahkan folder penyimpanan aplikasi (storage/app - berisi snapshot CCTV,
 * foto Telegram, berkas denah, dan private) ke drive lain, lalu mengganti
 * folder asalnya dengan junction sehingga tidak ada satu pun kode atau URL
 * yang perlu diubah. Aman dijalankan berulang, mis. ketika mengganti HDD.
 *
 * Tanpa opsi --force, perintah ini hanya memeriksa kelayakan (dry run).
 */
class StorageRelocateCommand extends Command
{
    protected $signature = 'storage:relocate
        {target : Folder induk tujuan, contoh D:/oleochemical-data}
        {--force : Benar-benar jalankan pemindahan (tanpa ini hanya memeriksa kelayakan)}';

    protected $description = 'Pindahkan storage/app (snapshot CCTV, foto, denah) ke drive lain memakai junction';

    /**
     * Periksa kelayakan lalu (bila --force) jalankan pemindahan.
     *
     * @param  StorageService            $storage
     * @param  StorageRelocationService  $relocation
     * @return int
     */
    public function handle(StorageService $storage, StorageRelocationService $relocation): int
    {
        $target = (string) $this->argument('target');
        $plan = $relocation->plan($target);

        $this->info('RENCANA PEMINDAHAN PENYIMPANAN APLIKASI');
        $this->line('  Sumber         : ' . $plan['source']);
        $this->line('  Ukuran sumber  : ' . number_format($plan['source_bytes'] / 1073741824, 2) . ' GB ('
            . number_format($plan['source_files']) . ' berkas)');
        $this->line('  Tujuan         : ' . $plan['target']);
        $this->line('  Sisa ruang     : ' . number_format($plan['target_free'] / 1073741824, 1) . ' GB di drive '
            . ($plan['drive'] ?? '?') . ':');
        $this->line('  Status saat ini: ' . ($plan['is_linked']
            ? 'sudah dipindah ke ' . ($plan['current_link'] ?? '-')
            : 'masih di folder project'));

        if ($plan['problems'] !== []) {
            $this->newLine();

            foreach ($plan['problems'] as $problem) {
                $this->error('  - ' . $problem);
            }

            return self::FAILURE;
        }

        foreach (($plan['notes'] ?? []) as $note) {
            $this->warn('  Catatan: ' . $note);
        }

        if (!$this->option('force')) {
            $this->newLine();
            $this->warn('Mode periksa saja (dry run). Jalankan ulang dengan --force untuk benar-benar memindahkan.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('Mulai memindahkan...');

        $startedAt = microtime(true);
        $result = $relocation->relocate($target, function (string $line) {
            $this->line('  - ' . $line);
        });

        if (!$result['success']) {
            $this->newLine();
            $this->error('Pemindahan GAGAL: ' . ($result['error'] ?? 'lihat langkah di atas'));

            foreach ($result['steps'] as $step) {
                $this->line('  ' . $step);
            }

            return self::FAILURE;
        }

        $storage->report(true); // segarkan cache laporan

        $this->newLine();
        $this->info('Pemindahan selesai dalam ' . round(microtime(true) - $startedAt, 1) . ' detik.');
        $this->line('  Data sekarang berada di: ' . $result['plan']['target']);
        $this->line('  storage/app tetap di : ' . $result['plan']['source'] . ' (junction)');
        $this->line('  Sisa ruang drive asal bisa langsung dicek dengan: php artisan storage:report --fresh');

        if (($result['backup'] ?? null) !== null) {
            $this->warn('  Perhatian: folder lama belum terhapus: ' . $result['backup']);
        }

        return self::SUCCESS;
    }
}
