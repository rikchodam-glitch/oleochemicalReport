<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesReportFilters;
use App\Http\Controllers\Traits\AssetExportTrait;
use App\Http\Controllers\Traits\AssetLocationTrait;
use App\Http\Controllers\Traits\AssetTechnicianTrait;
use App\Models\Area;
use App\Models\Asset;
use App\Models\Company;
use App\Models\Department;
use App\Models\FuncLocSyncLog;
use App\Models\FunctionalLocation;
use App\Models\Report;
use App\Models\SubArea;
use App\Models\Technician;
use App\Services\AssetNumberService;
use App\Services\FuncLocSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AssetController extends Controller
{
    use AssetExportTrait;
    use AssetTechnicianTrait;
    use AssetLocationTrait;
    use ResolvesReportFilters;

    /**
     * @var AssetNumberService Pembuat nomor equipment otomatis untuk asset
     *                         baru yang kolom Equipment No-nya dikosongkan
     */
    protected AssetNumberService $assetNumbers;

    /**
     * @param  AssetNumberService  $assetNumbers
     */
    public function __construct(AssetNumberService $assetNumbers)
    {
        $this->assetNumbers = $assetNumbers;
    }

    /**
     * Tampilkan daftar asset dengan filter dan paginasi.
     *
     * @param  Request  $request
     * @param  FuncLocSyncService  $syncService
     * @return \Illuminate\View\View
     */
    public function index(Request $request, FuncLocSyncService $syncService)
    {
        $query = Asset::with(['company', 'department', 'area', 'subArea']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('equipment_no', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('tech_ident_no', 'like', "%{$search}%")
                  ->orWhere('functional_loc', 'like', "%{$search}%");
            });
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('company_id')) {
            $query->where('company_id', $request->company_id);
        }
        if ($request->filled('department_id')) {
            $query->where('department_id', $request->department_id);
        }
        if ($request->filled('area_id')) {
            $query->where('area_id', $request->area_id);
        }
        if ($request->filled('sub_area_id')) {
            $query->where('sub_area_id', $request->sub_area_id);
        }
        if ($request->filled('equipment_type')) {
            $query->where('equipment_type', $request->equipment_type);
        }

        $assets      = $query->latest()->paginate(20)->withQueryString();
        $companies   = Company::all();
        $equipmentTypes = Asset::select('equipment_type')
            ->distinct()
            ->whereNotNull('equipment_type')
            ->orderBy('equipment_type')
            ->pluck('equipment_type');

        // Dropdown lokasi dinamis — hanya diisi jika filter terkait aktif
        $departments = collect();
        $areas       = collect();
        $subAreas    = collect();

        if ($request->filled('company_id')) {
            $departments = Department::where('company_id', $request->company_id)->orderBy('code')->get();
        }
        if ($request->filled('department_id')) {
            $areas = Area::where('department_id', $request->department_id)->orderBy('code')->get();
        }
        if ($request->filled('area_id')) {
            $subAreas = SubArea::where('area_id', $request->area_id)->orderBy('code')->get();
        }

        // Jumlah asset yang belum tertaut funcloc_id — untuk badge tautan
        // menuju halaman rekonsiliasi manual.
        $unlinkedFuncLocCount = $syncService->unlinkedAssetsCount();

        return view('assets.index', compact(
            'assets', 'companies', 'equipmentTypes', 'departments', 'areas', 'subAreas',
            'unlinkedFuncLocCount'
        ));
    }

    /**
     * Tampilkan detail asset beserta riwayat laporan dan statistik.
     *
     * @param  Asset  $asset
     * @return \Illuminate\View\View
     */
    public function show(Request $request, Asset $asset)
    {
        $asset->load(['company', 'department', 'area', 'subArea', 'technicians']);

        // Seluruh laporan asset ini TANPA filter — dipakai untuk statistik kartu,
        // daftar teknisi, dan grafik tren supaya angkanya tetap menyeluruh.
        $reports = $this->queryLaporanAset($asset)
            ->with('technician')
            ->latest('report_date')
            ->get();

        // ---- Filter riwayat perbaikan (tanggal per-hari + teknisi) ----
        $tanggal = $this->tanggalValid($request->query('tanggal'));
        $dari    = $this->tanggalValid($request->query('date_from'));
        $sampai  = $this->tanggalValid($request->query('date_to'));
        $teknisi = $request->filled('teknisi') ? (int) $request->query('teknisi') : null;

        $riwayat = $this->terapkanFilterTanggal(
            $this->queryLaporanAset($asset)->with('technician'),
            $tanggal,
            $dari,
            $sampai
        )
            ->when($teknisi !== null, fn ($q) => $q->where('technician_id', $teknisi))
            ->latest('report_date')
            ->get();

        // Riwayat dikelompokkan PER HARI seperti halaman Laporan.
        $riwayatPerHari = $riwayat->groupBy(
            fn (Report $item) => $item->report_date?->toDateString() ?? 'tanpa-tanggal'
        );

        // Statistik per jenis laporan
        $statsByType = $this->queryLaporanAset($asset)
            ->reorder()
            ->select('report_type', DB::raw('count(*) as total'))
            ->groupBy('report_type')
            ->pluck('total', 'report_type');

        // Statistik per bulan untuk grafik tren
        $dateFormat = DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', report_date)"
            : "DATE_FORMAT(report_date, '%Y-%m')";

        $statsByMonth = $this->queryLaporanAset($asset)
            ->reorder()
            ->select(DB::raw("{$dateFormat} as month"), DB::raw('count(*) as total'))
            ->groupBy('month')
            ->orderBy('month')
            ->pluck('total', 'month');

        // Teknisi yang pernah mengerjakan laporan asset ini
        $technicians = Technician::whereIn('id', $reports->pluck('technician_id')->unique())
            ->get()
            ->map(function ($t) use ($reports) {
                $t->repair_count = $reports->where('technician_id', $t->id)->count();
                $t->last_repair  = $reports->where('technician_id', $t->id)->sortByDesc('report_date')->first()?->report_date;
                return $t;
            })
            ->sortByDesc('repair_count');

        $totalReports        = $reports->count();
        $completedReports    = $reports->where('status', 'completed')->count();
        $needsReviewReports  = $reports->where('status', 'needs_review')->count();

        // Semua teknisi aktif untuk dropdown penugasan
        $allTechnicians = Technician::where('status', 'active')->orderBy('name')->get();

        // Data filter riwayat (per-hari + teknisi) yang sedang aktif.
        $filterRiwayat = [
            'tanggal'   => $tanggal,
            'date_from' => $dari,
            'date_to'   => $sampai,
            'teknisi'   => $teknisi,
            'jumlah'    => $riwayat->count(),
        ];

        return view('assets.show', compact(
            'asset', 'reports', 'riwayat', 'riwayatPerHari', 'filterRiwayat',
            'statsByType', 'statsByMonth',
            'technicians', 'totalReports', 'completedReports', 'needsReviewReports',
            'allTechnicians'
        ));
    }

    /**
     * Query laporan milik satu asset: lewat relasi asset_id, atau lewat kode
     * (Tech Ident No / Equipment No) yang tertulis di deskripsi pekerjaan —
     * supaya laporan lama yang belum punya asset_id tetap ikut terbaca.
     *
     * @param  Asset  $asset  Asset yang dibuka
     * @return \Illuminate\Database\Eloquent\Builder  Query laporan asset tersebut
     */
    private function queryLaporanAset(Asset $asset)
    {
        return Report::query()
            ->where(function ($q) use ($asset) {
                $q->where('asset_id', $asset->id);

                if ($asset->tech_ident_no) {
                    $q->orWhere('work_description', 'like', '%' . $asset->tech_ident_no . '%');
                }

                if ($asset->equipment_no) {
                    $q->orWhere('work_description', 'like', '%' . $asset->equipment_no . '%');
                }
            });
    }

    /**
     * Tampilkan form tambah asset baru.
     *
     * Lokasi dipilih lewat komponen <x-funcloc-picker> (bukan lagi input
     * teks bebas), sehingga tidak perlu lagi mengirim data Company/Department
     * ke view ini. Jika sebelumnya submit gagal validasi, funcloc_id yang
     * sempat dipilih (old input) ditampilkan ulang lewat label hasil lookup.
     *
     * @return \Illuminate\View\View
     */
    public function create()
    {
        $oldFuncLocId = old('funcloc_id');
        $oldFuncLoc   = $oldFuncLocId ? FunctionalLocation::find($oldFuncLocId) : null;

        return view('assets.create', [
            'selectedFuncLocId'    => $oldFuncLoc?->id,
            'selectedFuncLocLabel' => $this->funcLocLabel($oldFuncLoc),
        ]);
    }

    /**
     * Simpan asset baru ke database.
     *
     * FuncLoc kini wajib dipilih dari node yang sudah ada. Kolom lama
     * (functional_loc string, company_id/department_id/area_id/sub_area_id)
     * tetap diisi otomatis dari FuncLoc terpilih agar filter dan laporan
     * lama yang masih memakainya tetap konsisten.
     *
     * Kolom Equipment No tidak ada di form: nomor adalah id internal yang
     * dibuat sistem mengikuti seri milik PT dari lokasi yang dipilih
     * (lihat AssetNumberService), jadi nilainya tidak pernah diambil dari
     * request meskipun ada yang mengirimkannya.
     *
     * @param  Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'description'    => 'nullable|string',
            'tech_ident_no'  => 'nullable|string',
            'equipment_type' => 'nullable|string',
            'funcloc_id'     => 'required|exists:functional_locations,id',
            'manufacturer'   => 'nullable|string',
            'model_number'   => 'nullable|string',
            'construct_year' => 'nullable|string',
            'status'         => 'required|in:active,inactive,needs_review',
        ]);

        $funcLoc = FunctionalLocation::findOrFail($validated['funcloc_id']);

        $data = array_merge($validated, $this->locationFieldsFromFuncLoc($funcLoc));
        $data['data_source'] = 'manual';

        // Nomor equipment selalu dibuat sistem.
        $data['equipment_no']     = $this->assetNumbers->generateForFuncLoc($funcLoc);
        $data['has_equipment_no'] = true;

        Asset::create($data);

        return redirect()->route('assets.index')
            ->with('success', 'Asset berhasil ditambahkan dengan nomor equipment ' . $data['equipment_no'] . '.');
    }

    /**
     * Tampilkan form edit asset.
     *
     * @param  Asset  $asset
     * @return \Illuminate\View\View
     */
    public function edit(Asset $asset)
    {
        $oldFuncLocId = old('funcloc_id');
        $oldFuncLoc   = $oldFuncLocId ? FunctionalLocation::find($oldFuncLocId) : null;
        $currentFuncLoc = $oldFuncLoc ?? $asset->functionalLocation;

        return view('assets.edit', [
            'asset'                => $asset,
            'selectedFuncLocId'    => $currentFuncLoc?->id,
            'selectedFuncLocLabel' => $this->funcLocLabel($currentFuncLoc),
        ]);
    }

    /**
     * Perbarui data asset di database.
     *
     * Kolom Equipment No tidak bisa diubah dari form (nomor adalah id internal
     * yang dibuat sistem), jadi nilainya sengaja tidak diambil dari request.
     *
     * @param  Request  $request
     * @param  Asset    $asset
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request, Asset $asset)
    {
        $validated = $request->validate([
            'description'    => 'nullable|string',
            'tech_ident_no'  => 'nullable|string',
            'equipment_type' => 'nullable|string',
            'funcloc_id'     => 'required|exists:functional_locations,id',
            'manufacturer'   => 'nullable|string',
            'model_number'   => 'nullable|string',
            'construct_year' => 'nullable|string',
            'status'         => 'required|in:active,inactive,needs_review',
        ]);

        $funcLoc = FunctionalLocation::findOrFail($validated['funcloc_id']);

        $data = array_merge($validated, $this->locationFieldsFromFuncLoc($funcLoc));
        $data['has_equipment_no'] = $asset->equipment_no !== null && $asset->equipment_no !== '';

        $asset->update($data);

        return redirect()->route('assets.index')
            ->with('success', 'Asset berhasil diperbarui.');
    }

    /**
     * Hapus asset dari database.
     *
     * @param  Asset  $asset
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(Asset $asset)
    {
        $asset->delete();
        return redirect()->route('assets.index')
            ->with('success', 'Asset berhasil dihapus.');
    }

    /**
     * Tampilkan halaman rekonsiliasi manual: daftar asset yang funcloc_id-nya
     * masih NULL setelah sinkronisasi otomatis (kosong, typo, atau format
     * functional_loc lama tidak sesuai hierarki 4-level SAP).
     *
     * @param  Request  $request
     * @param  FuncLocSyncService  $syncService
     * @return \Illuminate\View\View
     */
    public function unlinked(Request $request, FuncLocSyncService $syncService)
    {
        $query = $syncService->unlinkedAssetsQuery();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('equipment_no', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('tech_ident_no', 'like', "%{$search}%")
                  ->orWhere('functional_loc', 'like', "%{$search}%");
            });
        }

        $assets = $query->latest()->paginate(20)->withQueryString();

        return view('assets.unlinked', compact('assets'));
    }

    /**
     * Jalankan ulang FuncLocSyncService::execute() dari halaman rekonsiliasi,
     * sebagai langkah pertama sebelum penautan manual. Asset yang
     * functional_loc lamanya cocok dengan kode FuncLoc akan langsung
     * tertaut; sisanya tetap muncul di daftar manual. Log auto_sync dicatat
     * langsung oleh FuncLocSyncService::execute().
     *
     * @param  FuncLocSyncService  $syncService
     * @return \Illuminate\Http\RedirectResponse
     */
    public function runAutoSync(FuncLocSyncService $syncService)
    {
        $analysis = $syncService->analyze();
        $result   = $syncService->execute($analysis);

        return redirect()->route('assets.unlinked-funcloc')->with(
            'success',
            "Sync otomatis selesai: {$result['asset_linked_count']} asset tertaut, " .
            "{$result['node_created_count']} node FuncLoc baru dibuat."
        );
    }

    /**
     * Tautkan satu asset ke FuncLoc yang dipilih secara manual dari halaman
     * rekonsiliasi. Aksi ini dicatat sebagai FuncLocSyncLog bertipe
     * manual_link agar riwayat penautan manual tetap terlacak.
     *
     * @param  Request  $request
     * @param  Asset    $asset
     * @return \Illuminate\Http\RedirectResponse
     */
    public function linkFuncLoc(Request $request, Asset $asset)
    {
        $validated = $request->validate([
            'funcloc_id' => 'required|exists:functional_locations,id',
        ]);

        $funcLoc = FunctionalLocation::findOrFail($validated['funcloc_id']);

        $data = array_merge(
            ['funcloc_id' => $funcLoc->id],
            $this->locationFieldsFromFuncLoc($funcLoc)
        );

        $asset->update($data);

        FuncLocSyncLog::create([
            'executed_by'                => auth()->id(),
            'type'                       => FuncLocSyncLog::TYPE_MANUAL_LINK,
            'total_scanned'               => 0,
            'node_created_count'          => 0,
            'asset_linked_count'          => 1,
            'asset_skipped_empty_count'   => 0,
            'asset_missing_node_count'    => 0,
            'detail_json'                 => [
                'asset_id'     => $asset->id,
                'funcloc_id'   => $funcLoc->id,
                'funcloc_code' => $funcLoc->code,
            ],
        ]);

        return redirect()->route('assets.unlinked-funcloc')
            ->with('success', "Asset berhasil ditautkan ke {$funcLoc->code}.");
    }

    /**
     * Tautkan beberapa asset sekaligus ke satu FuncLoc yang sama. Berguna
     * ketika banyak asset lama berasal dari section yang sama sehingga tidak
     * perlu ditautkan satu-per-satu. Aksi ini dicatat sebagai FuncLocSyncLog
     * bertipe bulk_link.
     *
     * @param  Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function bulkLinkFuncLoc(Request $request)
    {
        $validated = $request->validate([
            'asset_ids'    => 'required|array|min:1',
            'asset_ids.*'  => 'integer|exists:assets,id',
            'funcloc_id'   => 'required|exists:functional_locations,id',
        ]);

        $funcLoc = FunctionalLocation::findOrFail($validated['funcloc_id']);

        $data = array_merge(
            ['funcloc_id' => $funcLoc->id],
            $this->locationFieldsFromFuncLoc($funcLoc)
        );

        // Batasi ke asset yang masih funcloc_id NULL saja, mencegah race
        // condition menimpa asset yang sudah sempat ditautkan proses lain.
        $updated = Asset::whereIn('id', $validated['asset_ids'])
            ->whereNull('funcloc_id')
            ->update($data);

        FuncLocSyncLog::create([
            'executed_by'                => auth()->id(),
            'type'                       => FuncLocSyncLog::TYPE_BULK_LINK,
            'total_scanned'               => 0,
            'node_created_count'          => 0,
            'asset_linked_count'          => $updated,
            'asset_skipped_empty_count'   => 0,
            'asset_missing_node_count'    => 0,
            'detail_json'                 => [
                'asset_ids'    => $validated['asset_ids'],
                'funcloc_id'   => $funcLoc->id,
                'funcloc_code' => $funcLoc->code,
            ],
        ]);

        return redirect()->route('assets.unlinked-funcloc')
            ->with('success', "{$updated} asset berhasil ditautkan sekaligus ke {$funcLoc->code}.");
    }

    /**
     * Nomor equipment yang AKAN dipakai bila kolom Equipment No dikosongkan di
     * form tambah asset. Hanya untuk pratinjau di form (tidak menyimpan apa
     * pun), supaya pengguna tahu nomor yang akan diberikan sebelum menekan
     * Simpan.
     *
     * @param  Request  $request  Harus mengandung funcloc_id
     * @param  AssetNumberService  $assetNumbers
     * @return \Illuminate\Http\JsonResponse
     */
    public function nextEquipmentNo(Request $request, AssetNumberService $assetNumbers)
    {
        $funcLoc = FunctionalLocation::find((int) $request->input('funcloc_id'));

        if ($funcLoc === null) {
            return response()->json([
                'success' => false,
                'message' => 'Lokasi tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success'      => true,
            'equipment_no' => $assetNumbers->generateForFuncLoc($funcLoc),
            'pt'           => $assetNumbers->kodePtForFuncLoc($funcLoc),
        ]);
    }

    /**
     * Bangun label tampilan "KODE — Nama" untuk sebuah FuncLoc, dipakai
     * mengisi ulang komponen picker saat form gagal validasi atau saat
     * membuka form edit.
     *
     * @param  FunctionalLocation|null  $funcLoc
     * @return string|null
     */
    private function funcLocLabel(?FunctionalLocation $funcLoc): ?string
    {
        if ($funcLoc === null) {
            return null;
        }

        return $funcLoc->code . ' — ' . $funcLoc->name;
    }

    /**
     * Turunkan kolom lokasi lama (functional_loc string dan
     * company_id/department_id/area_id/sub_area_id) dari FuncLoc yang
     * dipilih di picker. FuncLoc adalah sumber kebenaran; kolom-kolom ini
     * hanya ikut terisi untuk menjaga kompatibilitas filter dan laporan
     * lama yang masih membacanya. Nilai bisa null jika node FuncLoc dibuat
     * manual tanpa relasi ke master data lama.
     *
     * Khusus company_id: sebagian besar node FuncLoc hasil sinkron belum
     * terhubung ke master Company, padahal kodenya berawalan kode PT
     * (mis. "EPE-PROD-TF01-6010"). PT diambil dari awalan kode itu bila
     * relasinya kosong, supaya asset baru tetap tampil di filter PT yang benar.
     *
     * @param  FunctionalLocation  $funcLoc
     * @return array{functional_loc: string, company_id: int|null, department_id: int|null, area_id: int|null, sub_area_id: int|null}
     */
    private function locationFieldsFromFuncLoc(FunctionalLocation $funcLoc): array
    {
        return [
            'functional_loc' => $funcLoc->code,
            'company_id'     => $this->assetNumbers->companyIdForFuncLoc($funcLoc),
            'department_id'  => $funcLoc->department_id,
            'area_id'        => $funcLoc->area_id,
            'sub_area_id'    => $funcLoc->sub_area_id,
        ];
    }
}
