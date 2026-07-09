<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Traits\ReportLocationTrait;
use App\Models\Area;
use App\Models\Asset;
use App\Models\FunctionalLocation;
use App\Models\Report;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;

class ReportController extends Controller
{
    use ReportLocationTrait;

    public function index(Request $request)
    {
        $query = Report::with(['technician', 'area', 'asset', 'creator'])
            ->withCount(['collaboratorReports as collaborator_count']);

        // Filter by date range
        if ($request->filled('date_from')) {
            $query->whereDate('report_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('report_date', '<=', $request->date_to);
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by report type
        if ($request->filled('report_type')) {
            $query->where('report_type', $request->report_type);
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

        // Filter by has_photo: '1' = ada foto (dokumentasi atau hygiene),
        // '0' = tidak ada foto sama sekali. NULL kolom dianggap "tidak ada foto".
        if ($request->filled('has_photo')) {
            if ($request->has_photo === '1') {
                $query->where(function ($q) {
                    $q->whereJsonLength('photo_documentation', '>', 0)
                      ->orWhereJsonLength('photo_hygiene_clearance', '>', 0);
                });
            } elseif ($request->has_photo === '0') {
                $query->where(function ($q) {
                    $q->whereNull('photo_documentation')
                      ->orWhereJsonLength('photo_documentation', 0);
                })->where(function ($q) {
                    $q->whereNull('photo_hygiene_clearance')
                      ->orWhereJsonLength('photo_hygiene_clearance', 0);
                });
            }
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

        return view('reports.index', compact('reports', 'areas'));
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
        ]);

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
