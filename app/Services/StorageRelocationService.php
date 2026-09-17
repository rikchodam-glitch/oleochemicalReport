<?php

namespace App\Services;

use Symfony\Component\Process\Process;

/**
 * StorageRelocationService
 *
 * Memindahkan folder penyimpanan aplikasi (storage/app — berisi snapshot CCTV,
 * foto Telegram, berkas denah, dan private) ke drive lain, lalu mengganti
 * folder asalnya dengan junction. Tujuannya: SSD sistem tidak penuh, dan
 * penggantian/penambahan HDD di masa depan cukup dengan menjalankan
 * pemindahan lagi (tanpa ubah kode).
 *
 * Alur pemindahan sengaja bertahap supaya aman - sumber TIDAK dihapus sebelum
 * hasil salinan diverifikasi:
 *   1. Periksa tujuan: drive ada, ruang cukup, bukan lokasi yang sama.
 *   2. Salin isi storage/app ke <tujuan>\app (robocopy, sumber tetap utuh).
 *   3. Salin ulang secara inkremental untuk menangkap berkas yang baru dibuat
 *      (mis. snapshot CCTV yang sedang berjalan).
 *   4. Bandingkan jumlah berkas sumber vs tujuan.
 *   5. Ganti nama folder asal menjadi storage/app-old-<waktu> (bukan dihapus).
 *   6. Buat junction storage/app -> <tujuan>\app.
 *   7. Uji tulis/baca lewat junction, lalu hapus folder lama.
 */
class StorageRelocationService
{
    /**
     * Nama subfolder di dalam target untuk data aplikasi.
     */
    private const APP_FOLDER = 'app';

    public function __construct(private readonly StorageService $storage)
    {
    }

    /**
     * Periksa kelayakan pemindahan tanpa mengubah apa pun.
     *
     * @param  string  $targetRoot  Folder induk tujuan, mis. D:\oleochemical-data
     * @return array<string, mixed>
     */
    public function plan(string $targetRoot): array
    {
        $source = storage_path('app');
        $targetRoot = rtrim(str_replace('/', '\\', $targetRoot), '\\');
        $target = $targetRoot . '\\' . self::APP_FOLDER;

        $usage = $this->storage->directoryUsage($source);
        $drive = $this->driveOf($targetRoot);
        $free = $drive !== null ? (int) @disk_free_space($drive . ':\\') : 0;
        $targets = $this->storage->storageTargets();

        $problems = [];
        $notes = [];

        if ($drive === null || !is_dir($drive . ':\\')) {
            $problems[] = 'Drive tujuan tidak ditemukan: ' . $targetRoot;
        }

        if (!is_dir($targetRoot)) {
            $notes[] = 'Folder tujuan belum ada dan akan dibuat otomatis saat pemindahan dijalankan.';
        }

        if ($drive !== null && is_dir($source) && $drive === strtoupper(substr($source, 0, 1))) {
            $problems[] = 'Drive tujuan sama dengan drive storage/app sekarang (' . $drive . ':).';
        }

        if ($targets['app_is_linked'] && $targets['app_real_path'] !== null
            && str_starts_with(strtolower($targets['app_real_path']), strtolower($target))) {
            $problems[] = 'storage/app sudah menunjuk ke folder tujuan ini.';
        }

        if ($free > 0 && $usage['bytes'] > 0 && $free < (int) ($usage['bytes'] * 1.15)) {
            $problems[] = 'Ruang di ' . $drive . ': tidak cukup (butuh sekitar '
                . number_format($usage['bytes'] / 1073741824, 1) . ' GB, tersedia '
                . number_format($free / 1073741824, 1) . ' GB).';
        }

        return [
            'source'       => $source,
            'source_bytes' => $usage['bytes'],
            'source_files' => $usage['files'],
            'target_root'  => $targetRoot,
            'target'       => $target,
            'drive'        => $drive,
            'target_free'  => $free,
            'is_linked'    => (bool) $targets['app_is_linked'],
            'current_link' => $targets['app_real_path'],
            'problems'     => $problems,
            'notes'        => $notes,
            'ok'           => $problems === [],
        ];
    }

    /**
     * Drive (huruf besar) dari sebuah path, mis. 'D'.
     *
     * @param  string  $path
     * @return string|null
     */
    private function driveOf(string $path): ?string
    {
        return preg_match('/^([A-Za-z]):/', $path, $match) === 1 ? strtoupper($match[1]) : null;
    }

    /**
     * Jalankan pemindahan storage/app ke drive tujuan (junction).
     *
     * @param  string         $targetRoot  Folder induk tujuan, mis. D:\oleochemical-data
     * @param  callable|null  $log         Dipanggil tiap langkah: fn(string $pesan)
     * @return array<string, mixed>
     */
    public function relocate(string $targetRoot, ?callable $log = null): array
    {
        $notify = static function (string $message) use ($log): void {
            if ($log !== null) {
                $log($message);
            }
        };

        $plan = $this->plan($targetRoot);

        if (!$plan['ok']) {
            return ['success' => false, 'steps' => $plan['problems'], 'plan' => $plan];
        }

        $source = $plan['source'];
        $target = $plan['target'];
        $steps  = [];

        if (!is_dir($target) && !@mkdir($target, 0775, true) && !is_dir($target)) {
            return ['success' => false, 'steps' => ['Gagal membuat folder tujuan: ' . $target], 'plan' => $plan];
        }

        $steps[] = 'Folder tujuan siap: ' . $target;
        $notify(end($steps));

        $copy = $this->copyTree($source, $target);
        $steps[] = 'Salin pertama selesai (exit ' . $copy['exit'] . ').';
        $notify(end($steps));

        if (!$copy['ok']) {
            return ['success' => false, 'steps' => $steps, 'plan' => $plan, 'copy' => $copy,
                'error' => 'robocopy gagal: ' . $copy['output']];
        }

        $again = $this->copyTree($source, $target);
        $steps[] = 'Salin ulang (inkremental) selesai (exit ' . $again['exit'] . ').';
        $notify(end($steps));

        $sourceUsage = $this->storage->directoryUsage($source);
        $targetUsage = $this->storage->directoryUsage($target);
        $steps[] = 'Bandingkan jumlah berkas: sumber ' . number_format($sourceUsage['files'])
            . ', tujuan ' . number_format($targetUsage['files']) . '.';
        $notify(end($steps));

        if ($targetUsage['files'] < $sourceUsage['files']) {
            return ['success' => false, 'steps' => $steps, 'plan' => $plan,
                'error' => 'Jumlah berkas di tujuan lebih sedikit dari sumber. Pemindahan dihentikan dan data sumber tidak diubah.'];
        }

        $backup = dirname($source) . '\\app-old-' . now()->format('Ymd-His');

        if (!@rename($source, $backup)) {
            return ['success' => false, 'steps' => $steps, 'plan' => $plan,
                'error' => 'Gagal mengganti nama folder lama menjadi ' . $backup];
        }

        $steps[] = 'Folder lama diganti nama menjadi: ' . $backup;
        $notify(end($steps));

        if (!$this->createJunction($source, $target)) {
            @rename($backup, $source);

            return ['success' => false, 'steps' => $steps, 'plan' => $plan,
                'error' => 'Gagal membuat junction; folder lama dikembalikan.'];
        }

        $steps[] = 'Junction dibuat: ' . $source . ' -> ' . $target;
        $notify(end($steps));

        $probe = $source . '\\.relocation-check.txt';

        if (@file_put_contents($probe, 'ok') === false || trim((string) @file_get_contents($probe)) !== 'ok') {
            $steps[] = 'Uji tulis/baca lewat junction GAGAL. Folder lama masih ada di ' . $backup . '.';

            return ['success' => false, 'steps' => $steps, 'plan' => $plan,
                'error' => 'Uji tulis lewat junction gagal.'];
        }

        @unlink($probe);
        $steps[] = 'Uji tulis/baca lewat junction: OK.';
        $notify(end($steps));

        $removed = $this->deleteTree($backup);
        $steps[] = $removed
            ? 'Folder lama dihapus: ' . $backup
            : 'Folder lama TIDAK terhapus (' . $backup . ') - hapus manual setelah memastikan semuanya normal.';
        $notify(end($steps));

        return [
            'success' => true,
            'steps'   => $steps,
            'plan'    => $plan,
            'moved'   => (int) $targetUsage['bytes'],
            'backup'  => $removed ? null : $backup,
        ];
    }


    /**
     * Salin folder secara rekursif memakai robocopy.
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
        $output = trim($process->getOutput() . $process->getErrorOutput());

        // Pada robocopy, exit code 0-7 berarti sukses (bitmask); 8 ke atas gagal.
        return ['ok' => $exit < 8, 'exit' => $exit, 'output' => $output];
    }

    /**
     * Hapus folder beserta isinya (hanya dipakai setelah verifikasi lolos).
     *
     * @param  string  $path
     * @return bool
     */
    private function deleteTree(string $path): bool
    {
        if (!is_dir($path)) {
            return true;
        }

        $process = new Process(['cmd', '/c', 'rmdir', '/s', '/q', $path]);
        $process->setTimeout(1800);

        try {
            $process->run();
        } catch (\Throwable $e) {
            return false;
        }

        return !is_dir($path);
    }

    /**
     * Buat junction (link folder) dari $link menuju $target.
     *
     * @param  string  $link
     * @param  string  $target
     * @return bool
     */
    private function createJunction(string $link, string $target): bool
    {
        if (file_exists($link)) {
            return false;
        }

        $process = new Process(['cmd', '/c', 'mklink', '/J', $link, $target]);
        $process->setTimeout(120);

        try {
            $process->run();
        } catch (\Throwable $e) {
            return false;
        }

        return is_dir($link);
    }

}
