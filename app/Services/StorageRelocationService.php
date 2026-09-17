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
     * Periksa kelayakan pengembalian penyimpanan ke folder project (default).
     *
     * @return array<string, mixed>
     */
    public function restorePlan(): array
    {
        $target = storage_path('app');
        $targets = $this->storage->storageTargets();
        $source = $targets['app_real_path'];
        $linked = (bool) $targets['app_is_linked'];

        $usage = $this->storage->directoryUsage($source ?? $target);
        $drive = strtoupper(substr($target, 0, 1));
        $free = (int) @disk_free_space($drive . ':\\');

        $problems = [];
        $notes = [];

        if (!$linked) {
            $notes[] = 'Penyimpanan sudah berada di folder project (kondisi default), tidak ada yang perlu dikembalikan.';
        }

        if ($source === null || !is_dir($source)) {
            $problems[] = 'Folder penyimpanan saat ini tidak ditemukan: ' . ($source ?? '-');
        }

        if ($free > 0 && $usage['bytes'] > 0 && $free < (int) ($usage['bytes'] * 1.15)) {
            $problems[] = 'Ruang di ' . $drive . ': tidak cukup untuk menampung data kembali (butuh sekitar '
                . number_format($usage['bytes'] / 1073741824, 1) . ' GB, tersedia '
                . number_format($free / 1073741824, 1) . ' GB).';
        }

        $notes[] = 'Data disalin ke folder project lebih dulu; junction baru dilepas setelah salinan terverifikasi, '
            . 'sehingga data di drive lama tetap aman.';

        return [
            'source'          => $source ?? $target,
            'target'          => $target,
            'bytes'           => $usage['bytes'],
            'files'           => $usage['files'],
            'drive'           => $drive,
            'drive_free'      => $free,
            'is_linked'       => $linked,
            'already_default' => !$linked,
            'problems'        => $problems,
            'notes'           => $notes,
            'ok'              => $problems === [],
        ];
    }

    /**
     * Kembalikan penyimpanan aplikasi ke folder project (kondisi default).
     *
     * @param  bool           $removeOld  Hapus salinan di drive lama setelah berhasil
     * @param  callable|null  $log        Dipanggil tiap langkah: fn(string $pesan)
     * @return array<string, mixed>
     */
    public function restoreToProject(bool $removeOld = false, ?callable $log = null): array
    {
        $notify = static function (string $message) use ($log): void {
            if ($log !== null) {
                $log($message);
            }
        };

        $plan = $this->restorePlan();

        if ($plan['already_default']) {
            return ['success' => true, 'steps' => $plan['notes'], 'plan' => $plan, 'noop' => true];
        }

        if (!$plan['ok']) {
            return ['success' => false, 'steps' => $plan['problems'], 'plan' => $plan];
        }

        $source = (string) $plan['source'];
        $target = (string) $plan['target'];
        $staging = dirname($target) . '\\app-restore-' . now()->format('YmdHis');
        $steps = [];

        // 1. Salin ke folder sementara di drive project (sumber tetap utuh).
        $copy = $this->copyTree($source, $staging);
        $steps[] = 'Salin ke folder sementara ' . $staging . ' selesai (exit ' . $copy['exit'] . ').';
        $notify(end($steps));

        if (!$copy['ok']) {
            return ['success' => false, 'steps' => $steps, 'plan' => $plan,
                'error' => 'robocopy gagal: ' . $copy['output']];
        }

        // 2. Bandingkan jumlah berkas sebelum melepas junction.
        $sourceUsage = $this->storage->directoryUsage($source);
        $stagingUsage = $this->storage->directoryUsage($staging);
        $steps[] = 'Bandingkan berkas: drive lama ' . number_format($sourceUsage['files'])
            . ', salinan ' . number_format($stagingUsage['files']) . '.';
        $notify(end($steps));

        if ($stagingUsage['files'] < $sourceUsage['files']) {
            return ['success' => false, 'steps' => $steps, 'plan' => $plan,
                'error' => 'Jumlah berkas salinan lebih sedikit. Junction tidak diubah; salinan sementara di '
                    . $staging . ' boleh dihapus manual.'];
        }


        // 3. Hapus junction. rmdir pada junction hanya menghapus link-nya,
        //    isi folder di drive lama tetap utuh (sumber aman).
        if (!@rmdir($target)) {
            return ['success' => false, 'steps' => $steps, 'plan' => $plan,
                'error' => 'Gagal melepas junction ' . $target . '. Salinan sementara di ' . $staging . ' dibiarkan.'];
        }

        $steps[] = 'Junction dilepas: ' . $target . ' (isi folder lama tetap ada).';
        $notify(end($steps));

        // 4. Jadikan salinan sebagai folder storage/app yang sebenarnya.
        if (!@rename($staging, $target)) {
            $this->createJunction($target, $source);

            return ['success' => false, 'steps' => $steps, 'plan' => $plan,
                'error' => 'Gagal memindahkan salinan menjadi ' . $target . '; junction dikembalikan.'];
        }

        $steps[] = 'Folder project kembali dipakai: ' . $target;
        $notify(end($steps));

        // 5. Uji tulis/baca pada folder project.
        $probe = $target . '\\.restore-check.txt';

        if (@file_put_contents($probe, 'ok') === false || trim((string) @file_get_contents($probe)) !== 'ok') {
            @unlink($probe);
            @rename($target, $staging);
            $this->createJunction($target, $source);

            return ['success' => false, 'steps' => $steps, 'plan' => $plan,
                'error' => 'Uji tulis pada folder project gagal; penyimpanan dikembalikan ke drive lama.'];
        }

        @unlink($probe);
        $steps[] = 'Uji tulis/baca pada folder project: OK.';
        $notify(end($steps));

        // 6. Opsional: hapus salinan di drive lama.
        $removed = false;

        if ($removeOld) {
            $removed = $this->deleteTree($source);
            $steps[] = $removed
                ? 'Salinan di drive lama dihapus: ' . $source
                : 'Salinan di drive lama TIDAK terhapus (' . $source . ') - hapus manual bila perlu.';
            $notify(end($steps));
        } else {
            $steps[] = 'Salinan di drive lama dibiarkan sebagai cadangan: ' . $source;
            $notify(end($steps));
        }

        return [
            'success' => true,
            'steps'   => $steps,
            'plan'    => $plan,
            'removed' => $removed,
            'moved'   => (int) $stagingUsage['bytes'],
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
