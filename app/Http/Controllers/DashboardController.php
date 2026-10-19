<?php

namespace App\Http\Controllers;

use App\Models\AiProvider;
use App\Models\AiUsageLog;
use App\Models\Asset;
use App\Models\BotRegistration;
use App\Models\BotUnknownAsset;
use App\Models\CmEquipment;
use App\Models\CmFinding;
use App\Models\CmReading;
use App\Models\IpAddress;
use App\Models\ItAsset;
use App\Models\ItNotification;
use App\Models\PanelPembacaan;
use App\Models\PanelRuangan;
use App\Models\Report;
use App\Models\SpImpor;
use App\Models\SpMaterial;
use App\Models\Technician;
use App\Models\WorkOrder;
use App\Support\WarnaJenisPekerjaan;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard utama lintas modul.
 *
 * Halaman ini merangkum indikator operasional dari modul yang boleh diakses
 * akun yang sedang login. Modul yang tidak diizinkan tidak dihitung dan tidak
 * ditampilkan, sehingga dashboard tidak menjadi jalan pintas untuk melihat
 * data di luar hak akses Bagian.
 */
class DashboardController extends Controller
{
    /** Pilihan periode ringkasan yang didukung. */
    private const PERIODE = [7, 30, 90];

    /**
     * Tampilkan dashboard utama.
     *
     * @param  Request $request Request halaman (parameter ?periode=7|30|90)
     * @return \Illuminate\View\View
     */
    public function index(Request $request)
    {
        $pengguna = $request->user();

        $akses = [
            'assets'       => $pengguna->punyaAkses('assets'),
            'technicians'  => $pengguna->punyaAkses('technicians'),
            'reports'      => $pengguna->punyaAkses('reports'),
            'cm'           => $pengguna->punyaAkses('cm'),
            'reliability'  => $pengguna->punyaAkses('reliability'),
            'sparepart'    => $pengguna->punyaAkses('sparepart'),
            'it_assets'    => $pengguna->punyaAkses('it_assets'),
            'panel'        => $pengguna->punyaAkses('panel'),
            'work_orders'  => $pengguna->punyaAkses('work_orders'),
            'ai_providers' => $pengguna->punyaAkses('ai_providers'),
            'bot'          => $pengguna->punyaAkses('bot'),
        ];

        $periode = (int) $request->query('periode', 30);

        if (! in_array($periode, self::PERIODE, true)) {
            $periode = 30;
        }

        $mulai = now()->subDays($periode - 1)->startOfDay();
        $akhir = now()->endOfDay();

        $laporan    = $akses['reports'] ? $this->dataLaporan($mulai, $akhir, $periode) : null;
        $aset       = $akses['assets'] ? $this->dataAset() : null;
        $teknisi    = $akses['technicians'] ? $this->dataTeknisi() : null;
        $cm         = $akses['cm'] ? $this->dataCm($mulai, $akhir) : null;
        $reliability = $akses['reliability'] ? $this->dataReliability($mulai, $akhir, $periode) : null;
        $sparepart  = $akses['sparepart'] ? $this->dataSparepart() : null;
        $it         = $akses['it_assets'] ? $this->dataIt() : null;
        $panel      = $akses['panel'] ? $this->dataPanel() : null;
        $workOrder  = $akses['work_orders'] ? $this->dataWorkOrder() : null;
        $ai         = $akses['ai_providers'] ? $this->dataAi($mulai) : null;
        $bot        = $akses['bot'] ? $this->dataBot() : null;

        $recentReports = $akses['reports']
            ? Report::with(['technician:id,name', 'area:id,name'])
                ->latest('report_date')
                ->latest('id')
                ->take(8)
                ->get()
            : collect();

        $kpi       = $this->kartuKpi($akses, $laporan, $aset, $teknisi, $cm, $sparepart, $it, $panel, $workOrder);
        $perhatian = $this->daftarPerhatian($akses, $cm, $sparepart, $it, $panel, $bot, $ai, $workOrder);

        return view('dashboard.index', [
            'akses'         => $akses,
            'periode'       => $periode,
            'labelPeriode'  => $periode . ' hari terakhir',
            'mulai'         => $mulai,
            'akhir'         => $akhir,
            'diperbarui'    => now(),
            'laporan'       => $laporan,
            'aset'          => $aset,
            'teknisi'       => $teknisi,
            'cm'            => $cm,
            'reliability'   => $reliability,
            'sparepart'     => $sparepart,
            'it'            => $it,
            'panel'         => $panel,
            'workOrder'     => $workOrder,
            'ai'            => $ai,
            'bot'           => $bot,
            'recentReports' => $recentReports,
            'kpi'           => $kpi,
            'perhatian'     => $perhatian,
        ]);
    }

    /**
     * Ringkasan laporan dan data grafik laporan.
     *
     * @param  Carbon $mulai   Awal periode
     * @param  Carbon $akhir   Akhir periode
     * @param  int    $periode Jumlah hari
     * @return array<string, mixed>
     */
    private function dataLaporan(Carbon $mulai, Carbon $akhir, int $periode): array
    {
        $dari = $mulai->toDateString();
        $sampai = $akhir->toDateString();

        $base = Report::query()->whereBetween('report_date', [$dari, $sampai]);

        $total = (clone $base)->count();

        $status = (clone $base)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $selesai   = (int) ($status['completed'] ?? 0);
        $review    = (int) ($status['needs_review'] ?? 0);
        $lanjut    = (int) ($status['continue'] ?? 0);
        $lainnya   = max(0, $total - $selesai - $review - $lanjut);
        $hariIni   = Report::whereDate('report_date', now()->toDateString())->count();
        $menit     = (int) (clone $base)->sum('work_duration_minutes');

        // Tren dihitung per JENIS PEKERJAAN, bukan per status. Dua mode:
        // jumlah laporan masuk dan jumlah teknisi unik yang melapor.
        $trendRaw = (clone $base)
            ->selectRaw("DATE(report_date) as tanggal, COALESCE(NULLIF(jenis_pekerjaan, ''), 'Lainnya') as jenis, COUNT(*) as total, COUNT(DISTINCT technician_id) as teknisi")
            ->groupByRaw("DATE(report_date), COALESCE(NULLIF(jenis_pekerjaan, ''), 'Lainnya')")
            ->get();

        $petaJenis = [];
        $totalPerJenis = [];

        foreach ($trendRaw as $baris) {
            $tanggal = (string) $baris->tanggal;
            $jenis   = (string) $baris->jenis;

            $petaJenis[$tanggal][$jenis] = [
                'laporan' => (int) $baris->total,
                'teknisi' => (int) $baris->teknisi,
            ];

            $totalPerJenis[$jenis] = ($totalPerJenis[$jenis] ?? 0) + (int) $baris->total;
        }

        arsort($totalPerJenis);

        $labels  = [];
        $tanggal = [];

        for ($i = 0; $i < $periode; $i++) {
            $hari      = $mulai->copy()->addDays($i);
            $labels[]  = $hari->format('d M');
            $tanggal[] = $hari->toDateString();
        }

        $trenJenis = ['labels' => $labels, 'datasets' => []];
        $trenTeknisi = ['labels' => $labels, 'datasets' => []];

        foreach (array_keys($totalPerJenis) as $jenis) {
            $dataLaporan = [];
            $dataTeknisi = [];

            foreach ($tanggal as $hari) {
                $dataLaporan[] = (int) ($petaJenis[$hari][$jenis]['laporan'] ?? 0);
                $dataTeknisi[] = (int) ($petaJenis[$hari][$jenis]['teknisi'] ?? 0);
            }

            $warna = $jenis === 'Lainnya'
                ? WarnaJenisPekerjaan::CADANGAN
                : WarnaJenisPekerjaan::hex($jenis);

            $trenJenis['datasets'][] = [
                'label'           => $jenis,
                'data'            => $dataLaporan,
                'backgroundColor' => $warna,
                'borderRadius'    => 4,
                'stack'           => 'jenis',
            ];

            $trenTeknisi['datasets'][] = [
                'label'           => $jenis,
                'data'            => $dataTeknisi,
                'backgroundColor' => $warna,
                'borderRadius'    => 4,
                'stack'           => 'jenis',
            ];
        }

        $jenis = (clone $base)
            ->selectRaw("COALESCE(NULLIF(jenis_pekerjaan, ''), 'Lainnya') as label, COUNT(*) as total")
            ->groupByRaw("COALESCE(NULLIF(jenis_pekerjaan, ''), 'Lainnya')")
            ->orderByRaw('COUNT(*) DESC')
            ->limit(8)
            ->get()
            ->map(fn ($baris): array => [
                'label' => (string) $baris->label,
                'total' => (int) $baris->total,
                'warna' => $baris->label === 'Lainnya'
                    ? WarnaJenisPekerjaan::CADANGAN
                    : WarnaJenisPekerjaan::hex((string) $baris->label),
            ])
            ->all();

        $topTeknisi = DB::table('reports as r')
            ->join('technicians as t', 't.id', '=', 'r.technician_id')
            ->whereBetween('r.report_date', [$dari, $sampai])
            ->whereNotNull('r.technician_id')
            ->selectRaw('t.name as nama, COUNT(*) as laporan, COALESCE(SUM(r.work_duration_minutes), 0) as menit')
            ->groupBy('t.id', 't.name')
            ->orderByRaw('COUNT(*) DESC')
            ->limit(7)
            ->get()
            ->map(fn ($baris): array => [
                'nama'    => (string) $baris->nama,
                'laporan' => (int) $baris->laporan,
                'jam'     => round(((int) $baris->menit) / 60, 1),
            ])
            ->all();

        return [
            'total'      => $total,
            'hari_ini'   => $hariIni,
            'selesai'    => $selesai,
            'review'     => $review,
            'lanjut'     => $lanjut,
            'lainnya'    => $lainnya,
            'jam'        => round($menit / 60, 1),
            'tren_jenis'    => $trenJenis,
            'tren_teknisi'  => $trenTeknisi,
            'status'     => [
                'labels' => ['Selesai', 'Perlu review', 'Dilanjutkan', 'Lainnya'],
                'data'   => [$selesai, $review, $lanjut, $lainnya],
            ],
            'jenis'      => $jenis,
            'top_teknisi' => $topTeknisi,
        ];
    }

    /**
     * Ringkasan aset dan sebaran per departemen.
     *
     * @return array<string, mixed>
     */
    private function dataAset(): array
    {
        $total  = Asset::count();
        $aktif  = Asset::where('status', 'active')->count();

        $perDepartemen = DB::table('assets as a')
            ->leftJoin('areas as ar', 'ar.id', '=', 'a.area_id')
            ->leftJoin('departments as d', 'd.id', '=', 'ar.department_id')
            ->selectRaw("COALESCE(d.name, ar.name, 'Tanpa area') as label, COUNT(*) as total")
            ->groupByRaw("COALESCE(d.name, ar.name, 'Tanpa area')")
            ->orderByRaw('COUNT(*) DESC')
            ->limit(8)
            ->get()
            ->map(fn ($baris): array => [
                'label' => (string) $baris->label,
                'total' => (int) $baris->total,
            ])
            ->all();

        return [
            'total'          => $total,
            'aktif'          => $aktif,
            'nonaktif'       => max(0, $total - $aktif),
            'per_departemen' => $perDepartemen,
        ];
    }

    /**
     * Ringkasan teknisi dan sebaran Bagian/Group.
     *
     * @return array<string, mixed>
     */
    private function dataTeknisi(): array
    {
        $total   = Technician::count();
        $aktif   = Technician::where('status', 'active')->count();
        $pending = Technician::where('status', 'pending')->count();

        $perBagian = Technician::query()
            ->selectRaw('section, COUNT(*) as total')
            ->groupBy('section')
            ->orderByRaw('COUNT(*) DESC')
            ->get()
            ->map(fn ($baris): array => [
                'label' => Technician::SECTIONS[(string) $baris->section] ?? ucfirst((string) $baris->section ?: 'Lainnya'),
                'total' => (int) $baris->total,
            ])
            ->all();

        return [
            'total'      => $total,
            'aktif'      => $aktif,
            'pending'    => $pending,
            'per_bagian' => $perBagian,
        ];
    }

    /**
     * Ringkasan Condition Monitoring: kondisi pembacaan dan temuan.
     *
     * @param  Carbon $mulai Awal periode
     * @param  Carbon $akhir Akhir periode
     * @return array<string, mixed>
     */
    private function dataCm(Carbon $mulai, Carbon $akhir): array
    {
        $dari = $mulai->toDateString();
        $sampai = $akhir->toDateString();

        $kondisi = CmReading::query()
            ->whereBetween('tanggal', [$dari, $sampai])
            ->selectRaw('kondisi, COUNT(*) as total')
            ->groupBy('kondisi')
            ->pluck('total', 'kondisi')
            ->all();

        $totalBaca = array_sum($kondisi);

        $temuanTerbuka = CmFinding::where('status', 'open')->count();
        $temuanTutup   = CmFinding::where('status', 'closed')->count();

        $severity = CmFinding::query()
            ->where('status', 'open')
            ->selectRaw('severity, COUNT(*) as total')
            ->groupBy('severity')
            ->pluck('total', 'severity')
            ->all();

        $kategori = CmFinding::query()
            ->where('status', 'open')
            ->selectRaw("COALESCE(NULLIF(kategori, ''), 'Lainnya') as label, COUNT(*) as total")
            ->groupByRaw("COALESCE(NULLIF(kategori, ''), 'Lainnya')")
            ->orderByRaw('COUNT(*) DESC')
            ->limit(6)
            ->get()
            ->map(fn ($baris): array => [
                'label' => (string) $baris->label,
                'total' => (int) $baris->total,
            ])
            ->all();

        $terbaru = CmReading::query()
            ->selectRaw('cm_equipment_id, MAX(tanggal) as tgl')
            ->groupBy('cm_equipment_id');

        $danger = DB::table('cm_readings as r')
            ->joinSub($terbaru, 'l', function ($join): void {
                $join->on('r.cm_equipment_id', '=', 'l.cm_equipment_id')
                    ->on('r.tanggal', '=', 'l.tgl');
            })
            ->join('cm_equipment as e', 'e.id', '=', 'r.cm_equipment_id')
            ->where('r.kondisi', 'danger')
            ->selectRaw('e.equipment_tag, MAX(r.tanggal) as tanggal, MAX(r.max_vibration) as vibrasi, MAX(r.max_temp) as suhu')
            ->groupBy('e.equipment_tag')
            ->orderByDesc('tanggal')
            ->limit(6)
            ->get()
            ->map(fn ($baris): array => [
                'tag'      => (string) $baris->equipment_tag,
                'tanggal'  => (string) $baris->tanggal,
                'vibrasi'  => $baris->vibrasi === null ? null : (float) $baris->vibrasi,
                'suhu'     => $baris->suhu === null ? null : (float) $baris->suhu,
            ])
            ->all();

        return [
            'equipment'       => CmEquipment::count(),
            'total_baca'      => (int) $totalBaca,
            'good'            => (int) ($kondisi['good'] ?? 0),
            'alarm'           => (int) ($kondisi['alarm'] ?? 0),
            'danger'          => (int) ($kondisi['danger'] ?? 0),
            'visual_bad'      => (int) ($kondisi['visual_bad'] ?? 0),
            'temuan_terbuka'  => $temuanTerbuka,
            'temuan_tutup'    => $temuanTutup,
            'severity'        => $severity,
            'kategori'        => $kategori,
            'equipment_danger' => $danger,
        ];
    }

    /**
     * Indikator Reliability: MTBF, rasio pekerjaan, dan tingkat keberhasilan.
     *
     * @param  Carbon $mulai   Awal periode
     * @param  Carbon $akhir   Akhir periode
     * @param  int    $periode Jumlah hari
     * @return array<string, mixed>
     */
    private function dataReliability(Carbon $mulai, Carbon $akhir, int $periode): array
    {
        $dari = $mulai->toDateString();
        $sampai = $akhir->toDateString();

        $base = Report::query()->whereBetween('report_date', [$dari, $sampai]);

        $cm  = (clone $base)->where('jenis_pekerjaan', 'CM')->count();
        $dcm = (clone $base)->where('jenis_pekerjaan', 'dCM')->count();
        $pm  = (clone $base)->where('jenis_pekerjaan', 'PM')->count();

        $dcmSukses = (clone $base)
            ->where('jenis_pekerjaan', 'dCM')
            ->where('sesuai_rencana', 'ya')
            ->count();

        $cmTanpaTemuan = (clone $base)
            ->where('jenis_pekerjaan', 'CM')
            ->whereNull('linked_finding_id')
            ->count();

        $failure = $cm + $dcm;
        $equipment = CmEquipment::count();
        $fleetJam = $equipment * $periode * 24;

        return [
            'mtbf'          => $failure > 0 ? round($fleetJam / $failure, 1) : 0.0,
            'failure'       => $failure,
            'cm'            => $cm,
            'dcm'           => $dcm,
            'pm'            => $pm,
            'defer_sukses'  => $dcm > 0 ? round(($dcmSukses / $dcm) * 100, 1) : 0.0,
            'defer_total'   => $dcm,
            'undetected'    => $cm > 0 ? round(($cmTanpaTemuan / $cm) * 100, 1) : 0.0,
            'cm_tanpa_temuan' => $cmTanpaTemuan,
        ];
    }

    /**
     * Ringkasan modul Integrasi PR/PO Spare Part.
     *
     * @return array<string, mixed>
     */
    private function dataSparepart(): array
    {
        $perAksi = SpMaterial::query()
            ->whereNotNull('aksi')
            ->selectRaw('aksi, COUNT(*) as total')
            ->groupBy('aksi')
            ->pluck('total', 'aksi')
            ->all();

        $aksi = [];

        foreach (SpMaterial::AKSI as $kode) {
            $aksi[] = [
                'kode'  => $kode,
                'label' => SpMaterial::labelAksi($kode),
                'total' => (int) ($perAksi[$kode] ?? 0),
            ];
        }

        $terakhir = SpImpor::latest('id')->first();

        return [
            'total'       => SpMaterial::count(),
            'reorder'     => SpMaterial::where('reorder', true)->count(),
            'pr_terbuka'  => (int) SpMaterial::sum('pr_terbuka'),
            'po_terbuka'  => (int) SpMaterial::sum('po_open'),
            'aksi'        => $aksi,
            'terakhir'    => $terakhir,
        ];
    }

    /**
     * Ringkasan perangkat IT, status ping, dan pemakaian IP.
     *
     * @return array<string, mixed>
     */
    private function dataIt(): array
    {
        $total   = ItAsset::count();
        $aktif   = ItAsset::where('status', 'active')->count();
        $spare   = ItAsset::where('status', 'spare')->count();

        $ping = ItAsset::query()
            ->selectRaw('last_ping_status, COUNT(*) as total')
            ->groupBy('last_ping_status')
            ->pluck('total', 'last_ping_status')
            ->all();

        $online  = (int) ($ping['online'] ?? 0);
        $offline = (int) ($ping['offline'] ?? 0);
        $unknown = max(0, $total - $online - $offline);

        $ipTotal     = IpAddress::count();
        $ipTerpakai  = IpAddress::where('status', 'assigned')->count();
        $ipTersedia  = IpAddress::where('status', 'available')->count();

        $perJaringan = DB::table('ip_networks as n')
            ->leftJoin('ip_addresses as a', 'a.ip_network_id', '=', 'n.id')
            ->selectRaw("n.vlan, n.network, COUNT(a.id) as total, SUM(CASE WHEN a.status = 'assigned' THEN 1 ELSE 0 END) as terpakai")
            ->groupBy('n.id', 'n.vlan', 'n.network')
            ->orderBy('n.vlan')
            ->get()
            ->map(function ($baris): array {
                $total = (int) $baris->total;
                $pakai = (int) ($baris->terpakai ?? 0);

                return [
                    'vlan'     => (string) $baris->vlan,
                    'network'  => (string) $baris->network,
                    'total'    => $total,
                    'terpakai' => $pakai,
                    'persen'   => $total > 0 ? round(($pakai / $total) * 100, 1) : 0.0,
                ];
            })
            ->all();

        $lastPing = ItAsset::max('last_ping_at');

        return [
            'total'           => $total,
            'aktif'           => $aktif,
            'spare'           => $spare,
            'online'          => $online,
            'offline'         => $offline,
            'unknown'         => $unknown,
            'ip_total'        => $ipTotal,
            'ip_terpakai'     => $ipTerpakai,
            'ip_tersedia'     => $ipTersedia,
            'ip_persen'       => $ipTotal > 0 ? round(($ipTerpakai / $ipTotal) * 100, 1) : 0.0,
            'per_jaringan'    => $perJaringan,
            'notifikasi_baru' => ItNotification::where('is_read', false)->count(),
            'terakhir_ping'   => $lastPing === null ? null : Carbon::parse($lastPing),
        ];
    }

    /**
     * Ringkasan ruang panel dan deret suhu/kelembapan terakhir.
     *
     * @return array<string, mixed>
     */
    private function dataPanel(): array
    {
        $ruangan = PanelRuangan::with('pembacaanTerakhir')->orderBy('nama_ruangan')->get();

        $daftar = $ruangan->map(function (PanelRuangan $r): array {
            $pembacaan = $r->pembacaanTerakhir;

            return [
                'id'             => $r->id,
                'nama'           => (string) $r->nama_ruangan,
                'lokasi'         => $r->labelLokasi(),
                'status'         => (string) ($r->status_terakhir ?? 'belum ada'),
                'online'         => (string) $r->status_terakhir === 'online',
                'kondisi'        => $r->kondisiSuhu($pembacaan?->suhu),
                'suhu'           => $pembacaan?->suhu,
                'kelembapan'     => $pembacaan?->kelembapan,
                'waktu'          => $pembacaan?->waktu,
                'terakhir_ditarik' => $r->terakhir_ditarik,
                'ambang_atas'    => $r->ambang_suhu_atas,
                'ambang_bawah'   => $r->ambang_suhu_bawah,
            ];
        })->all();

        $utama = $ruangan
            ->filter(fn (PanelRuangan $r): bool => $r->pembacaanTerakhir !== null)
            ->sortByDesc(fn (PanelRuangan $r) => $r->pembacaanTerakhir->waktu?->timestamp ?? 0)
            ->first() ?? $ruangan->first();

        $series = [];

        if ($utama !== null) {
            $series = PanelPembacaan::query()
                ->where('panel_ruangan_id', $utama->id)
                ->latest('waktu')
                ->limit(24)
                ->get()
                ->reverse()
                ->map(fn (PanelPembacaan $p): array => [
                    'label'       => $p->waktu?->format('H:i') ?? '-',
                    'suhu'        => $p->suhu,
                    'kelembapan'  => $p->kelembapan,
                ])
                ->values()
                ->all();
        }

        $normal = count(array_filter($daftar, fn (array $r): bool => $r['online'] && $r['kondisi'] === 'normal'));
        $alert  = count($daftar) - $normal;

        return [
            'total'    => count($daftar),
            'normal'   => $normal,
            'alert'    => max(0, $alert),
            'daftar'   => $daftar,
            'utama'    => $utama?->nama_ruangan,
            'series'   => $series,
        ];
    }

    /**
     * Ringkasan work order (modul yang sudah tersedia, datanya bisa kosong).
     *
     * @return array<string, mixed>
     */
    private function dataWorkOrder(): array
    {
        $total  = WorkOrder::count();
        $open   = WorkOrder::whereNull('tanggal_eksekusi_selesai')->count();
        $selesai = max(0, $total - $open);

        $jenis = WorkOrder::query()
            ->selectRaw("COALESCE(NULLIF(jenis_pekerjaan, ''), 'Lainnya') as label, COUNT(*) as total")
            ->groupByRaw("COALESCE(NULLIF(jenis_pekerjaan, ''), 'Lainnya')")
            ->orderByRaw('COUNT(*) DESC')
            ->limit(6)
            ->get()
            ->map(fn ($baris): array => [
                'label' => (string) $baris->label,
                'total' => (int) $baris->total,
            ])
            ->all();

        return [
            'total'   => $total,
            'open'    => $open,
            'selesai' => $selesai,
            'jenis'   => $jenis,
        ];
    }

    /**
     * Ringkasan kesehatan AI provider dan pemakaian token.
     *
     * @param  Carbon $mulai Awal periode log
     * @return array<string, mixed>
     */
    private function dataAi(Carbon $mulai): array
    {
        $providers = AiProvider::orderBy('priority')->get()->map(function (AiProvider $p): array {
            $limit = (int) ($p->monthly_token_limit ?? 0);
            $pakai = (int) ($p->tokens_used_month ?? 0);

            return [
                'nama'      => (string) $p->name,
                'status'    => (string) $p->status,
                'hari_ini'  => (int) ($p->tokens_used_today ?? 0),
                'bulan_ini' => $pakai,
                'limit'     => $limit,
                'persen'    => $limit > 0 ? min(100, round(($pakai / $limit) * 100, 1)) : 0.0,
                'request'   => (int) ($p->request_count_24h ?? 0),
            ];
        })->all();

        $log = AiUsageLog::query()
            ->where('created_at', '>=', $mulai)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $sukses = (int) ($log['success'] ?? 0);
        $gagal  = (int) ($log['error'] ?? 0);
        $jumlah = $sukses + $gagal;

        return [
            'provider'     => $providers,
            'total'        => count($providers),
            'sehat'        => count(array_filter($providers, fn (array $p): bool => $p['status'] === 'healthy')),
            'token_hari'   => array_sum(array_column($providers, 'hari_ini')),
            'token_bulan'  => array_sum(array_column($providers, 'bulan_ini')),
            'limit_bulan'  => array_sum(array_column($providers, 'limit')),
            'request'      => array_sum(array_column($providers, 'request')),
            'sukses'       => $sukses,
            'gagal'        => $gagal,
            'log'          => $jumlah,
            'rate'         => $jumlah > 0 ? round(($sukses / $jumlah) * 100, 1) : 0.0,
        ];
    }

    /**
     * Ringkasan bot Telegram.
     *
     * @return array<string, mixed>
     */
    private function dataBot(): array
    {
        return [
            'pending'  => BotRegistration::where('status', 'pending')->count(),
            'approved' => BotRegistration::where('status', 'approved')->count(),
            'unknown'  => BotUnknownAsset::count(),
        ];
    }

    /**
     * Menyusun kartu KPI sesuai modul yang boleh diakses.
     *
     * @param  array<string, bool>       $akses
     * @param  array<string, mixed>|null $laporan
     * @param  array<string, mixed>|null $aset
     * @param  array<string, mixed>|null $teknisi
     * @param  array<string, mixed>|null $cm
     * @param  array<string, mixed>|null $sparepart
     * @param  array<string, mixed>|null $it
     * @param  array<string, mixed>|null $panel
     * @param  array<string, mixed>|null $workOrder
     * @return array<int, array<string, string>>
     */
    private function kartuKpi(
        array $akses,
        ?array $laporan,
        ?array $aset,
        ?array $teknisi,
        ?array $cm,
        ?array $sparepart,
        ?array $it,
        ?array $panel,
        ?array $workOrder
    ): array {
        $kartu = [];

        if ($akses['assets'] && $aset !== null) {
            $kartu[] = [
                'label' => 'Asset aktif',
                'nilai' => number_format($aset['aktif'], 0, ',', '.'),
                'sub'   => number_format($aset['total'], 0, ',', '.') . ' total terdaftar',
                'href'  => route('assets.index'),
                'tone'  => 'blue',
                'ikon'  => 'asset',
            ];
        }

        if ($akses['technicians'] && $teknisi !== null) {
            $kartu[] = [
                'label' => 'Teknisi aktif',
                'nilai' => number_format($teknisi['aktif'], 0, ',', '.'),
                'sub'   => $teknisi['pending'] . ' menunggu persetujuan',
                'href'  => route('technicians.index'),
                'tone'  => 'emerald',
                'ikon'  => 'users',
            ];
        }

        if ($akses['reports'] && $laporan !== null) {
            $kartu[] = [
                'label' => 'Laporan periode',
                'nilai' => number_format($laporan['total'], 0, ',', '.'),
                'sub'   => $laporan['hari_ini'] . ' hari ini · ' . number_format($laporan['jam'], 1, ',', '.') . ' jam kerja',
                'href'  => route('reports.index'),
                'tone'  => 'violet',
                'ikon'  => 'report',
            ];

            $kartu[] = [
                'label' => 'Perlu review',
                'nilai' => number_format($laporan['review'], 0, ',', '.'),
                'sub'   => 'dari ' . number_format($laporan['total'], 0, ',', '.') . ' laporan periode',
                'href'  => route('reports.index', ['status' => 'needs_review']),
                'tone'  => 'amber',
                'ikon'  => 'review',
            ];
        }

        if ($akses['cm'] && $cm !== null) {
            $kartu[] = [
                'label' => 'Temuan CM terbuka',
                'nilai' => number_format($cm['temuan_terbuka'], 0, ',', '.'),
                'sub'   => $cm['danger'] . ' pembacaan danger · ' . number_format($cm['equipment'], 0, ',', '.') . ' equipment',
                'href'  => route('cm.findings', ['status' => 'open']),
                'tone'  => 'rose',
                'ikon'  => 'alert',
            ];
        }

        if ($akses['sparepart'] && $sparepart !== null) {
            $kartu[] = [
                'label' => 'Sparepart perlu tindakan',
                'nilai' => number_format($sparepart['reorder'], 0, ',', '.'),
                'sub'   => number_format($sparepart['total'], 0, ',', '.') . ' material dipantau',
                'href'  => route('sparepart.index', ['status' => 'reorder']),
                'tone'  => 'orange',
                'ikon'  => 'sparepart',
            ];
        }

        if ($akses['it_assets'] && $it !== null) {
            $kartu[] = [
                'label' => 'Perangkat IT online',
                'nilai' => number_format($it['online'], 0, ',', '.'),
                'sub'   => $it['offline'] . ' offline · ' . $it['unknown'] . ' belum diping',
                'href'  => route('it-assets.index'),
                'tone'  => 'cyan',
                'ikon'  => 'network',
            ];
        }

        if ($akses['panel'] && $panel !== null) {
            $kartu[] = [
                'label' => 'Ruang panel normal',
                'nilai' => number_format($panel['normal'], 0, ',', '.'),
                'sub'   => $panel['alert'] . ' perlu perhatian · ' . $panel['total'] . ' ruang',
                'href'  => route('panel.index'),
                'tone'  => 'teal',
                'ikon'  => 'panel',
            ];
        }

        if ($akses['work_orders'] && $workOrder !== null && $workOrder['total'] > 0) {
            $kartu[] = [
                'label' => 'Work order terbuka',
                'nilai' => number_format($workOrder['open'], 0, ',', '.'),
                'sub'   => number_format($workOrder['selesai'], 0, ',', '.') . ' selesai',
                'href'  => route('work-orders.index'),
                'tone'  => 'slate',
                'ikon'  => 'workorder',
            ];
        }

        return $kartu;
    }

    /**
     * Menyusun daftar perhatian dari indikator yang melewati batas.
     *
     * @param  array<string, bool>       $akses
     * @param  array<string, mixed>|null $cm
     * @param  array<string, mixed>|null $sparepart
     * @param  array<string, mixed>|null $it
     * @param  array<string, mixed>|null $panel
     * @param  array<string, mixed>|null $bot
     * @param  array<string, mixed>|null $ai
     * @param  array<string, mixed>|null $workOrder
     * @return array<int, array<string, string>>
     */
    private function daftarPerhatian(
        array $akses,
        ?array $cm,
        ?array $sparepart,
        ?array $it,
        ?array $panel,
        ?array $bot,
        ?array $ai,
        ?array $workOrder
    ): array {
        $daftar = [];

        if ($akses['cm'] && $cm !== null && $cm['temuan_terbuka'] > 0) {
            $daftar[] = [
                'judul'  => 'Temuan CM terbuka',
                'nilai'  => (string) $cm['temuan_terbuka'],
                'detail' => $cm['danger'] . ' pembacaan danger menunggu tindak lanjut',
                'href'   => route('cm.findings', ['status' => 'open']),
                'tone'   => 'rose',
            ];
        }

        if ($akses['sparepart'] && $sparepart !== null && $sparepart['reorder'] > 0) {
            $daftar[] = [
                'judul'  => 'Sparepart di bawah stok minimum',
                'nilai'  => (string) $sparepart['reorder'],
                'detail' => 'Material REORDER perlu dicek tindak lanjutnya',
                'href'   => route('sparepart.index', ['status' => 'reorder']),
                'tone'   => 'orange',
            ];
        }

        if ($akses['it_assets'] && $it !== null && $it['offline'] > 0) {
            $daftar[] = [
                'judul'  => 'Perangkat IT offline',
                'nilai'  => (string) $it['offline'],
                'detail' => 'Perangkat tidak merespons ping terakhir',
                'href'   => route('it-assets.index'),
                'tone'   => 'amber',
            ];
        }

        if ($akses['panel'] && $panel !== null && $panel['alert'] > 0) {
            $daftar[] = [
                'judul'  => 'Ruang panel perlu perhatian',
                'nilai'  => (string) $panel['alert'],
                'detail' => 'Suhu di luar ambang atau perangkat sedang offline',
                'href'   => route('panel.index'),
                'tone'   => 'teal',
            ];
        }

        if ($akses['bot'] && $bot !== null && $bot['pending'] > 0) {
            $daftar[] = [
                'judul'  => 'Pendaftaran teknisi menunggu',
                'nilai'  => (string) $bot['pending'],
                'detail' => 'Pendaftaran dari bot Telegram belum disetujui',
                'href'   => route('bot.index'),
                'tone'   => 'violet',
            ];
        }

        if ($akses['ai_providers'] && $ai !== null && $ai['log'] > 0 && $ai['rate'] < 80) {
            $daftar[] = [
                'judul'  => 'Tingkat sukses AI rendah',
                'nilai'  => number_format($ai['rate'], 1, ',', '.') . '%',
                'detail' => $ai['gagal'] . ' dari ' . $ai['log'] . ' permintaan gagal',
                'href'   => route('ai-providers.index'),
                'tone'   => 'rose',
            ];
        }

        if ($akses['work_orders'] && $workOrder !== null && $workOrder['open'] > 0) {
            $daftar[] = [
                'judul'  => 'Work order belum selesai',
                'nilai'  => (string) $workOrder['open'],
                'detail' => 'Work order menunggu eksekusi atau penutupan',
                'href'   => route('work-orders.index'),
                'tone'   => 'slate',
            ];
        }

        return array_slice($daftar, 0, 6);
    }
}
