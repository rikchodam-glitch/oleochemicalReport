<?php

namespace App\Console\Commands;

use App\Models\FunctionalLocation;
use App\Models\Report;
use App\Services\LocationResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Lengkapi lokasi (funcloc_id/area_id) laporan lama yang masih kosong.
 *
 * Latar belakang: laporan yang dibuat lewat jalur penguncian equipment pada
 * wizard Telegram versi lama hanya menyimpan kode lokasi sebagai teks, sehingga
 * kolom funcloc_id/area_id tetap NULL dan halaman laporan menampilkan lokasi
 * kosong. Command ini mengisinya dari data alat (funcloc_id/area_id aset atau
 * kode Functional Location aset) dan dari turunan segmen kode FuncLoc.
 *
 * Tanpa --execute command hanya menampilkan simulasi (tidak menyimpan apa pun).
 *
 * Contoh: php artisan reports:backfill-location
 *         php artisan reports:backfill-location --execute
 *         php artisan reports:backfill-location --execute --limit=500
 */
class ReportBackfillLocationCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'reports:backfill-location
                            {--execute : Simpan perubahan (tanpa flag ini hanya simulasi)}
                            {--limit=0 : Batasi jumlah laporan yang diperiksa (0 = semua)}';

    /**
     * @var string
     */
    protected $description = 'Lengkapi funcloc_id/area_id laporan lama yang kosong dari data alat atau kode Functional Location';

    /**
     * Jalankan command: periksa laporan yang funcloc_id/area_id-nya kosong,
     * tentukan nilai penggantinya, lalu simpan bila --execute diberikan.
     *
     * @param  LocationResolver  $resolver  Resolver lokasi bersama
     * @return int  Kode keluar command
     */
    public function handle(LocationResolver $resolver): int
    {
        $jalankan = (bool) $this->option('execute');
        $batas    = (int) $this->option('limit');

        $this->info($jalankan
            ? 'MODE SIMPAN: perubahan akan ditulis ke database.'
            : 'MODE SIMULASI: tidak ada perubahan ditulis (tambahkan --execute untuk menyimpan).');
        $this->newLine();

        $ringkas = [
            'diperiksa'          => 0,
            'baris_diubah'       => 0,
            'funcloc_diisi'      => 0,
            'area_diisi'         => 0,
            'tanpa_sumber'       => 0,
            'gagal'              => 0,
        ];

        $query = Report::query()
            ->with('asset')
            ->where(function ($q) {
                $q->whereNull('funcloc_id')->orWhereNull('area_id');
            })
            ->orderBy('id');

        if ($batas > 0) {
            $query->limit($batas);
        }

        $contoh = [];

        $query->chunk(200, function ($daftarLaporan) use ($resolver, $jalankan, &$ringkas, &$contoh) {
            foreach ($daftarLaporan as $laporan) {
                $ringkas['diperiksa']++;

                $perubahan = $this->tentukanPerubahan($laporan, $resolver);

                if ($perubahan === []) {
                    $ringkas['tanpa_sumber']++;
                    continue;
                }

                try {
                    if ($jalankan) {
                        $laporan->update($perubahan);
                    }

                    $ringkas['baris_diubah']++;

                    if (array_key_exists('funcloc_id', $perubahan)) {
                        $ringkas['funcloc_diisi']++;
                    }

                    if (array_key_exists('area_id', $perubahan)) {
                        $ringkas['area_diisi']++;
                    }

                    if (count($contoh) < 15) {
                        $contoh[] = sprintf(
                            '#%-5d | %-20s | %s',
                            $laporan->id,
                            (string) ($laporan->report_code ?? '-'),
                            json_encode($perubahan)
                        );
                    }
                } catch (\Throwable $e) {
                    $ringkas['gagal']++;
                    Log::warning('Gagal melengkapi lokasi laporan', [
                        'report_id' => $laporan->id,
                        'pesan'     => $e->getMessage(),
                    ]);
                }
            }
        });

        if ($contoh !== []) {
            $this->line('Contoh perubahan:');

            foreach ($contoh as $baris) {
                $this->line('   ' . $baris);
            }

            $this->newLine();
        }

        $this->table(
            ['Kategori', 'Jumlah'],
            [
                ['Laporan diperiksa', $ringkas['diperiksa']],
                ['Baris dilengkapi', $ringkas['baris_diubah']],
                ['   - funcloc_id diisi', $ringkas['funcloc_diisi']],
                ['   - area_id diisi', $ringkas['area_diisi']],
                ['Tanpa sumber lokasi', $ringkas['tanpa_sumber']],
                ['Gagal', $ringkas['gagal']],
            ]
        );

        // Penjaga keseimbangan: setiap baris yang diperiksa WAJIB berakhir di
        // salah satu kategori (AGENTS.md poin 9) supaya tidak ada baris yang
        // "hilang" tanpa catatan.
        $tercatat = $ringkas['baris_diubah'] + $ringkas['tanpa_sumber'] + $ringkas['gagal'];

        if ($tercatat !== $ringkas['diperiksa']) {
            $this->warn(sprintf(
                'PERINGATAN: jumlah tidak balance (diperiksa %d, tercatat %d).',
                $ringkas['diperiksa'],
                $tercatat
            ));
            Log::warning('reports:backfill-location tidak balance', $ringkas);
        }

        if ($ringkas['baris_diubah'] === 0) {
            $this->info('Tidak ada laporan yang perlu dilengkapi.');

            return self::SUCCESS;
        }

        if (! $jalankan) {
            $this->warn('Simulasi selesai. Jalankan ulang dengan --execute untuk menyimpan ' . $ringkas['baris_diubah'] . ' baris.');
        } else {
            $this->info('Selesai: ' . $ringkas['baris_diubah'] . ' baris laporan dilengkapi.');
        }

        return self::SUCCESS;
    }

    /**
     * Tentukan kolom funcloc_id/area_id yang perlu diisi untuk satu laporan.
     *
     * Sumber berurutan: kolom laporan sendiri -> data alat (funcloc_id/area_id
     * atau kode functional_loc) -> node FuncLoc -> turunan segmen ke-3 kode.
     *
     * @param  Report            $laporan   Laporan yang diperiksa
     * @param  LocationResolver  $resolver  Resolver lokasi bersama
     * @return array<string, int>  Kolom yang perlu diubah beserta nilai barunya (kosong bila tidak bisa ditentukan)
     */
    protected function tentukanPerubahan(Report $laporan, LocationResolver $resolver): array
    {
        $aset = $laporan->asset;

        $funclocId  = $laporan->funcloc_id ?? $aset?->funcloc_id;
        $kodeLokasi = $aset?->functional_loc;

        $node = $funclocId !== null
            ? FunctionalLocation::find($funclocId)
            : $resolver->nodeDariKode($kodeLokasi);

        if ($funclocId === null && $node !== null) {
            $funclocId = $node->id;
        }

        $areaId = $laporan->area_id
            ?? $aset?->area_id
            ?? $node?->area_id
            ?? $resolver->areaIdDariKode($node?->code ?? $kodeLokasi);

        $perubahan = [];

        if ($laporan->funcloc_id === null && $funclocId !== null) {
            $perubahan['funcloc_id'] = $funclocId;
        }

        if ($laporan->area_id === null && $areaId !== null) {
            $perubahan['area_id'] = $areaId;
        }

        return $perubahan;
    }
}
