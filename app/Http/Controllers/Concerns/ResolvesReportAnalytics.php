<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ResolvesReportAnalytics
 *
 * Helper bersama untuk grafik analitik perbaikan pada halaman detail equipment
 * dan halaman detail lokasi (PT, Departemen, Area, Sub Area).
 *
 * Dasar pengelompokan adalah kolom `reports.jenis_pekerjaan` (Jenis Pekerjaan)
 * karena kolom itulah yang menyimpan klasifikasi pekerjaan/ZPM tiap laporan
 * (CM, dCM, PM, CM/PM, EI, Mekanik, Fabrikasi, Sipil, IT, Project). Nilai kosong
 * dikelompokkan sebagai "Tanpa Jenis" supaya totalnya tidak pernah hilang.
 *
 * Semua metode menerima query laporan yang cakupannya sudah dibatasi (per alat
 * atau per lokasi) supaya kedua halaman memakai perhitungan yang sama persis.
 */
trait ResolvesReportAnalytics
{
    /**
     * Warna grafik per jenis pekerjaan, dipakai batang tren maupun donut
     * komposisi supaya satu jenis punya warna yang sama di kedua grafik.
     *
     * @var array<string, string>
     */
    protected array $warnaJenisPekerjaan = [
        'CM'        => '#EF4444',
        'dCM'       => '#F97316',
        'PM'        => '#14B8A6',
        'CM/PM'     => '#0E9E8E',
        'EI'        => '#6366F1',
        'Mekanik'   => '#F59E0B',
        'Fabrikasi' => '#8B5CF6',
        'Sipil'     => '#06B6D4',
        'IT'        => '#EC4899',
        'Project'   => '#64748B',
    ];

    /**
     * Ekspresi SQL format bulan (YYYY-MM) sesuai driver database yang aktif.
     *
     * @return string  Potongan SQL siap dipakai pada SELECT
     */
    protected function formatBulanSql(): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', report_date)"
            : "DATE_FORMAT(report_date, '%Y-%m')";
    }

    /**
     * Label tampilan satu jenis pekerjaan.
     *
     * @param  string|null  $jenis  Nilai kolom jenis_pekerjaan
     * @return string
     */
    protected function labelJenisPekerjaan(?string $jenis): string
    {
        $jenis = trim((string) $jenis);

        return $jenis === '' ? 'Tanpa Jenis' : $jenis;
    }

    /**
     * Warna satu jenis pekerjaan (jenis tak dikenal memakai abu-abu).
     *
     * @param  string|null  $jenis  Nilai kolom jenis_pekerjaan
     * @return string  Kode warna hex
     */
    protected function warnaJenisPekerjaan(?string $jenis): string
    {
        return $this->warnaJenisPekerjaan[trim((string) $jenis)] ?? '#94A3B8';
    }

    /**
     * Data grafik tren perbaikan per bulan: satu dataset per jenis pekerjaan,
     * masing-masing berisi jumlah perbaikan tiap bulan.
     *
     * @param  Builder  $query  Query laporan yang cakupannya sudah dibatasi
     * @return array{labels: array<int, string>, datasets: array<int, array<string, mixed>>, total: int}
     */
    protected function susunTrenPerbaikan(Builder $query): array
    {
        $formatBulan = $this->formatBulanSql();

        $baris = $query->reorder()
            ->whereNotNull('report_date')
            ->select('jenis_pekerjaan', DB::raw("{$formatBulan} as bulan"), DB::raw('count(*) as total'))
            ->groupBy('jenis_pekerjaan', 'bulan')
            ->orderBy('bulan')
            ->get();

        // Matriks [jenis][bulan] = jumlah disusun sebagai array biasa supaya
        // tidak bergantung pada perilaku Collection::where untuk nilai null.
        $matriks       = [];
        $totalPerJenis = [];
        $adaBulan      = [];

        foreach ($baris as $item) {
            $bulan = (string) ($item->bulan ?? '');

            if ($bulan === '') {
                continue;
            }

            $jenis = trim((string) ($item->jenis_pekerjaan ?? ''));

            $matriks[$jenis][$bulan] = (int) $item->total;
            $totalPerJenis[$jenis]   = ($totalPerJenis[$jenis] ?? 0) + (int) $item->total;
            $adaBulan[$bulan]        = true;
        }

        $bulanUrut = array_keys($adaBulan);
        sort($bulanUrut);

        // Jenis dengan jumlah terbanyak tampil lebih dulu pada legenda.
        arsort($totalPerJenis);

        $datasets = [];

        foreach (array_keys($totalPerJenis) as $jenis) {
            $datasets[] = [
                'label' => $this->labelJenisPekerjaan($jenis),
                'color' => $this->warnaJenisPekerjaan($jenis),
                'data'  => array_map(fn (string $bulan) => $matriks[$jenis][$bulan] ?? 0, $bulanUrut),
            ];
        }

        return [
            'labels'   => array_map(
                fn (string $bulan) => Carbon::createFromFormat('Y-m', $bulan)->format('M Y'),
                $bulanUrut
            ),
            'datasets' => $datasets,
            'total'    => (int) array_sum($totalPerJenis),
        ];
    }

    /**
     * Komposisi perbaikan per jenis pekerjaan, urut dari yang terbanyak.
     *
     * @param  Builder  $query  Query laporan yang cakupannya sudah dibatasi
     * @return Collection<int, array{label: string, jumlah: int, color: string}>
     */
    protected function susunKomposisiJenis(Builder $query): Collection
    {
        $baris = $query->reorder()
            ->select('jenis_pekerjaan', DB::raw('count(*) as total'))
            ->groupBy('jenis_pekerjaan')
            ->get();

        return $baris
            ->map(fn ($item) => [
                'label'  => $this->labelJenisPekerjaan($item->jenis_pekerjaan),
                'jumlah' => (int) $item->total,
                'color'  => $this->warnaJenisPekerjaan($item->jenis_pekerjaan),
            ])
            ->sortByDesc('jumlah')
            ->values();
    }

    /**
     * Jumlah perbaikan per bulan (kunci = YYYY-MM), dipakai ringkasan angka.
     *
     * @param  Builder  $query  Query laporan yang cakupannya sudah dibatasi
     * @return Collection<string, int>
     */
    protected function susunStatsPerBulan(Builder $query): Collection
    {
        $formatBulan = $this->formatBulanSql();

        return $query->reorder()
            ->whereNotNull('report_date')
            ->select(DB::raw("{$formatBulan} as bulan"), DB::raw('count(*) as total'))
            ->groupBy('bulan')
            ->orderBy('bulan')
            ->pluck('total', 'bulan');
    }

    /**
     * Ringkasan analitik perbaikan: total, rata-rata per bulan, bulan tersibuk,
     * interval rata-rata antar hari perbaikan, dan perbaikan terakhir.
     *
     * @param  Collection  $reports       Seluruh laporan pada cakupan halaman
     * @param  Collection  $statsPerBulan Jumlah perbaikan per bulan
     * @return array<string, mixed>
     */
    protected function susunRingkasanTren(Collection $reports, Collection $statsPerBulan): array
    {
        $total = $reports->count();

        if ($total === 0) {
            return [
                'total'          => 0,
                'rata_per_bulan' => 0.0,
                'bulan_tersibuk' => null,
                'jumlah_bulan'   => 0,
                'interval_rata'  => null,
                'terakhir'       => null,
            ];
        }

        // Hari unik yang punya perbaikan - dipakai menghitung interval.
        $hari = $reports
            ->pluck('report_date')
            ->filter()
            ->map(fn ($tanggal) => $tanggal->toDateString())
            ->unique()
            ->sort()
            ->values();

        $interval = null;

        if ($hari->count() >= 2) {
            $selisih = [];

            for ($i = 1; $i < $hari->count(); $i++) {
                $selisih[] = Carbon::parse($hari[$i])->diffInDays(Carbon::parse($hari[$i - 1]));
            }

            $interval = round(array_sum($selisih) / count($selisih), 1);
        }

        $bulanTersibuk = $statsPerBulan->sortDesc()->keys()->first();

        return [
            'total'          => $total,
            'rata_per_bulan' => round($total / max(1, $statsPerBulan->count()), 1),
            'bulan_tersibuk' => $bulanTersibuk
                ? Carbon::createFromFormat('Y-m', (string) $bulanTersibuk)->format('M Y')
                : null,
            'jumlah_bulan'   => $statsPerBulan->count(),
            'interval_rata'  => $interval,
            'terakhir'       => $hari->last(),
        ];
    }
}
