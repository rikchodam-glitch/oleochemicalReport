<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * StorageService
 *
 * Memberi informasi penyimpanan untuk panel Storage dan perintah artisan
 * `storage:report` / `storage:clean`, yaitu:
 *   - daftar drive pada PC (kapasitas, sisa, jenis SSD/HDD, model disk),
 *   - ukuran folder penyimpanan aplikasi (snapshot CCTV, foto Telegram,
 *     denah, preview denah, private, log, cache),
 *   - ukuran database MySQL,
 *   - statistik snapshot CCTV (jumlah berkas, total, laju per hari, serta
 *     kandidat yang melewati batas retensi),
 *   - lokasi penyimpanan saat ini (storage/app bila dipindah ke drive lain
 *     memakai junction, plus datadir MySQL dari my.ini).
 *
 * Kelas ini hanya membaca dan menghitung data. Pemindahan penyimpanan dan
 * pembersihan disediakan lewat method terpisah supaya bisa dipanggil
 * terkontrol (perintah artisan) dan mudah diuji.
 */
class StorageService
{
    /**
     * Skrip PowerShell untuk membaca seluruh drive beserta jenis disknya.
     */
    private const DISK_SCRIPT = <<<'PS'
$parts = @{}
Get-Partition | Where-Object { $_.DriveLetter } | ForEach-Object { $parts[[string]$_.DriveLetter] = $_.DiskNumber }
$list = @()
Get-Volume | Where-Object { $_.DriveLetter } | ForEach-Object {
    $letter = [string]$_.DriveLetter
    $number = $parts[$letter]
    $media = 'unknown'
    $model = ''
    $bus = ''
    $health = ''
    if ($null -ne $number) {
        $disk = Get-PhysicalDisk -DeviceNumber $number -ErrorAction SilentlyContinue
        if ($disk) {
            $media = [string]$disk.MediaType
            $model = [string]$disk.FriendlyName
            $bus = [string]$disk.BusType
            $health = [string]$disk.HealthStatus
        }
    }
    $list += [pscustomobject]@{
        letter = $letter
        label  = [string]$_.FileSystemLabel
        fs     = [string]$_.FileSystem
        size   = [int64]$_.Size
        free   = [int64]$_.SizeRemaining
        type   = [string]$_.DriveType
        media  = $media
        model  = $model
        bus    = $bus
        health = $health
    }
}
ConvertTo-Json -Compress -InputObject $list
PS;

    /**
     * Folder penyimpanan aplikasi yang selalu ditampilkan di laporan.
     *
     * @return array<string, string>
     */
    public function watchedFolders(): array
    {
        return [
            'Snapshot CCTV'   => $this->snapshotRoot(),
            'Foto Telegram'   => Storage::disk(config('telegram.photo_disk', 'telegram_photos'))->path(''),
            'Denah (PDF)'     => Storage::disk('public')->path('layouts'),
            'Preview Denah'   => Storage::disk('public')->path('layout-previews'),
            'Private'         => Storage::disk('local')->path(''),
            'Log'             => storage_path('logs'),
            'Cache & Session' => storage_path('framework'),
        ];
    }

    /**
     * Lokasi folder snapshot CCTV sesuai konfigurasi disk + folder.
     *
     * @return string
     */
    public function snapshotRoot(): string
    {
        $folder = trim((string) config('cctv.snapshot_folder', 'snapshots'), '/');

        return Storage::disk(config('cctv.snapshot_disk', 'cctv_snapshots'))->path($folder);
    }

    /**
     * Daftar drive pada PC: kapasitas, sisa ruang, jenis (SSD/HDD), model.
     *
     * @return array<int, array<string, mixed>>
     */
    public function disks(): array
    {
        $rows = [];

        foreach ($this->physicalDisks() as $row) {
            $letter = strtoupper((string) ($row['letter'] ?? ''));

            if ($letter === '') {
                continue;
            }

            $size = (int) ($row['size'] ?? 0);
            $free = (int) ($row['free'] ?? 0);

            // Cadangan: bila PowerShell tidak memberi ukuran, pakai fungsi PHP.
            if ($size === 0) {
                $size = (int) @disk_total_space($letter . ':\\');
                $free = (int) @disk_free_space($letter . ':\\');
            }

            if ($size === 0) {
                continue; // drive tanpa media (mis. CD-ROM kosong)
            }

            $rows[] = [
                'letter'   => $letter,
                'path'     => $letter . ':\\',
                'label'    => (string) ($row['label'] ?? ''),
                'media'    => $this->normalizeMedia((string) ($row['media'] ?? '')),
                'model'    => (string) ($row['model'] ?? ''),
                'bus'      => (string) ($row['bus'] ?? ''),
                'health'   => (string) ($row['health'] ?? ''),
                'size'     => $size,
                'free'     => $free,
                'used'     => max(0, $size - $free),
                'used_pct' => $size > 0 ? (int) round((($size - $free) / $size) * 100) : 0,
            ];
        }

        usort($rows, static fn ($a, $b) => strcmp($a['letter'], $b['letter']));

        return $rows;
    }

    /**
     * Lokasi penyimpanan yang sedang dipakai aplikasi.
     *
     * @return array<string, mixed>
     */
    public function storageTargets(): array
    {
        $appPath = storage_path('app');

        return [
            'app_path'      => $appPath,
            'app_real_path' => $this->resolvePath($appPath),
            'app_is_linked' => $this->isLinked($appPath),
            'public_link'   => public_path('storage'),
            'public_target' => $this->resolvePath(public_path('storage')),
            'mysql_datadir' => $this->mysqlDataDir(),
            'mysql_ini'     => $this->mysqlIniPath(),
        ];
    }

    /**
     * Jalankan skrip PowerShell lalu kembalikan hasilnya sebagai array.
     *
     * @return array<int, array<string, mixed>>
     */
    private function physicalDisks(): array
    {
        $process = new Process(['powershell', '-NoProfile', '-NonInteractive', '-Command', self::DISK_SCRIPT]);
        $process->setTimeout(25);

        try {
            $process->run();
        } catch (\Throwable $e) {
            return [];
        }

        if (!$process->isSuccessful()) {
            return [];
        }

        $decoded = json_decode(trim($process->getOutput()), true);

        if (!is_array($decoded)) {
            return [];
        }

        // ConvertTo-Json mengembalikan objek (bukan array) bila hanya satu drive.
        return isset($decoded['letter']) ? [$decoded] : array_values($decoded);
    }

    /**
     * Seragamkan jenis disk menjadi 'SSD', 'HDD', atau 'Tidak diketahui'.
     *
     * @param  string  $media
     * @return string
     */
    private function normalizeMedia(string $media): string
    {
        $value = strtoupper(trim($media));

        if ($value === 'SSD' || $value === 'HDD') {
            return $value;
        }

        return 'Tidak diketahui';
    }

    /**
     * Path sebenarnya (setelah junction/symlink diselesaikan).
     *
     * @param  string  $path
     * @return string|null
     */
    private function resolvePath(string $path): ?string
    {
        $real = @realpath($path);

        return $real === false ? null : str_replace('/', '\\', $real);
    }

    /**
     * Benar bila path merupakan junction/symlink ke lokasi lain.
     *
     * @param  string  $path
     * @return bool
     */
    private function isLinked(string $path): bool
    {
        $real = $this->resolvePath($path);

        if ($real === null) {
            return false;
        }

        return strtolower($real) !== strtolower(str_replace('/', '\\', $path));
    }

    /**
     * Lokasi file my.ini MySQL (dibaca dari pola di config/storage.php).
     *
     * @return string|null
     */
    /**
     * Cache hasil deteksi my.ini & mysqld (satu kali per request).
     */
    private bool $mysqlIniResolved = false;

    private ?string $mysqlIniCache = null;

    private bool $mysqldResolved = false;

    private ?string $mysqldCache = null;

    /**
     * Lokasi file my.ini MySQL.
     *
     * @return string|null
     */
    public function mysqlIniPath(): ?string
    {
        if ($this->mysqlIniResolved) {
            return $this->mysqlIniCache;
        }

        $this->mysqlIniResolved = true;
        $this->mysqlIniCache = $this->detectMysqlIni();

        return $this->mysqlIniCache;
    }

    /**
     * Deteksi my.ini tanpa bergantung pada path tetap, supaya panel tetap
     * bekerja setelah aplikasi dipindah ke PC lain:
     *   1. pola pada config/storage.php (Laragon, XAMPP, Program Files, dsb),
     *   2. folder program mysqld yang sedang berjalan - MySQL membaca my.ini
     *      dari folder instalasinya, sehingga cara ini berlaku di PC mana pun,
     *   3. nama my.ini/my.cnf di folder bin dan folder induknya.
     *
     * @return string|null
     */
    private function detectMysqlIni(): ?string
    {
        foreach ((array) config('storage.mysql_ini_paths', []) as $pattern) {
            foreach ((array) glob((string) $pattern) as $file) {
                if (is_file($file)) {
                    return str_replace('/', '\\', $file);
                }
            }
        }

        $mysqld = $this->runningMysqldPath();

        if ($mysqld !== null) {
            $bin = str_replace('/', '\\', dirname($mysqld));

            foreach ([$bin, dirname($bin)] as $folder) {
                foreach (['my.ini', 'my.cnf'] as $name) {
                    if (is_file($folder . '\\' . $name)) {
                        return $folder . '\\' . $name;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Path mysqld.exe yang sedang berjalan (bila ada) dibaca dari Windows.
     *
     * @return string|null
     */
    public function runningMysqldPath(): ?string
    {
        if ($this->mysqldResolved) {
            return $this->mysqldCache;
        }

        $this->mysqldResolved = true;
        $this->mysqldCache = null;

        $script = "Get-CimInstance Win32_Process -Filter \"Name='mysqld.exe'\" | Select-Object -First 1 -ExpandProperty ExecutablePath";
        $process = new Process(['powershell', '-NoProfile', '-NonInteractive', '-Command', $script]);
        $process->setTimeout(20);

        try {
            $process->run();
        } catch (\Throwable $e) {
            return null;
        }

        $path = trim($process->getOutput());

        if ($path !== '' && is_file($path)) {
            $this->mysqldCache = str_replace('/', '\\', $path);
        }

        return $this->mysqldCache;
    }

    /**
     * Folder bin MySQL (berisi mysqld.exe dan mysqladmin.exe).
     *
     * @return string|null
     */
    public function mysqlBinDir(): ?string
    {
        $ini = $this->mysqlIniPath();

        if ($ini !== null) {
            $folder = dirname($ini);

            foreach ([$folder . '\\bin', $folder] as $candidate) {
                if (is_file($candidate . '\\mysqld.exe') && is_file($candidate . '\\mysqladmin.exe')) {
                    return $candidate;
                }
            }
        }

        $mysqld = $this->runningMysqldPath();

        if ($mysqld !== null) {
            return str_replace('/', '\\', dirname($mysqld));
        }

        foreach ((array) config('storage.mysql_bin_paths', []) as $pattern) {
            foreach ((array) glob((string) $pattern) as $file) {
                if (is_file($file)) {
                    return str_replace('/', '\\', dirname($file));
                }
            }
        }

        return null;
    }

    /**
     * Datadir MySQL diambil dari my.ini; bila my.ini tidak ditemukan, dipakai
     * nilai @@datadir dari server yang sedang melayani aplikasi.
     *
     * @return string|null
     */
    public function mysqlDataDir(): ?string
    {
        $ini = $this->mysqlIniPath();

        if ($ini !== null) {
            foreach ((array) @file($ini) as $line) {
                if (preg_match('/^\s*datadir\s*=\s*"?([^"\r\n]+)"?\s*$/i', (string) $line, $match) === 1) {
                    return str_replace('/', '\\', trim($match[1]));
                }
            }
        }

        try {
            $datadir = (string) DB::selectOne('select @@datadir as datadir')->datadir;

            return $datadir !== '' ? str_replace('/', '\\', rtrim($datadir, '/\\')) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Benar bila koneksi database aplikasi memakai MySQL atau MariaDB.
     *
     * @return bool
     */
    public function databaseIsMySql(): bool
    {
        $connection = (string) config('database.default');
        $driver = (string) config('database.connections.' . $connection . '.driver', '');

        return in_array($driver, ['mysql', 'mariadb'], true);
    }



    /**
     * Kunci cache untuk ringkasan storage.
     */
    private const CACHE_KEY = 'storage.report';

    /**
     * Ukuran (byte & jumlah berkas) tiap folder yang dipantau.
     *
     * @return array<string, array<string, mixed>>
     */
    public function folderSizes(): array
    {
        $result = [];

        foreach ($this->watchedFolders() as $label => $path) {
            $usage = $this->directoryUsage($path);

            $result[$label] = [
                'path'   => str_replace('/', '\\', $path),
                'bytes'  => $usage['bytes'],
                'files'  => $usage['files'],
                'exists' => is_dir($path),
            ];
        }

        return $result;
    }

    /**
     * Hitung total byte & jumlah berkas dalam sebuah folder (rekursif).
     *
     * @param  string  $path
     * @return array{bytes: int, files: int}
     */
    public function directoryUsage(string $path): array
    {
        if (!is_dir($path)) {
            return ['bytes' => 0, 'files' => 0];
        }

        $bytes = 0;
        $files = 0;

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile()) {
                    $bytes += (int) $file->getSize();
                    $files++;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('StorageService: gagal menghitung folder ' . $path . ': ' . $e->getMessage());
        }

        return ['bytes' => $bytes, 'files' => $files];
    }

    /**
     * Ukuran tiap database MySQL (byte), diurutkan dari yang terbesar.
     *
     * @return array<int, array<string, mixed>>
     */
    public function databaseSizes(): array
    {
        $rows = DB::select(
            'select table_schema as name, sum(data_length + index_length) as bytes, count(*) as tables
             from information_schema.tables
             group by table_schema
             order by bytes desc'
        );

        return array_map(static function ($row) {
            return [
                'name'   => (string) $row->name,
                'bytes'  => (int) $row->bytes,
                'tables' => (int) $row->tables,
            ];
        }, $rows);
    }


    /**
     * Statistik snapshot CCTV: total, sebaran harian, dan kandidat hapus
     * (berkas yang lebih tua dari batas retensi).
     *
     * @param  int|null  $retentionDays  Batas retensi; null = ikut config.
     * @return array<string, mixed>
     */
    public function snapshotStats(?int $retentionDays = null): array
    {
        $days  = $retentionDays ?? (int) config('storage.snapshot_retention_days', 30);
        $root  = $this->snapshotRoot();
        $limit = now()->subDays($days)->getTimestamp();

        $totalBytes = 0;
        $totalFiles = 0;
        $oldBytes   = 0;
        $oldFiles   = 0;
        $perDay     = [];

        if (is_dir($root)) {
            try {
                foreach ($this->snapshotFiles($root) as $file) {
                    $size = (int) $file->getSize();
                    $time = (int) $file->getMTime();

                    $totalBytes += $size;
                    $totalFiles++;

                    if ($time < $limit) {
                        $oldBytes += $size;
                        $oldFiles++;
                    }

                    $key = date('Y-m-d', $time);
                    $perDay[$key] = ($perDay[$key] ?? 0) + 1;
                }
            } catch (\Throwable $e) {
                Log::warning('StorageService: gagal membaca folder snapshot: ' . $e->getMessage());
            }
        }

        ksort($perDay);

        return [
            'root'           => str_replace('/', '\\', $root),
            'retention_days' => $days,
            'total_bytes'    => $totalBytes,
            'total_files'    => $totalFiles,
            'old_bytes'      => $oldBytes,
            'old_files'      => $oldFiles,
            'per_day'        => $perDay,
        ];
    }

    /**
     * Hapus snapshot CCTV yang lebih tua dari batas retensi, lalu rapikan
     * folder perangkat yang menjadi kosong.
     *
     * @param  int|null  $retentionDays
     * @param  bool      $dryRun  true = hanya menghitung, tidak menghapus.
     * @return array<string, mixed>
     */
    public function cleanSnapshots(?int $retentionDays = null, bool $dryRun = false): array
    {
        $days  = $retentionDays ?? (int) config('storage.snapshot_retention_days', 30);
        $root  = $this->snapshotRoot();
        $limit = now()->subDays($days)->getTimestamp();

        $deletedFiles = 0;
        $deletedBytes = 0;
        $removedDirs  = 0;
        $failed       = 0;

        if (is_dir($root)) {
            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST
                );

                foreach ($iterator as $path => $info) {
                    if ($info->isDir()) {
                        if (!$dryRun && @rmdir((string) $path)) {
                            $removedDirs++;
                        }

                        continue;
                    }

                    if ((int) $info->getMTime() >= $limit) {
                        continue;
                    }

                    $size = (int) $info->getSize();

                    if ($dryRun) {
                        $deletedFiles++;
                        $deletedBytes += $size;

                        continue;
                    }

                    if (@unlink((string) $path)) {
                        $deletedFiles++;
                        $deletedBytes += $size;
                    } else {
                        $failed++;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('StorageService: gagal membersihkan snapshot: ' . $e->getMessage());
            }
        }

        return [
            'root'           => str_replace('/', '\\', $root),
            'retention_days' => $days,
            'dry_run'        => $dryRun,
            'deleted_files'  => $deletedFiles,
            'deleted_bytes'  => $deletedBytes,
            'removed_dirs'   => $removedDirs,
            'failed'         => $failed,
        ];
    }

    /**
     * Ringkasan lengkap untuk panel Storage dan perintah `storage:report`.
     * Hasilnya di-cache 5 menit karena menghitung ukuran folder memakan
     * waktu; pakai $fresh = true untuk memaksa hitung ulang.
     *
     * @param  bool  $fresh
     * @return array<string, mixed>
     */
    public function report(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget(self::CACHE_KEY);
        }

        return Cache::remember(self::CACHE_KEY, now()->addMinutes(5), function () {
            return [
                'disks'        => $this->disks(),
                'folders'      => $this->folderSizes(),
                'databases'    => $this->databaseSizes(),
                'snapshots'    => $this->snapshotStats(),
                'targets'      => $this->storageTargets(),
                'warn_free'    => (int) config('storage.warn_free_bytes', 0),
                'generated_at' => now()->toDateTimeString(),
            ];
        });
    }

    /**
     * Iterator berkas snapshot (hanya berkas, tanpa folder).
     *
     * @param  string  $root
     * @return \Iterator<int, \SplFileInfo>
     */
    private function snapshotFiles(string $root): \Iterator
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                yield $file;
            }
        }
    }


}
