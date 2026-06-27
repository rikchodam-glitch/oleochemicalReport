@extends('layouts.app')

@section('title', 'Asset Management')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Asset Management</span>
@endsection

@section('header-actions')
    <button onclick="toggleFilter()"
            class="inline-flex items-center gap-2 px-4 py-2 border border-blue-300 hover:bg-blue-50 text-blue-700 text-sm font-medium rounded-lg transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/>
        </svg>
        Filter
        @if(request()->hasAny(['search', 'status', 'company_id', 'department_id', 'area_id', 'sub_area_id', 'object_type']))
            <span class="inline-flex items-center justify-center w-5 h-5 text-xs font-bold text-white bg-blue-500 rounded-full">{{ collect([request('search'), request('status'), request('company_id'), request('department_id'), request('area_id'), request('sub_area_id'), request('object_type')])->filter()->count() }}</span>
        @endif
    </button>
    <a href="{{ route('assets.export-excel', request()->query()) }}"
       class="inline-flex items-center gap-2 px-4 py-2 border border-green-300 hover:bg-green-50 text-green-700 text-sm font-medium rounded-lg transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
        </svg>
        Export Excel
    </a>
    <a href="{{ route('assets.export-csv', request()->query()) }}"
       class="inline-flex items-center gap-2 px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
        </svg>
        Export CSV
    </a>
    <a href="{{ route('assets.import') }}"
       class="inline-flex items-center gap-2 px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
        </svg>
        Import Excel
    </a>
    <a href="{{ route('assets.create') }}"
       class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        Tambah Asset
    </a>
@endsection

@php
    $activeFilterCount = collect([
        'search' => request('search'),
        'status' => request('status'),
        'company_id' => request('company_id'),
        'department_id' => request('department_id'),
        'area_id' => request('area_id'),
        'sub_area_id' => request('sub_area_id'),
        'object_type' => request('object_type'),
    ])->filter()->count();

    $activeFilters = [];
    if (request('search')) $activeFilters[] = ['key' => 'search', 'label' => 'Pencarian: ' . request('search')];
    if (request('status')) $activeFilters[] = ['key' => 'status', 'label' => 'Status: ' . (['active' => 'Aktif', 'inactive' => 'Nonaktif', 'needs_review' => 'Perlu Review'][request('status')] ?? request('status'))];
    if (request('company_id')) {
        $c = $companies->firstWhere('id', request('company_id'));
        if ($c) $activeFilters[] = ['key' => 'company_id', 'label' => 'Company: ' . $c->code];
    }
    if (request('department_id')) {
        $d = $departments->firstWhere('id', request('department_id'));
        if ($d) $activeFilters[] = ['key' => 'department_id', 'label' => 'Dept: ' . $d->code];
    }
    if (request('area_id')) {
        $a = $areas->firstWhere('id', request('area_id'));
        if ($a) $activeFilters[] = ['key' => 'area_id', 'label' => 'Area: ' . $a->code];
    }
    if (request('sub_area_id')) {
        $s = $subAreas->firstWhere('id', request('sub_area_id'));
        if ($s) $activeFilters[] = ['key' => 'sub_area_id', 'label' => 'Sub Area: ' . $s->code];
    }
    if (request('object_type')) $activeFilters[] = ['key' => 'object_type', 'label' => 'Tipe: ' . request('object_type')];
@endphp

@section('content')
    <!-- Filter Popup Panel -->
    <div id="filterPanel" class="bg-white rounded-xl border border-slate-200 mb-6 overflow-hidden transition-all duration-200 ease-in-out"
         style="max-height: 0; opacity: 0; padding: 0 0; border-width: 0; visibility: hidden;">
        <div class="p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold text-slate-900 flex items-center gap-2">
                    <svg class="w-4 h-4 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/>
                    </svg>
                    Filter Pencarian
                </h3>
                <button onclick="toggleFilter()" class="text-slate-400 hover:text-slate-600 transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
            <form method="GET" action="{{ route('assets.index') }}" id="filterForm">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1.5">Pencarian</label>
                        <input type="text" name="search" value="{{ request('search') }}"
                               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                               placeholder="Equipment, deskripsi, tech ident...">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1.5">Status</label>
                        <select name="status" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white">
                            <option value="">Semua Status</option>
                            <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Aktif</option>
                            <option value="inactive" {{ request('status') === 'inactive' ? 'selected' : '' }}>Nonaktif</option>
                            <option value="needs_review" {{ request('status') === 'needs_review' ? 'selected' : '' }}>Perlu Review</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1.5">Object Type</label>
                        <select name="object_type" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white">
                            <option value="">Semua Tipe</option>
                            @foreach($objectTypes as $type)
                                <option value="{{ $type }}" {{ request('object_type') === $type ? 'selected' : '' }}>{{ $type }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="border-t border-slate-100 pt-4 mb-4">
                    <h4 class="text-xs font-medium text-slate-500 mb-3">Filter Lokasi</h4>
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-slate-500 mb-1.5">Company</label>
                            <select name="company_id" onchange="this.form.submit()"
                                    class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white">
                                <option value="">Semua Company</option>
                                @foreach($companies as $company)
                                    <option value="{{ $company->id }}" {{ request('company_id') == $company->id ? 'selected' : '' }}>{{ $company->code }} — {{ $company->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-500 mb-1.5">Departemen</label>
                            <select name="department_id" onchange="this.form.submit()"
                                    class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white">
                                <option value="">Semua Departemen</option>
                                @foreach($departments as $dept)
                                    <option value="{{ $dept->id }}" {{ request('department_id') == $dept->id ? 'selected' : '' }}>{{ $dept->code }} — {{ $dept->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-500 mb-1.5">Area</label>
                            <select name="area_id" onchange="this.form.submit()"
                                    class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white">
                                <option value="">Semua Area</option>
                                @foreach($areas as $area)
                                    <option value="{{ $area->id }}" {{ request('area_id') == $area->id ? 'selected' : '' }}>{{ $area->code }} — {{ $area->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-500 mb-1.5">Sub Area</label>
                            <select name="sub_area_id"
                                    class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white">
                                <option value="">Semua Sub Area</option>
                                @foreach($subAreas as $sub)
                                    <option value="{{ $sub->id }}" {{ request('sub_area_id') == $sub->id ? 'selected' : '' }}>{{ $sub->code }} — {{ $sub->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-3 pt-2 border-t border-slate-100">
                    <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
                        <svg class="w-4 h-4 inline mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                        Terapkan Filter
                    </button>
                    <a href="{{ route('assets.index') }}" class="px-5 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                        Reset Filter
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Active Filter Chips -->
    @if(count($activeFilters) > 0)
        <div class="flex flex-wrap items-center gap-2 mb-4">
            <span class="text-xs text-slate-500 font-medium">Filter aktif:</span>
            @foreach($activeFilters as $filter)
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-blue-50 border border-blue-200 text-blue-700 text-xs font-medium rounded-full">
                    {{ $filter['label'] }}
                    <a href="{{ route('assets.index', request()->except($filter['key'])) }}" class="text-blue-400 hover:text-blue-600 transition-colors">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </a>
                </span>
            @endforeach
            <a href="{{ route('assets.index') }}" class="text-xs text-slate-400 hover:text-red-500 transition-colors ml-1">
                Hapus semua
            </a>
        </div>
    @endif

    <!-- Table -->
    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-100">
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Kode Alat</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Deskripsi</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Tipe</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Lokasi</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Status</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($assets as $asset)
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-5 py-3.5">
                                <div class="flex flex-col">
                                    <span class="font-mono text-sm font-medium text-slate-900">{{ $asset->tech_ident_no ?? '-' }}</span>
                                    @if($asset->equipment_no)
                                        <span class="text-[10px] text-slate-400 font-mono mt-0.5">EQ: {{ $asset->equipment_no }}</span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-5 py-3.5">
                                <p class="text-slate-700">{{ Str::limit($asset->description, 60) }}</p>
                            </td>
                            <td class="px-5 py-3.5">
                                <span class="inline-flex px-2 py-0.5 text-xs font-medium bg-slate-100 text-slate-700 rounded">
                                    {{ $asset->object_type ?? '-' }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5">
                                <div class="text-xs text-slate-600">
                                    <span>{{ $asset->company?->code ?? '-' }}</span>
                                    @if($asset->department)
                                        <span class="mx-0.5 text-slate-300">/</span>
                                        <span>{{ $asset->department->code }}</span>
                                    @endif
                                    @if($asset->area)
                                        <span class="mx-0.5 text-slate-300">/</span>
                                        <span class="font-medium text-slate-700">{{ $asset->area->code }}</span>
                                    @endif
                                    @if($asset->subArea)
                                        <span class="mx-0.5 text-slate-300">/</span>
                                        <span>{{ $asset->subArea->code }}</span>
                                    @endif
                                </div>
                                @if($asset->functional_loc)
                                    <p class="text-[10px] text-slate-400 mt-0.5 font-mono">{{ $asset->functional_loc }}</p>
                                @endif
                            </td>
                            <td class="px-5 py-3.5">
                                <x-status-badge :status="$asset->status" />
                            </td>
                            <td class="px-5 py-3.5">
                                <div class="flex items-center gap-2">
                                    <a href="{{ route('assets.show', $asset) }}"
                                       class="text-blue-600 hover:text-blue-700 text-xs font-medium">Detail</a>
                                    <a href="{{ route('assets.edit', $asset) }}"
                                       class="text-amber-600 hover:text-amber-700 text-xs font-medium">Edit</a>
                                    <form method="POST" action="{{ route('assets.destroy', $asset) }}" class="inline"
                                          onsubmit="return confirm('Hapus asset ini?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-red-600 hover:text-red-700 text-xs font-medium">Hapus</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-8 text-center text-sm text-slate-400">
                                Belum ada asset. <a href="{{ route('assets.import') }}" class="text-blue-600 hover:underline">Import dari Excel</a>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($assets->hasPages())
            <div class="px-5 py-3 border-t border-slate-100">
                {{ $assets->links() }}
            </div>
        @endif
    </div>
@endsection

@push('scripts')
<script>
function toggleFilter() {
    const panel = document.getElementById('filterPanel');
    if (!panel) return;

    const isOpen = panel.style.maxHeight !== '0px' && panel.style.maxHeight !== '';
    if (isOpen) {
        panel.style.maxHeight = '0';
        panel.style.opacity = '0';
        panel.style.padding = '0';
        panel.style.borderWidth = '0';
        panel.style.visibility = 'hidden';
    } else {
        panel.style.visibility = 'visible';
        panel.style.borderWidth = '1px';
        panel.style.padding = '';
        const height = panel.scrollHeight;
        panel.style.maxHeight = height + 'px';
        panel.style.opacity = '1';
    }
}

// Auto-open if there are active filters
document.addEventListener('DOMContentLoaded', function() {
    @if($activeFilterCount > 0)
    setTimeout(function() {
        toggleFilter();
    }, 100);
    @endif
});
</script>
@endpush
