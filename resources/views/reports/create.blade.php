@extends('layouts.app')

@section('title', 'Buat Laporan')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('reports.index') }}" class="hover:text-slate-700">Laporan</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Buat</span>
@endsection

@section('content')
    <div class="max-w-2xl">
        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <form method="POST" action="{{ route('reports.store') }}" class="space-y-5">
                @csrf

                <div>
                    <label for="report_date" class="block text-sm font-medium text-slate-700 mb-1">Tanggal Laporan</label>
                    <input type="date" id="report_date" name="report_date"
                           value="{{ old('report_date', now()->format('Y-m-d')) }}"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    @error('report_date')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="report_type" class="block text-sm font-medium text-slate-700 mb-1">Tipe Laporan</label>
                    <select id="report_type" name="report_type"
                            class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">- Pilih Tipe -</option>
                        <option value="equipment_repair" {{ old('report_type') === 'equipment_repair' ? 'selected' : '' }}>Perbaikan Equipment</option>
                        <option value="area_work" {{ old('report_type') === 'area_work' ? 'selected' : '' }}>Pekerjaan Area</option>
                        <option value="general" {{ old('report_type') === 'general' ? 'selected' : '' }}>Umum</option>
                    </select>
                    @error('report_type')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="work_classification" class="block text-sm font-medium text-slate-700 mb-1">Klasifikasi Pekerjaan (Wizard)</label>
                    <select id="work_classification" name="work_classification" onchange="syncJenisPekerjaan()"
                            class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">- Pilih Klasifikasi -</option>
                        <option value="zpm1" {{ old('work_classification') === 'zpm1' ? 'selected' : '' }}>ZPM 1 - Corrective Maintenance</option>
                        <option value="zpm3" {{ old('work_classification') === 'zpm3' ? 'selected' : '' }}>ZPM 3 - Preventive Maintenance</option>
                        <option value="zpm6" {{ old('work_classification') === 'zpm6' ? 'selected' : '' }}>ZPM 6 - Planned Maintenance</option>
                        <option value="zpm5" {{ old('work_classification') === 'zpm5' ? 'selected' : '' }}>ZPM 5 - Fabrikasi</option>
                        <option value="zpm7" {{ old('work_classification') === 'zpm7' ? 'selected' : '' }}>ZPM 7 - Sipil</option>
                        <option value="zpm8" {{ old('work_classification') === 'zpm8' ? 'selected' : '' }}>ZPM 8 - Project</option>
                    </select>
                    @error('work_classification')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="area_id" class="block text-sm font-medium text-slate-700 mb-1">Area</label>
                    <select id="area_id" name="area_id"
                            class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">- Pilih Area -</option>
                        @foreach($areas as $area)
                            <option value="{{ $area->id }}" {{ old('area_id') == $area->id ? 'selected' : '' }}>
                                {{ $area->code }} - {{ $area->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('area_id')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="work_description" class="block text-sm font-medium text-slate-700 mb-1">Deskripsi Pekerjaan</label>
                    <textarea id="work_description" name="work_description" rows="5"
                              class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">{{ old('work_description') }}</textarea>
                    @error('work_description')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="work_duration_minutes" class="block text-sm font-medium text-slate-700 mb-1">Durasi Pengerjaan (menit)</label>
                    <input type="number" id="work_duration_minutes" name="work_duration_minutes" min="0"
                           value="{{ old('work_duration_minutes', 0) }}"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    @error('work_duration_minutes')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="root_cause" class="block text-sm font-medium text-slate-700 mb-1">Root Cause</label>
                    <textarea id="root_cause" name="root_cause" rows="4"
                              class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">{{ old('root_cause') }}</textarea>
                    @error('root_cause')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                @include('reports.partials._temuan', ['nilai' => old('finding')])

                {{-- ── Field Jenis Pekerjaan ── --}}
                <div class="border-t border-slate-100 pt-4">
                    <h3 class="text-sm font-medium text-slate-900 mb-3">Jenis Pekerjaan</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                        <div>
                            <label for="jenis_pekerjaan" class="block text-sm font-medium text-slate-700 mb-1">Jenis Pekerjaan</label>
                            <select id="jenis_pekerjaan" name="jenis_pekerjaan"
                                    data-value="{{ old('jenis_pekerjaan') }}"
                                    class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">- Pilih -</option>
                                <option value="CM" {{ old('jenis_pekerjaan') === 'CM' ? 'selected' : '' }}>CM (Corrective)</option>
                                <option value="dCM" {{ old('jenis_pekerjaan') === 'dCM' ? 'selected' : '' }}>dCM (Deferred)</option>
                                <option value="PM" {{ old('jenis_pekerjaan') === 'PM' ? 'selected' : '' }}>PM (Preventive)</option>
                                <option value="Project" {{ old('jenis_pekerjaan') === 'Project' ? 'selected' : '' }}>Project</option>
                                <option value="UMUM" {{ old('jenis_pekerjaan') === 'UMUM' ? 'selected' : '' }}>UMUM</option>
                                <option value="IT" {{ old('jenis_pekerjaan') === 'IT' ? 'selected' : '' }}>IT</option>
                            </select>
                            @error('jenis_pekerjaan')
                                <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="sesuai_rencana" class="block text-sm font-medium text-slate-700 mb-1">Sesuai Rencana (wajib jika dCM)</label>
                            <select id="sesuai_rencana" name="sesuai_rencana"
                                    class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">- Pilih -</option>
                                <option value="ya" {{ old('sesuai_rencana') === 'ya' ? 'selected' : '' }}>Ya</option>
                                <option value="tidak" {{ old('sesuai_rencana') === 'tidak' ? 'selected' : '' }}>Tidak</option>
                            </select>
                            @error('sesuai_rencana')
                                <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="tanggal_kejadian" class="block text-sm font-medium text-slate-700 mb-1">Tanggal Kejadian</label>
                            <input type="date" id="tanggal_kejadian" name="tanggal_kejadian"
                                   value="{{ old('tanggal_kejadian') }}"
                                   class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                            @error('tanggal_kejadian')
                                <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="equipment_tag" class="block text-sm font-medium text-slate-700 mb-1">Equipment Tag</label>
                            <input type="text" id="equipment_tag" name="equipment_tag"
                                   value="{{ old('equipment_tag') }}"
                                   placeholder="Tag equipment dari CM master"
                                   class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                            @error('equipment_tag')
                                <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                    </div>
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <button type="submit"
                            class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
                        Simpan Laporan
                    </button>
                    <a href="{{ route('reports.index') }}"
                       class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                        Batal
                    </a>
                </div>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    // Sinkronkan opsi "Jenis Pekerjaan" berdasarkan klasifikasi ZPM yang dipilih.
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
            'zpm1': [['EI', 'EI'], ['Mekanik', 'Mekanik'], ['IT', 'IT']],
            'zpm6': [['EI', 'EI'], ['Mekanik', 'Mekanik'], ['IT', 'IT']],
            'zpm3': [['CM/PM', 'CM/PM']],
            'zpm5': [['Fabrikasi', 'Fabrikasi']],
            'zpm7': [['Sipil', 'Sipil']],
            'zpm8': [['Project', 'Project']],
            '': [['CM', 'CM (Corrective)'], ['dCM', 'dCM (Deferred)'], ['PM', 'PM (Preventive)'], ['Project', 'Project'], ['UMUM', 'UMUM'], ['IT', 'IT']]
        };

        var wc = wcSelect.value;
        var list = options[wc] || options[''];
        var current = jenisSelect.getAttribute('data-value') || jenisSelect.value;

        // Nilai lama yang belum ada di daftar (mis. isian yang dikembalikan
        // setelah galat validasi) tetap dipertahankan supaya tidak terhapus.
        if (wc === '' && current !== '' && !list.some(function (pair) { return pair[0] === current; })) {
            list = list.concat([[current, current]]);
        }

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
