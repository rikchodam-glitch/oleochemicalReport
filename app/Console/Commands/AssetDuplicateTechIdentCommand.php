<?php

namespace App\Console\Commands;

use App\Models\Asset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Daftar Tech Ident No yang dipakai lebih dari satu aset (tidak unik).
 *
 * Import aset memakai Tech Ident No sebagai kunci pencocokan, jadi nilai yang
 * dobel membuat baris import dilewati ("Perlu Diperiksa"). Command ini
 * menampilkan kelompok duplikatnya beserta identitas tiap aset supaya bisa
 * ditindaklanjuti (mana yang harus diperbaiki/dihapus).
 *
 * Contoh: php artisan assets:duplicate-tech-ident
 *         php artisan assets:duplicate-tech-ident --pt=NPA
 */
class AssetDuplicateTechIdentCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'assets:duplicate-tech-ident {--pt= : Batasi ke kode PT tertentu (mis. EPE/NPA/CES)}';

    /**
     * @var string
     */
    protected $description = 'Tampilkan Tech Ident No yang dipakai lebih dari satu aset (data harus unik per aset)';

    /**
     * Jalankan command: cari kelompok Tech Ident No yang duplikat lalu cetak
     * identitas tiap aset pada kelompok tersebut.
     *
     * @return int
     */
    public function handle(): int
    {
        $kodePt = trim((string) $this->option('pt'));

        $query = Asset::query()
            ->whereNotNull('tech_ident_no')
            ->where('tech_ident_no', '!=', '');

        if ($kodePt !== '') {
            $query->whereHas('company', fn ($q) => $q->where('code', $kodePt));
        }

        $duplikat = $query
            ->selectRaw('UPPER(TRIM(tech_ident_no)) as kunci, COUNT(*) as jumlah')
            ->groupBy('kunci')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('kunci')
            ->get();

        if ($duplikat->isEmpty()) {
            $this->info('Tidak ada Tech Ident No yang dobel' . ($kodePt !== '' ? ' di PT ' . $kodePt : '') . '.');

            return self::SUCCESS;
        }

        $this->info('Tech Ident No dobel: ' . $duplikat->count() . ' nilai' . ($kodePt !== '' ? ' (PT ' . $kodePt . ')' : ''));
        $this->newLine();

        foreach ($duplikat as $kelompok) {
            $kunci = (string) $kelompok->kunci;

            $this->line('TECH IDENT NO: ' . $kunci . '  (' . $kelompok->jumlah . ' aset)');

            $aset = Asset::whereRaw('UPPER(TRIM(tech_ident_no)) = ?', [$kunci])
                ->orderBy('id')
                ->get(['id', 'equipment_no', 'tech_ident_no', 'description', 'functional_loc', 'data_source', 'created_at']);

            foreach ($aset as $baris) {
                // Jumlah laporan ditampilkan karena aset yang punya riwayat
                // laporan sebaiknya dipertahankan (bukan dihapus).
                $laporan = DB::table('reports')->where('asset_id', $baris->id)->count();

                $this->line(sprintf(
                    '   id=%-6d | EQ=%-10s | %-12s | %-28s | %-12s | laporan=%d | dibuat=%s',
                    $baris->id,
                    (string) ($baris->equipment_no ?? '-'),
                    (string) $baris->data_source,
                    mb_substr((string) $baris->functional_loc, 0, 28),
                    mb_substr((string) $baris->description, 0, 12),
                    $laporan,
                    (string) $baris->created_at
                ));
            }

            $this->newLine();
        }

        $this->comment('Perbaiki dengan: (a) menyamakan Tech Ident No di file lalu import ulang, atau');
        $this->comment('(b) memperbaiki/menghapus salah satu aset di halaman Asset Management.');

        return self::SUCCESS;
    }
}
