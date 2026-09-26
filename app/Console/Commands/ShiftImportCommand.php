<?php

namespace App\Console\Commands;

use App\Services\Shift\ShiftScheduleImportService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Import jadwal shift teknisi dari file Excel ke tabel technician_shifts.
 * Tanpa opsi --execute, command ini hanya membaca file dan melaporkan hasilnya.
 */
class ShiftImportCommand extends Command
{
    /**
     * Nama dan tanda tangan command.
     *
     * @var string
     */
    protected $signature = 'shifts:import
                            {file : Path file Excel jadwal shift}
                            {--execute : Simpan ke database (tanpa opsi ini hanya simulasi)}';

    /**
     * Deskripsi command.
     *
     * @var string
     */
    protected $description = 'Import jadwal shift teknisi dari file Excel (periode 26-25 tiap bulan)';

    /**
     * Jalankan command.
     *
     * @param  ShiftScheduleImportService $service Service parser jadwal
     * @return int                                 Kode keluar command
     */
    public function handle(ShiftScheduleImportService $service): int
    {
        $path    = (string) $this->argument('file');
        $execute = (bool) $this->option('execute');

        if (!is_file($path)) {
            $this->error('File tidak ditemukan: ' . $path);

            return self::FAILURE;
        }

        try {
            $hasil = $service->import($path, null, !$execute);
        } catch (Throwable $e) {
            $this->error('Import gagal: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(['Keterangan', 'Nilai'], [
            ['File', basename($path)],
            ['Mode', $execute ? 'EKSEKUSI' : 'SIMULASI (pakai --execute untuk menyimpan)'],
            ['Periode', ($hasil['periode_mulai'] ?? '-') . ' s/d ' . ($hasil['periode_selesai'] ?? '-')],
            ['Baris orang dibaca', $hasil['jumlah_baris']],
            ['Terpetakan ke teknisi', $hasil['jumlah_terpetakan']],
            ['Tidak cocok', $hasil['jumlah_tidak_cocok']],
            ['Baris shift disimpan', $hasil['jumlah_baris_shift']],
        ]);

        if ($hasil['per_section'] !== []) {
            $this->line('Per blok tabel:');

            foreach ($hasil['per_section'] as $nama => $jumlah) {
                $this->line(sprintf('  - %s: %d orang', $nama, $jumlah));
            }
        }

        if ($hasil['per_kode'] !== []) {
            $this->line('Per kode shift:');

            foreach ($hasil['per_kode'] as $kode => $jumlah) {
                $this->line(sprintf('  - %s: %d baris', $kode, $jumlah));
            }
        }

        $catatan = [
            'detail_tidak_cocok'    => 'Tidak cocok dengan teknisi mana pun',
            'dicocokkan_lewat_nama' => 'Dicocokkan lewat nama (NIK berbeda)',
        ];

        foreach ($catatan as $kunci => $judul) {
            if (($hasil[$kunci] ?? []) === []) {
                continue;
            }

            $this->newLine();
            $this->line($judul . ' (' . count($hasil[$kunci]) . '):');

            foreach (array_slice($hasil[$kunci], 0, 20) as $baris) {
                $this->line('  - ' . $baris);
            }
        }

        $this->newLine();

        return self::SUCCESS;
    }
}
