<?php

namespace App\Services;

use App\Models\ItServiceRecord;
use App\Models\Report;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Menyelaraskan kasus Service IT dengan laporan perangkat IT.
 *
 * Aturan yang dipakai (dikonfirmasi user 06/11/2026):
 *   - laporan yang dihitung hanya laporan INDUK (bukan salinan kolaborasi /
 *     multi alat) dan hanya laporan yang asetnya punya it_asset_id;
 *   - satu kasus per aset: laporan 'continue' pertama membuka kasus, laporan
 *     'completed' menutupnya dengan tanggal laporan;
 *   - jika laporan penutup diubah kembali menjadi 'continue', kasus dibuka lagi;
 *   - laporan 'completed' tanpa kasus terbuka tidak membuat kasus baru
 *     (tanggal selesai hanya diisi untuk aset yang sudah masuk halaman).
 *
 * Fungsi ini idempoten: dipanggil setiap halaman Service IT dibuka untuk
 * menangkap data laporan lama sekaligus perubahan status laporan.
 */
class ItServiceSyncService
{
    /**
     * Sinkronkan seluruh kasus Service IT dari laporan.
     *
     * @return void
     */
    public function sinkronkanSemua(): void
    {
        $grupLaporan = $this->laporanPerAset();

        // Catatan manual/bekas sinkronisasi ikut dibaca supaya kasus untuk aset
        // yang laporannya baru dihapus tetap bisa dibuka kembali.
        $grupCatatan = ItServiceRecord::query()
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get()
            ->groupBy('it_asset_id');

        $idAset = $grupLaporan->keys()
            ->merge($grupCatatan->keys())
            ->unique()
            ->values();

        if ($idAset->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($idAset, $grupLaporan, $grupCatatan): void {
            foreach ($idAset as $itAssetId) {
                $this->sinkronkanAset(
                    (int) $itAssetId,
                    $grupLaporan->get($itAssetId, collect()),
                    $grupCatatan->get($itAssetId, collect())
                );
            }
        });
    }

    /**
     * Ambil laporan IT induk yang relevan, dikelompokkan per it_asset_id.
     *
     * @return Collection<int|string, Collection<int, object>>
     */
    private function laporanPerAset(): Collection
    {
        return Report::query()
            ->join('assets', 'assets.id', '=', 'reports.asset_id')
            ->whereNotNull('assets.it_asset_id')
            ->whereIn('reports.status', ['continue', 'completed'])
            ->whereNull('reports.collaborator_of')
            ->whereNull('reports.multi_of')
            ->orderBy('reports.report_date')
            ->orderBy('reports.id')
            ->get([
                'reports.id',
                'reports.status',
                'reports.report_date',
                'assets.it_asset_id',
            ])
            ->groupBy('it_asset_id');
    }

    /**
     * Selaraskan kasus satu perangkat IT dengan daftar laporannya.
     *
     * @param  int  $itAssetId  ID perangkat IT
     * @param  Collection<int, object>  $laporan  Laporan relevan, urut tanggal
     * @param  Collection<int, ItServiceRecord>  $catatan  Kasus yang sudah ada
     * @return void
     */
    private function sinkronkanAset(int $itAssetId, Collection $laporan, Collection $catatan): void
    {
        $catatan = $catatan->sortBy('entry_date')->values();

        // 1. Buka kembali kasus yang penutupnya bukan laporan completed lagi.
        foreach ($catatan as $baris) {
            if ($baris->closed_by_report_id === null) {
                continue;
            }

            $penutup = $laporan->firstWhere('id', $baris->closed_by_report_id);

            if ($penutup !== null && $penutup->status === 'completed') {
                continue;
            }

            $baris->update([
                'finish_date'          => null,
                'status'               => ItServiceRecord::STATUS_PROSES,
                'closed_by_report_id'  => null,
            ]);
        }

        // 2. Proses laporan secara kronologis.
        foreach ($laporan as $laporanBaris) {
            $tanggal = $laporanBaris->report_date->toDateString();

            if ($laporanBaris->status === 'continue') {
                $this->prosesContinue($itAssetId, $laporanBaris, $tanggal, $catatan);

                continue;
            }

            $this->prosesCompleted($laporanBaris, $tanggal, $catatan);
        }
    }

    /**
     * Tangani laporan continue: tautkan, pakai kasus terbuka, atau buat baru.
     *
     * @param  int  $itAssetId  ID perangkat IT
     * @param  object  $laporanBaris  Baris laporan
     * @param  string  $tanggal  Tanggal laporan (Y-m-d)
     * @param  Collection<int, ItServiceRecord>  $catatan  Kasus perangkat
     * @return void
     */
    private function prosesContinue(int $itAssetId, object $laporanBaris, string $tanggal, Collection $catatan): void
    {
        $tertaut = $catatan->firstWhere('report_id', $laporanBaris->id);

        if ($tertaut !== null) {
            // Tanggal masuk mengikuti laporan pembuka selama kasus belum ditutup.
            if ($tertaut->source === ItServiceRecord::SOURCE_REPORT
                && $tertaut->finish_date === null
                && $tertaut->entry_date->toDateString() !== $tanggal) {
                $tertaut->update(['entry_date' => $tanggal]);
            }

            return;
        }

        // Sudah ada kasus terbuka (dari laporan lain atau manual): jangan dobel.
        $terbuka = $catatan->first(fn (ItServiceRecord $baris) => $baris->finish_date === null);

        if ($terbuka !== null) {
            // Laporan yang lebih lama memperpanjang tanggal masuk kasus laporan.
            if ($terbuka->source === ItServiceRecord::SOURCE_REPORT
                && $tanggal < $terbuka->entry_date->toDateString()) {
                $terbuka->update(['entry_date' => $tanggal]);
            }

            return;
        }

        $catatan->push(ItServiceRecord::create([
            'it_asset_id'  => $itAssetId,
            'report_id'    => $laporanBaris->id,
            'source'       => ItServiceRecord::SOURCE_REPORT,
            'entry_date'   => $tanggal,
            'finish_date'  => null,
            'status'       => ItServiceRecord::STATUS_PROSES,
        ]));
    }

    /**
     * Tangani laporan completed: tutup kasus terbuka atau perbarui penutupnya.
     *
     * @param  object  $laporanBaris  Baris laporan
     * @param  string  $tanggal  Tanggal laporan (Y-m-d)
     * @param  Collection<int, ItServiceRecord>  $catatan  Kasus perangkat
     * @return void
     */
    private function prosesCompleted(object $laporanBaris, string $tanggal, Collection $catatan): void
    {
        $penutup = $catatan->firstWhere('closed_by_report_id', $laporanBaris->id);

        if ($penutup !== null) {
            if ($penutup->finish_date?->toDateString() !== $tanggal) {
                $penutup->update([
                    'finish_date' => $tanggal,
                    'status'      => ItServiceRecord::STATUS_SELESAI,
                ]);
            }

            return;
        }

        $terbuka = $catatan->first(
            fn (ItServiceRecord $baris) => $baris->finish_date === null
                && $baris->entry_date->toDateString() <= $tanggal
        );

        if ($terbuka === null) {
            return;
        }

        $terbuka->update([
            'finish_date'          => $tanggal,
            'status'               => ItServiceRecord::STATUS_SELESAI,
            'closed_by_report_id'  => $laporanBaris->id,
        ]);
    }
}
