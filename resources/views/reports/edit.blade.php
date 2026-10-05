@extends('layouts.app')

@section('title', 'Edit Laporan')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('reports.index') }}" class="hover:text-slate-700">Laporan</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('reports.show', $report) }}" class="hover:text-slate-700">Detail Laporan #{{ $report->id }}</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Edit</span>
@endsection

@section('content')
    <div class="max-w-2xl" x-data="reportLocationManager()">
        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <form method="POST" action="{{ route('reports.update', $report) }}" class="space-y-5">
                @csrf
                @method('PUT')

                <div>
                    <label for="report_date" class="block text-sm font-medium text-slate-700 mb-1">Tanggal Laporan</label>
                    <input type="date" id="report_date" name="report_date"
                           value="{{ old('report_date', $report->report_date->format('Y-m-d')) }}"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    @error('report_date')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="work_classification" class="block text-sm font-medium text-slate-700 mb-1">Klasifikasi Pekerjaan (Wizard)</label>
                    <select id="work_classification" name="work_classification" onchange="syncJenisPekerjaan()"
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
                    <h3 class="text-sm font-medium text-slate-900 mb-3">Lokasi & Alat</h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label for="area_id" class="block text-sm font-medium text-slate-700 mb-1">Area</label>
                            <select id="area_id" name="area_id" x-model="areaId" @change="onAreaChange()"
                                    class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">- Pilih Area -</option>
                                @foreach($areas as $area)
                                    <option value="{{ $area->id }}">{{ $area->code }} - {{ $area->name }}</option>
                                @endforeach
                            </select>
                            @error('area_id')
                                <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="funcloc_id" class="block text-sm font-medium text-slate-700 mb-1">Functional Location</label>
                            <select id="funcloc_id" name="funcloc_id" x-model="funcLocId" @change="onFuncLocChange()"
                                    :disabled="!areaId"
                                    class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-slate-50 disabled:text-slate-400">
                                <option value="">- Pilih Functional Location -</option>
                                <template x-for="funcLoc in funcLocs" :key="funcLoc.id">
                                    <option :value="funcLoc.id" x-text="funcLoc.code + ' - ' + funcLoc.name"></option>
                                </template>
                            </select>
                            @error('funcloc_id')
                                <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="asset_id" class="block text-sm font-medium text-slate-700 mb-1">Kode Alat</label>
                            <select id="asset_id" name="asset_id" x-model="assetId"
                                    :disabled="!areaId"
                                    class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-slate-50 disabled:text-slate-400">
                                <option value="">- Pilih Asset -</option>
                                <template x-for="asset in assets" :key="asset.id">
                                    <option :value="asset.id" x-text="(asset.tech_ident_no || asset.equipment_no || '-') + ' - ' + (asset.description || '')"></option>
                                </template>
                            </select>
                            @error('asset_id')
                                <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                    <p class="text-xs text-slate-400 mt-2">
                        Pilih Area terlebih dahulu untuk memunculkan pilihan Functional Location dan Asset.
                    </p>

                    @include('reports.partials._cari-alat', ['report' => $report])
                </div>

                <div>
                    <label for="work_description" class="block text-sm font-medium text-slate-700 mb-1">Deskripsi Pekerjaan</label>
                    <textarea id="work_description" name="work_description" rows="5"
                              class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">{{ old('work_description', $report->work_description) }}</textarea>
                    @error('work_description')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                @include('reports.partials._waktu-kerja', ['report' => $report])

                <div>
                    <label for="work_duration_minutes" class="block text-sm font-medium text-slate-700 mb-1">Durasi Pengerjaan (menit)</label>
                    <input type="number" id="work_duration_minutes" name="work_duration_minutes" min="0"
                           value="{{ old('work_duration_minutes', $report->work_duration_minutes) }}"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    @error('work_duration_minutes')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="root_cause" class="block text-sm font-medium text-slate-700 mb-1">Root Cause</label>
                    <textarea id="root_cause" name="root_cause" rows="4"
                              class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">{{ old('root_cause', $report->root_cause) }}</textarea>
                    @error('root_cause')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                @include('reports.partials._temuan', ['nilai' => old('finding', $report->finding)])

                {{-- ── Field Jenis Pekerjaan ── --}}
                <div class="border-t border-slate-100 pt-4">
                    <h3 class="text-sm font-medium text-slate-900 mb-3">Jenis Pekerjaan</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                        <div>
                            <label for="jenis_pekerjaan" class="block text-sm font-medium text-slate-700 mb-1">Jenis Pekerjaan</label>
                            <select id="jenis_pekerjaan" name="jenis_pekerjaan"
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
                            <select id="sesuai_rencana" name="sesuai_rencana"
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
                            <input type="date" id="tanggal_kejadian" name="tanggal_kejadian"
                                   value="{{ old('tanggal_kejadian', $report->tanggal_kejadian?->format('Y-m-d')) }}"
                                   class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                            @error('tanggal_kejadian')
                                <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="equipment_tag" class="block text-sm font-medium text-slate-700 mb-1">Equipment Tag</label>
                            <input type="text" id="equipment_tag" name="equipment_tag"
                                   value="{{ old('equipment_tag', $report->equipment_tag) }}"
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
                        Simpan Perubahan
                    </button>
                    <a href="{{ route('reports.show', $report) }}"
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
function reportLocationManager() {
    return {
        areaId: {{ old('area_id', $report->area_id) ?? 'null' }},
        funcLocId: {{ old('funcloc_id', $report->funcloc_id) ?? 'null' }},
        assetId: {{ old('asset_id', $report->asset_id) ?? 'null' }},
        funcLocs: @json($funcLocs),
        assets: @json($assets),

        onAreaChange() {
            this.funcLocId = null;
            this.assetId = null;
            this.funcLocs = [];
            this.assets = [];

            if (!this.areaId) {
                return;
            }

            fetch(`{{ route('reports.locations.funclocs') }}?area_id=${this.areaId}`, {
                headers: { 'Accept': 'application/json' },
            })
                .then(res => res.json())
                .then(data => { this.funcLocs = data; });

            fetch(`{{ route('reports.locations.assets') }}?area_id=${this.areaId}`, {
                headers: { 'Accept': 'application/json' },
            })
                .then(res => res.json())
                .then(data => { this.assets = data; });
        },

        onFuncLocChange() {
            this.assetId = null;

            if (!this.areaId) {
                this.assets = [];
                return;
            }

            let url = `{{ route('reports.locations.assets') }}?area_id=${this.areaId}`;
            if (this.funcLocId) {
                url += `&funcloc_id=${this.funcLocId}`;
            }

            fetch(url, {
                headers: { 'Accept': 'application/json' },
            })
                .then(res => res.json())
                .then(data => { this.assets = data; });
        },

        /**
         * Isi Area + Functional Location + Kode Alat sekaligus dari satu hasil
         * pencarian alat lintas area (kotak "Cari Alat (semua area)").
         * Daftar lokasi & alat dimuat ulang untuk area alat itu supaya pilihan
         * pada dropdown tetap konsisten dengan nilai yang dipasang.
         *
         * @param {object} aset Hasil pencarian: id, area_id, funcloc_id, dst.
         */
        async terapkanAset(aset) {
            this.areaId = aset.area_id ?? null;

            if (this.areaId) {
                await Promise.all([
                    fetch(`{{ route('reports.locations.funclocs') }}?area_id=${this.areaId}`, {
                        headers: { 'Accept': 'application/json' },
                    })
                        .then(res => res.json())
                        .then(data => { this.funcLocs = data; }),

                    fetch(`{{ route('reports.locations.assets') }}?area_id=${this.areaId}`, {
                        headers: { 'Accept': 'application/json' },
                    })
                        .then(res => res.json())
                        .then(data => { this.assets = data; }),
                ]);
            } else {
                this.funcLocs = [];
                this.assets = [];
            }

            // Alat/lokasi terpilih wajib ada di daftar dropdown, kalau tidak
            // pilihannya tampak kosong walau nilainya sudah tersimpan.
            if (!this.assets.some(item => item.id === aset.id)) {
                this.assets.unshift(aset);
            }

            if (aset.funcloc_id && !this.funcLocs.some(item => item.id === aset.funcloc_id)) {
                this.funcLocs.unshift({
                    id: aset.funcloc_id,
                    code: aset.functional_loc || 'FuncLoc #' + aset.funcloc_id,
                    name: aset.area_label || '',
                    level: null,
                });
            }

            this.funcLocId = aset.funcloc_id ?? null;
            this.assetId   = aset.id;
        },

        /**
         * Kosongkan Area, Functional Location, dan Kode Alat pada form.
         */
        kosongkanLokasi() {
            this.areaId    = null;
            this.funcLocId = null;
            this.assetId   = null;
            this.funcLocs  = [];
            this.assets    = [];
        },
    };
}

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
