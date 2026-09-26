<?php

namespace App\Console\Commands;

use App\Models\Report;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Rapikan foto laporan yang tersimpan ganda.
 *
 * Latar belakang: sebelum penjaga anti-proses-ganda dipasang, satu update
 * Telegram yang terproses dua kali (mis. dua poller sempat berjalan bersamaan)
 * membuat foto yang SAMA tersimpan sebagai DUA file berbeda dan masuk dua kali
 * ke array photo_documentation/photo_hygiene_clearance. Command ini:
 *   1. menyisakan SATU entri per isi file (md5) pada setiap laporan;
 *   2. dengan --hapus-file, menghapus file foto yang tidak lagi dipakai laporan
 *      mana pun (hanya di dalam folder foto Telegram).
 *
 * Tanpa --execute command hanya menampilkan simulasi.
 *
 * Contoh: php artisan reports:dedupe-photo
 *         php artisan reports:dedupe-photo --execute
 *         php artisan reports:dedupe-photo --execute --hapus-file
 */
class ReportDedupePhotoCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'reports:dedupe-photo
                            {--execute : Simpan perubahan (tanpa flag ini hanya simulasi)}
                            {--hapus-file : Hapus file foto yang tidak lagi dipakai laporan mana pun}';

    /**
     * @var string
     */
    protected $description = 'Rapikan foto laporan yang ganda (isi file identik tersimpan lebih dari sekali)';

    /**
     * Jalankan command: dedupe array foto per laporan, lalu opsi hapus file yatim.
     *
     * @return int  Kode keluar command
     */
    public function handle(): int
    {
        $jalankan = (bool) $this->option('execute');
        $hapus    = (bool) $this->option('hapus-file');
        $disk     = Storage::disk(config('telegram.photo_disk', 'telegram_photos'));

        $this->info($jalankan
            ? 'MODE SIMPAN: perubahan akan ditulis ke database.'
            : 'MODE SIMULASI: tidak ada perubahan ditulis (tambahkan --execute untuk menyimpan).');
        $this->newLine();

        $ringkas = [
            'diperiksa'     => 0,
            'diubah'        => 0,
            'tetap'         => 0,
            'entri_dibuang' => 0,
            'file_hilang'   => 0,
            'gagal'         => 0,
        ];

        $fileDipakai = [];
        $contoh      = [];

        Report::query()->chunkById(200, function ($daftarLaporan) use (
            $disk,
            $jalankan,
            &$ringkas,
            &$fileDipakai,
            &$contoh
        ) {
            foreach ($daftarLaporan as $laporan) {
                $ringkas['diperiksa']++;

                $kolomBaru    = [];
                $adaPerubahan = false;

                foreach (['photo_documentation', 'photo_hygiene_clearance'] as $kolom) {
                    $hasil = [];
                    $seen  = [];

                    foreach ($laporan->{$kolom} ?? [] as $path) {
                        $full = $disk->path($path);

                        if (!is_file($full)) {
                            // File hilang: entri dipertahankan apa adanya.
                            $ringkas['file_hilang']++;
                            $hasil[] = $path;
                            continue;
                        }

                        $md5 = md5_file($full);

                        if (isset($seen[$md5])) {
                            $ringkas['entri_dibuang']++;
                            $adaPerubahan = true;
                            continue;
                        }

                        $seen[$md5] = true;
                        $hasil[]    = $path;
                    }

                    $kolomBaru[$kolom] = $hasil;

                    foreach ($hasil as $path) {
                        $fileDipakai[$path] = true;
                    }
                }

                if (!$adaPerubahan) {
                    $ringkas['tetap']++;
                    continue;
                }

                if (count($contoh) < 10) {
                    $contoh[] = sprintf(
                        '#%-5d %-20s dok %d->%d | hc %d->%d',
                        $laporan->id,
                        (string) ($laporan->report_code ?? '-'),
                        count($laporan->photo_documentation ?? []),
                        count($kolomBaru['photo_documentation']),
                        count($laporan->photo_hygiene_clearance ?? []),
                        count($kolomBaru['photo_hygiene_clearance'])
                    );
                }

                try {
                    if ($jalankan) {
                        $laporan->update($kolomBaru);
                    }

                    $ringkas['diubah']++;
                } catch (\Throwable $e) {
                    $ringkas['gagal']++;

                    Log::warning('Gagal merapikan foto laporan', [
                        'report_id' => $laporan->id,
                        'pesan'     => $e->getMessage(),
                    ]);
                }
            }
        });

        $this->tampilkanRingkasan($ringkas, $contoh);

        if ($hapus) {
            $this->hapusFileYatim($disk, $fileDipakai, $jalankan);
        }

        return self::SUCCESS;
    }

    /**
     * Tampilkan tabel ringkasan hasil dedupe + penjaga keseimbangan kategori.
     *
     * @param  array<int, int>  $ringkas  Counter hasil pemrosesan
     * @param  array<int, string>  $contoh  Contoh baris perubahan untuk ditampilkan
     * @return void
     */
    private function tampilkanRingkasan(array $ringkas, array $contoh): void
    {
        if ($contoh !== []) {
            $this->line('Contoh laporan yang dirapikan:');

            foreach ($contoh as $baris) {
                $this->line('   ' . $baris);
            }

            $this->newLine();
        }

        $this->table(
            ['Kategori', 'Jumlah'],
            [
                ['Laporan diperiksa', $ringkas['diperiksa']],
                ['Laporan dirapikan', $ringkas['diubah']],
                ['Laporan sudah rapi', $ringkas['tetap']],
                ['Entri foto duplikat dibuang', $ringkas['entri_dibuang']],
                ['Entri dengan file hilang', $ringkas['file_hilang']],
                ['Gagal', $ringkas['gagal']],
            ]
        );

        // Penjaga keseimbangan kategori (AGENTS.md poin 9).
        $tercatat = $ringkas['diubah'] + $ringkas['tetap'] + $ringkas['gagal'];

        if ($tercatat !== $ringkas['diperiksa']) {
            $this->warn(sprintf(
                'PERINGATAN: jumlah tidak balance (diperiksa %d, tercatat %d).',
                $ringkas['diperiksa'],
                $tercatat
            ));
        }
    }

    /**
     * Hapus file foto di folder laporan yang tidak dipakai laporan mana pun.
     *
     * @param  \Illuminate\Contracts\Filesystem\Filesystem  $disk        Disk foto Telegram
     * @param  array<string, bool>                          $fileDipakai Peta path yang masih dipakai
     * @param  bool                                         $jalankan    True = benar-benar dihapus
     * @return void
     */
    private function hapusFileYatim($disk, array $fileDipakai, bool $jalankan): void
    {
        $folder = config('telegram.photo_folder', 'reports');
        $yatim  = [];
        $ukuran = 0;

        foreach ($disk->allFiles($folder) as $file) {
            if (isset($fileDipakai[$file])) {
                continue;
            }

            $yatim[] = $file;
            $ukuran += (int) $disk->size($file);
        }

        $this->newLine();
        printf(
            "File foto tidak dipakai laporan mana pun: %d file (%.2f MB)\n",
            count($yatim),
            $ukuran / 1048576
        );

        if ($yatim === []) {
            return;
        }

        if (!$jalankan) {
            $this->warn('Simulasi: file TIDAK dihapus. Tambahkan --execute untuk benar-benar menghapus.');

            return;
        }

        $terhapus = 0;

        foreach ($yatim as $file) {
            if ($disk->delete($file)) {
                $terhapus++;
            }
        }

        $this->info("File dihapus: {$terhapus} dari " . count($yatim) . ' file.');
    }
}
