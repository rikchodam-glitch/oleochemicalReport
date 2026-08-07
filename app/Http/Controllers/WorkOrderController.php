<?php

namespace App\Http\Controllers;

use App\Models\CmEquipment;
use App\Models\CmFinding;
use App\Models\WorkOrder;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkOrderController extends Controller
{
    /**
     * Tampilkan daftar Laporan Kerjaan (Work Order).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\View\View
     */
    public function index(Request $request)
    {
        $filterPt       = $request->get('pt', '');
        $filterJenis    = $request->get('jenis', '');
        $filterTag      = $request->get('equipment_tag', '');
        $filterDari     = $request->get('dari', now()->startOfMonth()->format('Y-m-d'));
        $filterSampai   = $request->get('sampai', now()->format('Y-m-d'));
        $search         = $request->get('search', '');

        $query = WorkOrder::with(['cmEquipment', 'linkedFinding', 'creator']);

        if ($filterJenis) {
            $query->where('jenis_pekerjaan', $filterJenis);
        }
        if ($filterTag) {
            $query->where('equipment_tag', $filterTag);
        }
        if ($filterPt) {
            $query->whereHas('cmEquipment', fn($q) => $q->where('pt_location', $filterPt));
        }
        if ($filterDari && $filterSampai) {
            $query->whereBetween('tanggal_kejadian', [$filterDari, $filterSampai]);
        }
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('equipment_tag', 'like', "%{$search}%")
                  ->orWhere('deskripsi', 'like', "%{$search}%")
                  ->orWhere('root_cause', 'like', "%{$search}%");
            });
        }

        $workOrders = $query->orderBy('tanggal_kejadian', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate(20)
            ->withQueryString();

        // Data untuk filter dropdown
        $ptList         = CmEquipment::select('pt_location')->distinct()->pluck('pt_location');
        $equipmentList  = WorkOrder::select('equipment_tag')->distinct()->orderBy('equipment_tag')->pluck('equipment_tag');

        return view('work-orders.index', compact(
            'workOrders', 'ptList', 'equipmentList',
            'filterPt', 'filterJenis', 'filterTag',
            'filterDari', 'filterSampai', 'search'
        ));
    }

    /**
     * Tampilkan form tambah Laporan Kerjaan.
     *
     * @return \Illuminate\View\View
     */
    public function create()
    {
        $equipmentList = CmEquipment::orderBy('equipment_tag')->get(['id', 'equipment_tag', 'pt_location', 'plant']);

        return view('work-orders.create', compact('equipmentList'));
    }

    /**
     * Simpan Laporan Kerjaan baru.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'equipment_tag'           => ['required', 'string', 'max:50', 'exists:cm_equipment,equipment_tag'],
            'tanggal_kejadian'        => ['required', 'date', 'before_or_equal:tanggal_eksekusi_selesai'],
            'tanggal_eksekusi_selesai' => ['required', 'date', 'after_or_equal:tanggal_kejadian'],
            'jenis_pekerjaan'         => ['required', 'in:CM,dCM,PM'],
            'linked_finding_id'       => ['nullable', 'exists:cm_findings,id'],
            'deskripsi'               => ['nullable', 'string'],
            'root_cause'              => ['nullable', 'string', 'max:255'],
            'sesuai_rencana'          => ['nullable', 'in:ya,tidak'],
        ], [
            'tanggal_kejadian.before_or_equal' => 'Tanggal kejadian tidak boleh melebihi tanggal eksekusi selesai.',
            'tanggal_eksekusi_selesai.after_or_equal' => 'Tanggal eksekusi selesai tidak boleh kurang dari tanggal kejadian.',
        ]);

        // Validasi khusus dCM: sesuai_rencana wajib diisi
        if ($validated['jenis_pekerjaan'] === 'dCM' && empty($validated['sesuai_rencana'])) {
            return back()->withErrors(['sesuai_rencana' => 'Untuk pekerjaan dCM, field "Sesuai Rencana" wajib diisi.'])
                ->withInput();
        }

        // Validasi khusus non-dCM: sesuai_rencana harus null
        if ($validated['jenis_pekerjaan'] !== 'dCM') {
            $validated['sesuai_rencana'] = null;
        }

        $validated['created_by'] = Auth::id();

        $workOrder = WorkOrder::create($validated);

        return redirect()->route('work-orders.index')
            ->with('success', 'Laporan Kerjaan berhasil ditambahkan (#' . $workOrder->id . ').');
    }

    /**
     * Tampilkan detail Laporan Kerjaan.
     *
     * @param  \App\Models\WorkOrder  $workOrder
     * @return \Illuminate\View\View
     */
    public function show(WorkOrder $workOrder)
    {
        $workOrder->load(['cmEquipment', 'linkedFinding', 'creator']);

        return view('work-orders.show', compact('workOrder'));
    }

    /**
     * Tampilkan form edit Laporan Kerjaan.
     *
     * @param  \App\Models\WorkOrder  $workOrder
     * @return \Illuminate\View\View
     */
    public function edit(WorkOrder $workOrder)
    {
        $equipmentList = CmEquipment::orderBy('equipment_tag')->get(['id', 'equipment_tag', 'pt_location', 'plant']);

        $findings = CmFinding::where('status', 'open')
            ->whereHas('equipment', fn($q) => $q->where('equipment_tag', $workOrder->equipment_tag))
            ->orderBy('tanggal_temuan', 'desc')
            ->get(['id', 'kode_finding', 'kategori', 'deskripsi', 'tanggal_temuan']);

        return view('work-orders.edit', compact('workOrder', 'equipmentList', 'findings'));
    }

    /**
     * Update Laporan Kerjaan.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\WorkOrder  $workOrder
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request, WorkOrder $workOrder)
    {
        $validated = $request->validate([
            'equipment_tag'           => ['required', 'string', 'max:50', 'exists:cm_equipment,equipment_tag'],
            'tanggal_kejadian'        => ['required', 'date', 'before_or_equal:tanggal_eksekusi_selesai'],
            'tanggal_eksekusi_selesai' => ['required', 'date', 'after_or_equal:tanggal_kejadian'],
            'jenis_pekerjaan'         => ['required', 'in:CM,dCM,PM'],
            'linked_finding_id'       => ['nullable', 'exists:cm_findings,id'],
            'deskripsi'               => ['nullable', 'string'],
            'root_cause'              => ['nullable', 'string', 'max:255'],
            'sesuai_rencana'          => ['nullable', 'in:ya,tidak'],
        ]);

        // Validasi khusus dCM
        if ($validated['jenis_pekerjaan'] === 'dCM' && empty($validated['sesuai_rencana'])) {
            return back()->withErrors(['sesuai_rencana' => 'Untuk pekerjaan dCM, field "Sesuai Rencana" wajib diisi.'])
                ->withInput();
        }

        // Validasi khusus non-dCM: null-kan sesuai_rencana
        if ($validated['jenis_pekerjaan'] !== 'dCM') {
            $validated['sesuai_rencana'] = null;
        }

        $workOrder->update($validated);

        return redirect()->route('work-orders.index')
            ->with('success', 'Laporan Kerjaan berhasil diperbarui.');
    }

    /**
     * Hapus Laporan Kerjaan.
     *
     * @param  \App\Models\WorkOrder  $workOrder
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(WorkOrder $workOrder)
    {
        $workOrder->delete();

        return redirect()->route('work-orders.index')
            ->with('success', 'Laporan Kerjaan berhasil dihapus.');
    }

    /**
     * API: Ambil daftar finding open berdasarkan equipment_tag.
     * Dipanggil via AJAX saat user memilih equipment di form.
     *
     * @param  string  $tag
     * @return \Illuminate\Http\JsonResponse
     */
    public function getFindingsByEquipment($tag)
    {
        $findings = CmFinding::where('status', 'open')
            ->whereHas('equipment', fn($q) => $q->where('equipment_tag', $tag))
            ->orderBy('tanggal_temuan', 'desc')
            ->get(['id', 'kode_finding', 'kategori', 'deskripsi', 'tanggal_temuan', 'severity']);

        return response()->json($findings);
    }
}
