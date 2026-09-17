<?php

namespace App\Console\Commands;

use App\Services\MySqlRelocationService;
use App\Services\StorageService;
use Illuminate\Console\Command;

/**
 * Perintah `mysql:relocate`
 *
 * Memindahkan folder data (datadir) MySQL ke drive lain lalu memperbarui
 * my.ini secara otomatis, lengkap dengan verifikasi dan rollback. Dipakai
 * saat mengganti atau menambah HDD tanpa menyunting konfigurasi manual.
 *
 * Tanpa opsi --force, perintah ini hanya memeriksa kelayakan (dry run).
 */
class MysqlRelocateCommand extends Command
{
    protected $signature = 'mysql:relocate
        {drive : Huruf drive tujuan, contoh D}
        {folder : Nama folder tujuan, contoh oleochemical-mysql}
        {--force : Benar-benar jalankan pemindahan (tanpa ini hanya memeriksa kelayakan)}
        {--keep-old : Jangan hapus data lama setelah pemindahan berhasil}';

    protected $description = 'Pindahkan folder data MySQL ke drive lain (my.ini diperbarui otomatis, ada verifikasi & rollback)';

    /**
     * Periksa kelayakan lalu (bila --force) jalankan pemindahan.
     *
     * @param  StorageService         $storage
     * @param  MySqlRelocationService $mysql
     * @return int
     */
    public function handle(StorageService $storage, MySqlRelocationService $mysql): int
    {
        $drive = strtoupper(trim((string) $this->argument('drive')));
        $folder = (string) $this->argument('folder');
        $plan = $mysql->plan($drive, $folder);

        $this->info('RENCANA PEMINDAHAN DATA MYSQL');
        $this->line('  Drive tujuan  : ' . $plan['drive'] . ':');
        $this->line('  Folder tujuan : ' . $plan['target']);
        $this->line('  Datadir kini  : ' . ($plan['datadir'] ?? 'tidak ditemukan'));
        $this->line('  Ukuran data   : ' . number_format($plan['datadir_bytes'] / 1073741824, 2) . ' GB ('
            . number_format($plan['datadir_files']) . ' berkas)');
        $this->line('  Sisa ruang    : ' . number_format($plan['target_free'] / 1073741824, 1) . ' GB');
        $this->line('  my.ini        : ' . ($plan['my_ini'] ?? 'tidak ditemukan'));
        $this->line('  MySQL         : ' . ($plan['running'] ? 'sedang berjalan' : 'sedang berhenti'));

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

        if (!$this->option('force')) {
            $this->newLine();
            $this->warn('Mode periksa saja (dry run). Jalankan ulang dengan --force untuk benar-benar memindahkan.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('Mulai memindahkan...');

        $startedAt = microtime(true);
        $result = $mysql->relocate(
            $drive,
            $folder,
            !(bool) $this->option('keep-old'),
            function (string $line): void {
                $this->line('  - ' . $line);
            }
        );

        if (!$result['success']) {
            $this->newLine();
            $this->error('Pemindahan GAGAL: ' . ($result['error'] ?? 'lihat langkah di atas'));

            foreach ($result['steps'] as $step) {
                $this->line('  ' . $step);
            }

            return self::FAILURE;
        }

        $storage->report(true); // segarkan cache laporan penyimpanan

        $this->newLine();
        $this->info('Pemindahan data MySQL selesai dalam ' . round(microtime(true) - $startedAt, 1) . ' detik.');
        $this->line('  Data MySQL sekarang di: ' . $result['target']);
        $this->line('  Jangan lupa jalankan ulang queue worker dan scheduler agar koneksi database-nya segar.');

        return self::SUCCESS;
    }
}
