<?php

namespace App\Console\Commands;

use App\Services\Sparepart\SparepartImportService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Impor Integrasi PR/PO Spare Part satu periode dari folder berkas sumber.
 *
 * Lima berkas yang wajib ada di folder periode: SparePart_Monitoring.csv,
 * G001_clean.csv, EXPORT PR.XLSX, EXPORT PO.XLSX, dan EXPORT MRP.XLSX. Berkas
 * PR/PO/MRP boleh berada di folder bersebelahan yang memuatnya (lihat
 * SparepartImportService::berkasWajib()).
 *
 * Import pada periode yang sama selalu mengganti batch lama, sehingga aman
 * dijalankan berulang untuk periode yang sama.
 */
class SparepartImportCommand extends Command
{
    protected $signature = 'sparepart:import
        {folder : Folder periode berisi berkas sumber}
        {--periode= : Label periode; dikosongkan berarti memakai nama folder}
        {--oleh= : Id pengguna yang menjalankan import (opsional)}';

    protected $description = 'Impor data Integrasi PR/PO Spare Part dari folder periode (batch lama periode sama diganti)';

    /**
     * Menjalankan impor dan menampilkan ringkasannya.
     *
     * @param  SparepartImportService $layanan Layanan impor integrasi sparepart
     * @return int                             Kode keluar (SUCCESS atau FAILURE)
     */
    public function handle(SparepartImportService $layanan): int
    {
        $folder = (string) $this->argument('folder');
        $periode = (string) ($this->option('periode') ?? '');
        $oleh = (string) ($this->option('oleh') ?? '');

        if ($periode === '') {
            $periode = basename(rtrim(str_replace('\\', '/', $folder), '/'));

            $this->line('Periode tidak diisi, memakai nama folder: ' . $periode);
        }

        if ($oleh !== '' && ! is_numeric($oleh)) {
            $this->error('Opsi --oleh harus berupa angka id pengguna.');

            return self::FAILURE;
        }

        try {
            $batch = $layanan->import($folder, $periode, $oleh === '' ? null : (int) $oleh);
        } catch (Throwable $e) {
            $this->error('Impor gagal: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Batch #%d "%s" selesai: monitoring %d, G001 %d, PR %d, PO %d, MRP %d, hasil %d.',
            $batch->id,
            $batch->periode,
            $batch->jumlah_monitoring,
            $batch->jumlah_g001,
            $batch->jumlah_pr,
            $batch->jumlah_po,
            $batch->jumlah_mrp,
            $batch->jumlah_hasil
        ));

        $ringkasan = (array) ($batch->ringkasan ?? []);

        foreach ((array) ($ringkasan['aksi'] ?? []) as $nama => $jumlah) {
            $this->line(sprintf('  %-26s %d', $nama, $jumlah));
        }

        $min = (array) ($ringkasan['min'] ?? []);
        $this->line(sprintf(
            '  Stok minimum vs MRP: Sama %d, Beda %d, tanpa MRP %d',
            $min['sama'] ?? 0,
            $min['beda'] ?? 0,
            $min['tanpa_mrp'] ?? 0
        ));

        return self::SUCCESS;
    }
}
