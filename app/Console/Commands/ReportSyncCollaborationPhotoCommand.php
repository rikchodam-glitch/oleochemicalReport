<?php

namespace App\Console\Commands;

use App\Models\Report;
use Illuminate\Console\Command;

/**
 * Samakan dokumentasi baris salinan pekerjaan multi equipment dengan laporan
 * induknya. Dipakai untuk merapikan data lama yang dibuat saat salinan masih
 * disimpan tanpa foto. Path foto yang dipakai sama persis, jadi tidak ada file
 * baru di penyimpanan.
 */
class ReportSyncCollaborationPhotoCommand extends Command
{
    /**
     * Nama dan tanda tangan command.
     *
     * @var string
     */
    protected $signature = 'reports:sync-collaboration-photo
                            {--execute : Simpan perubahan ke database (tanpa opsi ini hanya simulasi)}';

    /**
     * Deskripsi command.
     *
     * @var string
     */
    protected $description = 'Samakan dokumentasi & HC baris salinan multi equipment dengan laporan induk';

    /**
     * Jalankan command.
     *
     * @return int  Kode keluar command
     */
    public function handle(): int
    {
        $execute   = (bool) $this->option('execute');
        $diperiksa = 0;
        $diperbaiki = 0;
        $rincian   = [];

        Report::query()
            ->whereNotNull('multi_of')
            ->with('multiOf')
            ->orderBy('id')
            ->chunkById(200, function ($salinan) use (&$diperiksa, &$diperbaiki, &$rincian, $execute) {
                foreach ($salinan as $baris) {
                    $diperiksa++;

                    $induk = $baris->multiOf;

                    if ($induk === null) {
                        continue;
                    }

                    $fotoInduk = $induk->photo_documentation ?? [];
                    $hcInduk   = $induk->photo_hygiene_clearance ?? [];

                    $perluDok = $fotoInduk !== [] && ($baris->photo_documentation ?? []) === [];
                    $perluHc  = $hcInduk !== [] && ($baris->photo_hygiene_clearance ?? []) === [];

                    if (!$perluDok && !$perluHc) {
                        continue;
                    }

                    $rincian[] = sprintf(
                        '%s (induk %s): dok %d -> %d, hc %d -> %d',
                        (string) $baris->report_code,
                        (string) $induk->report_code,
                        count($baris->photo_documentation ?? []),
                        count($fotoInduk),
                        count($baris->photo_hygiene_clearance ?? []),
                        count($hcInduk)
                    );

                    if ($execute) {
                        $baris->update([
                            'photo_documentation'     => $fotoInduk,
                            'photo_hygiene_clearance' => $hcInduk,
                        ]);
                    }

                    $diperbaiki++;
                }
            });

        $this->newLine();
        $this->table(
            ['Keterangan', 'Jumlah'],
            [
                ['Salinan multi diperiksa', $diperiksa],
                ['Salinan perlu disamakan', $diperbaiki],
                ['Mode', $execute ? 'EKSEKUSI' : 'SIMULASI (pakai --execute untuk menyimpan)'],
            ]
        );

        foreach ($rincian as $baris) {
            $this->line('  - ' . $baris);
        }

        $this->newLine();

        return self::SUCCESS;
    }
}
