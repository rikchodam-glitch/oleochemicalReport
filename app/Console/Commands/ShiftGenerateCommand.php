<?php

namespace App\Console\Commands;

use App\Services\Shift\ShiftPatternService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Pastikan jadwal shift semua teknisi pekerja shift tersedia otomatis dari pola
 * rotasi 20 hari sampai beberapa bulan ke depan, sehingga jadwal bulanan tidak
 * perlu diupload lagi. Tanpa opsi --execute hanya menampilkan rencana.
 */
class ShiftGenerateCommand extends Command
{
    /**
     * Nama dan tanda tangan command.
     *
     * @var string
     */
    protected $signature = 'shifts:generate
                            {--bulan=3 : Berapa bulan ke depan yang dipastikan tersedia}
                            {--sampai= : Tanggal akhir Y-m-d (menimpa opsi bulan)}
                            {--execute : Simpan ke database}';

    /**
     * Deskripsi command.
     *
     * @var string
     */
    protected $description = 'Buat/rapikan jadwal shift otomatis dari pola rotasi (tanpa upload jadwal bulanan)';

    /**
     * Jalankan command.
     *
     * @param  ShiftPatternService $pola Service pola rotasi shift
     * @return int                       Kode keluar command
     */
    public function handle(ShiftPatternService $pola): int
    {
        $sampai = $this->option('sampai') !== null
            ? Carbon::parse((string) $this->option('sampai'))->startOfDay()
            : now()->startOfMonth()->addMonths(max(1, (int) $this->option('bulan')))->endOfMonth();

        $this->newLine();
        $this->line(sprintf('Jadwal dipastikan tersedia sampai: %s', $sampai->toDateString()));
        $this->line('Siklus pola: ' . implode('', $pola->siklus()));

        foreach ((array) config('cm.shift_pattern.grup_ke_huruf', []) as $grup => $huruf) {
            $this->line(sprintf('  %-8s (group %s): %s -> kode %s', $grup, $huruf, now()->toDateString(), strtoupper((string) $pola->kode((string) $grup, now()))));
        }

        if (!$this->option('execute')) {
            $this->newLine();
            $this->line('MODE SIMULASI (tambah --execute untuk menyimpan).');
            $this->newLine();

            return self::SUCCESS;
        }

        try {
            $jumlah = $pola->pastikanSampai($sampai);
        } catch (Throwable $e) {
            $this->error('Gagal membuat jadwal: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();

        if ($jumlah === 0) {
            $this->info(sprintf('Tidak ada yang perlu ditambahkan: jadwal sudah tersedia sampai %s.', $sampai->toDateString()));
        } else {
            $this->info(sprintf('Selesai: %d baris jadwal ditulis sampai %s.', $jumlah, $sampai->toDateString()));
        }

        $this->newLine();

        return self::SUCCESS;
    }
}

