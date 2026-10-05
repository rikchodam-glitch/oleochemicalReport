{{-- Field kualifikasi & pelengkap laporan pada MODE EDIT halaman detail.
     Halaman detail (reports.show) sekarang satu-satunya tempat mengubah
     laporan (halaman reports.edit sudah dihapus), jadi semua isian di sini
     menempel ke form tersembunyi "form-edit-laporan" di show.blade.php.
     Status di sini menyetel completed_at dengan aturan yang sama seperti
     tombol status di halaman detail (lihat ReportController::update()).
     Parameter: $report. --}}
    <div class="space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label for="work_classification" class="block text-sm font-medium text-slate-700 mb-1">Klasifikasi Pekerjaan (Wizard)</label>
                <select id="work_classification" name="work_classification" form="form-edit-laporan"
                        onchange="syncJenisPekerjaan()"
                        class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">- Pilih Klasifikasi -</option>
                    <option value="zpm1" {{ old('work_classification', $report->work_classification) === 'zpm1' ? 'selected' : '' }}>ZPM 1 - Corrective Maintenance</option>
                    <option value="zpm3" {{ old('work_classification', $report->work_classification) === 'zpm3' ? 'selected' : '' }}>ZPM 3 - Preventive Maintenance</option>
                    <option value="zpm6" {{ old('work_classification', $report->work_classification) === 'zpm6' ? 'selected' : '' }}>ZPM 6 - Planned Maintenance</option>
                    <option value="zpm5" {{ old('work_classification', $report->work_classification) === 'zpm5' ? 'selected' : '' }}>ZPM 5 - Fabrikasi</option>
                    <option value="zpm7" {{ old('work_classification', $report->work_classification) === 'zpm7' ? 'selected' : '' }}>ZPM 7 - Sipil</option>
                    <option value="zpm8" {{ old('work_classification', $report->work_classification) === 'zpm8' ? 'selected' : '' }}>ZPM 8 - Project</option>
                </select>
                @error('work_classification')
                    <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="jenis_pekerjaan" class="block text-sm font-medium text-slate-700 mb-1">Jenis Pekerjaan</label>
                <select id="jenis_pekerjaan" name="jenis_pekerjaan" form="form-edit-laporan"
                        data-value="{{ old('jenis_pekerjaan', $report->jenis_pekerjaan) }}"
                        class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">- Pilih -</option>
                    <option value="CM" {{ old('jenis_pekerjaan', $report->jenis_pekerjaan) === 'CM' ? 'selected' : '' }}>CM (Corrective)</option>
                    <option value="dCM" {{ old('jenis_pekerjaan', $report->jenis_pekerjaan) === 'dCM' ? 'selected' : '' }}>dCM (Deferred)</option>
                    <option value="PM" {{ old('jenis_pekerjaan', $report->jenis_pekerjaan) === 'PM' ? 'selected' : '' }}>PM (Preventive)</option>
                    <option value="Project" {{ old('jenis_pekerjaan', $report->jenis_pekerjaan) === 'Project' ? 'selected' : '' }}>Project</option>
                </select>
                @error('jenis_pekerjaan')
                    <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="sesuai_rencana" class="block text-sm font-medium text-slate-700 mb-1">Sesuai Rencana (wajib jika dCM)</label>
                <select id="sesuai_rencana" name="sesuai_rencana" form="form-edit-laporan"
                        class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">- Pilih -</option>
                    <option value="ya" {{ old('sesuai_rencana', $report->sesuai_rencana) === 'ya' ? 'selected' : '' }}>Ya</option>
                    <option value="tidak" {{ old('sesuai_rencana', $report->sesuai_rencana) === 'tidak' ? 'selected' : '' }}>Tidak</option>
                </select>
                @error('sesuai_rencana')
                    <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="tanggal_kejadian" class="block text-sm font-medium text-slate-700 mb-1">Tanggal Kejadian</label>
                <input type="date" id="tanggal_kejadian" name="tanggal_kejadian" form="form-edit-laporan"
                       value="{{ old('tanggal_kejadian', $report->tanggal_kejadian?->format('Y-m-d')) }}"
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                @error('tanggal_kejadian')
                    <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="equipment_tag" class="block text-sm font-medium text-slate-700 mb-1">Equipment Tag</label>
                <input type="text" id="equipment_tag" name="equipment_tag" form="form-edit-laporan"
                       value="{{ old('equipment_tag', $report->equipment_tag) }}"
                       placeholder="Tag equipment dari CM master"
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                @error('equipment_tag')
                    <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                @enderror
            </div>
        </div>

<div class="border-t border-slate-100 pt-4">
    <h3 class="text-sm font-medium text-slate-900 mb-3">Tipe & Status Laporan</h3>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            <label for="report_type" class="block text-sm font-medium text-slate-700 mb-1">Tipe Laporan</label>
            <select id="report_type" name="report_type" form="form-edit-laporan"
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
            <select id="status" name="status" form="form-edit-laporan"
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
</div>

@push('scripts')
<script>
    // Sinkronkan opsi "Jenis Pekerjaan" berdasarkan klasifikasi ZPM yang
    // dipilih (dipindahkan dari halaman reports.edit yang sudah dihapus).
    // - ZPM 1 / ZPM 6 : pilih EI atau Mekanik
    // - ZPM 3          : otomatis CM/PM
    // - ZPM 5          : otomatis Fabrikasi
    // - ZPM 7          : otomatis Sipil
    // - ZPM 8          : otomatis Project
    function syncJenisPekerjaan() {
        var wcSelect = document.getElementById('work_classification');
        var jenisSelect = document.getElementById('jenis_pekerjaan');

        if (!wcSelect || !jenisSelect) {
            return;
        }

        var options = {
            'zpm1': [['EI', 'EI'], ['Mekanik', 'Mekanik']],
            'zpm6': [['EI', 'EI'], ['Mekanik', 'Mekanik']],
            'zpm3': [['CM/PM', 'CM/PM']],
            'zpm5': [['Fabrikasi', 'Fabrikasi']],
            'zpm7': [['Sipil', 'Sipil']],
            'zpm8': [['Project', 'Project']],
            '': [['CM', 'CM (Corrective)'], ['dCM', 'dCM (Deferred)'], ['PM', 'PM (Preventive)'], ['Project', 'Project']]
        };

        var wc = wcSelect.value;
        var list = options[wc] || options[''];
        var current = jenisSelect.getAttribute('data-value') || jenisSelect.value;

        jenisSelect.innerHTML = '';

        if (list.length > 1) {
            var placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = '- Pilih -';
            jenisSelect.appendChild(placeholder);
        }

        list.forEach(function (pair) {
            var opt = document.createElement('option');
            opt.value = pair[0];
            opt.textContent = pair[1];

            if (pair[0] === current) {
                opt.selected = true;
            }

            jenisSelect.appendChild(opt);
        });

        if (list.length === 1) {
            jenisSelect.value = list[0][0];
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        syncJenisPekerjaan();
    });
</script>
@endpush
