<?php

namespace App\Console\Commands;

use App\Services\StorageService;
use Illuminate\Console\Command;

/**
 * Perintah `storage:report`
 *
 * Menampilkan kondisi penyimpanan mesin ini: seluruh drive (beserta jenis
 * SSD/HDD dan sisa ruang), ukuran folder penyimpanan aplikasi, ukuran
 * database, statistik snapshot CCTV, serta lokasi penyimpanan yang sedang
 * dipakai. Dipakai untuk memantau sebelum/sesudah memindahkan data ke HDD.
 */
class StorageReportCommand extends Command
{
    protected $signature = 'storage:report {--fresh : Hitung ulang tanpa memakai cache}';

    protected $description = 'Tampilkan pemakaian penyimpanan: drive, folder aplikasi, database, dan snapshot CCTV';

    /**
     * Jalankan pelaporan penyimpanan.
     *
     * @param  StorageService  $storage
     * @return int
     */
    public function handle(StorageService $storage): int
    {
        $report = $storage->report((bool) $this->option('fresh'));
        $warnFree = (int) ($report['warn_free'] ?? 0);

        $this->info('Laporan Penyimpanan - ' . $report['generated_at']);
        $this->newLine();

        $this->line('<fg=cyan;options=bold>DRIVE PADA PC INI</>');
        $rows = [];
        $lowSpace = [];

        foreach ($report['disks'] as $disk) {
            $rows[] = [
                $disk['letter'] . ':',
                $disk['media'],
                $disk['label'] !== '' ? $disk['label'] : '-',
                $this->human($disk['size']),
                $this->human($disk['free']),
                $disk['used_pct'] . '%',
                $disk['model'] !== '' ? $disk['model'] : '-',
            ];

            if ($warnFree > 0 && $disk['free'] < $warnFree) {
                $lowSpace[] = $disk['letter'] . ': sisa ' . $this->human($disk['free']);
            }
        }

        $this->table(
            ['Drive', 'Jenis', 'Label', 'Kapasitas', 'Sisa', 'Terpakai', 'Model'],
            $rows
        );

        $targets = $report['targets'];
        $this->line('<fg=cyan;options=bold>FOLDER PENYIMPANAN APLIKASI</>');

        $folderRows = [];
        foreach ($report['folders'] as $label => $folder) {
            $folderRows[] = [
                $label,
                $folder['exists'] ? $this->human($folder['bytes']) : '(belum ada)',
                $folder['exists'] ? number_format($folder['files']) . ' berkas' : '-',
                $folder['path'],
            ];
        }
        $this->table(['Folder', 'Ukuran', 'Jumlah', 'Lokasi'], $folderRows);

        $this->line('<fg=cyan;options=bold>DATABASE MySQL</>');
        $dbRows = [];
        foreach ($report['databases'] as $database) {
            $dbRows[] = [$database['name'], $this->human($database['bytes']), number_format($database['tables']) . ' tabel'];
        }
        $this->table(['Database', 'Ukuran', 'Tabel'], $dbRows);

        $snap = $report['snapshots'];
        $this->line('<fg=cyan;options=bold>SNAPSHOT CCTV</>');
        $this->line('  Lokasi         : ' . $snap['root']);
        $this->line('  Total          : ' . number_format($snap['total_files']) . ' berkas (' . $this->human($snap['total_bytes']) . ')');
        $this->line('  Retensi        : ' . $snap['retention_days'] . ' hari');
        $this->line('  Kandidat hapus : ' . number_format($snap['old_files']) . ' berkas (' . $this->human($snap['old_bytes']) . ')');
        $this->line('  Jalankan       : php artisan storage:clean --dry-run  (uji)  /  php artisan storage:clean --force  (hapus)');
        $this->newLine();

        $this->line('<fg=cyan;options=bold>LOKASI PENYIMPANAN SAAT INI</>');
        $this->line('  storage/app  : ' . $targets['app_path']);
        $this->line('  Menunjuk ke  : ' . ($targets['app_is_linked'] ? ($targets['app_real_path'] ?? '-') . '  (dipindah ke drive lain)' : 'folder project sendiri (belum dipindah)'));
        $this->line('  public/storage -> ' . ($targets['public_target'] ?? '-'));
        $this->line('  my.ini MySQL : ' . ($targets['mysql_ini'] ?? 'tidak ditemukan'));
        $this->line('  datadir MySQL: ' . ($targets['mysql_datadir'] ?? 'tidak ditemukan'));

        if ($lowSpace !== []) {
            $this->newLine();
            $this->warn('PERINGATAN ruang menipis: ' . implode(' | ', $lowSpace));
        }

        return self::SUCCESS;
    }

    /**
     * Ubah ukuran byte menjadi teks yang mudah dibaca.
     *
     * @param  int  $bytes
     * @return string
     */
    private function human(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $index = 0;

        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        return $index === 0
            ? number_format($value) . ' ' . $units[$index]
            : number_format($value, 1) . ' ' . $units[$index];
    }
}
