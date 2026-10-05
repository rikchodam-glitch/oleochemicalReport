{{-- Field pelengkap laporan pada form edit: tipe laporan & status pekerjaan,
     plus keterangan pelaksana/kode laporan (hanya baca) sebagai konteks.
     Status di sini menyetel completed_at dengan aturan yang sama seperti tombol
     status di halaman detail (lihat ReportController::update()).
     Dipisah jadi partial supaya blade halaman edit tidak perlu ditulis ulang
     (lihat aturan edit blade di AGENTS.md).
     Parameter: $report. --}}
<div class="border-t border-slate-100 pt-4">
    <h3 class="text-sm font-medium text-slate-900 mb-3">Tipe & Status Laporan</h3>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            <label for="report_type" class="block text-sm font-medium text-slate-700 mb-1">Tipe Laporan</label>
            <select id="report_type" name="report_type"
                    class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                <option value="equipment_repair" {{ old('report_type', $report->report_type) === 'equipment_repair' ? 'selected' : '' }}>Perbaikan Equipment</option>
                <option value="area_work" {{ old('report_type', $report->report_type) === 'area_work' ? 'selected' : '' }}>Pekerjaan Area</option>
                <option value="general" {{ old('report_type', $report->report_type) === 'general' ? 'selected' : '' }}>Umum</option>
            </select>
            @error('report_type')
                <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="status" class="block text-sm font-medium text-slate-700 mb-1">Status Pekerjaan</label>
            <select id="status" name="status"
                    class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                <option value="completed" {{ old('status', $report->status) === 'completed' ? 'selected' : '' }}>Selesai (completed)</option>
                <option value="continue" {{ old('status', $report->status) === 'continue' ? 'selected' : '' }}>Belum Selesai (continue)</option>
                <option value="needs_review" {{ old('status', $report->status) === 'needs_review' ? 'selected' : '' }}>Perlu Review (needs_review)</option>
            </select>
            @error('status')
                <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <dl class="mt-3 grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs text-slate-500">
        <div>
            <dt>Kode Laporan</dt>
            <dd class="font-mono text-slate-700">{{ $report->report_code ?? ('#' . $report->id) }}</dd>
        </div>
        <div>
            <dt>Teknisi Pelaksana</dt>
            <dd class="text-slate-700">{{ $report->technician?->name ?? '-' }}</dd>
        </div>
        <div>
            <dt>Selesai Pada</dt>
            <dd class="text-slate-700">
                {{ $report->completed_at?->format('d/m/Y H:i') ?? '-' }}
                <span class="block text-slate-400">Mengikuti pilihan status di atas saat disimpan.</span>
            </dd>
        </div>
    </dl>
</div>
