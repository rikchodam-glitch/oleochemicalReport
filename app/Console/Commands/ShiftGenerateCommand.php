<?php

namespace App\Console\Commands;

use App\Models\TechnicianShift;
use App\Services\Shift\ShiftPatternService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Buat jadwal shift otomatis dari pola rotasi 20 hari, sehingga periode
 * berikutnya tidak perlu upload Excel lagi. Tanpa opsi --execute hanya
 * menampilkan rencana (tidak menyimpan).
 */
class ShiftGenerateCommand extends Command
{
    /**
     * Nama dan tanda tangan command.
     *
     * @var string
     */
    protected $signature = 'shifts:generate
                            {--mulai= : Tanggal awal Y-m-d (default: sehari setelah jadwal terakhir)}
                            {--sampai= : Tanggal akhir Y-m-d (default: 29 hari setelah tanggal awal)}
                            {--execute : Simpan ke database}';

    /**
     * Deskripsi command.
     *
     * @var string
     */
    protected $description = 'Buat jadwal shift otomatis dari pola rotasi (tanpa upload jadwal bulanan)';

    /**
     * Jalankan command.
     *
     * @param  ShiftPatternService $pola Service pola rotasi shift
     * @return int                       Kode keluar command
     */
    public function handle(ShiftPatternService $pola): int
    {
        $terakhir = TechnicianShift::max('shift_date');

        $mulai = $this->option('mulai') !== null
            ? Carbon::parse((string) $this->option('mulai'))->startOfDay()
            : ($terakhir !== null ? Carbon::parse((string) $terakhir)->addDay()->startOfDay() : now()->startOfDay());

        $selesai = $this->option('sampai') !== null
            ? Carbon::parse((string) $this->option('sampai'))->startOfDay()
            : $mulai->copy()->addDays(29);

        if ($selesai->lt($mulai)) {
            $this->error('Tanggal selesai lebih awal dari tanggal mulai.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->line(sprintf('Rentang proyeksi: %s s/d %s', $mulai->toDateString(), $selesai->toDateString()));
        $this->line('Siklus: ' . implode('', $pola->siklus()));

        foreach ((array) config('cm.shift_pattern.grup_ke_huruf', []) as $grup => $huruf) {
            $contoh = $pola->kode((string) $grup, $mulai);

            $this->line(sprintf('  %-8s (group %s): %s -> kode %s', $grup, $huruf, $mulai->toDateString(), strtoupper((string) $contoh)));
        }

        if (!$this->option('execute')) {
            $this->newLine();
            $this->line('MODE SIMULASI (tambah --execute untuk menyimpan).');
            $this->newLine();

            return self::SUCCESS;
        }

        $jumlah = $pola->simpanProyeksi($mulai, $selesai);

        $this->newLine();
        $this->info(sprintf('Selesai: %d baris jadwal ditulis untuk rentang %s s/d %s.',
            $jumlah, $mulai->toDateString(), $selesai->toDateString()));
        $this->newLine();

        return self::SUCCESS;
    }
}
