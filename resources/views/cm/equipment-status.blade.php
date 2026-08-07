@extends('layouts.app')

@section('title', 'Equipment Status — Condition Monitoring')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('cm.overview') }}" class="hover:text-slate-700">Condition Monitoring</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Equipment Status</span>
@endsection

@section('content')
    @include('cm._tabs')

    {{-- Filter & Search --}}
    <div class="bg-white rounded-xl border border-slate-200 p-4 mb-6">
        <form method="GET" class="flex flex-wrap gap-4 items-end">
            <div class="flex-1 min-w-[200px]">
                <label class="block text-xs font-medium text-slate-500 mb-1.5">Cari Equipment</label>
                <div class="relative">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                    <input type="text" name="search" value="{{ $search }}"
                           class="w-full border border-slate-300 rounded-lg pl-9 pr-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500"
                           placeholder="Cari tag / PT / nama / model...">
                </div>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1.5">PT</label>
                <select name="pt" class="border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-teal-500">
                    <option value="">Semua PT</option>
                    @foreach($ptList as $pt)
                        <option value="{{ $pt }}" {{ $filterPt === $pt ? 'selected' : '' }}>{{ $pt }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1.5">Status</label>
                <select name="status" class="border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-teal-500">
                    <option value="">Semua Status</option>
                    <option value="good" {{ $filterStatus === 'good' ? 'selected' : '' }}>Normal</option>
                    <option value="alarm" {{ $filterStatus === 'alarm' ? 'selected' : '' }}>Alarm</option>
                    <option value="danger" {{ $filterStatus === 'danger' ? 'selected' : '' }}>Danger</option>
                    <option value="visual_bad" {{ $filterStatus === 'visual_bad' ? 'selected' : '' }}>Visual Bad</option>
                </select>
            </div>
            <div class="flex items-end gap-2">
                <button type="submit"
                        class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white text-sm font-medium rounded-lg transition-colors">
                    <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                    Cari
                </button>
                <a href="{{ route('cm.equipment-status') }}"
                   class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                    Reset
                </a>
            </div>
        </form>
    </div>

    {{-- Active Filter Chips --}}
    @php
        $activeFilters = [];
        if ($search) $activeFilters[] = ['key' => 'search', 'label' => 'Pencarian: ' . $search];
        if ($filterPt) $activeFilters[] = ['key' => 'pt', 'label' => 'PT: ' . $filterPt];
        if ($filterStatus) $activeFilters[] = ['key' => 'status', 'label' => 'Status: ' . ucfirst(str_replace('_', ' ', $filterStatus))];
    @endphp
    @if(count($activeFilters) > 0)
        <div class="flex flex-wrap items-center gap-2 mb-4">
            <span class="text-xs text-slate-500 font-medium">Filter aktif:</span>
            @foreach($activeFilters as $f)
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-teal-50 border border-teal-200 text-teal-700 text-xs font-medium rounded-full">
                    {{ $f['label'] }}
                    <a href="{{ route('cm.equipment-status', request()->except($f['key'])) }}" class="text-teal-400 hover:text-teal-600 transition-colors">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </a>
                </span>
            @endforeach
            <a href="{{ route('cm.equipment-status') }}" class="text-xs text-slate-400 hover:text-red-500 transition-colors ml-1">
                Hapus semua
            </a>
        </div>
    @endif

    {{-- Info jumlah data --}}
    <div class="text-xs text-slate-400 mb-3">
        Menampilkan {{ $equipments->firstItem() ?? 0 }}&ndash;{{ $equipments->lastItem() ?? 0 }} dari {{ $equipments->total() }} equipment
    </div>

    {{-- Tabel Equipment Status --}}
    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden mb-6">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-100">
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Tag No</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Nama Equipment</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Model</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">PT</th>
                        <th class="text-center text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Status</th>
                        <th class="text-center text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Vibr / Temp</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Terakhir Ukur</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($equipments as $eq)
                        @php
                            $statusColors = [
                                'good'       => 'bg-green-100 text-green-700 border-green-300',
                                'alarm'      => 'bg-amber-100 text-amber-700 border-amber-300',
                                'danger'     => 'bg-red-100 text-red-700 border-red-300',
                                'visual_bad' => 'bg-purple-100 text-purple-700 border-purple-300',
                            ];
                            $statusDotColors = [
                                'good'       => 'bg-green-500',
                                'alarm'      => 'bg-amber-500',
                                'danger'     => 'bg-red-500',
                                'visual_bad' => 'bg-purple-500',
                            ];
                            $currentStatus = $eq->current_status;
                            $statusColor   = $statusColors[$currentStatus] ?? 'bg-slate-100 text-slate-600';
                            $dotColor      = $statusDotColors[$currentStatus] ?? 'bg-slate-500';
                            $statusLabel   = ucfirst(str_replace('_', ' ', $currentStatus));
                            $namaEquipment = $eq->asset?->description ?? '&mdash;';
                            $model         = $eq->asset?->model_number ?? '&mdash;';
                        @endphp
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-5 py-3.5">
                                <a href="{{ route('cm.equipment-show', $eq->equipment_tag) }}"
                                   class="font-mono text-sm font-medium text-teal-700 hover:text-teal-900 hover:underline transition-colors">
                                    {{ $eq->equipment_tag }}
                                </a>
                            </td>
                            <td class="px-5 py-3.5 text-sm text-slate-700 max-w-[200px] truncate" title="{{ strip_tags($namaEquipment) }}">
                                {!! $namaEquipment !!}
                            </td>
                            <td class="px-5 py-3.5 text-xs text-slate-600">{!! $model !!}</td>
                            <td class="px-5 py-3.5 text-xs text-slate-600">{{ $eq->pt_location }}</td>
                            <td class="px-5 py-3.5 text-center">
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium border {{ $statusColor }}">
                                    <span class="w-1.5 h-1.5 rounded-full mr-1.5 {{ $dotColor }}"></span>
                                    {{ $statusLabel }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5 text-center">
                                <span class="font-mono text-xs {{ $currentStatus === 'danger' ? 'text-red-600 font-semibold' : ($currentStatus === 'alarm' ? 'text-amber-600' : 'text-slate-600') }}">
                                    V:{{ $eq->last_vibration }}
                                </span>
                                <span class="text-slate-300 mx-1">|</span>
                                <span class="font-mono text-xs {{ $currentStatus === 'danger' ? 'text-red-600 font-semibold' : ($currentStatus === 'alarm' ? 'text-amber-600' : 'text-slate-600') }}">
                                    T:{{ $eq->last_temp }}&deg;C
                                </span>
                            </td>
                            <td class="px-5 py-3.5 text-xs text-slate-500">
                                {{ $eq->last_reading_date ? \Carbon\Carbon::parse($eq->last_reading_date)->format('d M Y') : '-' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-5 py-8 text-center text-slate-400 text-sm">
                                Tidak ada data equipment.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        @if($equipments->hasPages())
            <div class="px-5 py-3 border-t border-slate-100">
                {{ $equipments->links() }}
            </div>
        @endif
    </div>
@endsection
