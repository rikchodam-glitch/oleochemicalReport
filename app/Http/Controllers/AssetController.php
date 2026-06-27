<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Company;
use App\Models\Department;
use App\Models\Report;
use App\Models\SubArea;
use App\Models\Technician;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class AssetController extends Controller
{
    public function index(Request $request)
    {
        $query = Asset::with(['company', 'department', 'area', 'subArea']);

        // Filter by search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('equipment_no', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('tech_ident_no', 'like', "%{$search}%")
                  ->orWhere('functional_loc', 'like', "%{$search}%");
            });
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by company
        if ($request->filled('company_id')) {
            $query->where('company_id', $request->company_id);
        }

        // Filter by department
        if ($request->filled('department_id')) {
            $query->where('department_id', $request->department_id);
        }

        // Filter by area
        if ($request->filled('area_id')) {
            $query->where('area_id', $request->area_id);
        }

        // Filter by sub area
        if ($request->filled('sub_area_id')) {
            $query->where('sub_area_id', $request->sub_area_id);
        }

        // Filter by object type
        if ($request->filled('object_type')) {
            $query->where('object_type', $request->object_type);
        }

        $assets = $query->latest()->paginate(20)->withQueryString();

        $companies = Company::all();
        $objectTypes = Asset::select('object_type')->distinct()->whereNotNull('object_type')->orderBy('object_type')->pluck('object_type');

        // For dynamic location dropdowns
        $departments = collect();
        $areas = collect();
        $subAreas = collect();

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

    public function show(Asset $asset)
    {
        $asset->load(['company', 'department', 'area', 'subArea', 'technicians']);

        // Reports terkait asset ini — cari berdasarkan tech_ident_no atau equipment_no
        $reports = Report::with('technician')
            ->where(function ($q) use ($asset) {
                // Cari berdasarkan asset_id langsung
                $q->where('asset_id', $asset->id);
                // Atau berdasarkan tech_ident_no di work_description
                if ($asset->tech_ident_no) {
                    $q->orWhere('work_description', 'like', '%' . $asset->tech_ident_no . '%');
                }
                if ($asset->equipment_no) {
                    $q->orWhere('work_description', 'like', '%' . $asset->equipment_no . '%');
                }
            })
            ->latest('report_date')
            ->get();

        // Statistik untuk grafik
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

        // Teknisi yang pernah memperbaiki
        $technicians = \App\Models\Technician::whereIn('id', $reports->pluck('technician_id')->unique())
            ->get()
            ->map(function ($t) use ($reports) {
                $t->repair_count = $reports->where('technician_id', $t->id)->count();
                $t->last_repair = $reports->where('technician_id', $t->id)->sortByDesc('report_date')->first()?->report_date;
                return $t;
            })
            ->sortByDesc('repair_count');

        $totalReports = $reports->count();
        $completedReports = $reports->where('status', 'completed')->count();
        $needsReviewReports = $reports->where('status', 'needs_review')->count();

        // All active technicians for assignment dropdown
        $allTechnicians = Technician::where('status', 'active')->orderBy('name')->get();

        return view('assets.show', compact(
            'asset', 'reports', 'statsByType', 'statsByMonth',
            'technicians', 'totalReports', 'completedReports', 'needsReviewReports',
            'allTechnicians'
        ));
    }

    public function create()
    {
        $companies = Company::all();
        return view('assets.create', compact('companies'));
    }

    public function exportExcel(Request $request)
    {
        ini_set('memory_limit', '512M');
        set_time_limit(300);

        $assets = $this->buildExportQuery($request)->get();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Assets');

        // Headers
        $headers = [
            'Equipment No', 'Description', 'Tech Ident No', 'Object Type',
            'Functional Loc', 'Company', 'Department', 'Area', 'Sub Area',
            'Manufacturer', 'Model Number', 'Construct Year', 'Status',
            'Data Source', 'Imported At', 'Created At', 'Updated At'
        ];

        foreach (array_values($headers) as $i => $header) {
            $col = chr(65 + $i); // A, B, C...
            $sheet->setCellValue($col . '1', $header);
            $sheet->getStyle($col . '1')->getFont()->setBold(true);
        }

        // Data rows
        $rowNum = 2;
        foreach ($assets as $asset) {
            $sheet->setCellValue('A' . $rowNum, $asset->equipment_no);
            $sheet->setCellValue('B' . $rowNum, $asset->description);
            $sheet->setCellValue('C' . $rowNum, $asset->tech_ident_no);
            $sheet->setCellValue('D' . $rowNum, $asset->object_type);
            $sheet->setCellValue('E' . $rowNum, $asset->functional_loc);
            $sheet->setCellValue('F' . $rowNum, $asset->company?->code ?? '');
            $sheet->setCellValue('G' . $rowNum, $asset->department?->code ?? '');
            $sheet->setCellValue('H' . $rowNum, $asset->area?->code ?? '');
            $sheet->setCellValue('I' . $rowNum, $asset->subArea?->code ?? '');
            $sheet->setCellValue('J' . $rowNum, $asset->manufacturer);
            $sheet->setCellValue('K' . $rowNum, $asset->model_number);
            $sheet->setCellValue('L' . $rowNum, $asset->construct_year);
            $sheet->setCellValue('M' . $rowNum, $asset->status);
            $sheet->setCellValue('N' . $rowNum, $asset->data_source);
            $sheet->setCellValue('O' . $rowNum, $asset->imported_at?->format('Y-m-d H:i:s') ?? '');
            $sheet->setCellValue('P' . $rowNum, $asset->created_at->format('Y-m-d H:i:s'));
            $sheet->setCellValue('Q' . $rowNum, $asset->updated_at->format('Y-m-d H:i:s'));
            $rowNum++;
        }

        // Auto-size columns
        foreach (range('A', 'Q') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $filename = 'assets-export-' . now()->format('Y-m-d-His') . '.xlsx';

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function exportCsv(Request $request)
    {
        ini_set('memory_limit', '512M');
        set_time_limit(300);

        $assets = $this->buildExportQuery($request)->get();
        $filename = 'assets-export-' . now()->format('Y-m-d-His') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function () use ($assets) {
            $file = fopen('php://output', 'w');

            // UTF-8 BOM for Excel compatibility
            fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));

            // Header row
            fputcsv($file, [
                'Equipment No', 'Description', 'Tech Ident No', 'Object Type',
                'Functional Loc', 'Company', 'Department', 'Area', 'Sub Area',
                'Manufacturer', 'Model Number', 'Construct Year', 'Status',
                'Data Source', 'Imported At', 'Created At', 'Updated At'
            ]);

            // Data rows
            foreach ($assets as $asset) {
                fputcsv($file, [
                    $asset->equipment_no,
                    $asset->description,
                    $asset->tech_ident_no,
                    $asset->object_type,
                    $asset->functional_loc,
                    $asset->company?->code ?? '',
                    $asset->department?->code ?? '',
                    $asset->area?->code ?? '',
                    $asset->subArea?->code ?? '',
                    $asset->manufacturer,
                    $asset->model_number,
                    $asset->construct_year,
                    $asset->status,
                    $asset->data_source,
                    $asset->imported_at?->format('Y-m-d H:i:s') ?? '',
                    $asset->created_at->format('Y-m-d H:i:s'),
                    $asset->updated_at->format('Y-m-d H:i:s'),
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function getDepartments(Request $request)
    {
        $departments = Department::where('company_id', $request->company_id)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        return response()->json($departments);
    }

    public function getAreas(Request $request)
    {
        $areas = Area::where('department_id', $request->department_id)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        return response()->json($areas);
    }

    public function getSubAreas(Request $request)
    {
        $subAreas = SubArea::where('area_id', $request->area_id)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        return response()->json($subAreas);
    }

    public function getAssignedTechnicians(Asset $asset)
    {
        $technicians = Technician::where('status', 'active')
            ->orderBy('name')
            ->get()
            ->map(function ($t) use ($asset) {
                $pivot = $asset->technicians->find($t->id)?->pivot;
                $t->is_assigned = !is_null($pivot);
                $t->pivot_note = $pivot?->note;
                $t->pivot_assigned_at = $pivot?->assigned_at;
                return $t;
            });

        return response()->json($technicians);
    }

    public function assignTechnician(Request $request, Asset $asset, TelegramService $telegram)
    {
        $validated = $request->validate([
            'technician_id' => 'required|exists:technicians,id',
            'note' => 'nullable|string|max:500',
        ]);

        $technician = Technician::findOrFail($validated['technician_id']);

        $asset->technicians()->syncWithoutDetaching([
            $technician->id => [
                'note' => $validated['note'] ?? null,
                'assigned_by' => auth()->id(),
                'assigned_at' => now(),
            ],
        ]);

        // Broadcast to technician if has telegram
        $broadcasted = false;
        if ($technician->telegram_id && $technician->status === 'active') {
            $assetInfo = $telegram->formatAssetInfo($asset);
            $message = "Anda ditugaskan untuk menangani asset berikut:\n\n{$assetInfo}\n\n";
            if ($validated['note']) {
                $message .= "📝 <b>Catatan:</b> " . e($validated['note']);
            }
            $broadcasted = $telegram->sendMessage($technician->telegram_id, $message);
        }

        return response()->json([
            'success' => true,
            'message' => 'Teknisi berhasil ditambahkan.',
            'broadcasted' => $broadcasted,
            'technician' => [
                'id' => $technician->id,
                'name' => $technician->name,
                'nik' => $technician->nik,
                'telegram_username' => $technician->telegram_username,
            ],
        ]);
    }

    public function removeTechnician(Asset $asset, Technician $technician)
    {
        $asset->technicians()->detach($technician->id);

        return response()->json([
            'success' => true,
            'message' => 'Teknisi berhasil dihapus dari asset ini.',
        ]);
    }

    public function broadcastToTechnicians(Request $request, Asset $asset, TelegramService $telegram)
    {
        $validated = $request->validate([
            'message' => 'required|string',
            'technician_ids' => 'required|array',
            'technician_ids.*' => 'exists:technicians,id',
        ]);

        $assetInfo = $telegram->formatAssetInfo($asset);
        $results = $telegram->broadcastToTechnicians(
            $validated['technician_ids'],
            $validated['message'],
            $assetInfo
        );

        return response()->json([
            'success' => true,
            'message' => "Broadcast selesai. Terkirim: {$results['sent']}, Gagal: {$results['failed']} dari {$results['total']} teknisi.",
            'results' => $results,
        ]);
    }

    public function listTechnicians(Asset $asset)
    {
        $asset->load('technicians');
        return response()->json($asset->technicians->map(function ($t) {
            return [
                'id' => $t->id,
                'name' => $t->name,
                'nik' => $t->nik,
                'telegram_username' => $t->telegram_username,
                'has_telegram' => !is_null($t->telegram_id),
                'note' => $t->pivot->note,
                'assigned_at' => $t->pivot->assigned_at?->diffForHumans(),
            ];
        }));
    }

    private function buildExportQuery(Request $request)
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

        $query->latest();
        return $query;
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'equipment_no' => 'nullable|unique:assets',
            'description' => 'nullable|string',
            'tech_ident_no' => 'nullable|string',
            'object_type' => 'nullable|string',
            'functional_loc' => 'nullable|string',
            'company_id' => 'nullable|exists:companies,id',
            'department_id' => 'nullable|exists:departments,id',
            'area_id' => 'nullable|exists:areas,id',
            'sub_area_id' => 'nullable|exists:sub_areas,id',
            'manufacturer' => 'nullable|string',
            'model_number' => 'nullable|string',
            'construct_year' => 'nullable|string',
            'status' => 'required|in:active,inactive,needs_review',
        ]);

        $validated['has_equipment_no'] = !empty($validated['equipment_no']);
        $validated['data_source'] = 'manual';

        Asset::create($validated);

        return redirect()->route('assets.index')
            ->with('success', 'Asset berhasil ditambahkan.');
    }

    public function edit(Asset $asset)
    {
        $companies = Company::all();
        $departments = Department::where('company_id', $asset->company_id)->get();
        $areas = Area::where('department_id', $asset->department_id)->get();
        return view('assets.edit', compact('asset', 'companies', 'departments', 'areas'));
    }

    public function update(Request $request, Asset $asset)
    {
        $validated = $request->validate([
            'equipment_no' => 'nullable|unique:assets,equipment_no,' . $asset->id,
            'description' => 'nullable|string',
            'tech_ident_no' => 'nullable|string',
            'object_type' => 'nullable|string',
            'functional_loc' => 'nullable|string',
            'company_id' => 'nullable|exists:companies,id',
            'department_id' => 'nullable|exists:departments,id',
            'area_id' => 'nullable|exists:areas,id',
            'sub_area_id' => 'nullable|exists:sub_areas,id',
            'manufacturer' => 'nullable|string',
            'model_number' => 'nullable|string',
            'construct_year' => 'nullable|string',
            'status' => 'required|in:active,inactive,needs_review',
        ]);

        $validated['has_equipment_no'] = !empty($validated['equipment_no']);
        $asset->update($validated);

        return redirect()->route('assets.index')
            ->with('success', 'Asset berhasil diperbarui.');
    }

    public function destroy(Asset $asset)
    {
        $asset->delete();
        return redirect()->route('assets.index')
            ->with('success', 'Asset berhasil dihapus.');
    }
}
