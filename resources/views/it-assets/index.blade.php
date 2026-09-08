@extends('layouts.app')

@section('title', 'IT Assets')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">IT Assets</span>
@endsection

@section('header-actions')
    <a href="{{ route('it-assets.notifications') }}" title="Notifikasi perangkat"
       class="relative inline-flex items-center justify-center w-10 h-10 border border-slate-300 hover:bg-slate-50 text-slate-700 rounded-lg transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
        </svg>
        @if($unreadNotificationCount > 0)
            <span class="absolute -top-1.5 -right-1.5 inline-flex items-center justify-center w-5 h-5 text-[10px] font-bold text-white bg-red-500 rounded-full">{{ $unreadNotificationCount }}</span>
        @endif
    </a>
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
    <a href="{{ route('it-assets.create') }}"
       class="inline-flex items-center gap-2 px-4 py-2 border border-blue-600 text-blue-700 hover:bg-blue-50 text-sm font-medium rounded-lg transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        Tambah Manual
    </a>
    <a href="{{ route('it-assets.import') }}"
       class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
        </svg>
        Import Excel
    </a>
    <a href="{{ route('it-assets.layouts.index') }}" title="Denah / peta letak — penempatan perangkat IT"
       class="inline-flex items-center gap-2 px-4 py-2 border border-blue-600 text-blue-700 hover:bg-blue-50 text-sm font-medium rounded-lg transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
        </svg>
        Denah IT
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
    if (request('status')) $activeFilters[] = ['key' => 'status', 'label' => 'Status: ' . (['active' => 'Aktif', 'inactive' => 'Nonaktif', 'needs_review' => 'Perlu Review', 'spare' => 'Spare'][request('status')] ?? request('status'))];
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
                            <option value="spare" {{ request('status') === 'spare' ? 'selected' : '' }}>Spare</option>
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


    @if($viewMode === 'layout')
    <!-- Layout CCTV per PT (collapsible) -->
    @forelse($cctvGroups as $plant => $switchGroups)
        <div class="mb-6">
            <button type="button" onclick="toggleCctvGroup('cctv-group-{{ Str::slug($plant) }}', this)"
                    class="w-full flex items-center justify-between bg-white rounded-xl border border-slate-200 px-5 py-3 hover:bg-slate-50 transition-colors">
                <span class="flex items-center gap-2">
                    <svg class="w-4 h-4 text-slate-400 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                    <span class="text-sm font-semibold text-slate-900">{{ $plant }}</span>
                    <span class="inline-flex px-2 py-0.5 text-xs font-medium bg-slate-100 text-slate-600 rounded">{{ $switchGroups->flatten(1)->count() }} kamera</span>
                </span>
            </button>
            <div id="cctv-group-{{ Str::slug($plant) }}" class="mt-3 space-y-4">
                @forelse($switchGroups as $switch => $cameras)
                    <div>
                        <button type="button" onclick="toggleCctvGroup('switch-group-{{ Str::slug($plant) }}-{{ Str::slug($switch) }}', this)"
                                class="w-full flex items-center justify-between px-3 py-2 rounded-lg hover:bg-slate-50 transition-colors">
                            <span class="flex items-center gap-2">
                                <svg class="w-4 h-4 text-slate-400 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                </svg>
                                <span class="text-xs font-semibold text-slate-700">{{ $switch }}</span>
                                <span class="inline-flex px-2 py-0.5 text-xs font-medium bg-blue-50 text-blue-700 rounded">{{ $cameras->count() }} kamera</span>
                            </span>
                        </button>
                        <div id="switch-group-{{ Str::slug($plant) }}-{{ Str::slug($switch) }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 mt-2">
                        @foreach($cameras as $itAsset)
            <div class="bg-white rounded-xl border border-slate-200 overflow-hidden flex flex-col">
                <div class="relative h-40 bg-slate-100">
                    @if(in_array($itAsset->device_type, ['CCTV', 'NVR'], true) && $itAsset->last_snapshot_path)
                        <img src="{{ \Illuminate\Support\Facades\Storage::disk(config('cctv.snapshot_disk'))->url($itAsset->last_snapshot_path) }}"
                             id="snapshot-img-{{ $itAsset->id }}"
                             alt="Snapshot {{ $itAsset->tag_name }}"
                             class="w-full h-full object-cover">
                    @else
                        <div class="w-full h-full flex items-center justify-center">
                            <svg class="w-10 h-10 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                            </svg>
                        </div>
                    @endif
                    <span class="absolute top-2 left-2 inline-flex px-2 py-0.5 text-xs font-medium bg-slate-900/70 text-white rounded">
                        {{ $deviceTypeLabels[$itAsset->device_type] ?? $itAsset->device_type }}
                    </span>
                </div>
                <div class="p-4 flex flex-col flex-1">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="font-medium text-slate-900 text-sm truncate">{{ $itAsset->tag_name ?? $itAsset->name ?? '-' }}</p>
                            @if($itAsset->ip_address)
                                <p class="font-mono text-xs text-slate-500 mt-0.5 truncate">{{ $itAsset->ip_address }}</p>
                            @endif
                        </div>
                        <span id="ping-badge-{{ $itAsset->id }}" class="shrink-0">
                            @if($itAsset->last_ping_status === 'online')
                                <x-status-badge status="active" :label="'Online' . ($itAsset->last_ping_latency !== null ? ' (' . $itAsset->last_ping_latency . 'ms)' : '')" />
                            @elseif($itAsset->last_ping_status === 'offline')
                                <x-status-badge status="error" label="Offline" />
                            @else
                                <x-status-badge status="inactive" label="Belum Dicek" />
                            @endif
                        </span>
                    </div>
                    @if($itAsset->location)
                        <p class="text-xs text-slate-400 mt-1.5 truncate">{{ $itAsset->location }}</p>
                    @endif
                    @if(($itAsset->spec['connect_to'] ?? null))
                        <p class="text-[11px] text-slate-400 mt-0.5 truncate">Switch: {{ $itAsset->spec['connect_to'] }}</p>
                    @endif
                    <div class="mt-3 pt-3 border-t border-slate-100 flex items-center justify-between gap-2">
                        <a href="{{ route('it-assets.show', $itAsset) }}" class="text-blue-600 hover:text-blue-700 text-xs font-medium shrink-0">Detail</a>@include('it-assets.partials._snapshot-btn', ['itAsset' => $itAsset])
                        <div class="flex items-center gap-2.5">
                            @if($itAsset->ip_address)
                                <button type="button" id="ping-btn-{{ $itAsset->id }}"
                                        onclick="pingDevice({{ $itAsset->id }}, this)"
                                        class="text-blue-600 hover:text-blue-700 text-xs font-medium">Ping</button>
                            @endif
                            <a href="{{ route('it-assets.edit', $itAsset) }}" class="text-slate-500 hover:text-blue-600 text-xs font-medium">Edit</a>
                            <form method="POST" action="{{ route('it-assets.destroy', $itAsset) }}"
                                  onsubmit="return confirm('Yakin ingin menghapus perangkat ini beserta riwayatnya?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-red-500 hover:text-red-700 text-xs font-medium">Hapus</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <div class="text-sm text-slate-400 italic py-2">Tidak ada kamera untuk switch ini.</div>
                @endforelse
            </div>
        </div>
        @empty
            <div class="bg-white rounded-xl border border-slate-200 py-10 text-center text-sm text-slate-400">
                Belum ada data kamera CCTV untuk filter ini.
            </div>
        @endforelse
    @else

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
                                <div class="flex items-center gap-2.5">
                                    @if($itAsset->ip_address)
                                        <button type="button" id="ping-btn-{{ $itAsset->id }}"
                                                onclick="pingDevice({{ $itAsset->id }}, this)"
                                                class="text-blue-600 hover:text-blue-700 text-xs font-medium">
                                            Ping
                                        </button>
                                    @else
                                        <span class="text-slate-300 text-xs" title="Tidak ada IP address">Ping</span>
                                    @endif
                                    <a href="{{ route('it-assets.show', $itAsset) }}" class="text-slate-500 hover:text-blue-600 text-xs font-medium transition-colors">Detail</a>@include('it-assets.partials._snapshot-btn', ['itAsset' => $itAsset])
                                    <a href="{{ route('it-assets.edit', $itAsset) }}" class="text-slate-500 hover:text-blue-600 text-xs font-medium transition-colors">Edit</a>
                                    <form method="POST" action="{{ route('it-assets.destroy', $itAsset) }}"
                                          onsubmit="return confirm('Yakin ingin menghapus perangkat ini beserta riwayatnya?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-red-500 hover:text-red-700 text-xs font-medium">Hapus</button>
                                    </form>
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
@endif
@endsection

@push('scripts')
<script>
const itAssetPingUrlTemplate = '{{ route('it-assets.ping', ['itAsset' => '__ID__']) }}';

/**
 * Collapse/expand grup CCTV per PT pada mode layout. Grup disembunyikan
 * dengan class 'hidden' dan chevron diputar 180 derajat.
 *
 * @param {string} groupId
 * @param {HTMLButtonElement} button
 */
function toggleCctvGroup(groupId, button) {
    const group = document.getElementById(groupId);
    if (!group) return;

    group.classList.toggle('hidden');
    const chevron = button.querySelector('svg');
    if (chevron) {
        chevron.classList.toggle('rotate-180');
    }
}

/**
 * Ping satu perangkat lewat AJAX dan perbarui badge status ping inline
 * tanpa reload halaman.
 *
 * Karena perangkat offline bisa memakan waktu hingga beberapa detik untuk
 * merespons, tombol ping menampilkan spinner + keterangan selama proses
 * berjalan agar user tidak mengira halaman macet.
 *
 * @param {number} id
 * @param {HTMLButtonElement} button
 */
function pingDevice(id, button) {
    const originalHtml = button.innerHTML;
    button.disabled = true;
    button.classList.add('cursor-wait');
    // Tampilkan indikator loading + keterangan pada tombol.
    button.innerHTML = '<svg class="w-3 h-3 mr-1 inline animate-spin" fill="none" viewBox="0 0 24 24">'
        + '<circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>'
        + '<path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>'
        + '</svg>Memeriksa...';
    button.title = 'Sedang memeriksa koneksi perangkat...';

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
        button.classList.remove('cursor-wait');
        button.innerHTML = originalHtml;
        button.title = '';
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

