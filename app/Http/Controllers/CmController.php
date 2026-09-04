<?php

namespace App\Http\Controllers;

use App\Exports\CmFindingsExport;
use App\Exports\CmMonitoringExport;
use App\Exports\CmReadingsExport;
use App\Models\CmEquipment;
use App\Models\CmFinding;
use App\Models\CmMonthlyTracking;
use App\Models\CmReading;
use App\Models\Report;
use App\Models\WorkOrder;
use App\Jobs\ProcessCmExcelImport;
use App\Jobs\SyncGoogleSheetsJob;
use App\Services\Telegram\PhotoStorageService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Rap2hpoutre\FastExcel\FastExcel;

class CmController extends Controller
{
    /**
     * Tampilkan halaman Overview Condition Monitoring.
     */
    public function overview(Request $request)
    {
        $filterPt    = $request->input('pt');
        $filterTahun = $request->input('tahun', now()->year);
        $filterBulan = $request->input('bulan');
        $filterStatus = $request->get('status', '');

        // Query dasar dengan filter
        // Hanya baris dengan minimal satu nilai pengukuran aktual (> 0) yang
        // dihitung — baris semua 0/strip dianggap tidak ada pembacaan.
        $baseQuery = CmReading::query()
            ->whereRaw($this->measureSql());
        if ($filterPt) {
            $baseQuery->whereHas('equipment', fn($q) => $q->where('pt_location', $filterPt));
        }
        if ($filterTahun) {
            $baseQuery->whereYear('tanggal', $filterTahun);
        }
        if ($filterBulan) {
            $baseQuery->whereMonth('tanggal', $filterBulan);
        }
        if ($filterStatus) {
            $baseQuery->where('kondisi', $filterStatus);
        }

        // Data untuk summary cards
        $totalRecords  = (clone $baseQuery)->count();
        $goodCount     = (clone $baseQuery)->where('kondisi', 'good')->count();
        $alarmCount    = (clone $baseQuery)->where('kondisi', 'alarm')->count();
        $dangerCount   = (clone $baseQuery)->where('kondisi', 'danger')->count();
        $visualBadCount = (clone $baseQuery)->where('kondisi', 'visual_bad')->count();

        $goodPct = $totalRecords > 0 ? round(($goodCount / $totalRecords) * 100, 1) : 0;
        $alarmPct = $totalRecords > 0 ? round(($alarmCount / $totalRecords) * 100, 1) : 0;
        $dangerPct = $totalRecords > 0 ? round(($dangerCount / $totalRecords) * 100, 1) : 0;
        $visualBadPct = $totalRecords > 0 ? round(($visualBadCount / $totalRecords) * 100, 1) : 0;

        // Breakdown status per PT untuk donut chart
        $donutQuery = CmReading::selectRaw('cm_equipment.pt_location, cm_readings.kondisi, COUNT(*) as total')
            ->join('cm_equipment', 'cm_equipment.id', '=', 'cm_readings.cm_equipment_id');

        if ($filterPt) {
            $donutQuery->where('cm_equipment.pt_location', $filterPt);
        }
        if ($filterTahun) {
            $donutQuery->whereYear('cm_readings.tanggal', $filterTahun);
        }
        if ($filterBulan) {
            $donutQuery->whereMonth('cm_readings.tanggal', $filterBulan);
        }
        if ($filterStatus) {
            $donutQuery->where('cm_readings.kondisi', $filterStatus);
        }

        $statusPerPt = $donutQuery
            ->groupBy('cm_equipment.pt_location', 'cm_readings.kondisi')
            ->orderBy('cm_equipment.pt_location')
            ->get()
            ->groupBy('pt_location');

        // Trend bulanan stacked
        $trendQuery = CmReading::selectRaw('YEAR(tanggal) as tahun, MONTH(tanggal) as bulan, kondisi, COUNT(*) as total');

        if ($filterPt) {
            $trendQuery->whereHas('equipment', fn($q) => $q->where('pt_location', $filterPt));
        }
        if ($filterTahun) {
            $trendQuery->whereYear('tanggal', $filterTahun);
        }
        if ($filterBulan) {
            $trendQuery->whereMonth('tanggal', $filterBulan);
        }
        if ($filterStatus) {
            $trendQuery->where('kondisi', $filterStatus);
        }

        $trendData = $trendQuery
            ->groupBy('tahun', 'bulan', 'kondisi')
            ->orderBy('tahun')
            ->orderBy('bulan')
            ->get();

        // Top 10 vibrasi tertinggi — latest reading per equipment yg masih DANGER
        // Subquery: ambil (cm_equipment_id, tanggal_max) per equipment
        $latestSub = CmReading::selectRaw('cm_equipment_id, MAX(tanggal) as tanggal_max')
            ->groupBy('cm_equipment_id');

        if ($filterTahun) {
            $latestSub->whereYear('tanggal', $filterTahun);
        }
        if ($filterBulan) {
            $latestSub->whereMonth('tanggal', $filterBulan);
        }

        $topVibrasiQuery = CmReading::selectRaw('r.*, cm_equipment.equipment_tag, cm_equipment.pt_location, GREATEST(COALESCE(r.ndev_motor,0), COALESCE(r.ndeh_motor,0), COALESCE(r.ndea_motor,0), COALESCE(r.dev_motor,0), COALESCE(r.deh_motor,0), COALESCE(r.dea_motor,0)) as max_motor, GREATEST(COALESCE(r.ndev_pompa,0), COALESCE(r.ndeh_pompa,0), COALESCE(r.ndea_pompa,0), COALESCE(r.dev_pompa,0), COALESCE(r.deh_pompa,0), COALESCE(r.dea_pompa,0)) as max_mesin, GREATEST(COALESCE(r.temp_de_motor,0), COALESCE(r.temp_nde_motor,0)) as max_temp_motor, GREATEST(COALESCE(r.temp_de_pompa,0), COALESCE(r.temp_nde_pompa,0)) as max_temp_mesin')
            ->from('cm_readings as r')
            ->joinSub($latestSub, 'latest', function ($join) {
                $join->on('r.cm_equipment_id', '=', 'latest.cm_equipment_id')
                     ->on('r.tanggal', '=', 'latest.tanggal_max');
            })
            ->join('cm_equipment', 'cm_equipment.id', '=', 'r.cm_equipment_id')
            ->where('r.kondisi', 'danger');

        if ($filterPt) {
            $topVibrasiQuery->where('cm_equipment.pt_location', $filterPt);
        }

        $topVibrasi = $topVibrasiQuery
            ->orderByRaw('GREATEST(max_motor, max_mesin) DESC')
            ->take(10)
            ->get();

        // Daftar PT untuk filter
        $ptList = CmEquipment::select('pt_location')->distinct()->pluck('pt_location');

        // Tahun untuk filter
        $tahunList = CmReading::selectRaw('YEAR(tanggal) as tahun')
            ->distinct()
            ->orderBy('tahun', 'desc')
            ->pluck('tahun');

        return view('cm.overview', compact(
            'totalRecords', 'goodCount', 'alarmCount', 'dangerCount', 'visualBadCount',
            'goodPct', 'alarmPct', 'dangerPct', 'visualBadPct',
            'statusPerPt', 'trendData', 'topVibrasi',
            'ptList', 'tahunList',
            'filterPt', 'filterTahun', 'filterBulan', 'filterStatus'
        ));
    }

    /**
     * Tampilkan halaman Finding CM.
     */
    public function findings(Request $request)
    {
        $filterPt     = $request->input('pt');
        $filterStatus = $request->get('status', '');
        $search       = $request->get('search', '');

        $query = CmFinding::with('equipment');

        if ($filterPt) {
            $query->whereHas('equipment', fn($q) => $q->where('pt_location', $filterPt));
        }
        if ($filterStatus) {
            $query->where('status', $filterStatus);
        }
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('deskripsi', 'like', "%{$search}%")
                  ->orWhereHas('equipment', fn($sq) => $sq->where('equipment_tag', 'like', "%{$search}%"));
            });
        }

        $findings = $query->orderBy('tanggal_temuan', 'desc')->paginate(10)->withQueryString();

        // Jika request AJAX, return partial view
        if ($request->ajax() || $request->get('ajax')) {
            return view('cm._findings-list', compact('findings'));
        }

        // Summary cards
        $totalOpen      = CmFinding::where('status', 'open')->count();
        $openOver7      = CmFinding::where('status', 'open')->where('hari_open', '>', 7)->count();
        $totalClosed    = CmFinding::where('status', 'closed')->count();
        $totalFindings  = CmFinding::count();

        $ptList = CmEquipment::select('pt_location')->distinct()->pluck('pt_location');

        return view('cm.findings', compact(
            'findings', 'ptList',
            'filterPt', 'filterStatus', 'search',
            'totalOpen', 'openOver7', 'totalClosed', 'totalFindings'
        ));
    }

    /**
     * Tampilkan detail satu finding berdasarkan kode finding.
     */
    public function findingShow(string $kodeFinding)
    {
        $finding = CmFinding::with(['equipment', 'workOrder'])
            ->where('kode_finding', $kodeFinding)
            ->firstOrFail();

        return view('cm.finding-show', compact('finding'));
    }

    /**
     * Tampilkan halaman CM Monitoring.
     */
    public function monitoring(Request $request)
    {
        $filterTahun = $request->input('tahun', now()->year);
        $hideDone    = $request->boolean('hide_done', false);

        $tahunIni = (int) $filterTahun;
        $bulanIni = (int) now()->format('n');

        // Bulan pertama yang dinilai untuk tahun terpilih.
        // Reporting monitoring baru dimulai Maret 2026, jadi Jan & Feb 2026
        // diabaikan (tidak dihitung progress maupun ditampilkan sebagai belum).
        $bulanMulai = (($tahunIni === 2026) ? 3 : 1);

        // Semua equipment (termasuk yang belum pernah diambil reading, karena
        // equipment bisa valid tetapi datanya memang belum diambil manpower).
        $equipments = CmEquipment::with(['monthlyTrackings' => fn($q) => $q->where('tahun', $tahunIni)])
            ->orderBy('pt_location')
            ->orderBy('equipment_tag')
            ->get();

        // Hitung progress per equipment.
        // Bulan "free" (equipment tidak running/stop) dianggap tercakup dan
        // tidak menurunkan progress, karena bukan keterlambatan sungguhan.
        $equipments->each(function ($eq) use ($tahunIni, $bulanIni, $bulanMulai) {
            // Jumlah bulan yang dinilai pada rentang aktif.
            $totalBulan = ($tahunIni < now()->year)
                ? 12
                : ($bulanIni - $bulanMulai + 1);
            $totalBulan = max($totalBulan, 0);
            $list = $eq->monthlyTrackings->whereIn('status', ['sudah', 'free']);
            $coveredCount = $list->filter(fn($t) => $t->bulan >= $bulanMulai)->count();
            $eq->progress_pct = $totalBulan > 0 ? round(($coveredCount / $totalBulan) * 100, 0) : 0;
            $eq->covered_count = $coveredCount;
            $eq->sudah_count = $list->where('status', 'sudah')->count();
            $eq->free_count = $list->where('status', 'free')->count();
            $eq->total_bulan = $totalBulan;
        });

        // Kelompokkan alert: equipment yang belum ada data bulan berjalan.
        // Equipment berstatus "free" (stop) di bulan berjalan TIDAK dianggap
        // belum; hanya yang benar-benar belum tercatat (belum) yang di-alert.
        $alertEquipments = CmEquipment::whereDoesntHave('monthlyTrackings', function ($q) use ($tahunIni, $bulanIni) {
                $q->where('tahun', $tahunIni)
                    ->where('bulan', $bulanIni)
                    ->whereIn('status', ['sudah', 'free']);
            })->with(['monthlyTrackings' => fn($q) => $q->where('tahun', $tahunIni)->orderBy('bulan', 'desc')])
            ->get();

        // Data terakhir bulan apa per equipment untuk alert.
        // Mengambil bulan TERBESAR yang berstatus "sudah" (data benar-benar
        // diambil), bukan sekadar baris tracking terbesar (yang bisa berupa
        // bulan masa depan berstatus "belum").
        $alertEquipments->each(function ($eq) use ($tahunIni, $bulanIni) {
            $lastTrack = $eq->monthlyTrackings
                ->where('status', 'sudah')
                ->sortByDesc('bulan')
                ->first();

            if ($lastTrack) {
                $eq->last_month_label = $this->bulanLabel($lastTrack->bulan);
            } elseif ($tahunIni >= now()->year) {
                $eq->last_month_label = 'belum ada data';
            } else {
                $eq->last_month_label = 'tidak ada di ' . $tahunIni;
            }
        });

        // Filter hide done
        if ($hideDone) {
            $equipments = $equipments->filter(fn($eq) => $eq->progress_pct < 100);
        }

        // Insight: equipment yang 2 bulan TERAKHIR BERTURUT tidak ada data "sudah".
        // Fokus pada bulan berjalan dan bulan sebelumnya; bulan yang lebih lama
        // (mis. Mar-Apr) tidak lagi di-scoring karena sudah lewat / teratasi.
        // Masuk insight bila KEDUA bulan terakhir berstatus bukan "sudah"
        // (belum-belum, free-free, atau belum-free / free-belum). Stop 2 bulan
        // berturut pun dianggap bermasalah dan wajib di-arrange untuk running.
        // Hanya jika salah satu bulan tersebut "sudah" -> tidak masuk insight.
        $bulanSebelum = $bulanIni - 1;
        $insightEntities = [];
        if ($bulanSebelum >= $bulanMulai) {
            foreach ($equipments as $eq) {
                $trackIni = $eq->monthlyTrackings->firstWhere('bulan', $bulanIni);
                $trackSebelum = $eq->monthlyTrackings->firstWhere('bulan', $bulanSebelum);

                if ($trackIni && $trackSebelum
                    && $trackIni->status !== 'sudah'
                    && $trackSebelum->status !== 'sudah') {
                    $eq->insight_bln = [$bulanSebelum, $bulanIni];
                    $eq->insight_jml = 2;
                    $eq->insight_keterangan = $this->insightKeterangan($trackIni->status, $trackSebelum->status);
                    $insightEntities[] = $eq;
                }
            }
        }

        // Tambahkan label bulan & tautan pada equipment insight.
        foreach ($insightEntities as $eq) {
            $eq->insight_bulan_label = collect($eq->insight_bln)
                ->map(fn($b) => $this->bulanLabel((int) $b))
                ->implode(', ');
        }
        $insightEntities = collect($insightEntities);

        $ptList = CmEquipment::select('pt_location')->distinct()->pluck('pt_location');
        $tahunList = range(now()->year - 2, now()->year);

        // Jumlah total equipment (penyebut pada ringkasan "X dari Y belum")
        $totalEquipments = CmEquipment::count();
        $belumBulanIni = $alertEquipments->count();

        return view('cm.monitoring', compact(
            'equipments', 'ptList', 'tahunList',
            'filterTahun', 'hideDone', 'alertEquipments',
            'tahunIni', 'bulanIni', 'totalEquipments', 'belumBulanIni',
            'bulanMulai', 'insightEntities'
        ));
    }

    /**
     * Tampilkan halaman detail equipment berdasarkan tag.
     * Route /cm/equipment/{tag} dengan filter rentang waktu.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $tag
     * @return \Illuminate\View\View
     */
    public function equipmentShow(Request $request, string $tag)
    {
        $range = $request->get('range', (string) now()->year); // default tahun berjalan

        // Threshold untuk garis bantu di chart trend
        $vibThresholds = config('cm.vibration_insight.thresholds', ['alarm' => 7.1, 'danger' => 11.2]);
        $tempThreshold = config('cm.temperature_high_threshold', 80); // konsisten dengan card stat Temp Maks

        $equipment = CmEquipment::with(['asset.company', 'findings'])
            ->where('equipment_tag', $tag)
            ->firstOrFail();

        // Asset manual (data_source = 'manual') bukan data SAP asli — bisa
        // berupa data sisa percobaan edit. Jangan ditampilkan sebagai data
        // SAP di halaman ini; perlakukan seperti equipment tanpa asset.
        if ($equipment->asset?->data_source === 'manual') {
            $equipment->setRelation('asset', null);
        }

        // Query readings dengan filter tanggal
        $filteredQuery = $equipment->readings()
            ->orderBy('tanggal', 'desc');

        if ($range !== 'all') {
            $filteredQuery->whereYear('tanggal', (int) $range);
        }

        // Ambil semua readings yang difilter untuk data ringkasan
        $filteredReadings = $filteredQuery->get();

        // Data ringkasan dari last reading
        $lastReading = $filteredReadings->first();
        $equipment->current_status = $lastReading?->kondisi ?? 'good';

        // Vibrasi & temperatur maks dari last reading
        $vibMax = '-';
        $tempMax = '-';
        if ($lastReading) {
            $vibValues = array_filter([
                $lastReading->ndev_motor, $lastReading->ndeh_motor, $lastReading->ndea_motor,
                $lastReading->dev_motor,  $lastReading->deh_motor,  $lastReading->dea_motor,
                $lastReading->ndev_pompa, $lastReading->ndeh_pompa, $lastReading->ndea_pompa,
                $lastReading->dev_pompa,  $lastReading->deh_pompa,  $lastReading->dea_pompa,
                $lastReading->ndev_screw, $lastReading->ndeh_screw, $lastReading->ndea_screw,
                $lastReading->dev_screw,  $lastReading->deh_screw,  $lastReading->dea_screw,
            ], fn($v) => $v !== null);
            if (count($vibValues) > 0) {
                $maxV = max($vibValues);
                $vibMax = $maxV > 0 ? number_format($maxV, 1) : '-';
            }

            $tempValues = array_filter([
                $lastReading->temp_de_motor, $lastReading->temp_nde_motor,
                $lastReading->temp_de_pompa, $lastReading->temp_nde_pompa,
                $lastReading->temp_de_screw, $lastReading->temp_nde_screw,
            ], fn($t) => $t !== null);
            if (count($tempValues) > 0) {
                $maxT = max($tempValues);
                $tempMax = $maxT > 0 ? number_format($maxT, 1) : '-';
            }
        }

        // Data JSON untuk Chart.js (sort ascending by tanggal)
        // Nilai 0 (benar-benar 0,000) diabaikan (diubah jadi null) karena
        // dianggap data tidak diambil — Chart.js otomatis membuat gap.
        $trendKeys = [
            'ndev_motor', 'ndeh_motor', 'ndea_motor',
            'dev_motor',  'deh_motor',  'dea_motor',
            'ndev_pompa', 'ndeh_pompa', 'ndea_pompa',
            'dev_pompa',  'deh_pompa',  'dea_pompa',
            'temp_de_motor', 'temp_nde_motor',
            'temp_de_pompa', 'temp_nde_pompa',
        ];
        $chartData = $filteredReadings->sortBy('tanggal')->values()->map(function ($r) use ($trendKeys) {
            $row = ['tanggal' => $r->tanggal->format('Y-m-d')];
            foreach ($trendKeys as $key) {
                $value = $r->{$key};
                $row[$key] = ($value === null || (float) $value == 0) ? null : (float) $value;
            }
            return $row;
        });

        // Readings dengan pagination (10 per halaman) — tetap difilter
        $paginatedQuery = $equipment->readings()
            ->orderBy('tanggal', 'desc');

        if ($range !== 'all') {
            $paginatedQuery->whereYear('tanggal', (int) $range);
        }

        $readings = $paginatedQuery->paginate(10)->appends(['range' => $range]);

        // ------------------------------------------------------------------
        // Total Readings Aktual & Visual Bad
        // ------------------------------------------------------------------
        // "Total Readings" = baris yang punya minimal satu nilai pengukuran
        // aktual (> 0). Baris yang semua nilai pengukurannya 0/NULL (termasuk
        // yang berstatus visual_bad) dianggap TIDAK ada pembacaan instrumen.
        // Baris visual_bad dihitung terpisah karena merupakan catatan visual,
        // bukan pengukuran.
        $measureSql = $this->measureSql();

        $totalReadings = $equipment->readings()
            ->whereRaw($measureSql)
            ->count();

        $totalVisualBad = $equipment->readings()
            ->where('kondisi', 'visual_bad')
            ->count();

        // Hitung total findings open/closed
        $totalFindingsOpen   = $equipment->findings->where('status', 'open')->count();
        $totalFindingsClosed = $equipment->findings->where('status', 'closed')->count();

        // ------------------------------------------------------------------
        // MTBF, Total Failures & Last Failure — dari tabel Reports (Laporan)
        // Sumber: Report dengan jenis_pekerjaan IN ('CM','dCM') untuk
        // equipment ini, dalam rentang filter yang aktif.
        // ------------------------------------------------------------------
        $mtbfData = [
            'mtbf'          => '-',
            'total_failures' => 0,
            'last_failure'   => null,
            'days_since'     => null,
        ];

        $failureReportsQuery = Report::where('equipment_tag', $equipment->equipment_tag)
            ->whereIn('jenis_pekerjaan', ['CM', 'dCM']);

        // Terapkan filter rentang waktu
        if ($range !== 'all') {
            $year = (int) $range;
            $failureReportsQuery->where(function ($q) use ($year) {
                $q->whereYear('tanggal_kejadian', $year)
                  ->orWhere(function ($sq) use ($year) {
                      $sq->whereNull('tanggal_kejadian')
                         ->whereYear('report_date', $year);
                  });
            });
        }

        $failureReports = $failureReportsQuery
            ->orderByRaw('COALESCE(tanggal_kejadian, report_date) ASC')
            ->get(['tanggal_kejadian', 'report_date']);

        if ($failureReports->isNotEmpty()) {
            $totalFailures = $failureReports->count();

            // Last failure = tanggal_kejadian terakhir (atau report_date jika null)
            $lastReport  = $failureReports->last();
            $lastFailure = $lastReport->tanggal_kejadian ?? $lastReport->report_date;
            $daysSince   = $lastFailure ? now()->startOfDay()->diffInDays($lastFailure) : null;

            // Hitung MTBF
            $dates = $failureReports->map(function ($r) {
                return $r->tanggal_kejadian ?? $r->report_date;
            })->filter()->sort()->values();

            if ($dates->count() >= 2) {
                $diffs = [];
                for ($i = 1; $i < $dates->count(); $i++) {
                    $diffs[] = $dates[$i]->diffInDays($dates[$i - 1]);
                }
                $mtbf = count($diffs) > 0 ? array_sum($diffs) / count($diffs) : 0;
            } else {
                // Hanya 1 failure — MTBF = jarak dari tanggal failure ke sekarang
                $mtbf = $dates->first() ? now()->startOfDay()->diffInDays($dates->first()) : 0;
            }

            $mtbfData = [
                'mtbf'           => round($mtbf, 1),
                'total_failures' => $totalFailures,
                'last_failure'   => $lastFailure ? $lastFailure->format('d M Y') : null,
                'days_since'     => $daysSince,
            ];
        }

        // ------------------------------------------------------------------
        // Insight Trend Vibrasi Motor — deteksi slope/kenaikan signifikan
        // ------------------------------------------------------------------
        $vibrationInsight = $this->detectVibrationTrend($chartData);

        // Tahun-tahun yang tersedia untuk dropdown filter
        $availableYears = $equipment->readings()
            ->selectRaw('YEAR(tanggal) as year')
            ->distinct()
            ->orderBy('year', 'desc')
            ->pluck('year')
            ->toArray();
        $currentYear = (int) now()->format('Y');
        if (!in_array($currentYear, $availableYears)) {
            $availableYears[] = $currentYear;
        }
        if (!in_array($currentYear + 1, $availableYears)) {
            $availableYears[] = $currentYear + 1;
        }
        rsort($availableYears);

        return view('cm.equipment-show', compact(
            'equipment',
            'lastReading',
            'vibMax',
            'tempMax',
            'chartData',
            'readings',
            'range',
            'totalReadings',
            'totalVisualBad',
            'totalFindingsOpen',
            'totalFindingsClosed',
            'mtbfData',
            'vibrationInsight',
            'availableYears',
            'vibThresholds',
            'tempThreshold'
        ));
    }

    /**
     * Update informasi spek equipment (tipe_lubrikasi, manufacturer, model_number, construct_year).
     * Dipanggil dari form modal Edit Informasi Spek di halaman detail equipment.
     */
    public function equipmentUpdate(Request $request, string $tag)
    {
        $request->validate([
            'tipe_lubrikasi' => 'nullable|string|max:255',
            'manufacturer'   => 'nullable|string|max:255',
            'model_number'   => 'nullable|string|max:255',
            'construct_year' => 'nullable|integer|min:1900|max:2099',
        ]);

        $equipment = CmEquipment::where('equipment_tag', $tag)->firstOrFail();

        $updateData = [];
        if ($request->has('tipe_lubrikasi')) {
            $updateData['tipe_lubrikasi'] = $request->input('tipe_lubrikasi');
        }

        if (!empty($updateData)) {
            $equipment->update($updateData);
        }

        // Update relasi Asset jika ada
        $assetUpdate = [];
        if ($request->has('manufacturer')) {
            $assetUpdate['manufacturer'] = $request->input('manufacturer');
        }
        if ($request->has('model_number')) {
            $assetUpdate['model_number'] = $request->input('model_number');
        }
        if ($request->has('construct_year')) {
            $assetUpdate['construct_year'] = $request->input('construct_year');
        }
        if ($equipment->asset && !empty($assetUpdate)) {
            $equipment->asset->update($assetUpdate);
        }

        return redirect()->route('cm.equipment-show', $tag)->with('success', 'Informasi spek berhasil diperbarui.');
    }

    /**
     * Hapus satu reading (riwayat pembacaan) secara manual dari halaman
     * detail equipment. Finding yang merujuk ke reading ini otomatis
     * ter-lepas (FK cm_reading_id nullOnDelete), dan status tracking
     * bulanan dihitung ulang untuk bulan reading tersebut.
     *
     * @param  CmReading $reading Reading yang akan dihapus
     * @return \Illuminate\Http\RedirectResponse
     */
    public function readingDestroy(CmReading $reading)
    {
        try {
            $equipmentTag = $reading->equipment?->equipment_tag ?? '-';
            $tanggal      = $reading->tanggal ? Carbon::parse($reading->tanggal)->format('d/m/Y') : '-';

            $reading->delete();

            // Hitung ulang tracking SETELAH delete, agar reading yang baru
            // dihapus tidak ikut terhitung sebagai sisa di bulan tersebut.
            $this->refreshMonthlyTrackingForReading($reading);

            return back()->with('success', "Reading {$equipmentTag} ({$tanggal}) berhasil dihapus.");
        } catch (\Exception $e) {
            Log::error('Gagal hapus reading #' . $reading->id . ': ' . $e->getMessage());

            return back()->with('error', 'Gagal menghapus reading. Silakan coba lagi.');
        }
    }

    /**
     * Hitung ulang status tracking bulanan untuk equipment & bulan dari
     * reading yang akan dihapus. Jika tidak ada reading tersisa di bulan
     * itu, baris tracking dihapus; jika masih ada, status ditentukan ulang
     * dari sisa reading (ada yang START = sudah, hanya Stop = free).
     *
     * @param  CmReading $reading Reading yang sedang dihapus
     * @return void
     */
    private function refreshMonthlyTrackingForReading(CmReading $reading): void
    {
        if (!$reading->tanggal) {
            return;
        }

        $tahun = (int) $reading->tanggal->format('Y');
        $bulan = (int) $reading->tanggal->format('n');

        $sisaReadings = CmReading::where('cm_equipment_id', $reading->cm_equipment_id)
            ->whereYear('tanggal', $tahun)
            ->whereMonth('tanggal', $bulan)
            ->get();

        if ($sisaReadings->isEmpty()) {
            CmMonthlyTracking::where('cm_equipment_id', $reading->cm_equipment_id)
                ->where('tahun', $tahun)
                ->where('bulan', $bulan)
                ->delete();

            return;
        }

        $adaStart = $sisaReadings->contains(function ($r) {
            return str_contains(strtoupper(trim((string) $r->status)), 'START');
        });

        CmMonthlyTracking::updateOrCreate(
            [
                'cm_equipment_id' => $reading->cm_equipment_id,
                'tahun'           => $tahun,
                'bulan'           => $bulan,
            ],
            ['status' => $adaStart ? 'sudah' : 'free']
        );
    }

    /**
     * Hapus satu finding secara manual (dari halaman detail equipment atau
     * daftar finding). Work order yang terhubung otomatis ter-lepas
     * (FK linked_finding_id nullOnDelete) dan foto finding ikut dihapus
     * dari storage.
     *
     * @param  CmFinding $finding Finding yang akan dihapus
     * @return \Illuminate\Http\RedirectResponse
     */
    public function findingDestroy(CmFinding $finding)
    {
        try {
            $kode = $finding->kode_finding ?? '#' . $finding->id;

            $fotoPaths = collect($finding->foto_urls ?? [])
                ->push($finding->foto_url)
                ->filter()
                ->unique()
                ->values()
                ->toArray();

            $finding->delete();

            if (!empty($fotoPaths)) {
                app(PhotoStorageService::class)->delete($fotoPaths);
            }

            return back()->with('success', "Finding {$kode} berhasil dihapus.");
        } catch (\Exception $e) {
            Log::error('Gagal hapus finding #' . $finding->id . ': ' . $e->getMessage());

            return back()->with('error', 'Gagal menghapus finding. Silakan coba lagi.');
        }
    }

    /**
     * Tarik data CM langsung dari spreadsheet Google Sheets online.
     * Spreadsheet di-download sebagai xlsx lalu import dijadwalkan lewat
     * queued job yang sama dengan upload manual. Response JSON jika request
     * meminta JSON (tombol sync di halaman Overview), selain itu redirect.
     *
     * @param  Request $request
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function syncGoogleSheets(Request $request)
    {
        set_time_limit(300);

        try {
            $results = SyncGoogleSheetsJob::runSync();
        } catch (\Exception $e) {
            Log::error('Sync Google Sheets gagal: ' . $e->getMessage());

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sync Google Sheets gagal: ' . $e->getMessage(),
                ]);
            }

            return back()->with('error', 'Sync Google Sheets gagal: ' . $e->getMessage());
        }

        $messages = [];
        foreach ($results as $key => $result) {
            $label      = $key === 'data_cm' ? 'Data CM' : 'Finding CM';
            $messages[] = $label . ': ' . $result['message'];
        }

        $hasError = collect($results)->contains('status', 'error');
        $summary  = 'Sync Google Sheets: ' . implode(' | ', $messages);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => !$hasError,
                'message' => $summary,
            ]);
        }

        return back()->with(
            $hasError ? 'warning' : 'success',
            $summary
        );
    }

    /**
     * Tampilkan halaman Equipment Status — daftar equipment dengan
     * search, filter PT, filter status, dan ringkasan vibrasi/temperatur.
     *
     * @param  Request  $request
     * @return \Illuminate\View\View
     */
    public function equipmentStatus(Request $request)
    {
        $filterPt     = $request->input('pt');
        $filterStatus = $request->get('status', []);
        $filterStatus = is_string($filterStatus) ? array_filter(explode(',', $filterStatus)) : (array) $filterStatus;
        $filterStatus = array_values(array_intersect($filterStatus, ['good', 'alarm', 'danger', 'visual_bad']));
        $search       = $request->get('search', '');

        $query = CmEquipment::with(['asset.company']);

        // Filter PT
        if ($filterPt) {
            $query->where('pt_location', $filterPt);
        }

        // Filter status — cari equipment dengan last reading sesuai status
        if ($filterStatus) {
            $query->whereHas('readings', function ($q) use ($filterStatus) {
                $q->whereIn('kondisi', $filterStatus)
                  ->whereIn('id', function ($sub) {
                      $sub->selectRaw('MAX(id)')
                          ->from('cm_readings')
                          ->whereColumn('cm_equipment_id', 'cm_equipment.id');
                  });
            });
        }

        // Search — cari tag, PT, plant, atau nama/model asset
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('equipment_tag', 'like', "%{$search}%")
                  ->orWhere('pt_location', 'like', "%{$search}%")
                  ->orWhere('plant', 'like', "%{$search}%")
                  ->orWhereHas('asset', function ($sq) use ($search) {
                      $sq->where('description', 'like', "%{$search}%")
                         ->orWhere('model_number', 'like', "%{$search}%");
                  });
            });
        }

        $equipments = $query->orderBy('pt_location')
            ->orderBy('equipment_tag')
            ->paginate(20)
            ->withQueryString();

        // Load reading terakhir untuk ringkasan vibrasi & temperatur
        $equipments->load(['readings' => function ($q) {
            $q->orderBy('tanggal', 'desc');
        }]);

        $equipments->each(function ($eq) {
            $lastReading = $eq->readings->first();
            $eq->current_status     = $lastReading?->kondisi ?? 'good';
            $eq->last_reading_date  = $lastReading?->tanggal;

            // Ringkasan nilai maks vibrasi & temperatur dari reading terakhir
            if ($lastReading) {
                $vibMax = max(
                    $lastReading->ndev_motor ?? 0, $lastReading->ndeh_motor ?? 0, $lastReading->ndea_motor ?? 0,
                    $lastReading->dev_motor ?? 0,  $lastReading->deh_motor ?? 0,  $lastReading->dea_motor ?? 0,
                    $lastReading->ndev_pompa ?? 0, $lastReading->ndeh_pompa ?? 0, $lastReading->ndea_pompa ?? 0,
                    $lastReading->dev_pompa ?? 0,  $lastReading->deh_pompa ?? 0,  $lastReading->dea_pompa ?? 0
                );
                $tempMax = max(
                    $lastReading->temp_de_motor ?? 0,  $lastReading->temp_nde_motor ?? 0,
                    $lastReading->temp_de_pompa ?? 0,  $lastReading->temp_nde_pompa ?? 0
                );
                $eq->last_vibration = $vibMax > 0 ? number_format($vibMax, 1) : '-';
                $eq->last_temp      = $tempMax > 0 ? number_format($tempMax, 1) : '-';
            } else {
                $eq->last_vibration = '-';
                $eq->last_temp      = '-';
            }
        });

        $ptList = CmEquipment::select('pt_location')->distinct()->pluck('pt_location');

        return view('cm.equipment-status', compact(
            'equipments', 'ptList',
            'filterPt', 'filterStatus', 'search'
        ));
    }

    /**
     * Tampilkan halaman Report & Analysis.
     *
     * Menyediakan data untuk:
     * 1. Section "Equipment Vibrasi Tinggi per PT" — breakdown 3 kolom per PT
     * 2. Section "Ranking Analisa Bulanan" — tabel trend per kategori (semua PT)
     * 3. Insight analisa otomatis rule-based
     * 4. Export Analisa Vibrasi (Excel)
     */
    public function reportAnalysis(Request $request)
    {
        $filterTahun = $request->input('tahun', now()->year);
        $filterPt    = $request->input('pt');
        $filterBulan = $request->input('bulan');

        // Threshold dari config
        $vibThreshold = config('cm.high_vibration.threshold', 4.5);
        $statusFilter = config('cm.high_vibration.status_filter', ['alarm', 'danger']);

        // ---------------------------------------------------------------
        // SECTION 1: Equipment Vibrasi Tinggi per PT (LATEST PER EQUIPMENT)
        // ---------------------------------------------------------------
        // Pendekatan Opsi A:
        // 1. Latest reading = ALARM/DANGER + vib > threshold → tampilkan
        // 2. Latest reading = GOOD/VISUAL_BAD vib ≤ 0.5, tapi PERNAH
        //    alarm/danger di 3 bulan sebelumnya → fallback ke reading
        //    alarm/danger TERAKHIR (trouble belum selesai, cuma blm terdata)
        // STEP 1: Cari reading TERBARU per equipment (self-join by MAX tanggal).
        // PENTING: pakai MAX(tanggal) karena id TIDAK mencerminkan urutan
        // tanggal (ada anomali — id lebih besar tapi tanggal lebih kecil).
        $latestReadingIds = DB::table('cm_readings as r1')
            ->select('r1.id')
            ->whereRaw('r1.tanggal = (
                select MAX(r2.tanggal) from cm_readings r2
                where r2.cm_equipment_id = r1.cm_equipment_id
            )')
            ->pluck('r1.id');

        // STEP 2a: Cari ID reading TERAKHIR yang ALARM/DANGER vib>threshold
        // per equipment (untuk fallback Opsi A)
        $lastAlarmDangerIds = DB::table('cm_readings as r1')
            ->select(DB::raw('MAX(r1.id) as id'))
            ->whereIn('r1.kondisi', $statusFilter)
            ->where('r1.max_vibration', '>', $vibThreshold)
            ->groupBy('r1.cm_equipment_id')
            ->pluck('id');

        // STEP 2b: Cari ID reading terakhir yang PUNYA analysis per equipment
        // (untuk fallback analysis jika reading hasil fallback analysis-nya kosong)
        $latestAnalysisIds = DB::table('cm_readings as r1')
            ->select(DB::raw('MAX(r1.id) as id'))
            ->whereNotNull('r1.analysis')
            ->where('r1.analysis', '!=', '')
            ->groupBy('r1.cm_equipment_id')
            ->pluck('id');

        // STEP 2c: Ambil SEMUA data yang relevan — gabung via UNION logic
        // Set A: Latest reading ALARM/DANGER (masih aktif)
        // Set B: Latest GOOD/VISUAL_BAD vib≤0.5, fallback ke alarm/danger terakhir
        $alarmDangerLatest = DB::table('cm_readings')
            ->join('cm_equipment', 'cm_equipment.id', '=', 'cm_readings.cm_equipment_id')
            ->whereIn('cm_readings.id', $latestReadingIds)
            ->whereIn('cm_readings.kondisi', $statusFilter)
            ->where('cm_readings.max_vibration', '>', $vibThreshold);

        // Equipment yang latest GOOD/VISUAL_BAD vib≤0.5 — ambil reading
        // alarm/danger TERAKHIR-nya sebagai fallback
        $goodLowLatestIds = DB::table('cm_readings')
            ->whereIn('id', $latestReadingIds)
            ->whereIn('kondisi', ['good', 'visual_bad'])
            ->where('max_vibration', '<=', 0.5)
            ->pluck('cm_equipment_id');

        $fallbackSet = DB::table('cm_readings')
            ->join('cm_equipment', 'cm_equipment.id', '=', 'cm_readings.cm_equipment_id')
            ->whereIn('cm_readings.id', $lastAlarmDangerIds)
            ->whereIn('cm_readings.cm_equipment_id', $goodLowLatestIds);

        // Gabung filter
        if ($filterTahun) {
            $alarmDangerLatest->whereYear('cm_readings.tanggal', $filterTahun);
            $fallbackSet->whereYear('cm_readings.tanggal', $filterTahun);
        }
        if ($filterBulan) {
            $alarmDangerLatest->whereMonth('cm_readings.tanggal', $filterBulan);
            $fallbackSet->whereMonth('cm_readings.tanggal', $filterBulan);
        }
        if ($filterPt) {
            $alarmDangerLatest->where('cm_equipment.pt_location', $filterPt);
            $fallbackSet->where('cm_equipment.pt_location', $filterPt);
        }

        // LEFT JOIN ke analysis fallback untuk kedua set
        $analysisFallbackTable = DB::table('cm_readings')
            ->select('cm_equipment_id', 'analysis as fallback_analysis')
            ->whereIn('id', $latestAnalysisIds);

        $alarmDangerRows = (clone $alarmDangerLatest)
            ->leftJoinSub($analysisFallbackTable, 'fb_ad', 'fb_ad.cm_equipment_id', '=', 'cm_readings.cm_equipment_id')
            ->select(
                DB::raw("'aktif' as source"),
                'cm_equipment.pt_location',
                DB::raw("COALESCE(NULLIF(cm_readings.analysis, ''), fb_ad.fallback_analysis) as analysis"),
                'cm_readings.cm_equipment_id',
                'cm_equipment.equipment_tag',
                'cm_readings.kondisi',
                'cm_readings.tanggal'
            );

        $fallbackRows = (clone $fallbackSet)
            ->leftJoinSub($analysisFallbackTable, 'fb_fb', 'fb_fb.cm_equipment_id', '=', 'cm_readings.cm_equipment_id')
            ->select(
                DB::raw("'fallback' as source"),
                'cm_equipment.pt_location',
                DB::raw("COALESCE(NULLIF(cm_readings.analysis, ''), fb_fb.fallback_analysis) as analysis"),
                'cm_readings.cm_equipment_id',
                'cm_equipment.equipment_tag',
                'cm_readings.kondisi',
                'cm_readings.tanggal'
            );

        // Union kedua set — pakai raw SQL karena Eloquent tidak mendukung union
        // antar query builder yang sudah di-joinSub.
        // CATATAN KOMPATIBILITAS: MySQL menerima tanda kurung di sekitar
        // subquery UNION "(SELECT...) UNION (SELECT...)", namun SQLite menolaknya
        // (error "near \"(\": syntax error"). Bentuk tanpa tanda kurung
        // "SELECT ... UNION SELECT ..." valid di kedua driver.
        $unionSql = $alarmDangerRows->toSql() . ' UNION ' . $fallbackRows->toSql();
        $unionBindings = array_merge(
            $alarmDangerRows->getBindings(),
            $fallbackRows->getBindings()
        );

        $latestRows = collect(DB::select($unionSql, $unionBindings))
            ->filter(function ($row) {
                return !empty($row->analysis);
            })
            ->values();

        // Pastikan unik per equipment (fallback tidak boleh duplikat dengan aktif)
        $latestRows = $latestRows->unique('cm_equipment_id')->values();

        // Daftar PT
        $ptListForCards = $filterPt
            ? [$filterPt]
            : $latestRows->pluck('pt_location')->unique()->sort()->values()->toArray();

        $ptBreakdown = [];

        foreach ($ptListForCards as $pt) {
            $ptRows = $latestRows->where('pt_location', $pt);
            $totalPt = $ptRows->count();

            if ($totalPt === 0) {
                $ptBreakdown[$pt] = ['total' => 0, 'categories' => []];
                continue;
            }

            // Group by analysis — sudah 1 per equipment (pakai Collection)
            $catGroups = $ptRows->groupBy('analysis')->sortByDesc(function ($group) {
                return $group->count();
            });

            $categories = [];
            $rank = 1;
            foreach ($catGroups as $analysisName => $group) {
                $count = $group->count();
                $pct = $totalPt > 0 ? round(($count / $totalPt) * 100, 1) : 0;

                // Sample equipment (max 10, unik per tag)
                $sampleTags = $group->pluck('equipment_tag')->unique()->take(10);
                $equipments = $sampleTags->map(function ($tag) use ($group) {
                    $row = $group->firstWhere('equipment_tag', $tag);
                    $bulanLabel = $row->tanggal
                        ? $this->bulanLabel((int) \Carbon\Carbon::parse($row->tanggal)->format('n')) . '-' . \Carbon\Carbon::parse($row->tanggal)->format('y')
                        : '';
                    return [
                        'tag'    => $tag,
                        'status' => $row->kondisi,
                        'bulan'  => $bulanLabel,
                    ];
                    });

                $categories[] = [
                    'rank'        => $rank++,
                    'name'        => $analysisName,
                    'count'       => $count,
                    'percentage'  => $pct,
                    'equipments'  => $equipments,
                ];
            }

            $ptBreakdown[$pt] = [
                'total'      => $totalPt,
                'categories' => $categories,
            ];
        }

        // ---------------------------------------------------------------
        // SECTION 2: Ranking Analisa Bulanan (tabel trend per kategori)
        // ---------------------------------------------------------------
        // SEMUA histori kejadian (bukan latest-only) — supaya trend bulanan
        // tetap valid untuk melihat pola historis, meskipun equipment sudah
        // membaik di bulan berikutnya.
        $trendAllQuery = CmReading::query()
            ->join('cm_equipment', 'cm_equipment.id', '=', 'cm_readings.cm_equipment_id')
            ->whereIn('cm_readings.kondisi', $statusFilter)
            ->where('cm_readings.max_vibration', '>', $vibThreshold)
            ->whereNotNull('cm_readings.analysis')
            ->where('cm_readings.analysis', '!=', '');

        if ($filterTahun) { $trendAllQuery->whereYear('cm_readings.tanggal', $filterTahun); }
        if ($filterBulan) { $trendAllQuery->whereMonth('cm_readings.tanggal', $filterBulan); }
        if ($filterPt) { $trendAllQuery->where('cm_equipment.pt_location', $filterPt); }

        $trendRaw = (clone $trendAllQuery)
            ->selectRaw('cm_readings.analysis, MONTH(cm_readings.tanggal) as bulan, COUNT(DISTINCT cm_readings.cm_equipment_id) as total')
            ->groupBy('cm_readings.analysis', 'bulan')
            ->orderBy('cm_readings.analysis')
            ->orderBy('bulan')
            ->get();

        // Kumpulkan semua kategori dan bulan
        $allCategories = $trendRaw->pluck('analysis')->unique()->values()->toArray();
        $allMonths = range(1, 12);

        // Bangun tabel ranking
        $rankingTable = [];
        $totalsPerBulan = array_fill_keys($allMonths, 0);
        $grandTotalAll = 0;

        foreach ($allCategories as $cat) {
            $row = ['analysis' => $cat];
            $rowTotal = 0;
            foreach ($allMonths as $m) {
                $val = $trendRaw->firstWhere(function ($item) use ($cat, $m) {
                    return $item->analysis === $cat && (int) $item->bulan === $m;
                });
                $count = $val ? (int) $val->total : 0;
                $row[$m] = $count;
                $rowTotal += $count;
                $totalsPerBulan[$m] += $count;
            }
            $row['total'] = $rowTotal;
            $grandTotalAll += $rowTotal;
            $rankingTable[] = $row;
        }

        // Urutkan berdasarkan total terbesar (ranking)
        usort($rankingTable, fn($a, $b) => $b['total'] <=> $a['total']);

        // Tambahkan rank (setelah sorting)
        $rankedTable = [];
        $rank = 1;
        foreach ($rankingTable as &$row) {
            $row['rank'] = $rank++;
            $rankedTable[] = $row;
        }

        // ---------------------------------------------------------------
        // SECTION 3: Insight Analisa Otomatis (Rule-Based)
        // ---------------------------------------------------------------
        $insight = null;

        if (!empty($rankedTable) && $rankedTable[0]['total'] > 0) {
            $topCategory = $rankedTable[0];

            // Kategori dominan
            $dominantCatName = $topCategory['analysis'];
            $dominantTotal = $topCategory['total'];

            // Cari PT dengan proporsi tertinggi untuk kategori dominan
            $ptProporsi = [];
            foreach ($ptBreakdown as $pt => $ptData) {
                foreach ($ptData['categories'] as $cat) {
                    if ($cat['name'] === $dominantCatName) {
                        $ptProporsi[$pt] = [
                            'count' => $cat['count'],
                            'pct'   => $cat['percentage'],
                            'total' => $ptData['total'],
                        ];
                        break;
                    }
                }
            }

            // PT dengan proporsi tertinggi
            $topPt = null;
            $topPtPct = 0;
            foreach ($ptProporsi as $pt => $prop) {
                if ($prop['pct'] > $topPtPct) {
                    $topPt = $pt;
                    $topPtPct = $prop['pct'];
                }
            }

            // Trend naik/turun — bandingkan 2 bulan terakhir
            $trendDirection = null;
            $monthsWithData = array_filter($allMonths, fn($m) => $topCategory[$m] > 0);
            $sortedMonths = array_values($monthsWithData);
            if (count($sortedMonths) >= 2) {
                $lastMonth = end($sortedMonths);
                $prevMonth = prev($sortedMonths);
                $lastVal = $topCategory[$lastMonth];
                $prevVal = $topCategory[$prevMonth];

                if ($lastVal > $prevVal) {
                    $diff = $lastVal - $prevVal;
                    $trendDirection = "naik {$diff} kasus dari bulan sebelumnya";
                } elseif ($lastVal < $prevVal) {
                    $diff = $prevVal - $lastVal;
                    $trendDirection = "turun {$diff} kasus dari bulan sebelumnya";
                } else {
                    $trendDirection = "stabil (sama dengan bulan sebelumnya)";
                }
            } else {
                $trendDirection = "data baru tersedia untuk 1 bulan";
            }

            $insight = [
                'dominant_category' => $dominantCatName,
                'dominant_total'    => $dominantTotal,
                'top_pt'            => $topPt,
                'top_pt_pct'        => $topPtPct,
                'trend_direction'   => $trendDirection,
            ];
        }

        // ---------------------------------------------------------------
        // DATA UNTUK RINGKASAN UMUM (existing)
        // ---------------------------------------------------------------
        $totalEquipment = CmEquipment::when($filterPt, fn($q) => $q->where('pt_location', $filterPt))->count();

        $totalReadingsQuery = CmReading::when($filterPt, fn($q) => $q->whereHas('equipment', fn($sq) => $sq->where('pt_location', $filterPt)));
        if ($filterTahun) { $totalReadingsQuery->whereYear('tanggal', $filterTahun); }
        $totalReadings = $totalReadingsQuery->count();

        $totalFindings = CmFinding::when($filterPt, fn($q) => $q->whereHas('equipment', fn($sq) => $sq->where('pt_location', $filterPt)))->count();

        $openFindings = CmFinding::where('status', 'open')
            ->when($filterPt, fn($q) => $q->whereHas('equipment', fn($sq) => $sq->where('pt_location', $filterPt)))
            ->count();

        $monitoringProgress = CmMonthlyTracking::where('tahun', $filterTahun)
            ->when($filterPt, fn($q) => $q->whereHas('equipment', fn($sq) => $sq->where('pt_location', $filterPt)))
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $monitoringSudah = (int) ($monitoringProgress['sudah']->total ?? 0);
        $monitoringTotal = $monitoringProgress->sum('total');
        $monitoringPct = $monitoringTotal > 0 ? round(($monitoringSudah / $monitoringTotal) * 100, 1) : 0;

        // Daftar bulan untuk filter
        $bulanList = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ];

        $ptList = CmEquipment::select('pt_location')->distinct()->pluck('pt_location');
        $tahunList = range(now()->year - 2, now()->year);

        return view('cm.report-analysis', compact(
            'totalEquipment', 'totalReadings', 'totalFindings', 'openFindings',
            'monitoringSudah', 'monitoringTotal', 'monitoringPct',
            'ptList', 'tahunList', 'filterTahun', 'filterPt', 'filterBulan',
            'bulanList',
            'ptBreakdown', 'rankedTable', 'allMonths', 'insight',
        ));
    }

    /**
     * Ambil data JSON untuk chart trend bulanan.
     */
    public function trendChartData(Request $request)
    {
        $filterPt    = $request->input('pt');
        $filterTahun = $request->get('tahun', '');
        $filterBulan = $request->input('bulan');
        $filterStatus = $request->get('status', '');

        $query = CmReading::selectRaw('YEAR(tanggal) as tahun, MONTH(tanggal) as bulan, kondisi, COUNT(*) as total');

        if ($filterPt) {
            $query->whereHas('equipment', fn($q) => $q->where('pt_location', $filterPt));
        }
        if ($filterTahun) {
            $query->whereYear('tanggal', $filterTahun);
        }
        if ($filterBulan) {
            $query->whereMonth('tanggal', $filterBulan);
        }
        if ($filterStatus) {
            $query->where('kondisi', $filterStatus);
        }

        $data = $query
            ->groupBy('tahun', 'bulan', 'kondisi')
            ->orderBy('tahun')
            ->orderBy('bulan')
            ->get();

        // Format untuk chart
        $months = collect();
        $grouped = $data->groupBy(fn($d) => $d->tahun . '-' . str_pad($d->bulan, 2, '0', STR_PAD_LEFT));

        $result = [];
        foreach ($grouped as $period => $items) {
            $row = ['period' => $period];
            $row['good'] = $items->where('kondisi', 'good')->sum('total');
            $row['alarm'] = $items->where('kondisi', 'alarm')->sum('total');
            $row['danger'] = $items->where('kondisi', 'danger')->sum('total');
            $row['visual_bad'] = $items->where('kondisi', 'visual_bad')->sum('total');
            $result[] = $row;
        }

        return response()->json($result);
    }

    /**
     * Ambil data JSON untuk chart donut (breakdown per PT).
     */
    public function donutData(Request $request)
    {
        $filterPt    = $request->input('pt');
        $filterTahun = $request->get('tahun', '');
        $filterBulan = $request->input('bulan');
        $filterStatus = $request->get('status', '');

        $query = CmReading::selectRaw('cm_equipment.pt_location, cm_readings.kondisi, COUNT(*) as total')
            ->join('cm_equipment', 'cm_equipment.id', '=', 'cm_readings.cm_equipment_id');

        if ($filterPt) {
            $query->where('cm_equipment.pt_location', $filterPt);
        }
        if ($filterTahun) {
            $query->whereYear('cm_readings.tanggal', $filterTahun);
        }
        if ($filterBulan) {
            $query->whereMonth('cm_readings.tanggal', $filterBulan);
        }
        if ($filterStatus) {
            $query->where('cm_readings.kondisi', $filterStatus);
        }

        $data = $query
            ->groupBy('cm_equipment.pt_location', 'cm_readings.kondisi')
            ->orderBy('cm_equipment.pt_location')
            ->get()
            ->groupBy('pt_location');

        $result = [];
        foreach ($data as $pt => $items) {
            $result[$pt] = ['good' => 0, 'alarm' => 0, 'danger' => 0, 'visual_bad' => 0];
            foreach ($items as $item) {
                $result[$pt][$item->kondisi] = (int) $item->total;
            }
        }

        return response()->json($result);
    }

    /**
     * Render partial summary cards via AJAX.
     */
    public function overviewSummary(Request $request)
    {
        $filterPt    = $request->input('pt');
        $filterTahun = $request->get('tahun', '');
        $filterBulan = $request->input('bulan');
        $filterStatus = $request->get('status', '');

        $query = CmReading::query()
            ->whereRaw($this->measureSql());
        if ($filterPt) {
            $query->whereHas('equipment', fn($q) => $q->where('pt_location', $filterPt));
        }
        if ($filterTahun) {
            $query->whereYear('tanggal', $filterTahun);
        }
        if ($filterBulan) {
            $query->whereMonth('tanggal', $filterBulan);
        }
        if ($filterStatus) {
            $query->where('kondisi', $filterStatus);
        }

        $totalRecords  = (clone $query)->count();
        $goodCount     = (clone $query)->where('kondisi', 'good')->count();
        $alarmCount    = (clone $query)->where('kondisi', 'alarm')->count();
        $dangerCount   = (clone $query)->where('kondisi', 'danger')->count();
        $visualBadCount = (clone $query)->where('kondisi', 'visual_bad')->count();

        $goodPct     = $totalRecords > 0 ? round(($goodCount / $totalRecords) * 100, 1) : 0;
        $alarmPct    = $totalRecords > 0 ? round(($alarmCount / $totalRecords) * 100, 1) : 0;
        $dangerPct   = $totalRecords > 0 ? round(($dangerCount / $totalRecords) * 100, 1) : 0;
        $visualBadPct = $totalRecords > 0 ? round(($visualBadCount / $totalRecords) * 100, 1) : 0;

        return view('cm._overview-cards', compact(
            'totalRecords', 'goodCount', 'goodPct',
            'alarmCount', 'alarmPct',
            'dangerCount', 'dangerPct',
            'visualBadCount', 'visualBadPct'
        ));
    }

        /**
     * Deteksi trend kenaikan vibrasi motor (NDEV, NDEH, NDEA) dari data
     * chart yang sudah di-sort ascending. Mengembalikan array insight
     * atau null jika tidak ada kenaikan signifikan.
     *
     * Hanya data non-zero yang diproses - data vibrasi 0 mm/s berarti
     * equipment belum running / belum diambil, tidak dianggap tren.
     *
     * @param  \Illuminate\Support\Collection  $chartData
     * @return array|null
     */
    private function detectVibrationTrend($chartData): ?array
    {
        $config = config('cm.vibration_insight');
        $minPoints = $config['min_data_points'] ?? 3;
        $risePct = $config['significant_rise_pct'] ?? 20;
        $alarmThreshold = $config['alarm_threshold'] ?? 4.5;
        $dangerThreshold = $config['danger_threshold'] ?? 10.0;

        $params = ['ndev_motor', 'ndeh_motor', 'ndea_motor'];
        $insight = null;

        foreach ($params as $param) {
            $validPoints = collect($chartData)
                ->filter(fn($d) => isset($d[$param]) && $d[$param] !== null && $d[$param] > 0)
                ->values();

            if ($validPoints->count() < $minPoints) {
                continue;
            }

            $first = $validPoints->first()[$param];
            $last  = $validPoints->last()[$param];

            if ($first <= 0 || $last <= 0) {
                continue;
            }

            $pctChange = $first > 0 ? (($last - $first) / $first) * 100 : 0;

            if ($pctChange >= $risePct && $last > $first) {
                $firstDate = \Carbon\Carbon::parse($validPoints->first()['tanggal']);
                $lastDate  = \Carbon\Carbon::parse($validPoints->last()['tanggal']);
                $daysSpan  = $firstDate->diffInDays($lastDate);

                $projectedDaysToAlarm = null;
                $projectedDaysToDanger = null;
                if ($daysSpan > 0) {
                    $slope = ($last - $first) / $daysSpan;
                    if ($slope > 0) {
                        if ($alarmThreshold > $last) {
                            $projectedDaysToAlarm = ceil(($alarmThreshold - $last) / $slope);
                        }
                        if ($dangerThreshold > $last) {
                            $projectedDaysToDanger = ceil(($dangerThreshold - $last) / $slope);
                        }
                    }
                }

                $paramLabels = [
                    'ndev_motor' => 'NDEV Motor',
                    'ndeh_motor' => 'NDEH Motor',
                    'ndea_motor' => 'NDEA Motor',
                ];
                $label = $paramLabels[$param] ?? $param;

                $msg = "Tren vibrasi {$label} naik signifikan (dari " . number_format($first, 2) . " mm/s ke " . number_format($last, 2) . " mm/s dalam {$daysSpan} hari terakhir, kenaikan " . round($pctChange, 1) . "%).";

                $insight = [
                    'parameter' => $param,
                    'from_val' => $first,
                    'to_val' => $last,
                    'days_span' => $daysSpan,
                    'projected_days_to_alarm' => $projectedDaysToAlarm,
                    'projected_days_to_danger' => $projectedDaysToDanger,
                    'message' => $msg,
                ];

                if ($param === 'ndea_motor') {
                    break;
                }
            }
        }

        return $insight;
    }

    /**
     * Konversi angka bulan ke label Indonesia.
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

    /**
     * Buat keterangan singkat untuk insight 2 bulan terakhir tanpa data "sudah".
     *
     * @param  string  $statusIni      Status pada bulan berjalan.
     * @param  string  $statusSebelum  Status pada bulan sebelumnya.
     */
    private function insightKeterangan(string $statusIni, string $statusSebelum): string
    {
        if ($statusIni === 'free' && $statusSebelum === 'free') {
            return 'stop 2 bulan berturut';
        }
        if ($statusIni === 'free' || $statusSebelum === 'free') {
            return 'belum / stop';
        }
        return 'belum 2 bulan berturut';
    }

    /**
     * SQL condition untuk baris yang punya minimal satu nilai pengukuran
     * aktual (> 0). Baris yang semua nilai pengukurannya 0/NULL dianggap
     * TIDAK ada pembacaan instrumen (0 / strip), sehingga tidak dihitung
     * dalam Total Records / Total Readings.
     *
     * @return string
     */
    private function measureSql(): string
    {
        $columns = [
            'ndev_motor', 'ndeh_motor', 'ndea_motor',
            'dev_motor', 'deh_motor', 'dea_motor',
            'ndev_pompa', 'ndeh_pompa', 'ndea_pompa',
            'dev_pompa', 'deh_pompa', 'dea_pompa',
            'ndev_screw', 'ndeh_screw', 'ndea_screw',
            'dev_screw', 'deh_screw', 'dea_screw',
            'temp_de_motor', 'temp_nde_motor',
            'temp_de_pompa', 'temp_nde_pompa',
            'temp_de_screw', 'temp_nde_screw',
            'ampere', 'discharge_pressure',
        ];

        return '(' . implode(' OR ', array_map(fn($c) => "`{$c}` > 0", $columns)) . ')';
    }

    // ====================================================================
    // EXPORT METHODS
    // ====================================================================

    /**
     * Export readings ke Excel.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function exportReadings(Request $request)
    {
        $filterPt    = $request->input('pt');
        $filterTahun = $request->get('tahun', '');
        $filterBulan = $request->input('bulan');
        $filterStatus = $request->get('status', '');

        $exporter = new CmReadingsExport();
        return $exporter->export(
            $filterPt ?: null,
            $filterTahun ?: null,
            $filterBulan ?: null,
            $filterStatus ?: null
        );
    }

    /**
     * Export findings ke Excel.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function exportFindings(Request $request)
    {
        $filterPt     = $request->input('pt');
        $filterStatus = $request->get('status', '');

        $exporter = new CmFindingsExport();
        return $exporter->export(
            $filterPt ?: null,
            $filterStatus ?: null
        );
    }

    /**
     * Export monitoring monthly tracking ke Excel.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function exportMonitoring(Request $request)
    {
        $filterTahun = $request->get('tahun', '');
        $filterPt    = $request->input('pt');

        $exporter = new CmMonitoringExport();
        return $exporter->export(
            $filterTahun ?: null,
            $filterPt ?: null
        );
    }

    /**
     * Export Analisa Vibrasi ke Excel.
     *
     * Data yang di-export mencakup:
     * 1. Sheet "Per PT" — breakdown equipment vibrasi tinggi per kategori per PT
     * 2. Sheet "Ranking Bulanan" — tabel ranking kategori per bulan (semua PT)
     *
     * Filter tahun, bulan, dan PT diterapkan sama seperti halaman Report & Analysis.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function exportAnalysis(Request $request)
    {
        $filterTahun = $request->input('tahun', now()->year);
        $filterPt    = $request->input('pt');
        $filterBulan = $request->input('bulan');

        $vibThreshold = config('cm.high_vibration.threshold', 4.5);
        $statusFilter = config('cm.high_vibration.status_filter', ['alarm', 'danger']);

        // --- Sheet 1: Breakdown per PT ---
        // Hanya equipment dengan reading terakhir alarm/danger
        $latestReadingSub = CmReading::selectRaw('cm_equipment_id, MAX(tanggal) as max_tanggal')
            ->groupBy('cm_equipment_id');

        $vibEqIds = CmReading::select('cm_readings.cm_equipment_id')
            ->joinSub($latestReadingSub, 'latest', function ($join) {
                $join->on('cm_readings.cm_equipment_id', '=', 'latest.cm_equipment_id')
                     ->on('cm_readings.tanggal', '=', 'latest.max_tanggal');
            })
            ->whereIn('cm_readings.kondisi', $statusFilter)
            ->where('cm_readings.max_vibration', '>', $vibThreshold)
            ->whereNotNull('cm_readings.analysis')
            ->where('cm_readings.analysis', '!=', '');

        if ($filterTahun) { $vibEqIds->whereYear('cm_readings.tanggal', $filterTahun); }
        if ($filterBulan) { $vibEqIds->whereMonth('cm_readings.tanggal', $filterBulan); }
        if ($filterPt) {
            $vibEqIds->whereIn('cm_readings.cm_equipment_id', function ($q) use ($filterPt) {
                $q->select('id')->from('cm_equipment')->where('pt_location', $filterPt);
            });
        }

        $vibEqIds = $vibEqIds->distinct()->pluck('cm_equipment_id');

        $baseQuery = CmReading::join('cm_equipment', 'cm_equipment.id', '=', 'cm_readings.cm_equipment_id')
            ->whereIn('cm_readings.cm_equipment_id', $vibEqIds)
            ->whereIn('cm_readings.kondisi', $statusFilter)
            ->where('cm_readings.max_vibration', '>', $vibThreshold)
            ->whereNotNull('cm_readings.analysis')
            ->where('cm_readings.analysis', '!=', '');

        $sheet1Data = (clone $baseQuery)
            ->select(
                'cm_equipment.pt_location',
                'cm_equipment.equipment_tag',
                'cm_equipment.plant',
                'cm_readings.analysis',
                'cm_readings.kondisi',
                'cm_readings.max_vibration',
                'cm_readings.max_temp',
                'cm_readings.tanggal'
            )
            ->orderBy('cm_equipment.pt_location')
            ->orderBy('cm_readings.analysis')
            ->orderBy('cm_equipment.equipment_tag')
            ->get()
            ->map(function ($item) {
                return [
                    'PT'             => $item->pt_location,
                    'Plant'          => $item->plant,
                    'Equipment Tag'  => $item->equipment_tag,
                    'Analysis'       => $item->analysis,
                    'Kondisi'        => $item->kondisi,
                    'Max Vibration'  => $item->max_vibration,
                    'Max Temp'       => $item->max_temp,
                    'Tanggal'        => $item->tanggal ? $item->tanggal->format('Y-m-d') : '',
                ];
            });

        // --- Sheet 2: Ranking Bulanan ---
        $trendBase = CmReading::join('cm_equipment', 'cm_equipment.id', '=', 'cm_readings.cm_equipment_id')
            ->whereIn('cm_readings.kondisi', $statusFilter)
            ->where('cm_readings.max_vibration', '>', $vibThreshold)
            ->whereNotNull('cm_readings.analysis')
            ->where('cm_readings.analysis', '!=', '');

        if ($filterTahun) { $trendBase->whereYear('cm_readings.tanggal', $filterTahun); }
        if ($filterBulan) { $trendBase->whereMonth('cm_readings.tanggal', $filterBulan); }

        $trendRaw = (clone $trendBase)
            ->selectRaw('cm_readings.analysis, MONTH(cm_readings.tanggal) as bulan, COUNT(*) as total')
            ->groupBy('cm_readings.analysis', 'bulan')
            ->orderBy('cm_readings.analysis')
            ->orderBy('bulan')
            ->get();

        $allCategories = $trendRaw->pluck('analysis')->unique()->values()->toArray();
        $allMonths = range(1, 12);

        $rankingRows = [];
        foreach ($allCategories as $cat) {
            $row = ['Kategori' => $cat];
            $rowTotal = 0;
            foreach ($allMonths as $m) {
                $val = $trendRaw->firstWhere(function ($item) use ($cat, $m) {
                    return $item->analysis === $cat && (int) $item->bulan === $m;
                });
                $count = $val ? (int) $val->total : 0;
                $label = $this->bulanLabel($m);
                $row[$label] = $count;
                $rowTotal += $count;
            }
            $row['Total'] = $rowTotal;
            $rankingRows[] = $row;
        }

        usort($rankingRows, fn($a, $b) => $b['Total'] <=> $a['Total']);

        $bulanLabels = array_map(fn($m) => $this->bulanLabel($m), $allMonths);

        // Buat data flat untuk FastExcel (2 sheet via separate files approach — we'll combine inline)
        // FastExcel hanya support single sheet, jadi kita gabung jadi 1 sheet dengan separator header
        // Atau lebih baik: gunakan pendekatan file terpisah / manual spreadsheet
        // Alternatif: gunakan Laravel Excel / PhpSpreadsheet langsung

        // Karena FastExcel tidak mendukung multi-sheet dengan mudah,
        // kita buat satu file dengan dua bagian yang dipisah baris kosong.
        // Atau lebih praktis: buat 2 file terpisah dalam satu ZIP.
        // Paling sederhana: export sebagai 1 sheet dengan kolom berbeda.

        // Pendekatan: export 1 sheet detail + 1 sheet rekap dalam file berbeda? Tidak user-friendly.
        // Lebih baik: gunakan PhpSpreadsheet langsung untuk multi-sheet.
        // Tapi constraint: aplikasi pakai FastExcel. Alternatif: buat 2 sheet via PhpSpreadsheet.

        // Gunakan PhpSpreadsheet langsung untuk multi-sheet
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

        // --- Sheet 1: Per PT ---
        $sheet1 = $spreadsheet->getActiveSheet();
        $sheet1->setTitle('Per PT');

        // Header
        $sheet1Headers = ['PT', 'Plant', 'Equipment Tag', 'Analysis', 'Kondisi', 'Max Vibration (mm/s)', 'Max Temp (C)', 'Tanggal'];
        $col = 'A';
        foreach ($sheet1Headers as $header) {
            $sheet1->setCellValue($col . '1', $header);
            $sheet1->getStyle($col . '1')->getFont()->setBold(true);
            $col++;
        }

        // Data
        $rowNum = 2;
        foreach ($sheet1Data as $row) {
            $sheet1->setCellValue('A' . $rowNum, $row['PT']);
            $sheet1->setCellValue('B' . $rowNum, $row['Plant']);
            $sheet1->setCellValue('C' . $rowNum, $row['Equipment Tag']);
            $sheet1->setCellValue('D' . $rowNum, $row['Analysis']);
            $sheet1->setCellValue('E' . $rowNum, $row['Kondisi']);
            $sheet1->setCellValue('F' . $rowNum, $row['Max Vibration']);
            $sheet1->setCellValue('G' . $rowNum, $row['Max Temp']);
            $sheet1->setCellValue('H' . $rowNum, $row['Tanggal']);
            $rowNum++;
        }

        // Auto-size columns
        foreach (range('A', 'H') as $colLetter) {
            $sheet1->getColumnDimension($colLetter)->setAutoSize(true);
        }

        // --- Sheet 2: Ranking Bulanan ---
        $sheet2 = $spreadsheet->createSheet();
        $sheet2->setTitle('Ranking Bulanan');

        // Header
        $sheet2Headers = ['Rank', 'Kategori'];
        foreach ($bulanLabels as $label) {
            $sheet2Headers[] = $label;
        }
        $sheet2Headers[] = 'Total';

        $col = 'A';
        foreach ($sheet2Headers as $header) {
            $sheet2->setCellValue($col . '1', $header);
            $sheet2->getStyle($col . '1')->getFont()->setBold(true);
            $col++;
        }

        // Data
        $rowNum = 2;
        $rank = 1;
        $totalsRow = array_fill(0, count($bulanLabels), 0);
        $grandTotal = 0;

        foreach ($rankingRows as $row) {
            $sheet2->setCellValue('A' . $rowNum, $rank);
            $sheet2->setCellValue('B' . $rowNum, $row['Kategori']);

            $colIdx = 0;
            foreach ($bulanLabels as $label) {
                $val = $row[$label] ?? 0;
                $sheet2->setCellValue(chr(67 + $colIdx) . $rowNum, $val);
                $totalsRow[$colIdx] += $val;
                $colIdx++;
            }

            $sheet2->setCellValue(chr(67 + count($bulanLabels)) . $rowNum, $row['Total']);
            $grandTotal += $row['Total'];
            $rank++;
            $rowNum++;
        }

        // Baris Total
        $sheet2->setCellValue('A' . $rowNum, '');
        $sheet2->setCellValue('B' . $rowNum, 'Total');
        $sheet2->getStyle('B' . $rowNum)->getFont()->setBold(true);
        $colIdx = 0;
        foreach ($bulanLabels as $label) {
            $sheet2->setCellValue(chr(67 + $colIdx) . $rowNum, $totalsRow[$colIdx]);
            $sheet2->getStyle(chr(67 + $colIdx) . $rowNum)->getFont()->setBold(true);
            $colIdx++;
        }
        $sheet2->setCellValue(chr(67 + count($bulanLabels)) . $rowNum, $grandTotal);
        $sheet2->getStyle(chr(67 + count($bulanLabels)) . $rowNum)->getFont()->setBold(true);

        // Auto-size
        $maxCol = chr(67 + count($bulanLabels));
        foreach (range('A', $maxCol) as $colLetter) {
            $sheet2->getColumnDimension($colLetter)->setAutoSize(true);
        }

        // Output
        $filename = 'analisa-vibrasi-' . now()->format('Ymd-His') . '.xlsx';

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);

        // Stream download
        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
