@extends('layouts.app')

@section('title', 'Functional Location')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Functional Location</span>
@endsection

@section('header-actions')
    <button type="button"
            onclick="openModal('funcloc-sync'); window.dispatchEvent(new CustomEvent('funcloc-sync-open'))"
            class="inline-flex items-center gap-2 px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
        </svg>
        Sinkronisasi dari Asset
    </button>

    <a href="{{ route('func-locs.create') }}"
       class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        Tambah FuncLoc
    </a>
@endsection

@section('content')
    <!-- Stat Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <x-stat-card label="Total FuncLoc" :value="$stats['total']" icon="box" />
        <x-stat-card label="Aktif" :value="$stats['active']" value-color="text-green-600" icon="box" />
        <x-stat-card label="Nonaktif" :value="$stats['inactive']" value-color="text-slate-500" icon="box" />
        <x-stat-card label="Tanpa Asset" :value="$stats['without_asset']" value-color="text-amber-600" icon="alert" />
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-xl border border-slate-200 p-4 mb-6">
        <form method="GET" action="{{ route('func-locs.index') }}" class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
                <select name="level" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white">
                    <option value="">Semua Level</option>
                    @foreach($levelLabels as $value => $label)
                        <option value="{{ $value }}" {{ (string) $filters['level'] === (string) $value ? 'selected' : '' }}>
                            L{{ $value }} - {{ $label }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <select name="parent_id" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white">
                    <option value="">Semua Parent</option>
                    @foreach($parentOptions as $parent)
                        <option value="{{ $parent->id }}" {{ (string) $filters['parent_id'] === (string) $parent->id ? 'selected' : '' }}>
                            {{ $parent->code }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <select name="status" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white">
                    <option value="">Semua Status</option>
                    <option value="active" {{ $filters['status'] === 'active' ? 'selected' : '' }}>Aktif</option>
                    <option value="inactive" {{ $filters['status'] === 'inactive' ? 'selected' : '' }}>Nonaktif</option>
                </select>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">Filter</button>
                <a href="{{ route('func-locs.index') }}" class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">Reset</a>
            </div>
        </form>
    </div>

    @if($tree !== null)
        <!-- Tampilan Pohon Hierarki -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="flex items-center gap-3 px-5 py-3 bg-slate-50 border-b border-slate-100 text-xs font-medium text-slate-500 uppercase tracking-wide">
                <div class="w-5 shrink-0"></div>
                <div class="w-40 shrink-0">Code</div>
                <div class="flex-1">Nama</div>
                <div class="w-24 shrink-0">Level</div>
                <div class="w-20 shrink-0">Asset</div>
                <div class="w-24 shrink-0">Status</div>
                <div class="w-44 shrink-0 text-right">Aksi</div>
            </div>

            @forelse($tree as $node)
                @include('func-locs.partials._tree-node', ['node' => $node])
            @empty
                <div class="px-5 py-8 text-center text-sm text-slate-400">Belum ada Functional Location.</div>
            @endforelse
        </div>
    @else
        <!-- Tampilan Daftar Datar (hasil filter) -->
        @php
            $rows = $items->map(fn ($item) => view('func-locs.partials._row-cells', ['item' => $item])->render())->all();
        @endphp

        <x-data-table
            :headers="['Code', 'Nama', 'Level', 'Asset', 'Status', 'Aksi']"
            :rows="$rows"
            :searchable="false"
            empty-message="Tidak ada Functional Location yang cocok dengan filter."
        />
    @endif

    <!-- Modal Sinkronisasi FuncLoc dari Asset -->
    <x-modal name="funcloc-sync" title="Sinkronisasi FuncLoc dari Asset" max-width="xl">
        <div x-data="funcLocSyncManager()" x-on:funcloc-sync-open.window="loadPreview()" class="space-y-4">
            <p class="text-sm text-slate-500">
                Memindai kolom Functional Loc. pada data Asset yang belum tertaut ke Functional Location,
                lalu membuat node yang belum ada dan menautkannya secara otomatis.
            </p>

            <!-- Loading -->
            <div x-show="loading" class="flex items-center gap-2 text-sm text-slate-500 py-6 justify-center">
                <svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                </svg>
                Menganalisa data...
            </div>

            <!-- Error -->
            <div x-show="error" x-cloak class="flex items-start gap-3 p-4 border rounded-lg text-sm bg-red-50 border-red-200 text-red-800">
                <div>
                    <p class="font-medium">Gagal Menganalisa</p>
                    <p class="mt-0.5" x-text="error"></p>
                </div>
            </div>

            <!-- Preview -->
            <template x-if="analysis && !loading">
                <div class="space-y-4">
                    <!-- Summary -->
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                        <div class="bg-slate-50 rounded-lg p-3 text-center">
                            <p class="text-xl font-bold text-blue-600" x-text="analysis.new_node_count"></p>
                            <p class="text-xs text-slate-500 mt-0.5">FuncLoc Baru</p>
                        </div>
                        <div class="bg-slate-50 rounded-lg p-3 text-center">
                            <p class="text-xl font-bold text-slate-600" x-text="analysis.existing_node_count"></p>
                            <p class="text-xs text-slate-500 mt-0.5">Sudah Ada</p>
                        </div>
                        <div class="bg-slate-50 rounded-lg p-3 text-center">
                            <p class="text-xl font-bold text-green-600" x-text="analysis.assets_to_link_count"></p>
                            <p class="text-xs text-slate-500 mt-0.5">Asset Akan Ditautkan</p>
                        </div>
                        <div class="bg-slate-50 rounded-lg p-3 text-center">
                            <p class="text-xl font-bold text-red-600" x-text="analysis.invalid_code_count"></p>
                            <p class="text-xs text-slate-500 mt-0.5">Kode Tidak Valid</p>
                        </div>
                    </div>

                    <!-- Node Preview -->
                    <div>
                        <p class="text-sm font-medium text-slate-700 mb-2">Functional Location Terdeteksi</p>
                        <div class="max-h-56 overflow-y-auto border border-slate-100 rounded-lg divide-y divide-slate-50">
                            <template x-for="node in analysis.nodes" :key="node.code">
                                <div class="flex items-center gap-3 px-4 py-2 text-sm" :style="{ paddingLeft: (16 + node.level * 20) + 'px' }">
                                    <span class="font-mono text-xs text-slate-700 flex-1 truncate" x-text="node.code"></span>
                                    <span class="text-xs text-slate-400 shrink-0" x-text="'L' + node.level"></span>
                                    <span x-show="!node.exists" class="inline-flex px-2 py-0.5 text-xs font-medium bg-blue-100 text-blue-700 rounded shrink-0">FuncLoc Baru</span>
                                    <span x-show="node.exists" class="inline-flex px-2 py-0.5 text-xs font-medium bg-slate-100 text-slate-600 rounded shrink-0">Sudah Ada</span>
                                </div>
                            </template>
                            <template x-if="analysis.nodes.length === 0">
                                <div class="px-4 py-6 text-center text-sm text-slate-400">Tidak ada Functional Location baru terdeteksi.</div>
                            </template>
                        </div>
                    </div>

                    <!-- Invalid Codes -->
                    <template x-if="analysis.invalid_codes.length > 0">
                        <div>
                            <p class="text-sm font-medium text-slate-700 mb-2">Kode Tidak Valid (dilewati)</p>
                            <div class="max-h-32 overflow-y-auto border border-red-100 rounded-lg divide-y divide-red-50">
                                <template x-for="invalid in analysis.invalid_codes" :key="invalid.code">
                                    <div class="flex items-center gap-3 px-4 py-2 text-sm">
                                        <span class="font-mono text-xs text-red-700 flex-1 truncate" x-text="invalid.code"></span>
                                        <span class="text-xs text-slate-400 shrink-0" x-text="invalid.count + ' asset'"></span>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>

                    <!-- Actions -->
                    <div class="flex gap-3 pt-2">
                        <button @click="executeSync"
                                x-bind:disabled="executing || analysis.assets_to_link_count === 0"
                                class="px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:opacity-60 disabled:cursor-not-allowed text-white text-sm font-medium rounded-lg transition-colors">
                            <span x-show="!executing">Jalankan Sinkronisasi</span>
                            <span x-show="executing" class="flex items-center gap-2">
                                <svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                                </svg>
                                Menyinkronkan...
                            </span>
                        </button>
                        <button type="button" onclick="closeModal('funcloc-sync')"
                                class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                            Tutup
                        </button>
                    </div>
                </div>
            </template>

            <!-- Result -->
            <template x-if="result">
                <div x-show="result.status === 'success'"
                     class="flex items-start gap-3 p-4 border rounded-lg text-sm bg-green-50 border-green-200 text-green-800">
                    <div>
                        <p class="font-medium">Sinkronisasi Berhasil</p>
                        <p class="mt-0.5" x-text="result.message"></p>
                        <p class="mt-1 text-xs text-green-700">Halaman akan dimuat ulang...</p>
                    </div>
                </div>
            </template>
            <template x-if="result && result.status !== 'success'">
                <div class="flex items-start gap-3 p-4 border rounded-lg text-sm bg-red-50 border-red-200 text-red-800">
                    <div>
                        <p class="font-medium">Sinkronisasi Gagal</p>
                        <p class="mt-0.5" x-text="result.message"></p>
                    </div>
                </div>
            </template>
        </div>
    </x-modal>
@endsection

@push('scripts')
<script>
function funcLocSyncManager() {
    return {
        loading: false,
        executing: false,
        analysis: null,
        error: null,
        result: null,

        loadPreview() {
            this.loading = true;
            this.error = null;
            this.analysis = null;
            this.result = null;

            fetch('{{ route('func-locs.sync.preview') }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json',
                },
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    this.analysis = data.analysis;
                } else {
                    this.error = data.message || 'Gagal menganalisa data.';
                }
            })
            .catch(() => {
                this.error = 'Terjadi kesalahan saat menganalisa data.';
            })
            .finally(() => {
                this.loading = false;
            });
        },

        executeSync() {
            this.executing = true;
            this.result = null;

            fetch('{{ route('func-locs.sync.execute') }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ analysis: this.analysis }),
            })
            .then(res => res.json())
            .then(data => {
                this.result = data;
                if (data.status === 'success') {
                    this.analysis = null;
                    setTimeout(() => window.location.reload(), 1200);
                }
            })
            .catch(() => {
                this.result = { status: 'error', message: 'Terjadi kesalahan saat sinkronisasi.' };
            })
            .finally(() => {
                this.executing = false;
            });
        },
    };
}
</script>
@endpush
