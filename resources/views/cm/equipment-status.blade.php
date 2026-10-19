@extends('layouts.app')

@section('title', 'Equipment Status — Condition Monitoring')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-[#1d1d1f]">Dashboard</a>
    <span class="text-[#d2d2d7]">/</span>
    <a href="{{ route('cm.overview') }}" class="hover:text-[#1d1d1f]">Condition Monitoring</a>
    <span class="text-[#d2d2d7]">/</span>
    <span class="text-[#1d1d1f] font-medium">Equipment Status</span>
@endsection

@section('content')
    @include('partials._apple-ui')

    <div class="ap">
        <div class="ap-wrap">
            @include('cm._tabs')

            <section class="ap-hero" style="padding:36px 40px;margin-bottom:24px;">
                <p class="ap-eyebrow">Condition Monitoring</p>
                <h2 class="ap-display" style="font-size:clamp(26px,3.2vw,40px);margin-top:8px;">Status equipment terkini.</h2>
                <p class="ap-lede" style="margin-top:10px;">
                    Pantau kondisi terakhir setiap equipment, lengkap dengan vibrasi dan temperatur.
                </p>
            </section>

    {{-- Filter & Search --}}
    <div class="ap-card p-4 mb-6">
        <form method="GET" class="flex flex-wrap gap-4 items-end">
            <div class="flex-1 min-w-[200px]">
                <label class="block text-xs font-medium text-[#6e6e73] mb-1.5">Cari Equipment</label>
                <div class="relative">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-[#6e6e73]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                    <input type="text" name="search" value="{{ $search }}"
                           class="w-full border border-black/10 rounded-lg pl-9 pr-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#0071e3] focus:border-[#0071e3]"
                           placeholder="Cari tag / PT / nama / model...">
                </div>
            </div>
            <div>
                <label class="block text-xs font-medium text-[#6e6e73] mb-1.5">PT</label>
                <select name="pt" class="border border-black/10 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-[#0071e3]">
                    <option value="">Semua PT</option>
                    @foreach($ptList as $pt)
                        <option value="{{ $pt }}" {{ $filterPt === $pt ? 'selected' : '' }}>{{ $pt }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-[#6e6e73] mb-1.5">Status</label>
                @php
                    $statusLabels = ['good' => 'Normal', 'alarm' => 'Alarm', 'danger' => 'Danger', 'visual_bad' => 'Visual Bad'];
                    $selectedStatuses = array_values(array_intersect((array) $filterStatus, array_keys($statusLabels)));
                @endphp
                <div class="relative" id="status-wrap">
                    <button type="button" id="status-drop-btn" onclick="toggleStatusDrop('status-drop-panel', 'status-drop-btn')"
                            class="w-full flex items-center justify-between gap-2 border border-black/10 hover:border-[#0071e3] rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-[#0071e3] text-left">
                        <span id="status-drop-label" class="truncate text-sm text-[#1d1d1f]">{{ $selectedStatuses ? implode(', ', array_map(fn($s) => $statusLabels[$s], $selectedStatuses)) : 'Semua Status' }}</span>
                        <svg class="w-4 h-4 text-[#6e6e73] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>
                    <div id="status-drop-panel" class="hidden absolute z-10 mt-1 w-full bg-white border border-black/5 rounded-lg shadow-lg py-1 max-h-48 overflow-auto">
                        <label class="flex items-center gap-2 px-3 py-1.5 text-sm text-[#1d1d1f] hover:bg-[#f5f5f7] cursor-pointer">
                            <input type="checkbox" name="status[]" value="good" class="status-check rounded" {{ in_array('good', (array) $filterStatus, true) ? 'checked' : '' }}>
                            Normal
                        </label>
                        <label class="flex items-center gap-2 px-3 py-1.5 text-sm text-[#1d1d1f] hover:bg-[#f5f5f7] cursor-pointer">
                            <input type="checkbox" name="status[]" value="alarm" class="status-check rounded" {{ in_array('alarm', (array) $filterStatus, true) ? 'checked' : '' }}>
                            Alarm
                        </label>
                        <label class="flex items-center gap-2 px-3 py-1.5 text-sm text-[#1d1d1f] hover:bg-[#f5f5f7] cursor-pointer">
                            <input type="checkbox" name="status[]" value="danger" class="status-check rounded" {{ in_array('danger', (array) $filterStatus, true) ? 'checked' : '' }}>
                            Danger
                        </label>
                        <label class="flex items-center gap-2 px-3 py-1.5 text-sm text-[#1d1d1f] hover:bg-[#f5f5f7] cursor-pointer">
                            <input type="checkbox" name="status[]" value="visual_bad" class="status-check rounded" {{ in_array('visual_bad', (array) $filterStatus, true) ? 'checked' : '' }}>
                            Visual Bad
                        </label>
                    </div>
                </div>
            </div>
            <div class="flex items-end gap-2">
                <button type="submit"
                        class="px-4 py-2 bg-[#0071e3] hover:bg-[#0077ed] text-white text-sm font-medium rounded-lg transition-colors">
                    <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                    Cari
                </button>
                <a href="{{ route('cm.equipment-status') }}"
                   class="px-4 py-2 border border-black/10 hover:bg-[#fafafc] text-[#1d1d1f] text-sm font-medium rounded-lg transition-colors">
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
        if ($filterStatus) $activeFilters[] = ['key' => 'status', 'label' => 'Status: ' . implode(', ', array_map(fn($s) => ucfirst(str_replace('_', ' ', $s)), $filterStatus))];
    @endphp
    @if(count($activeFilters) > 0)
        <div class="flex flex-wrap items-center gap-2 mb-4">
            <span class="text-xs text-[#6e6e73] font-medium">Filter aktif:</span>
            @foreach($activeFilters as $f)
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-[#f5f5f7] border border-[#0071e3]/20 text-[#0066cc] text-xs font-medium rounded-full">
                    {{ $f['label'] }}
                    <a href="{{ route('cm.equipment-status', request()->except($f['key'])) }}" class="text-[#6e6e73] hover:text-[#0066cc] transition-colors">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </a>
                </span>
            @endforeach
            <a href="{{ route('cm.equipment-status') }}" class="text-xs text-[#6e6e73] hover:text-red-500 transition-colors ml-1">
                Hapus semua
            </a>
        </div>
    @endif

    {{-- Info jumlah data --}}
    <div class="text-xs text-[#6e6e73] mb-3">
        Menampilkan {{ $equipments->firstItem() ?? 0 }}&ndash;{{ $equipments->lastItem() ?? 0 }} dari {{ $equipments->total() }} equipment
    </div>

    {{-- Tabel Equipment Status --}}
    <div class="ap-card overflow-hidden mb-6">
        <div class="overflow-x-auto">
            <table class="ap-table w-full text-sm">
                <thead>
                    <tr class="bg-[#fafafc] border-b border-black/5">
                        <th class="text-left text-xs font-medium text-[#6e6e73] uppercase tracking-wide px-5 py-3">Tag No</th>
                        <th class="text-left text-xs font-medium text-[#6e6e73] uppercase tracking-wide px-5 py-3">Nama Equipment</th>
                        <th class="text-left text-xs font-medium text-[#6e6e73] uppercase tracking-wide px-5 py-3">Model</th>
                        <th class="text-left text-xs font-medium text-[#6e6e73] uppercase tracking-wide px-5 py-3">PT</th>
                        <th class="text-center text-xs font-medium text-[#6e6e73] uppercase tracking-wide px-5 py-3">Status</th>
                        <th class="text-center text-xs font-medium text-[#6e6e73] uppercase tracking-wide px-5 py-3">Vibr / Temp</th>
                        <th class="text-left text-xs font-medium text-[#6e6e73] uppercase tracking-wide px-5 py-3">Terakhir Ukur</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-black/5">
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
                            $statusColor   = $statusColors[$currentStatus] ?? 'bg-[#e8e8ed] text-[#3a3a3c]';
                            $dotColor      = $statusDotColors[$currentStatus] ?? 'bg-[#fafafc]0';
                            $statusLabel   = ucfirst(str_replace('_', ' ', $currentStatus));
                            $namaEquipment = $eq->asset?->description ?? '&mdash;';
                            $model         = $eq->asset?->model_number ?? '&mdash;';
                        @endphp
                        <tr class="hover:bg-[#fafafc] transition-colors">
                            <td class="px-5 py-3.5">
                                <a href="{{ route('cm.equipment-show', $eq->equipment_tag) }}"
                                   class="font-mono text-sm font-medium text-[#0066cc] hover:text-[#004a99] hover:underline transition-colors">
                                    {{ $eq->equipment_tag }}
                                </a>
                            </td>
                            <td class="px-5 py-3.5 text-sm text-[#1d1d1f] max-w-[200px] truncate" title="{{ strip_tags($namaEquipment) }}">
                                {!! $namaEquipment !!}
                            </td>
                            <td class="px-5 py-3.5 text-xs text-[#3a3a3c]">{!! $model !!}</td>
                            <td class="px-5 py-3.5 text-xs text-[#3a3a3c]">{{ $eq->pt_location }}</td>
                            <td class="px-5 py-3.5 text-center">
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium border {{ $statusColor }}">
                                    <span class="w-1.5 h-1.5 rounded-full mr-1.5 {{ $dotColor }}"></span>
                                    {{ $statusLabel }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5 text-center">
                                <span class="font-mono text-xs {{ $currentStatus === 'danger' ? 'text-red-600 font-semibold' : ($currentStatus === 'alarm' ? 'text-amber-600' : 'text-[#3a3a3c]') }}">
                                    V:{{ $eq->last_vibration }}
                                </span>
                                <span class="text-[#d2d2d7] mx-1">|</span>
                                <span class="font-mono text-xs {{ $currentStatus === 'danger' ? 'text-red-600 font-semibold' : ($currentStatus === 'alarm' ? 'text-amber-600' : 'text-[#3a3a3c]') }}">
                                    T:{{ $eq->last_temp }}&deg;C
                                </span>
                            </td>
                            <td class="px-5 py-3.5 text-xs text-[#6e6e73]">
                                {{ $eq->last_reading_date ? \Carbon\Carbon::parse($eq->last_reading_date)->format('d M Y') : '-' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-5 py-8 text-center text-[#6e6e73] text-sm">
                                Tidak ada data equipment.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        @if($equipments->hasPages())
            <div class="px-5 py-3 border-t border-black/5">
                {{ $equipments->links() }}
            </div>
        @endif
    </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
/**
 * Toggle panel dropdown filter Status (multi-select checkbox).
 *
 * @param {string} panelId
 * @param {string} btnId
 */
function toggleStatusDrop(panelId, btnId) {
    const panel = document.getElementById(panelId);
    if (!panel) return;
    panel.classList.toggle('hidden');

    const btn = document.getElementById(btnId);
    if (btn) {
        // Putar chevron saat panel terbuka
        const chevron = btn.querySelector('svg');
        if (chevron) {
            chevron.classList.toggle('rotate-180');
        }
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const panel = document.getElementById('status-drop-panel');
    if (!panel) return;

    const updateLabel = function () {
        const labels = { good: 'Normal', alarm: 'Alarm', danger: 'Danger', visual_bad: 'Visual Bad' };
        const checked = Array.from(panel.querySelectorAll('input.status-check:checked'))
            .map(function (input) {
                return labels[input.value] || input.value;
            });
        const label = document.getElementById('status-drop-label');
        if (label) {
            label.textContent = checked.length ? checked.join(', ') : 'Semua Status';
        }
    };

    panel.querySelectorAll('input.status-check').forEach(function (input) {
        input.addEventListener('change', updateLabel);
    });

    // Tutup panel saat klik di luar
    document.addEventListener('click', function (e) {
        const btn = document.getElementById('status-drop-btn');
        if (panel.contains(e.target) || (btn && btn.contains(e.target))) {
            return;
        }
        panel.classList.add('hidden');
    });
});
</script>
@endpush

