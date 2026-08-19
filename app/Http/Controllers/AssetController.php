<?php

namespace App\Http\Controllers;

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
use App\Services\FuncLocSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AssetController extends Controller
{
    use AssetExportTrait;
    use AssetTechnicianTrait;
    use AssetLocationTrait;

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
    public function show(Asset $asset)
    {
        $asset->load(['company', 'department', 'area', 'subArea', 'technicians']);

        // Laporan terkait asset — berdasarkan asset_id, tech_ident_no, atau equipment_no
        $reports = Report::with('technician')
            ->where(function ($q) use ($asset) {
                $q->where('asset_id', $asset->id);
                if ($asset->tech_ident_no) {
                    $q->orWhere('work_description', 'like', '%' . $asset->tech_ident_no . '%');
                }
                if ($asset->equipment_no) {
                    $q->orWhere('work_description', 'like', '%' . $asset->equipment_no . '%');
                }
            })
            ->latest('report_date')
            ->get();

        // Statistik per jenis laporan
        $statsByType = Report::select('report_type', DB::raw('count(*) as total'))
            ->where(function ($q) use ($asset) {
                $q->where('asset_id', $asset->id);
                if ($asset->tech_ident_no) {
                    $q->orWhere('work_description', 'like', '%' . $asset->tech_ident_no . '%');
                }
                if ($asset->equipment_no) {
                    $q->orWhere('work_description', 'like', '%' . $asset->equipment_no . '%');
                }
            })
            ->groupBy('report_type')
            ->pluck('total', 'report_type');

        // Statistik per bulan untuk grafik tren
        $dateFormat = DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', report_date)"
            : "DATE_FORMAT(report_date, '%Y-%m')";

        $statsByMonth = Report::select(
                DB::raw("{$dateFormat} as month"),
                DB::raw('count(*) as total')
            )
            ->where(function ($q) use ($asset) {
                $q->where('asset_id', $asset->id);
                if ($asset->tech_ident_no) {
                    $q->orWhere('work_description', 'like', '%' . $asset->tech_ident_no . '%');
                }
                if ($asset->equipment_no) {
                    $q->orWhere('work_description', 'like', '%' . $asset->equipment_no . '%');
                }
            })
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

        return view('assets.show', compact(
            'asset', 'reports', 'statsByType', 'statsByMonth',
            'technicians', 'totalReports', 'completedReports', 'needsReviewReports',
            'allTechnicians'
        ));
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
     * @param  Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'equipment_no'   => 'nullable|unique:assets',
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
        $data['has_equipment_no'] = !empty($validated['equipment_no']);
        $data['data_source']      = 'manual';

        Asset::create($data);

        return redirect()->route('assets.index')
            ->with('success', 'Asset berhasil ditambahkan.');
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
     * @param  Request  $request
     * @param  Asset    $asset
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request, Asset $asset)
    {
        $validated = $request->validate([
            'equipment_no'   => 'nullable|unique:assets,equipment_no,' . $asset->id,
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
        $data['has_equipment_no'] = !empty($validated['equipment_no']);

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
     * @param  FunctionalLocation  $funcLoc
     * @return array{functional_loc: string, company_id: int|null, department_id: int|null, area_id: int|null, sub_area_id: int|null}
     */
    private function locationFieldsFromFuncLoc(FunctionalLocation $funcLoc): array
    {
        return [
            'functional_loc' => $funcLoc->code,
            'company_id'     => $funcLoc->company_id,
            'department_id'  => $funcLoc->department_id,
            'area_id'        => $funcLoc->area_id,
            'sub_area_id'    => $funcLoc->sub_area_id,
        ];
    }
}
