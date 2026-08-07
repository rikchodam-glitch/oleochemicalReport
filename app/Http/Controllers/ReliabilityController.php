<?php

namespace App\Http\Controllers;

use App\Models\CmEquipment;
use App\Models\CmFinding;
use App\Models\Report;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReliabilityController extends Controller
{
    /**
     * Tampilkan halaman Reliability Dashboard.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\View\View
     */
    public function index(Request $request)
    {
        $filterTahun = (string) ($request->get('tahun', now()->format('Y')) ?? now()->format('Y'));
        $filterBulan = (string) ($request->get('bulan', '') ?? '');
        $filterPt    = (string) ($request->get('pt', '') ?? '');

        // Tentukan periode
        if ($filterBulan) {
            $periodeMulai = Carbon::create($filterTahun, $filterBulan, 1)->startOfMonth();
            $periodeAkhir = Carbon::create($filterTahun, $filterBulan, 1)->endOfMonth();
        } else {
            $periodeMulai = Carbon::create($filterTahun, 1, 1)->startOfYear();
            $periodeAkhir = Carbon::create($filterTahun, 12, 31)->endOfYear();
        }

        // Base query reports dalam periode — pakai tanggal_kejadian (fallback ke report_date)
        $reportQuery = Report::where(function ($q) use ($periodeMulai, $periodeAkhir) {
            $q->whereBetween('tanggal_kejadian', [$periodeMulai, $periodeAkhir])
              ->orWhereBetween('report_date', [$periodeMulai, $periodeAkhir]);
        });

        // Filter PT — melalui relasi cmEquipment
        if ($filterPt) {
            $reportQuery->whereHas('cmEquipment', fn($q) => $q->where('pt_location', $filterPt));
        }

        // --- Ringkasan Cards ---

        // 1. Fleet MTBF — hanya CM + dCM
        $failureQuery = (clone $reportQuery)->whereIn('jenis_pekerjaan', ['CM', 'dCM']);
        $totalFailures = $failureQuery->count();

        // Total waktu kalender periode (jam)
        $totalDays = $periodeMulai->diffInDays($periodeAkhir) + 1;
        $totalHours = $totalDays * 24;

        // Hitung jumlah equipment yang punya data dalam periode
        $equipmentInPeriodQuery = CmEquipment::query();
        if ($filterPt) {
            $equipmentInPeriodQuery->where('pt_location', $filterPt);
        }
        $totalEquipment = $equipmentInPeriodQuery->count();

        // Fleet MTBF: total kalender semua equipment / total failures
        $fleetTotalHours = $totalEquipment * $totalHours;
        $fleetMtbf = $totalFailures > 0 ? round($fleetTotalHours / $totalFailures, 1) : 0;

        // 2. CM : dCM : PM Ratio
        $countCM  = (clone $reportQuery)->where('jenis_pekerjaan', 'CM')->count();
        $countDCM = (clone $reportQuery)->where('jenis_pekerjaan', 'dCM')->count();
        $countPM  = (clone $reportQuery)->where('jenis_pekerjaan', 'PM')->count();

        // 3. Defer Success Rate
        $dcmTotal   = (clone $reportQuery)->where('jenis_pekerjaan', 'dCM')->count();
        $dcmSuccess = (clone $reportQuery)->where('jenis_pekerjaan', 'dCM')
            ->where('sesuai_rencana', 'ya')
            ->count();
        $deferSuccessRate = $dcmTotal > 0 ? round(($dcmSuccess / $dcmTotal) * 100, 1) : 0;

        // 4. Undetected Failure Rate
        $cmTotal         = (clone $reportQuery)->where('jenis_pekerjaan', 'CM')->count();
        $cmUndetected    = (clone $reportQuery)->where('jenis_pekerjaan', 'CM')
            ->whereNull('linked_finding_id')
            ->count();
        $undetectedRate = $cmTotal > 0 ? round(($cmUndetected / $cmTotal) * 100, 1) : 0;

        // --- Chart Data ---
        $mtbfTrend     = $this->getMtbfTrend($filterTahun, $filterPt);
        $distribution  = $this->getMonthlyDistribution($filterTahun, $filterPt);
        $worstMtbf     = $this->getWorstMtbf($periodeMulai, $periodeAkhir, $filterPt);
        $paretoData    = $this->getParetoData($periodeMulai, $periodeAkhir, $filterPt);

        // Filter dropdown
        $tahunList = range(now()->year - 3, now()->year);
        $ptList    = CmEquipment::select('pt_location')->distinct()->pluck('pt_location');

        return view('reliability.index', compact(
            'fleetMtbf', 'totalFailures', 'totalEquipment',
            'countCM', 'countDCM', 'countPM',
            'deferSuccessRate', 'dcmSuccess', 'dcmTotal',
            'undetectedRate', 'cmUndetected', 'cmTotal',
            'mtbfTrend', 'distribution', 'worstMtbf', 'paretoData',
            'ptList', 'tahunList',
            'filterTahun', 'filterBulan', 'filterPt'
        ));
    }

    /**
     * Hitung trend MTBF bulanan dengan rolling window 3 bulan.
     */
    private function getMtbfTrend(string $tahun, string $filterPt = ''): array
    {
        $result = [];
        $tahunInt = (int) $tahun;

        $eqQuery = CmEquipment::query();
        if ($filterPt) {
            $eqQuery->where('pt_location', $filterPt);
        }
        $totalEquipment = $eqQuery->count();
        if ($totalEquipment === 0) {
            return [];
        }

        for ($bulan = 1; $bulan <= 12; $bulan++) {
            $periodeAkhir = Carbon::create($tahunInt, $bulan, 1)->endOfMonth();
            $periodeMulai = Carbon::create($tahunInt, $bulan, 1)->subMonths(2)->startOfMonth();

            $totalDays = $periodeMulai->diffInDays($periodeAkhir) + 1;
            $totalHours = $totalDays * 24;

            $query = Report::whereIn('jenis_pekerjaan', ['CM', 'dCM'])
                ->where(function ($q) use ($periodeMulai, $periodeAkhir) {
                    $q->whereBetween('tanggal_kejadian', [$periodeMulai, $periodeAkhir])
                      ->orWhereBetween('report_date', [$periodeMulai, $periodeAkhir]);
                });

            if ($filterPt) {
                $query->whereHas('cmEquipment', fn($q) => $q->where('pt_location', $filterPt));
            }

            $failures = $query->count();
            $fleetTotalHours = $totalEquipment * $totalHours;
            $mtbf = $failures > 0 ? round($fleetTotalHours / $failures, 1) : 0;

            $result[] = [
                'bulan'     => $bulan,
                'label'     => $this->bulanLabel($bulan),
                'mtbf'      => $mtbf,
                'failures'  => $failures,
                'periode'   => $periodeMulai->format('Y-m') . ' s/d ' . $periodeAkhir->format('Y-m'),
            ];
        }

        return $result;
    }

    /**
     * Distribusi CM/dCM/PM per bulan.
     */
    private function getMonthlyDistribution(string $tahun, string $filterPt = ''): array
    {
        $result = [];
        $tahunInt = (int) $tahun;

        for ($bulan = 1; $bulan <= 12; $bulan++) {
            $periodeMulai = Carbon::create($tahunInt, $bulan, 1)->startOfMonth();
            $periodeAkhir = Carbon::create($tahunInt, $bulan, 1)->endOfMonth();

            $query = Report::where(function ($q) use ($periodeMulai, $periodeAkhir) {
                $q->whereBetween('tanggal_kejadian', [$periodeMulai, $periodeAkhir])
                  ->orWhereBetween('report_date', [$periodeMulai, $periodeAkhir]);
            });

            if ($filterPt) {
                $query->whereHas('cmEquipment', fn($q) => $q->where('pt_location', $filterPt));
            }

            $result[] = [
                'bulan' => $bulan,
                'label' => $this->bulanLabel($bulan),
                'CM'    => (clone $query)->where('jenis_pekerjaan', 'CM')->count(),
                'dCM'   => (clone $query)->where('jenis_pekerjaan', 'dCM')->count(),
                'PM'    => (clone $query)->where('jenis_pekerjaan', 'PM')->count(),
            ];
        }

        return $result;
    }

    /**
     * Top 10 equipment dengan MTBF terendah (paling sering failure).
     */
    private function getWorstMtbf(Carbon $periodeMulai, Carbon $periodeAkhir, string $filterPt = ''): array
    {
        $totalDays = $periodeMulai->diffInDays($periodeAkhir) + 1;
        $totalHours = $totalDays * 24;

        $query = Report::selectRaw('
                equipment_tag,
                COUNT(*) as total_failures,
                MIN(COALESCE(tanggal_kejadian, report_date)) as first_failure,
                MAX(COALESCE(tanggal_kejadian, report_date)) as last_failure
            ')
            ->whereIn('jenis_pekerjaan', ['CM', 'dCM'])
            ->where(function ($q) use ($periodeMulai, $periodeAkhir) {
                $q->whereBetween('tanggal_kejadian', [$periodeMulai, $periodeAkhir])
                  ->orWhereBetween('report_date', [$periodeMulai, $periodeAkhir]);
            })
            ->whereNotNull('equipment_tag')
            ->groupBy('equipment_tag')
            ->orderBy('total_failures', 'desc');

        if ($filterPt) {
            $query->whereHas('cmEquipment', fn($q) => $q->where('pt_location', $filterPt));
        }

        $worst = $query->take(10)->get();

        $result = [];
        foreach ($worst as $item) {
            $failures = (int) $item->total_failures;
            $mtbf = $failures > 0 ? round($totalHours / $failures, 1) : 0;

            $equipment = CmEquipment::where('equipment_tag', $item->equipment_tag)->first();

            $result[] = [
                'equipment_tag'  => $item->equipment_tag,
                'pt_location'    => $equipment?->pt_location ?? '-',
                'description'    => $equipment?->asset?->description ?? '-',
                'total_failures' => $failures,
                'mtbf'           => $mtbf,
                'first_failure'  => $item->first_failure ? Carbon::parse($item->first_failure)->format('d M Y') : '-',
                'last_failure'   => $item->last_failure ? Carbon::parse($item->last_failure)->format('d M Y') : '-',
            ];
        }

        return $result;
    }

    /**
     * Pareto chart data — frekuensi root_cause atau kategori finding.
     */
    private function getParetoData(Carbon $periodeMulai, Carbon $periodeAkhir, string $filterPt = ''): array
    {
        // Prioritaskan root_cause dari reports
        $query = Report::selectRaw("
                COALESCE(NULLIF(root_cause, ''), 'Tidak disebutkan') as cause,
                COUNT(*) as total
            ")
            ->whereIn('jenis_pekerjaan', ['CM', 'dCM'])
            ->where(function ($q) use ($periodeMulai, $periodeAkhir) {
                $q->whereBetween('tanggal_kejadian', [$periodeMulai, $periodeAkhir])
                  ->orWhereBetween('report_date', [$periodeMulai, $periodeAkhir]);
            })
            ->whereNotNull('root_cause')
            ->groupBy('cause')
            ->orderBy('total', 'desc')
            ->take(10);

        if ($filterPt) {
            $query->whereHas('cmEquipment', fn($q) => $q->where('pt_location', $filterPt));
        }

        $data = $query->get();

        // Fallback ke kategori finding yang ter-link
        if ($data->isEmpty()) {
            $queryFallback = Report::selectRaw("
                    COALESCE(cf.kategori, 'Tidak diketahui') as cause,
                    COUNT(*) as total
                ")
                ->leftJoin('cm_findings as cf', 'cf.id', '=', 'reports.linked_finding_id')
                ->whereIn('reports.jenis_pekerjaan', ['CM', 'dCM'])
                ->where(function ($q) use ($periodeMulai, $periodeAkhir) {
                    $q->whereBetween('reports.tanggal_kejadian', [$periodeMulai, $periodeAkhir])
                      ->orWhereBetween('reports.report_date', [$periodeMulai, $periodeAkhir]);
                })
                ->groupBy('cause')
                ->orderBy('total', 'desc')
                ->take(10);

            if ($filterPt) {
                $queryFallback->whereHas('cmEquipment', fn($q) => $q->where('pt_location', $filterPt));
            }

            $data = $queryFallback->get();
        }

        $result = [];
        $grandTotal = $data->sum('total');

        foreach ($data as $item) {
            $total = (int) $item->total;
            $result[] = [
                'cause' => $item->cause,
                'total' => $total,
                'pct'   => $grandTotal > 0 ? round(($total / $grandTotal) * 100, 1) : 0,
            ];
        }

        return $result;
    }

    /**
     * Konversi angka bulan ke label Indonesia pendek.
     */
    private function bulanLabel(int $bulan): string
    {
        $labels = [
            1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
            5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Agu',
            9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
        ];
        return $labels[$bulan] ?? '';
    }
}


