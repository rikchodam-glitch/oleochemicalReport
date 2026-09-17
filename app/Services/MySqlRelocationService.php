<?php

namespace App\Services;

use Symfony\Component\Process\Process;

/**
 * MySqlRelocationService
 *
 * Memindahkan folder data (datadir) MySQL ke drive lain lalu memperbarui
 * my.ini, dengan verifikasi dan rollback otomatis. Dipakai panel Storage
 * supaya penggantian atau penambahan HDD bisa dilakukan tanpa menyunting
 * file konfigurasi secara manual.
 *
 * Catatan tentang MySQL di mesin ini (Laragon, bukan Windows Service):
 *   - Dihentikan dengan `mysqladmin shutdown` (graceful) dan dijalankan
 *     kembali dengan `mysqld --defaults-file=<my.ini>` sebagai proses baru.
 *   - Laragon menulis log ke <datadir lama>\mysqld.log, sehingga folder
 *     datadir lama tetap dibuat (kosong) bila datanya dihapus, agar Laragon
 *     tetap bisa menjalankan MySQL seperti biasa.
 *   - Data lama TIDAK dihapus sebelum server terbukti jalan dari lokasi baru.
 */
class MySqlRelocationService
{
    public function __construct(private readonly StorageService $storage)
    {
    }

    /**
     * Periksa kelayakan pemindahan datadir MySQL tanpa mengubah apa pun.
     *
     * @param  string  $drive   Huruf drive tujuan, mis. 'D'
     * @param  string  $folder  Nama folder di drive tersebut
     * @return array<string, mixed>
     */
    public function plan(string $drive, string $folder): array
    {
        $drive = strtoupper(trim($drive));
        $folder = rtrim(trim($folder), '. ');
        $target = $drive . ':\\' . $folder;

        $ini = $this->storage->mysqlIniPath();
        $datadir = $this->storage->mysqlDataDir();
        $binDir = $this->mysqlBinDir();

        $problems = [];
        $notes = [];

        if ($ini === null) {
            $problems[] = 'File my.ini MySQL tidak ditemukan.';
        }

        if ($datadir === null || !is_dir($datadir)) {
            $problems[] = 'Folder data MySQL (datadir) tidak ditemukan.';
        }

        if (!is_dir($drive . ':\\')) {
            $problems[] = 'Drive tujuan tidak ditemukan: ' . $drive . ':';
        }

        if ($binDir === null || !is_file($binDir . '\\mysqld.exe') || !is_file($binDir . '\\mysqladmin.exe')) {
            $problems[] = 'Program mysqld.exe atau mysqladmin.exe milik MySQL tidak ditemukan.';
        }

        $usage = ($datadir !== null && is_dir($datadir))
            ? $this->storage->directoryUsage($datadir)
            : ['bytes' => 0, 'files' => 0];

        $free = (int) @disk_free_space($drive . ':\\');
        $existingFiles = is_dir($target) ? $this->storage->directoryUsage($target)['files'] : 0;

        if (is_dir($target) && $existingFiles > 0) {
            $problems[] = 'Folder tujuan sudah ada dan berisi ' . number_format($existingFiles)
                . ' berkas. Pilih nama folder lain supaya data tidak tercampur.';
        }

        if ($datadir !== null && strtolower(rtrim($datadir, '\\')) === strtolower($target)) {
            $problems[] = 'Data MySQL sudah berada di folder tujuan ini.';
        }

        if ($free > 0 && $usage['bytes'] > 0 && $free < (int) ($usage['bytes'] * 1.2)) {
            $problems[] = 'Ruang di ' . $drive . ': tidak cukup (butuh sekitar '
                . number_format($usage['bytes'] / 1073741824, 2) . ' GB, tersedia '
                . number_format($free / 1073741824, 1) . ' GB).';
        }

        $notes[] = 'MySQL akan dihentikan sementara (sekitar 15-60 detik) lalu dijalankan kembali otomatis.';
        $notes[] = 'Setelah pemindahan, jalankan ulang queue worker dan scheduler agar koneksi database-nya segar.';

        return [
            'drive'         => $drive,
            'target'        => $target,
            'my_ini'        => $ini,
            'datadir'       => $datadir,
            'datadir_bytes' => $usage['bytes'],
            'datadir_files' => $usage['files'],
            'bin_dir'       => $binDir,
            'target_free'   => $free,
            'running'       => $this->isRunning(),
            'problems'      => $problems,
            'notes'         => $notes,
            'ok'            => $problems === [],
        ];
    }

    /**
     * Folder bin MySQL (berisi mysqld.exe dan mysqladmin.exe).
     *
     * @return string|null
     */
    private function mysqlBinDir(): ?string
    {
        $ini = $this->storage->mysqlIniPath();

        if ($ini !== null) {
            $candidate = dirname($ini) . '\\bin';

            if (is_file($candidate . '\\mysqld.exe')) {
                return $candidate;
            }
        }

        foreach ((array) glob('C:/laragon/bin/mysql/*/bin/mysqld.exe') as $file) {
            return str_replace('/', '\\', dirname($file));
        }

        return null;
    }

    /**
     * Benar bila port MySQL sedang menerima koneksi.
     *
     * @return bool
     */
    public function isRunning(): bool
    {
        $port = (int) config('database.connections.mysql.port', 3306);
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    /**
     * Jalankan pemindahan datadir MySQL ke drive tujuan.
     *
     * @param  string         $drive      Huruf drive tujuan, mis. 'D'
     * @param  string         $folder     Nama folder di drive tersebut
     * @param  bool           $removeOld  Hapus data lama setelah verifikasi berhasil
     * @param  callable|null  $log        Dipanggil tiap langkah: fn(string $pesan)
     * @return array<string, mixed>
     */
    public function relocate(string $drive, string $folder, bool $removeOld = true, ?callable $log = null): array
    {
        $notify = static function (string $message) use ($log): void {
            if ($log !== null) {
                $log($message);
            }
        };

        $plan = $this->plan($drive, $folder);

        if (!$plan['ok']) {
            return ['success' => false, 'steps' => $plan['problems'], 'plan' => $plan];
        }

        $ini = (string) $plan['my_ini'];
        $datadir = (string) $plan['datadir'];
        $target = (string) $plan['target'];
        $binDir = (string) $plan['bin_dir'];
        $steps = [];

        $before = $this->verify();

        if (!$before['connected']) {
            return [
                'success' => false,
                'steps'   => ['Tidak bisa terhubung ke MySQL sebelum pemindahan: ' . $before['error']],
                'plan'    => $plan,
                'error'   => 'Pemeriksaan awal gagal, tidak ada data yang diubah.',
            ];
        }

        $steps[] = 'Kondisi awal: MySQL ' . $before['version'] . ', datadir ' . $before['datadir']
            . ', ' . $before['tables'] . ' tabel pada database aplikasi.';
        $notify(end($steps));

        $iniBackup = $ini . '.bak-' . now()->format('YmdHis');

        if (!@copy($ini, $iniBackup)) {
            return ['success' => false, 'steps' => $steps, 'plan' => $plan,
                'error' => 'Gagal mencadangkan my.ini.'];
        }

        $steps[] = 'my.ini dicadangkan ke: ' . $iniBackup;
        $notify(end($steps));

        if ($plan['running']) {
            $stop = $this->stopServer($binDir);
            $steps[] = $stop['message'];
            $notify(end($steps));

            if (!$stop['ok']) {
                return ['success' => false, 'steps' => $steps, 'plan' => $plan,
                    'error' => 'MySQL tidak bisa dihentikan; tidak ada data yang diubah.'];
            }
        } else {
            $steps[] = 'MySQL sedang tidak berjalan, lanjut menyalin data.';
            $notify(end($steps));
        }

        if (!is_dir($target) && !@mkdir($target, 0775, true) && !is_dir($target)) {
            $this->startServer($binDir, $ini, $datadir . '\\mysqld.log');

            return ['success' => false, 'steps' => $steps, 'plan' => $plan,
                'error' => 'Gagal membuat folder tujuan; MySQL dijalankan kembali dari lokasi lama.'];
        }

        $copy = $this->copyTree($datadir, $target);
        $steps[] = 'Salin data selesai (exit ' . $copy['exit'] . ').';
        $notify(end($steps));

        if (!$copy['ok']) {
            $this->startServer($binDir, $ini, $datadir . '\\mysqld.log');

            return ['success' => false, 'steps' => $steps, 'plan' => $plan,
                'error' => 'robocopy gagal: ' . $copy['output']];
        }

        $sourceUsage = $this->storage->directoryUsage($datadir);
        $targetUsage = $this->storage->directoryUsage($target);
        $steps[] = 'Bandingkan berkas: sumber ' . number_format($sourceUsage['files'])
            . ', tujuan ' . number_format($targetUsage['files']) . '.';
        $notify(end($steps));

        if ($targetUsage['files'] < $sourceUsage['files']) {
            $this->startServer($binDir, $ini, $datadir . '\\mysqld.log');

            return ['success' => false, 'steps' => $steps, 'plan' => $plan,
                'error' => 'Jumlah berkas di tujuan lebih sedikit. MySQL dijalankan kembali dari lokasi lama.'];
        }

        if (!$this->patchIni($ini, $target)) {
            $this->startServer($binDir, $ini, $datadir . '\\mysqld.log');

            return ['success' => false, 'steps' => $steps, 'plan' => $plan,
                'error' => 'Gagal memperbarui my.ini; MySQL dijalankan kembali dari lokasi lama.'];
        }

        $steps[] = 'my.ini diperbarui: datadir -> ' . $target;
        $notify(end($steps));

        $start = $this->startServer($binDir, $ini, $target . '\\mysqld.log');
        $steps[] = $start['message'];
        $notify(end($steps));

        $after = $this->verify();
        $expected = strtolower(rtrim(str_replace('/', '\\', $target), '\\'));
        $actual = strtolower(rtrim(str_replace('/', '\\', $after['datadir']), '\\'));

        $verified = $start['ok']
            && $after['connected']
            && $actual === $expected
            && $after['tables'] === $before['tables'];

        if ($verified) {
            $steps[] = 'Verifikasi berhasil: MySQL memakai datadir baru (' . $after['datadir'] . ') dan '
                . $after['tables'] . ' tabel database aplikasi terbaca utuh.';
            $notify(end($steps));
        } else {
            $steps[] = 'Verifikasi GAGAL, mengembalikan konfigurasi semula...';

            @copy($iniBackup, $ini);
            $this->stopServer($binDir);
            $rollback = $this->startServer($binDir, $ini, $datadir . '\\mysqld.log');
            $steps[] = 'Rollback: ' . $rollback['message'];

            return [
                'success' => false,
                'steps'   => $steps,
                'plan'    => $plan,
                'error'   => 'MySQL tidak terbukti berjalan dari lokasi baru. my.ini dikembalikan dan server dijalankan dari lokasi lama; data lama tidak dihapus.',
            ];
        }

        if ($removeOld) {
            $emptied = $this->emptyFolder($datadir);
            $steps[] = $emptied
                ? 'Data lama dihapus; folder lama tetap ada (kosong) untuk berkas log Laragon: ' . $datadir
                : 'Data lama TIDAK terhapus (' . $datadir . ') - hapus manual bila perlu.';
            $notify(end($steps));

            // Salin berkas log terbaru supaya Laragon tetap menemukannya.
            if (is_file($target . '\\mysqld.log')) {
                @copy($target . '\\mysqld.log', $datadir . '\\mysqld.log');
            }
        }

        return [
            'success' => true,
            'steps'   => $steps,
            'plan'    => $plan,
            'target'  => $target,
            'moved'   => (int) $targetUsage['bytes'],
        ];
    }


    /**
     * Hentikan server MySQL secara graceful memakai mysqladmin, lalu tunggu
     * sampai port benar-benar bebas.
     *
     * @param  string  $binDir
     * @return array{ok: bool, message: string}
     */
    private function stopServer(string $binDir): array
    {
        $connection = (array) config('database.connections.mysql');

        $args = [
            $binDir . '\\mysqladmin.exe',
            '--protocol=TCP',
            '--host=' . ($connection['host'] ?? '127.0.0.1'),
            '--port=' . ($connection['port'] ?? 3306),
            '--user=' . ($connection['username'] ?? 'root'),
        ];

        if ((string) ($connection['password'] ?? '') !== '') {
            $args[] = '--password=' . $connection['password'];
        }

        $args[] = 'shutdown';

        $process = new Process($args);
        $process->setTimeout(60);

        try {
            $process->run();
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Gagal menjalankan mysqladmin: ' . $e->getMessage()];
        }

        for ($second = 0; $second < 30; $second++) {
            if (!$this->isRunning()) {
                return ['ok' => true, 'message' => 'MySQL dihentikan dengan aman (port bebas setelah ' . $second . ' detik).'];
            }

            sleep(1);
        }

        return [
            'ok'      => false,
            'message' => 'MySQL belum berhenti setelah 30 detik: '
                . trim($process->getErrorOutput() . $process->getOutput()),
        ];
    }

    /**
     * Jalankan server MySQL kembali dengan my.ini yang sama.
     *
     * @param  string  $binDir
     * @param  string  $ini
     * @param  string  $logFile
     * @return array{ok: bool, message: string}
     */
    private function startServer(string $binDir, string $ini, string $logFile): array
    {
        $command = 'start "" /B "' . $binDir . '\\mysqld.exe" --defaults-file="' . $ini . '" --log-error="' . $logFile . '"';

        @pclose(@popen('cmd /c ' . $command, 'r'));

        for ($second = 0; $second < 60; $second++) {
            if ($this->isRunning()) {
                sleep(1);

                return ['ok' => true, 'message' => 'MySQL dijalankan kembali (port aktif setelah ' . $second . ' detik).'];
            }

            sleep(1);
        }

        return ['ok' => false, 'message' => 'MySQL tidak menerima koneksi setelah 60 detik.'];
    }


    /**
     * Cek koneksi, versi, datadir yang sedang dipakai server, dan jumlah tabel
     * database aplikasi (dipakai untuk verifikasi sebelum/sesudah pemindahan).
     *
     * @return array{connected: bool, version: string, datadir: string, tables: int, error: string}
     */
    public function verify(): array
    {
        $connection = (array) config('database.connections.mysql');
        $database = (string) ($connection['database'] ?? '');

        try {
            $pdo = new \PDO(
                'mysql:host=' . ($connection['host'] ?? '127.0.0.1') . ';port=' . ($connection['port'] ?? 3306),
                (string) ($connection['username'] ?? 'root'),
                (string) ($connection['password'] ?? ''),
                [\PDO::ATTR_TIMEOUT => 5, \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );

            $version = (string) $pdo->query('select version()')->fetchColumn();
            $datadir = rtrim((string) $pdo->query('select @@datadir')->fetchColumn(), '/\\');

            $statement = $pdo->prepare('select count(*) from information_schema.tables where table_schema = ?');
            $statement->execute([$database]);

            return [
                'connected' => true,
                'version'   => $version,
                'datadir'   => $datadir,
                'tables'    => (int) $statement->fetchColumn(),
                'error'     => '',
            ];
        } catch (\Throwable $e) {
            return [
                'connected' => false,
                'version'   => '',
                'datadir'   => '',
                'tables'    => 0,
                'error'     => $e->getMessage(),
            ];
        }
    }

    /**
     * Ganti nilai datadir pada my.ini (baris lain dibiarkan apa adanya).
     *
     * @param  string  $ini
     * @param  string  $datadir
     * @return bool
     */
    private function patchIni(string $ini, string $datadir): bool
    {
        $content = @file_get_contents($ini);

        if ($content === false) {
            return false;
        }

        $value = str_replace('\\', '/', rtrim($datadir, '\\')) . '/';
        $updated = preg_replace('/^(\s*datadir\s*=\s*).*$/im', '${1}"' . $value . '"', $content, 1, $count);

        if ($updated === null || $count !== 1) {
            return false;
        }

        if (@file_put_contents($ini, $updated) === false) {
            return false;
        }

        // Baca ulang untuk memastikan isi tersimpan seperti yang diharapkan.
        $check = @file_get_contents($ini);

        return $check !== false && str_contains($check, 'datadir="' . $value . '"');
    }

    /**
     * Salin folder memakai robocopy (exit code 0-7 berarti sukses).
     *
     * @param  string  $source
     * @param  string  $target
     * @return array{ok: bool, exit: int, output: string}
     */
    private function copyTree(string $source, string $target): array
    {
        $process = new Process([
            'robocopy', $source, $target,
            '/E', '/COPY:DAT', '/DCOPY:DAT', '/R:2', '/W:2', '/NFL', '/NDL', '/NP', '/NJH', '/NJS',
        ]);
        $process->setTimeout(3600);

        try {
            $process->run();
        } catch (\Throwable $e) {
            return ['ok' => false, 'exit' => -1, 'output' => $e->getMessage()];
        }

        $exit = (int) $process->getExitCode();

        return [
            'ok'     => $exit < 8,
            'exit'   => $exit,
            'output' => trim($process->getOutput() . $process->getErrorOutput()),
        ];
    }

    /**
     * Kosongkan folder lama tetapi foldernya tetap ada, supaya Laragon masih
     * bisa menulis berkas log MySQL ke path lama (mis. mysqld.log).
     *
     * @param  string  $path
     * @return bool
     */
    private function emptyFolder(string $path): bool
    {
        if (is_dir($path)) {
            $process = new Process(['cmd', '/c', 'rmdir', '/s', '/q', $path]);
            $process->setTimeout(1800);

            try {
                $process->run();
            } catch (\Throwable $e) {
                return false;
            }
        }

        return is_dir($path) || @mkdir($path, 0775, true);
    }

}
