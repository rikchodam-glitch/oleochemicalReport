<?php

namespace App\Services;

use App\Support\WindowsProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        $binDir = $this->storage->mysqlBinDir();

        $problems = [];
        $notes = [];

        if (!$this->storage->databaseIsMySql()) {
            $problems[] = 'Koneksi database aplikasi bukan MySQL/MariaDB, sehingga pemindahan data MySQL tidak berlaku di PC ini.';
        }

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

        // Pemeriksaan awal sebelum mengubah apa pun:
        // 1. koneksi aplikasi ke MySQL (wajib, karena penghentian memakai
        //    perintah SQL SHUTDOWN dari koneksi ini),
        // 2. uji mysqladmin (opsional, hanya cadangan) - bila gagal karena
        //    lingkungan proses terbatas, penghentian tetap berjalan lewat SQL.
        $running = $this->isRunning();
        $connectionCheck = $this->verify();
        $serverCheck = ($binDir !== null && $running) ? $this->serverCheck($binDir) : null;

        if (!$connectionCheck['connected']) {
            $problems[] = 'Tidak bisa terhubung ke MySQL dari aplikasi: ' . $connectionCheck['error'];
        }

        if ($serverCheck !== null && $serverCheck['ok']) {
            $notes[] = 'Uji mysqladmin berhasil: ' . $serverCheck['output'];
        }

        if ($serverCheck !== null && !$serverCheck['ok']) {
            $notes[] = 'mysqladmin belum bisa dipakai dari konteks ini ('
                . ($serverCheck['error'] !== '' ? $serverCheck['error'] : 'tanpa pesan galat')
                . '), sehingga penghentian MySQL memakai perintah SQL SHUTDOWN - tetap aman.';
        }

        return [
            'drive'         => $drive,
            'target'        => $target,
            'my_ini'        => $ini,
            'datadir'       => $datadir,
            'datadir_bytes' => $usage['bytes'],
            'datadir_files' => $usage['files'],
            'bin_dir'       => $binDir,
            'target_free'   => $free,
            'running'       => $running,
            'server_check'  => $serverCheck,
            'problems'      => $problems,
            'notes'         => $notes,
            'ok'            => $problems === [],
        ];
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
                    'error' => 'MySQL tidak bisa dihentikan, jadi tidak ada data yang diubah. Silakan hentikan MySQL dari Laragon '
                        . '(tombol Stop pada MySQL), lalu jalankan pemindahan ini sekali lagi.'];
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
     * Uji koneksi mysqladmin ke server MySQL tanpa mengubah apa pun.
     *
     * @param  string|null  $binDir  Folder bin MySQL (opsional)
     * @return array{ok: bool, output: string, error: string}
     */
    public function serverCheck(?string $binDir = null): array
    {
        $binDir = $binDir ?? $this->storage->mysqlBinDir();

        if ($binDir === null) {
            return ['ok' => false, 'output' => '', 'error' => 'Program mysqladmin.exe tidak ditemukan.'];
        }

        $process = WindowsProcess::make($this->adminArgs($binDir, 'status'), 30);

        try {
            $process->run();
        } catch (\Throwable $e) {
            return ['ok' => false, 'output' => '', 'error' => $e->getMessage()];
        }

        return [
            'ok'     => $process->isSuccessful(),
            'output' => trim($process->getOutput()),
            'error'  => trim($process->getErrorOutput()),
        ];
    }

    /**
     * Hentikan server MySQL: lewat perintah SQL SHUTDOWN lebih dulu, lalu
     * mysqladmin sebagai cadangan.
     *
     * @param  string|null  $binDir  Folder bin MySQL (opsional)
     * @return array{ok: bool, message: string}
     */
    public function stopServer(?string $binDir = null): array
    {
        // Cara utama: SHUTDOWN lewat koneksi aplikasi sendiri. Tidak memakai
        // proses anak, sehingga tetap bekerja walau lingkungan proses terbatas
        // (mis. mysqladmin gagal inisialisasi socket dengan error 10106).
        if ($this->stopViaSql()) {
            return ['ok' => true, 'message' => 'MySQL dihentikan dengan aman lewat perintah SQL SHUTDOWN.'];
        }

        $binDir = $binDir ?? $this->storage->mysqlBinDir();

        if ($binDir === null) {
            return ['ok' => false, 'message' => 'MySQL belum berhenti dan program mysqladmin.exe tidak ditemukan.'];
        }

        $process = WindowsProcess::make($this->adminArgs($binDir, 'shutdown'), 60);

        try {
            $process->run();
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Gagal menjalankan mysqladmin: ' . $e->getMessage()];
        }

        for ($second = 0; $second < 60; $second++) {
            if (!$this->isRunning()) {
                return ['ok' => true, 'message' => 'MySQL dihentikan lewat mysqladmin (port bebas setelah ' . $second . ' detik).'];
            }

            sleep(1);
        }

        return [
            'ok'      => false,
            'message' => 'MySQL belum berhenti setelah 60 detik: '
                . trim($process->getErrorOutput() . $process->getOutput()),
        ];
    }

    /**
     * Hentikan MySQL lewat perintah SQL SHUTDOWN (tanpa proses anak).
     *
     * @return bool  Benar bila server berhasil berhenti
     */
    private function stopViaSql(): bool
    {
        try {
            DB::statement('SHUTDOWN');
        } catch (\Throwable $e) {
            // Umumnya koneksi memang terputus saat server berhenti; dicatat
            // hanya untuk keperluan diagnosa.
            Log::info('MySqlRelocationService: SHUTDOWN - ' . $e->getMessage());
        }

        // MySQL perlu waktu menutup berkas dan melakukan flush (bisa lebih dari
        // 30 detik pada datadir besar), jadi tunggu sampai 90 detik.
        for ($second = 0; $second < 90; $second++) {
            if (!$this->isRunning()) {
                return true;
            }

            sleep(1);
        }

        return false;
    }

    /**
     * Argumen mysqladmin untuk server yang dipakai aplikasi.
     *
     * @param  string  $binDir
     * @param  string  $command  Mis. 'status' atau 'shutdown'
     * @return array<int, string>
     */
    private function adminArgs(string $binDir, string $command): array
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

        $args[] = $command;

        return $args;
    }

    /**
     * Jalankan server MySQL kembali dengan my.ini yang sama.
     *
     * @param  string  $binDir
     * @param  string  $ini
     * @param  string  $logFile
     * @return array{ok: bool, message: string}
     */
    public function startServer(string $binDir, string $ini, string $logFile): array
    {
        $command = 'start "" /B "' . $binDir . '\\mysqld.exe" --defaults-file="' . $ini . '" --log-error="' . $logFile . '"';

        @pclose(@popen('cmd /c ' . $command, 'r'));

        for ($second = 0; $second < 60; $second++) {
            if ($this->isRunning()) {
                sleep(1);

                // Koneksi aplikasi ikut terputus saat MySQL dihentikan tadi;
                // sambungkan ulang supaya permintaan berikutnya lancar.
                try {
                    DB::reconnect();
                } catch (\Throwable $e) {
                    // Diabaikan: koneksi akan dibuat lagi saat dibutuhkan.
                }

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
        $process = WindowsProcess::make([
            'robocopy', $source, $target,
            '/E', '/COPY:DAT', '/DCOPY:DAT', '/R:2', '/W:2', '/NFL', '/NDL', '/NP', '/NJH', '/NJS',
        ], 3600);

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
            $process = WindowsProcess::make(['cmd', '/c', 'rmdir', '/s', '/q', $path], 1800);

            try {
                $process->run();
            } catch (\Throwable $e) {
                return false;
            }
        }

        return is_dir($path) || @mkdir($path, 0775, true);
    }

}
