@extends('layouts.app')

@section('title', 'IT Assets')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">IT Assets</span>
@endsection

@section('header-actions')
    <button onclick="toggleFilter()"
            class="inline-flex items-center gap-2 px-4 py-2 border border-blue-300 hover:bg-blue-50 text-blue-700 text-sm font-medium rounded-lg transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/>
        </svg>
        Filter
        @if(request()->hasAny(['search', 'device_type', 'status']))
            <span class="inline-flex items-center justify-center w-5 h-5 text-xs font-bold text-white bg-blue-500 rounded-full">{{ collect([request('search'), request('device_type'), request('status')])->filter()->count() }}</span>
        @endif
    </button>
    <a href="{{ route('it-assets.import') }}"
       class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
        </svg>
        Import Excel
    </a>
@endsection

@php
    $deviceTypeLabels = [
        'PC'          => 'PC',
        'LAPTOP'      => 'Laptop',
        'PRINTER'     => 'Printer',
        'CCTV'        => 'CCTV',
        'NVR'         => 'NVR',
        'ACCESS_DOOR' => 'Access Door',
        'WIFI'        => 'WiFi',
        'NETWORK'     => 'Network',
        'UPS'         => 'UPS',
        'OTHER'       => 'Lainnya',
    ];

    $activeFilters = [];
    if (request('search')) $activeFilters[] = ['key' => 'search', 'label' => 'Pencarian: ' . request('search')];
    if (request('device_type')) $activeFilters[] = ['key' => 'device_type', 'label' => 'Tipe: ' . ($deviceTypeLabels[request('device_type')] ?? request('device_type'))];
    if (request('status')) $activeFilters[] = ['key' => 'status', 'label' => 'Status: ' . (['active' => 'Aktif', 'inactive' => 'Nonaktif', 'needs_review' => 'Perlu Review'][request('status')] ?? request('status'))];
@endphp

@section('content')
    <!-- Summary Cards per device_type -->
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
        @foreach($deviceTypes as $type)
            <a href="{{ route('it-assets.index', ['device_type' => $type]) }}">
                <x-stat-card
                    :label="$deviceTypeLabels[$type] ?? $type"
                    :value="$summary[$type] ?? 0"
                    :value-color="request('device_type') === $type ? 'text-blue-600' : 'text-slate-900'"
                />
            </a>
        @endforeach
    </div>

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
            <form method="GET" action="{{ route('it-assets.index') }}" id="filterForm">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1.5">Pencarian</label>
                        <input type="text" name="search" value="{{ request('search') }}"
                               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                               placeholder="Tag, nama, atau IP...">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1.5">Tipe Perangkat</label>
                        <select name="device_type" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white">
                            <option value="">Semua Tipe</option>
                            @foreach($deviceTypes as $type)
                                <option value="{{ $type }}" {{ request('device_type') === $type ? 'selected' : '' }}>{{ $deviceTypeLabels[$type] ?? $type }}</option>
                            @endforeach
                        </select>
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
                </div>

                <div class="flex items-center gap-3 pt-2 border-t border-slate-100">
                    <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
                        <svg class="w-4 h-4 inline mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                        Terapkan Filter
                    </button>
                    <a href="{{ route('it-assets.index') }}" class="px-5 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
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
                    <a href="{{ route('it-assets.index', request()->except($filter['key'])) }}" class="text-blue-400 hover:text-blue-600 transition-colors">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </a>
                </span>
            @endforeach
            <a href="{{ route('it-assets.index') }}" class="text-xs text-slate-400 hover:text-red-500 transition-colors ml-1">
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
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Tipe</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Tag / Name</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Brand / Model</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">IP Address</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Lokasi</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Status Ping</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($itAssets as $itAsset)
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-5 py-3.5">
                                <span class="inline-flex px-2 py-0.5 text-xs font-medium bg-slate-100 text-slate-700 rounded">
                                    {{ $deviceTypeLabels[$itAsset->device_type] ?? $itAsset->device_type }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5">
                                <div class="flex flex-col">
                                    <span class="font-medium text-slate-900">{{ $itAsset->tag_name ?? $itAsset->name ?? '-' }}</span>
                                    @if($itAsset->tag_name && $itAsset->name)
                                        <span class="text-[11px] text-slate-400 mt-0.5">{{ $itAsset->name }}</span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-5 py-3.5">
                                <p class="text-slate-700">{{ $itAsset->brand ?? '-' }}</p>
                                @if($itAsset->model)
                                    <p class="text-[11px] text-slate-400 mt-0.5">{{ $itAsset->model }}</p>
                                @endif
                            </td>
                            <td class="px-5 py-3.5">
                                <span class="font-mono text-xs text-slate-700">{{ $itAsset->ip_address ?? '-' }}</span>
                            </td>
                            <td class="px-5 py-3.5">
                                <p class="text-slate-600 text-xs">{{ $itAsset->location ?? '-' }}</p>
                                @if($itAsset->plant || $itAsset->department)
                                    <p class="text-[11px] text-slate-400 mt-0.5">
                                        {{ collect([$itAsset->plant, $itAsset->department])->filter()->implode(' / ') }}
                                    </p>
                                @endif
                            </td>
                            <td class="px-5 py-3.5">
                                <span id="ping-badge-{{ $itAsset->id }}">
                                    @if($itAsset->last_ping_status === 'online')
                                        <x-status-badge status="active" :label="'Online' . ($itAsset->last_ping_latency !== null ? ' (' . $itAsset->last_ping_latency . 'ms)' : '')" />
                                    @elseif($itAsset->last_ping_status === 'offline')
                                        <x-status-badge status="error" label="Offline" />
                                    @else
                                        <x-status-badge status="inactive" label="Belum Dicek" />
                                    @endif
                                </span>
                            </td>
                            <td class="px-5 py-3.5">
                                <div class="flex items-center gap-2">
                                    @if($itAsset->ip_address)
                                        <button type="button" id="ping-btn-{{ $itAsset->id }}"
                                                onclick="pingDevice({{ $itAsset->id }}, this)"
                                                class="text-blue-600 hover:text-blue-700 text-xs font-medium">
                                            Ping
                                        </button>
                                    @else
                                        <span class="text-slate-300 text-xs" title="Tidak ada IP address">Ping</span>
                                    @endif
                                    <a href="{{ route('it-assets.show', $itAsset) }}" class="text-slate-500 hover:text-blue-600 text-xs font-medium transition-colors">Detail</a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-5 py-8 text-center text-sm text-slate-400">
                                Belum ada data. <a href="{{ route('it-assets.import') }}" class="text-blue-600 hover:underline">Import dari Excel</a>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($itAssets->hasPages())
            <div class="px-5 py-3 border-t border-slate-100">
                {{ $itAssets->links() }}
            </div>
        @endif
    </div>
@endsection

@push('scripts')
<script>
const itAssetPingUrlTemplate = '{{ route('it-assets.ping', ['itAsset' => '__ID__']) }}';

/**
 * Ping satu perangkat lewat AJAX dan perbarui badge status ping inline
 * tanpa reload halaman.
 *
 * @param {number} id
 * @param {HTMLButtonElement} button
 */
function pingDevice(id, button) {
    const originalText = button.textContent;
    button.disabled = true;
    button.textContent = 'Ping...';

    const url = itAssetPingUrlTemplate.replace('__ID__', id);

    fetch(url, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
    })
    .then(res => res.json().then(data => ({ ok: res.ok, data })))
    .then(({ ok, data }) => {
        if (!ok || !data.success) {
            alert(data.message || 'Gagal melakukan ping.');
            return;
        }

        const badge = document.getElementById('ping-badge-' + id);
        if (!badge) {
            return;
        }

        if (data.status === 'online') {
            const latencyText = data.latency_ms !== null ? ' (' + data.latency_ms + 'ms)' : '';
            badge.innerHTML = '<span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">'
                + '<span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>Online' + latencyText + '</span>';
        } else {
            badge.innerHTML = '<span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700">'
                + '<span class="w-1.5 h-1.5 rounded-full bg-red-500"></span>Offline</span>';
        }
    })
    .catch(err => {
        alert('Error: ' + err.message);
    })
    .finally(() => {
        button.disabled = false;
        button.textContent = originalText;
    });
}

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

document.addEventListener('DOMContentLoaded', function() {
    @if(count($activeFilters) > 0)
    setTimeout(function() {
        toggleFilter();
    }, 100);
    @endif
});
</script>
@endpush