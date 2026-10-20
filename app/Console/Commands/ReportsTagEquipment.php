<?php

namespace App\Console\Commands;

use App\Services\EquipmentMentionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Lengkapi reports.equipment_tag agar laporan teknisi muncul di halaman
 * "Laporan Teknisi (Daftar Perbaikan)" pada detail equipment CM.
 *
 * Dua masalah data yang diperbaiki (insiden 19/10/2026):
 *   1. Laporan yang punya asset_id tetapi equipment_tag kosong — wizard
 *      Telegram lama hanya menyimpan asset_id.
 *   2. Laporan yang equipment_tag-nya berisi assets.equipment_no (mis.
 *      10002840), padahal CM memakai assets.tech_ident_no (mis. 6400P5).
 *
 * Contoh pemakaian:
 *   php artisan reports:tag-equipment            (simulasi, tidak mengubah data)
 *   php artisan reports:tag-equipment --apply    (simpan perubahan)
 */
class ReportsTagEquipment extends Command
{
    protected $signature = 'reports:tag-equipment
                            {--apply : Simpan perubahan ke database (tanpa opsi ini hanya simulasi)}';

    protected $description = 'Lengkapi equipment_tag laporan teknisi dari asset (tech ident no) / deskripsi pekerjaan';

    public function handle(EquipmentMentionService $mentions): int
    {
        $apply = (bool) $this->option('apply');

        $this->info($apply
            ? 'MODE: SIMPAN — perubahan langsung ditulis ke database.'
            : 'MODE: SIMULASI — tidak ada data yang diubah (tambahkan --apply untuk menyimpan).');

        $dariAsset = $this->lengkapiDariAsset($apply);
        $dariTeks = $this->lengkapiDariTeks($mentions, $apply);

        $this->newLine();
        $this->table(
            ['Sumber', 'Laporan diperbaiki', 'Keterangan'],
            [
                ['asset_id → tech_ident_no', $dariAsset, 'Tag kosong atau masih berisi equipment_no'],
                ['deskripsi pekerjaan', $dariTeks, 'Laporan tanpa asset/tag yang menyebut tepat satu kode equipment'],
            ]
        );

        if (! $apply) {
            $this->warn('Simulasi selesai. Jalankan ulang dengan --apply untuk menyimpan.');
        }

        return self::SUCCESS;
    }

    /**
     * Isi equipment_tag dari assets.tech_ident_no untuk laporan yang punya asset.
     *
     * Nilai lama hanya diganti bila kosong atau sama dengan assets.equipment_no
     * (data hasil backfill lama yang memakai kunci yang salah).
     *
     * @param  bool  $apply  Simpan perubahan?
     * @return int Jumlah laporan yang (akan) diperbaiki
     */
    private function lengkapiDariAsset(bool $apply): int
    {
        $rows = DB::table('reports')
            ->join('assets', 'assets.id', '=', 'reports.asset_id')
            ->whereNotNull('assets.tech_ident_no')
            ->where('assets.tech_ident_no', '<>', '')
            ->where(function ($q): void {
                $q->whereNull('reports.equipment_tag')
                    ->orWhere('reports.equipment_tag', '')
                    ->orWhereColumn('reports.equipment_tag', '=', 'assets.equipment_no');
            })
            ->select([
                'reports.id',
                'reports.report_code',
                'reports.equipment_tag as tag_lama',
                'assets.tech_ident_no as tag_baru',
            ])
            ->orderBy('reports.id')
            ->get();

        foreach ($rows as $row) {
            $this->line(sprintf(
                '  %s : %s -> %s',
                $row->report_code,
                $row->tag_lama === null || $row->tag_lama === '' ? '(kosong)' : $row->tag_lama,
                $row->tag_baru
            ));

            if ($apply) {
                DB::table('reports')->where('id', $row->id)->update([
                    'equipment_tag' => $row->tag_baru,
                ]);
            }
        }

        return $rows->count();
    }

    /**
     * Isi equipment_tag untuk laporan tanpa asset & tanpa tag, hanya bila
     * deskripsinya menyebut TEPAT SATU kode equipment (tidak ambigu).
     *
     * Laporan yang menyebut beberapa equipment tetap dibiarkan kosong; halaman
     * CM akan menampilkannya lewat pencocokan penyebutan tag di deskripsi.
     *
     * @param  bool  $apply  Simpan perubahan?
     * @return int Jumlah laporan yang (akan) diperbaiki
     */
    private function lengkapiDariTeks(EquipmentMentionService $mentions, bool $apply): int
    {
        $jumlah = 0;
        $ambigu = 0;
        $contoh = 0;

        DB::table('reports')
            ->select(['id', 'report_code', 'work_description'])
            ->whereNull('asset_id')
            ->where(function ($q): void {
                $q->whereNull('equipment_tag')->orWhere('equipment_tag', '');
            })
            ->chunkById(200, function ($rows) use ($mentions, $apply, &$jumlah, &$ambigu, &$contoh): void {
                foreach ($rows as $row) {
                    $tags = $mentions->mentions((string) $row->work_description);

                    if (count($tags) !== 1) {
                        if (count($tags) > 1) {
                            $ambigu++;
                        }

                        continue;
                    }

                    $jumlah++;

                    if ($contoh < 20) {
                        $this->line(sprintf('  %s : (kosong) -> %s', $row->report_code, $tags[0]));
                        $contoh++;
                    }

                    if ($apply) {
                        DB::table('reports')->where('id', $row->id)->update([
                            'equipment_tag' => $tags[0],
                        ]);
                    }
                }
            });

        if ($contoh === 20) {
            $this->line('  ...');
        }

        if ($ambigu > 0) {
            $this->line("  Dilewati karena menyebut lebih dari satu equipment: {$ambigu} laporan");
        }

        return $jumlah;
    }
}
