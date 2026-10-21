<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Report;
use App\Models\Technician;
use App\Services\ReportCopyService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Form laporan web untuk teknisi yang sudah login.
 *
 * Ini adalah alternatif MVP dari wizard Telegram: teknisi membuat laporan
 * atas namanya sendiri, memilih area/alat, mengisi detail pekerjaan, foto,
 * dan kolaborator. Salinan kolaborasi tetap dibuat seperti jalur Telegram.
 */
class ReportFormController extends Controller
{
    /**
     * Tampilkan form laporan teknisi.
     *
     * @return \Illuminate\View\View
     */
    public function create()
    {
        $teknisi = $this->teknisi();

        return view('reports.form-teknisi', [
            'teknisi'      => $teknisi,
            'areas'        => Area::orderBy('code')->get(['id', 'code', 'name']),
            'kolaborator'  => Technician::query()
                ->where('status', 'active')
                ->where('id', '!=', $teknisi->id)
                ->orderBy('name')
                ->get(['id', 'name', 'nik', 'group']),
        ]);
    }

    /**
     * Simpan laporan yang dibuat dari form web.
     *
     * @param  Request $request Request form
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        $teknisi = $this->teknisi();

        $maxKb = (int) (config('telegram.photo_max_bytes', 20 * 1024 * 1024) / 1024);

        $data = $request->validate([
            'report_date'             => ['required', 'date'],
            'report_type'             => ['required', Rule::in(['equipment_repair', 'area_work', 'general'])],
            'work_classification'     => ['nullable', Rule::in(['zpm1', 'zpm3', 'zpm6', 'zpm5', 'zpm7', 'zpm8'])],
            'jenis_pekerjaan'         => ['nullable', Rule::in(['CM', 'dCM', 'PM', 'EI', 'Mekanik', 'CM/PM', 'Fabrikasi', 'Sipil', 'IT', 'Project', 'UMUM'])],
            'sesuai_rencana'          => ['nullable', Rule::in(['ya', 'tidak'])],
            'area_id'                 => ['nullable', 'exists:areas,id'],
            'asset_id'                => ['nullable', 'exists:assets,id'],
            'equipment_tag'           => ['nullable', 'string', 'max:100'],
            'tanggal_kejadian'        => ['nullable', 'date'],
            'work_description'        => ['required', 'string'],
            'work_duration_minutes'   => ['required', 'integer', 'min:0', 'max:1440'],
            'root_cause'              => ['nullable', 'string'],
            'finding'                 => ['nullable', 'string'],
            'final_status'            => ['required', Rule::in(['completed', 'continue'])],
            'collaborator_ids'        => ['nullable', 'array'],
            'collaborator_ids.*'      => ['integer', 'exists:technicians,id'],
            'photos_documentation'    => ['nullable', 'array'],
            'photos_documentation.*'  => ['image', 'mimes:jpg,jpeg,png,webp', 'max:' . $maxKb],
            'photos_hygiene'          => ['nullable', 'array'],
            'photos_hygiene.*'        => ['image', 'mimes:jpg,jpeg,png,webp', 'max:' . $maxKb],
        ]);

        if (($data['jenis_pekerjaan'] ?? '') === 'dCM' && empty($data['sesuai_rencana'])) {
            return back()->withErrors(['sesuai_rencana' => 'Untuk pekerjaan dCM, "Sesuai Rencana" wajib diisi.'])
                ->withInput();
        }

        if (($data['jenis_pekerjaan'] ?? '') !== 'dCM') {
            $data['sesuai_rencana'] = null;
        }

        if ($data['report_type'] === 'equipment_repair' && empty($data['asset_id'])) {
            return back()->withErrors(['asset_id' => 'Pilih alat/equipment untuk laporan perbaikan equipment.'])
                ->withInput();
        }

        if ($data['report_type'] === 'area_work' && empty($data['area_id'])) {
            return back()->withErrors(['area_id' => 'Pilih area untuk laporan pekerjaan area.'])
                ->withInput();
        }

        $aset = ! empty($data['asset_id']) ? Asset::find($data['asset_id']) : null;
        $areaId = $aset?->area_id ?? ($data['area_id'] ?? null);

        $report = Report::create([
            'technician_id'         => $teknisi->id,
            'creator_id'            => $teknisi->id,
            'report_date'           => $data['report_date'],
            'report_code'           => Report::generateReportCode(),
            'work_description'      => $data['work_description'],
            'work_duration_minutes' => $data['work_duration_minutes'],
            'root_cause'            => $data['root_cause'] ?? null,
            'finding'               => $data['finding'] ?? null,
            'report_type'           => $data['report_type'],
            'work_classification'   => $data['work_classification'] ?? null,
            'jenis_pekerjaan'       => $data['jenis_pekerjaan'] ?? null,
            'sesuai_rencana'        => $data['sesuai_rencana'] ?? null,
            'tanggal_kejadian'      => $data['tanggal_kejadian'] ?? null,
            'equipment_tag'         => $aset?->tech_ident_no ?? ($data['equipment_tag'] ?? null),
            'asset_id'              => $aset?->id,
            'funcloc_id'            => $aset?->funcloc_id,
            'area_id'               => $areaId,
            'status'                => $data['final_status'],
            'completed_at'          => $data['final_status'] === 'completed' ? now() : null,
            'submitted_at'          => now(),
            'wizard_started_at'     => now(),
            'is_manually_edited'    => true,
            'ai_analyzed'           => false,
            'photo_documentation'   => [],
            'photo_hygiene_clearance' => [],
        ]);

        $this->simpanFoto($report, $request, 'photos_documentation', 'photo_documentation');
        $this->simpanFoto($report, $request, 'photos_hygiene', 'photo_hygiene_clearance');

        $kolaboratorIds = collect($data['collaborator_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->reject(fn ($id) => $id === (int) $teknisi->id)
            ->unique()
            ->values();

        if ($kolaboratorIds->isNotEmpty()) {
            $salinan = app(ReportCopyService::class);

            Technician::query()
                ->whereIn('id', $kolaboratorIds)
                ->where('status', 'active')
                ->get()
                ->each(fn (Technician $rekan) => $salinan->buatSalinanKolaborasi($report, $rekan));

            // Broadcast kolaborasi tersedia setelah modul broadcast di-merge.
            if (class_exists(\App\Jobs\KirimBroadcastKolaborasi::class)) {
                \App\Jobs\KirimBroadcastKolaborasi::dispatch($report->id);
            }
        }

        return redirect()
            ->route('reports.show', $report)
            ->with('success', 'Laporan berhasil dibuat dari form teknisi.');
    }

    /**
     * Simpan foto dari form ke disk yang sama dengan foto Telegram.
     *
     * @param  Report  $report Laporan
     * @param  Request $request Request
     * @param  string  $input  Nama input file
     * @param  string  $kolom  Kolom JSON pada reports
     * @return void
     */
    private function simpanFoto(Report $report, Request $request, string $input, string $kolom): void
    {
        $berkas = $request->file($input, []);

        if (! is_array($berkas) || $berkas === []) {
            return;
        }

        $disk   = config('telegram.photo_disk', 'public');
        $folder = config('telegram.photo_folder', 'reports');
        $paths  = [];

        foreach ($berkas as $file) {
            $paths[] = $file->store(
                "{$folder}/" . now()->format('Y/m/d') . "/{$report->id}",
                $disk
            );
        }

        $report->update([$kolom => array_values($paths)]);
    }

    /**
     * Teknisi pemilik akun yang sedang login.
     *
     * @return Technician
     */
    private function teknisi(): Technician
    {
        $teknisi = auth()->user()?->technician;

        if ($teknisi === null) {
            abort(403, 'Akun ini belum terhubung ke data teknisi. Hubungi administrator.');
        }

        if ($teknisi->status !== 'active') {
            abort(403, 'Akun teknisi ini sedang tidak aktif.');
        }

        return $teknisi;
    }
}
