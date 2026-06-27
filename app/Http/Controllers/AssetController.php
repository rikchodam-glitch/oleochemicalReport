<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Traits\AssetExportTrait;
use App\Http\Controllers\Traits\AssetLocationTrait;
use App\Http\Controllers\Traits\AssetTechnicianTrait;
use App\Models\Area;
use App\Models\Asset;
use App\Models\Company;
use App\Models\Department;
use App\Models\Report;
use App\Models\SubArea;
use App\Models\Technician;
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
     * @return \Illuminate\View\View
     */
    public function index(Request $request)
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
        if ($request->filled('object_type')) {
            $query->where('object_type', $request->object_type);
        }

        $assets      = $query->latest()->paginate(20)->withQueryString();
        $companies   = Company::all();
        $objectTypes = Asset::select('object_type')
            ->distinct()
            ->whereNotNull('object_type')
            ->orderBy('object_type')
            ->pluck('object_type');

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

        return view('assets.index', compact('assets', 'companies', 'objectTypes', 'departments', 'areas', 'subAreas'));
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
        $statsByMonth = Report::select(
                DB::raw("DATE_FORMAT(report_date, '%Y-%m') as month"),
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
     * @return \Illuminate\View\View
     */
    public function create()
    {
        $companies = Company::all();
        return view('assets.create', compact('companies'));
    }

    /**
     * Simpan asset baru ke database.
     *
     * @param  Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'equipment_no'  => 'nullable|unique:assets',
            'description'   => 'nullable|string',
            'tech_ident_no' => 'nullable|string',
            'object_type'   => 'nullable|string',
            'functional_loc'=> 'nullable|string',
            'company_id'    => 'nullable|exists:companies,id',
            'department_id' => 'nullable|exists:departments,id',
            'area_id'       => 'nullable|exists:areas,id',
            'sub_area_id'   => 'nullable|exists:sub_areas,id',
            'manufacturer'  => 'nullable|string',
            'model_number'  => 'nullable|string',
            'construct_year'=> 'nullable|string',
            'status'        => 'required|in:active,inactive,needs_review',
        ]);

        $validated['has_equipment_no'] = !empty($validated['equipment_no']);
        $validated['data_source']      = 'manual';

        Asset::create($validated);

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
        $companies   = Company::all();
        $departments = Department::where('company_id', $asset->company_id)->get();
        $areas       = Area::where('department_id', $asset->department_id)->get();
        return view('assets.edit', compact('asset', 'companies', 'departments', 'areas'));
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
            'equipment_no'  => 'nullable|unique:assets,equipment_no,' . $asset->id,
            'description'   => 'nullable|string',
            'tech_ident_no' => 'nullable|string',
            'object_type'   => 'nullable|string',
            'functional_loc'=> 'nullable|string',
            'company_id'    => 'nullable|exists:companies,id',
            'department_id' => 'nullable|exists:departments,id',
            'area_id'       => 'nullable|exists:areas,id',
            'sub_area_id'   => 'nullable|exists:sub_areas,id',
            'manufacturer'  => 'nullable|string',
            'model_number'  => 'nullable|string',
            'construct_year'=> 'nullable|string',
            'status'        => 'required|in:active,inactive,needs_review',
        ]);

        $validated['has_equipment_no'] = !empty($validated['equipment_no']);
        $asset->update($validated);

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
}
