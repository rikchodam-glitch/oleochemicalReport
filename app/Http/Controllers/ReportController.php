<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Traits\ReportLocationTrait;
use App\Models\Area;
use App\Models\Asset;
use App\Models\FunctionalLocation;
use App\Models\Report;
use App\Models\Technician;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;

class ReportController extends Controller
{
    use ReportLocationTrait;

    public function index(Request $request)
    {
        $query = Report::with(['technician', 'area', 'asset', 'creator', 'linkedFinding'])
            ->withCount(['collaboratorReports as collaborator_count']);

        // Filter by date range
        if ($request->filled('date_from')) {
            $query->whereDate('report_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('report_date', '<=', $request->date_to);
        }

        // Filter by report type
        if ($request->filled('report_type')) {
            $query->where('report_type', $request->report_type);
        }

        // Filter by work classification (ZPM 1/3/6/5/7, dipilih teknisi
        // di step awal wizard — berbeda dari report_type)
        if ($request->filled('work_classification')) {
            $query->where('work_classification', $request->work_classification);
        }

        // Filter by area
        if ($request->filled('area_id')) {
            $query->where('area_id', $request->area_id);
        }

        // Filter by technician
        if ($request->filled('technician_id')) {
            $query->where('technician_id', $request->technician_id);
        }

        // Filter by kode alat (tech_ident_no pada tabel assets)
        if ($request->filled('asset_code')) {
            $assetCode = $request->asset_code;
            $query->whereHas('asset', function ($q) use ($assetCode) {
                $q->where('tech_ident_no', 'like', '%' . $assetCode . '%');
            });
        }

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('work_description', 'like', "%{$search}%");
            });
        }

        // Filter by report code (text search)
        if ($request->filled('report_code')) {
            $query->where('report_code', 'like', '%' . $request->report_code . '%');
        }

        // Filter by jenis pekerjaan
        if ($request->filled('jenis_pekerjaan')) {
            $query->where('jenis_pekerjaan', $request->jenis_pekerjaan);
        }

        $reports = $query->latest()->paginate(20)->withQueryString();

        // Append jumlah foto dokumentasi & hygiene ke setiap laporan di
        // halaman ini (bukan kolom DB — dihitung dari array JSON).
        // Dipakai Sesi 3 untuk kolom indikator foto di index.blade.php.
        $reports->getCollection()->transform(function (Report $report) {
            $report->photo_doc_count = count($report->photo_documentation ?? []);
            $report->photo_hyg_count = count($report->photo_hygiene_clearance ?? []);

            return $report;
        });

        $areas = Area::all();

        // Hitung ringkasan jenis pekerjaan dari seluruh dataset (tanpa filter halaman)
        $summaryQuery = Report::query();
        if ($request->filled('date_from')) {
            $summaryQuery->whereDate('report_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $summaryQuery->whereDate('report_date', '<=', $request->date_to);
        }
        $totalLaporan   = (clone $summaryQuery)->count();
        $countEI        = (clone $summaryQuery)->where('jenis_pekerjaan', 'EI')->count();
        $countMekanik   = (clone $summaryQuery)->where('jenis_pekerjaan', 'Mekanik')->count();
        $countCmPm      = (clone $summaryQuery)->where('jenis_pekerjaan', 'CM/PM')->count();
        $countFabrikasi = (clone $summaryQuery)->where('jenis_pekerjaan', 'Fabrikasi')->count();
        $countSipil     = (clone $summaryQuery)->where('jenis_pekerjaan', 'Sipil')->count();

        $technicians = Technician::orderBy('name')->get(['id', 'name', 'nik']);

        return view('reports.index', compact(
            'reports', 'areas', 'technicians',
            'totalLaporan', 'countEI', 'countMekanik', 'countCmPm', 'countFabrikasi', 'countSipil'
        ));
    }

    /**
     * Tampilkan form laporan baru (dibuat manual oleh admin di web).
     *
     * @return \Illuminate\View\View
     */
    public function create()
    {
        $areas = Area::orderBy('code')->get(['id', 'code', 'name']);

        return view('reports.create', compact('areas'));
    }

    /**
     * Simpan laporan baru yang dibuat manual oleh admin di web.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'report_date'             => 'required|date',
            'work_description'        => 'required|string',
            'work_duration_minutes'   => 'required|integer|min:0',
            'root_cause'              => 'nullable|string',
            'area_id'                 => 'nullable|exists:areas,id',
            'funcloc_id'              => 'nullable|exists:functional_locations,id',
            'asset_id'                => 'nullable|exists:assets,id',
            'report_type'             => 'required|in:equipment_repair,area_work,general',
            'work_classification'     => 'nullable|in:zpm1,zpm3,zpm6,zpm5,zpm7',
            'jenis_pekerjaan'         => 'nullable|in:CM,dCM,PM,EI,Mekanik,CM/PM,Fabrikasi,Sipil,IT',
            'linked_finding_id'       => 'nullable|exists:cm_findings,id',
            'sesuai_rencana'          => 'nullable|in:ya,tidak',
            'tanggal_kejadian'        => 'nullable|date',
            'equipment_tag'           => 'nullable|string|max:100',
        ]);

        // Validasi khusus dCM: sesuai_rencana wajib bila jenis dCM
        if (($validated['jenis_pekerjaan'] ?? '') === 'dCM' && empty($validated['sesuai_rencana'])) {
            return back()->withErrors(['sesuai_rencana' => 'Untuk pekerjaan dCM, field "Sesuai Rencana" wajib diisi.'])
                ->withInput();
        }

        // Validasi non-dCM: null-kan sesuai_rencana
        if (($validated['jenis_pekerjaan'] ?? '') !== 'dCM') {
            $validated['sesuai_rencana'] = null;
        }

        $validated['report_code']    = Report::generateReportCode();
        $validated['status']         = $request->status ?? 'draft';
        $validated['is_manually_edited'] = true;

        $report = Report::create($validated);

        return redirect()->route('reports.show', $report)
            ->with('success', 'Laporan berhasil dibuat.');
    }

    public function show(Report $report)
{
    $report->load([
        'technician',
        'area',
        'asset',
        'aiSuggestions.suggestedArea',
        'aiSuggestions.suggestedAsset',
        'creator',
        'parentReport.technician',
        'collaboratorReports.technician',
        'linkedFinding',
        'cmEquipment',
    ]);

    // Variabel untuk dropdown inline edit di show.blade.php.
    // Mengikuti pola yang sama dengan edit() agar prefetch awal sudah terisi.
    $areas = Area::orderBy('code')->get(['id', 'code', 'name']);

    $funcLocs = $report->area_id
        ? FunctionalLocation::where('area_id', $report->area_id)->orderBy('code')->get(['id', 'code', 'name', 'level'])
        : collect();

    $assets = $report->area_id
        ? Asset::where('area_id', $report->area_id)->orderBy('tech_ident_no')->get(['id', 'equipment_no', 'tech_ident_no', 'description'])
        : collect();

    return view('reports.show', compact('report', 'areas', 'funcLocs', 'assets'));
}

    /**
     * Tampilkan form edit laporan untuk admin.
     * Functional Location dan Asset di-prefetch berdasarkan area_id laporan
     * saat ini (jika ada) agar dropdown sudah terisi tanpa perlu AJAX awal,
     * mengikuti pola yang sama dengan AssetController@edit.
     *
     * @param  Report  $report  Laporan yang akan diedit.
     * @return \Illuminate\View\View
     */
    public function edit(Report $report)
    {
        $areas = Area::orderBy('code')->get(['id', 'code', 'name']);

        $funcLocs = $report->area_id
            ? FunctionalLocation::where('area_id', $report->area_id)->orderBy('code')->get(['id', 'code', 'name', 'level'])
            : collect();

        $assets = $report->area_id
            ? Asset::where('area_id', $report->area_id)->orderBy('tech_ident_no')->get(['id', 'equipment_no', 'tech_ident_no', 'description'])
            : collect();

        return view('reports.edit', compact('report', 'areas', 'funcLocs', 'assets'));
    }

    /**
     * Simpan perubahan hasil edit admin pada laporan.
     * Menandai laporan sebagai is_manually_edited agar panel AI di halaman
     * show/index menampilkan badge "Edited" alih-alih persentase confidence.
     * Termasuk perubahan Area, Functional Location, dan Asset (kode alat).
     *
     * @param  Request  $request  Data form edit.
     * @param  Report  $report  Laporan yang diperbarui.
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request, Report $report)
    {
        $validated = $request->validate([
            'work_description'       => 'required|string',
            'work_duration_minutes'  => 'required|integer|min:0',
            'root_cause'              => 'nullable|string',
            'report_date'             => 'required|date',
            'area_id'                 => 'nullable|exists:areas,id',
            'funcloc_id'              => 'nullable|exists:functional_locations,id',
            'asset_id'                => 'nullable|exists:assets,id',
            'work_classification'     => 'nullable|in:zpm1,zpm3,zpm6,zpm5,zpm7',
            'jenis_pekerjaan'         => 'nullable|in:CM,dCM,PM,EI,Mekanik,CM/PM,Fabrikasi,Sipil,IT',
            'linked_finding_id'       => 'nullable|exists:cm_findings,id',
            'sesuai_rencana'          => 'nullable|in:ya,tidak',
            'tanggal_kejadian'        => 'nullable|date',
            'equipment_tag'           => 'nullable|string|max:100',
        ]);

        // Validasi khusus dCM: sesuai_rencana wajib jika jenis diisi dCM
        if (($validated['jenis_pekerjaan'] ?? '') === 'dCM' && empty($validated['sesuai_rencana'])) {
            return back()->withErrors(['sesuai_rencana' => 'Untuk pekerjaan dCM, field "Sesuai Rencana" wajib diisi.'])
                ->withInput();
        }

        // Validasi non-dCM: null-kan sesuai_rencana
        if (($validated['jenis_pekerjaan'] ?? '') !== 'dCM') {
            $validated['sesuai_rencana'] = null;
        }

        // Form edit web belum menyediakan pilihan jenis pekerjaan IT (hanya di
        // wizard Telegram), sehingga menyimpan lewat form ini tidak boleh
        // menghapus klasifikasi IT yang sudah tercatat.
        if (empty($validated['jenis_pekerjaan']) && $report->jenis_pekerjaan === 'IT') {
            $validated['jenis_pekerjaan'] = 'IT';
        }

        $validated['is_manually_edited'] = true;

        $report->update($validated);

        return redirect()->route('reports.show', $report)
            ->with('success', 'Laporan berhasil diperbarui.');
    }

    public function updateStatus(Request $request, Report $report)
    {
        $request->validate([
            'status' => 'required|in:draft,needs_review,completed',
        ]);

        $data = ['status' => $request->status];
        if ($request->status === 'completed') {
            $data['completed_at'] = now();
        } else {
            $data['completed_at'] = null;
        }

        $report->update($data);

        return back()->with('success', 'Status laporan berhasil diperbarui.');
    }

    /**
     * Tambah foto ke laporan lewat upload manual admin (web), bukan dari bot.
     * Disk & folder mengikuti config('telegram.photo_disk'/'photo_folder')
     * yang sama dipakai PhotoStorageService, supaya accessor URL di Model
     * Report tetap konsisten untuk foto dari sumber manapun.
     */
    public function addPhoto(Request $request, Report $report)
    {
        $maxKb = (int) (config('telegram.photo_max_bytes', 20 * 1024 * 1024) / 1024);

        $request->validate([
            'photo' => "required|image|mimes:jpg,jpeg,png,webp|max:{$maxKb}",
            'type'  => 'required|in:documentation,hygiene',
        ]);

        $disk   = config('telegram.photo_disk', 'public');
        $folder = config('telegram.photo_folder', 'reports');

        $path = $request->file('photo')->store(
            "{$folder}/" . now()->format('Y/m/d') . "/{$report->id}",
            $disk
        );

        if ($request->type === 'hygiene') {
            $photos   = $report->photo_hygiene_clearance ?? [];
            $photos[] = $path;
            $report->update(['photo_hygiene_clearance' => $photos]);
        } else {
            $photos   = $report->photo_documentation ?? [];
            $photos[] = $path;
            $report->update(['photo_documentation' => $photos]);
        }

        return back()->with('success', 'Foto berhasil ditambahkan ke laporan.');
    }

    /**
     * Hapus satu foto dari laporan (dokumentasi atau hygiene clearance).
     * Dipanggil via fetch() dari halaman detail — return JSON, tidak redirect.
     * File fisik dihapus dari storage; array di DB di-splice dan di-reindex.
     *
     * @param  Request  $request  Query param: type (documentation|hygiene)
     * @param  Report   $report   Laporan yang fotonya dihapus.
     * @param  int      $index    Index foto dalam array JSON (0-based).
     * @return \Illuminate\Http\JsonResponse
     */
    public function deletePhoto(Request $request, Report $report, int $index)
    {
        $request->validate([
            'type' => 'required|in:documentation,hygiene',
        ]);

        // Tentukan kolom DB berdasarkan tipe foto
        $column = $request->type === 'hygiene'
            ? 'photo_hygiene_clearance'
            : 'photo_documentation';

        $photos = $report->{$column} ?? [];

        // Pastikan index valid sebelum proses apapun
        if (!array_key_exists($index, $photos)) {
            return response()->json([
                'success' => false,
                'message' => 'Foto tidak ditemukan pada index tersebut.',
            ], 404);
        }

        // Hapus file fisik dari storage (gagal diam-diam jika file sudah tidak ada)
        $disk = config('telegram.photo_disk', 'public');
        Storage::disk($disk)->delete($photos[$index]);

        // Buang elemen dari array, pastikan tetap sequential (bukan associative)
        // agar disimpan sebagai JSON array bukan JSON object
        array_splice($photos, $index, 1);

        $report->update([$column => array_values($photos)]);

        return response()->json(['success' => true]);
    }

    public function exportCsv(Request $request)
    {
        $query = Report::with(['technician', 'area']);

        if ($request->filled('date_from')) {
            $query->whereDate('report_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('report_date', '<=', $request->date_to);
        }

        $reports = $query->latest()->get();

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="reports-export.csv"',
        ];

        $callback = function () use ($reports) {
            $output = fopen('php://output', 'w');
            fputcsv($output, [
                'Kode Laporan',
                'Tanggal',
                'Teknisi',
                'Deskripsi',
                'Area',
                'Tipe',
                'Klasifikasi',
                'Jenis Pekerjaan',
                'Status',
                'Durasi (menit)',
                'Root Cause',
                'Jml Foto Dokumentasi',
                'Jml Foto Hygiene',
                'AI Confidence',
            ]);

            foreach ($reports as $report) {
                // Hitung durasi dalam menit jika wizard_started_at dan submitted_at tersedia
                $durasiMenit = '-';
                if (!empty($report->wizard_started_at) && !empty($report->submitted_at)) {
                    $durasiMenit = (int) \Carbon\Carbon::parse($report->wizard_started_at)
                        ->diffInMinutes(\Carbon\Carbon::parse($report->submitted_at));
                }

                fputcsv($output, [
                    $report->report_code ?? '-',
                    $report->report_date->format('Y-m-d'),
                    $report->technician->name,
                    $report->work_description,
                    $report->area?->code ?? '-',
                    $report->report_type,
                    $report->work_classification ?? '-',
                    $report->jenis_pekerjaan ?? '-',
                    $report->status,
                    $durasiMenit,
                    $report->root_cause ?? '-',
                    count($report->photo_documentation ?? []),
                    count($report->photo_hygiene_clearance ?? []),
                    $report->ai_confidence ? $report->ai_confidence . '%' : '-',
                ]);
            }

            fclose($output);
        };

        return Response::stream($callback, 200, $headers);
    }

    public function destroy(Report $report)
    {
        $report->delete();
        return redirect()->route('reports.index')
            ->with('success', 'Laporan berhasil dihapus.');
    }
}
