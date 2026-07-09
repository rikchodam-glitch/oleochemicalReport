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
                </div>

                <div>
                    <label for="work_description" class="block text-sm font-medium text-slate-700 mb-1">Deskripsi Pekerjaan</label>
                    <textarea id="work_description" name="work_description" rows="5"
                              class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">{{ old('work_description', $report->work_description) }}</textarea>
                    @error('work_description')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>

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
    };
}
</script>
@endpush
