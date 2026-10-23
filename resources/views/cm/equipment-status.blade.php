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

            <section class="ap-hero" style="margin-bottom:24px;">
                <p class="ap-eyebrow">Condition Monitoring</p>
                <h2 class="ap-display" style="margin-top:10px;">Status equipment terkini.</h2>
                <p class="ap-lede" style="margin-top:14px;">
                    Pantau kondisi terakhir setiap equipment, lengkap dengan vibrasi dan temperatur.
                </p>
            </section>

    {{-- Filter & Search --}}
    <div class="ap-card" style="margin-bottom:24px;">
        <form method="GET" class="ap-toolbar">
            <div class="ap-field ap-field--grow">
                <label class="ap-field-label" for="eqSearch">Cari equipment</label>
                <div class="ap-search">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                        <circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>
                    </svg>
                    <input type="text" id="eqSearch" name="search" value="{{ $search }}"
                           class="ap-input" placeholder="Cari tag / PT / nama / model..."
                           style="width:100%;">
                </div>
            </div>
            <div class="ap-field">
                <label class="ap-field-label" for="eqPt">PT</label>
                <select id="eqPt" name="pt" class="ap-select">
                    <option value="">Semua PT</option>
                    @foreach($ptList as $pt)
                        <option value="{{ $pt }}" {{ $filterPt === $pt ? 'selected' : '' }}>{{ $pt }}</option>
                    @endforeach
                </select>
            </div>
            <div class="ap-field">
                <label class="ap-field-label">Status</label>
                @php
                    $statusLabels = ['good' => 'Normal', 'alarm' => 'Alarm', 'danger' => 'Danger', 'visual_bad' => 'Visual Bad'];
                    $selectedStatuses = array_values(array_intersect((array) $filterStatus, array_keys($statusLabels)));
                @endphp
                <div style="position:relative;" id="status-wrap">
                    <button type="button" id="status-drop-btn" onclick="toggleStatusDrop('status-drop-panel', 'status-drop-btn')"
                            class="ap-input"
                            style="display:inline-flex;align-items:center;justify-content:space-between;gap:10px;width:100%;min-width:200px;text-align:left;">
                        <span id="status-drop-label" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $selectedStatuses ? implode(', ', array_map(fn($s) => $statusLabels[$s], $selectedStatuses)) : 'Semua Status' }}</span>
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                             style="flex:0 0 auto;color:#6e6e73;" aria-hidden="true">
                            <path d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>
                    <div id="status-drop-panel" class="hidden"
                         style="position:absolute;z-index:20;margin-top:6px;width:100%;min-width:200px;background:#fff;border-radius:12px;padding:6px;max-height:220px;overflow:auto;box-shadow:0 10px 30px rgba(0, 0, 0, .14), inset 0 0 0 1px rgba(0, 0, 0, .06);">
                        @foreach($statusLabels as $value => $label)
                            <label class="flex items-center gap-2 px-3 py-2 text-sm text-[#1d1d1f] rounded-lg hover:bg-[#f5f5f7] cursor-pointer">
                                <input type="checkbox" name="status[]" value="{{ $value }}" class="status-check rounded"
                                       style="accent-color:#0071e3;"
                                       {{ in_array($value, (array) $filterStatus, true) ? 'checked' : '' }}>
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                </div>
            </div>
            <div style="display:flex;gap:8px;">
                <button type="submit" class="ap-btn ap-btn--primary">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" aria-hidden="true">
                        <circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>
                    </svg>
                    Cari
                </button>
                <a href="{{ route('cm.equipment-status') }}" class="ap-btn ap-btn--quiet">Reset</a>
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
        <div style="display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin-bottom:16px;">
            <span class="ap-caption">Filter aktif:</span>
            @foreach($activeFilters as $f)
                <span class="ap-filter-chip">
                    {{ $f['label'] }}
                    <a href="{{ route('cm.equipment-status', request()->except($f['key'])) }}" aria-label="Hapus filter {{ $f['label'] }}">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true">
                            <path d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </a>
                </span>
            @endforeach
            <a href="{{ route('cm.equipment-status') }}" class="ap-link" style="font-size:12px;">Hapus semua</a>
        </div>
    @endif

    {{-- Info jumlah data --}}
    <p class="ap-caption" style="margin-bottom:12px;">
        Menampilkan {{ $equipments->firstItem() ?? 0 }}&ndash;{{ $equipments->lastItem() ?? 0 }} dari {{ $equipments->total() }} equipment
    </p>

    {{-- Tabel Equipment Status --}}
    <div class="ap-card ap-card--flush" style="margin-bottom:24px;">
        <div class="ap-table-wrap">
            <table class="ap-table">
                <thead>
                    <tr>
                        <th style="padding-left:24px;">Tag No</th>
                        <th>Nama Equipment</th>
                        <th>Model</th>
                        <th>PT</th>
                        <th style="text-align:center;">Status</th>
                        <th style="text-align:center;">Vibr / Temp</th>
                        <th style="padding-right:24px;">Terakhir Ukur</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($equipments as $eq)
                        @php
                            $statusTones = [
                                'good'       => 'ok',
                                'alarm'      => 'warn',
                                'danger'     => 'bad',
                                'visual_bad' => 'info',
                            ];
                            $currentStatus = $eq->current_status;
                            $statusTone    = $statusTones[$currentStatus] ?? '';
                            $statusLabel   = $currentStatus ? ucfirst(str_replace('_', ' ', $currentStatus)) : '&mdash;';
                            $valueColor    = $currentStatus === 'danger'
                                ? '#8a1f16'
                                : ($currentStatus === 'alarm' ? '#6f4402' : 'rgba(0, 0, 0, .68)');
                            $valueWeight   = $currentStatus === 'danger' ? '600' : '400';
                            $namaEquipment = $eq->asset?->description ?? '&mdash;';
                            $model         = $eq->asset?->model_number ?? '&mdash;';
                        @endphp
                        <tr>
                            <td style="padding-left:24px;">
                                <a href="{{ route('cm.equipment-show', $eq->equipment_tag) }}" class="ap-tag ap-mono">
                                    {{ $eq->equipment_tag }}
                                </a>
                            </td>
                            <td style="max-width:220px;">
                                <a href="{{ $eq->asset ? route('assets.show', $eq->asset) : route('cm.equipment-show', $eq->equipment_tag) }}"
                                   class="ap-link"
                                   style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                                   title="{{ strip_tags($namaEquipment) }}">
                                    {!! $namaEquipment !!}
                                </a>
                            </td>
                            <td class="ap-mono">{!! $model !!}</td>
                            <td>{{ $eq->pt_location }}</td>
                            <td style="text-align:center;">
                                <span class="ap-status {{ $statusTone ? 'ap-status--' . $statusTone : '' }}">
                                    <span class="ap-dot"></span>{{ $statusLabel }}
                                </span>
                            </td>
                            <td style="text-align:center;">
                                <span class="ap-mono" style="font-size:12px;color:{{ $valueColor }};font-weight:{{ $valueWeight }};">
                                    V:{{ $eq->last_vibration }}
                                </span>
                                <span style="color:rgba(0, 0, 0, .22);margin:0 6px;">|</span>
                                <span class="ap-mono" style="font-size:12px;color:{{ $valueColor }};font-weight:{{ $valueWeight }};">
                                    T:{{ $eq->last_temp }}&deg;C
                                </span>
                            </td>
                            <td style="padding-right:24px;color:rgba(0, 0, 0, .55);">
                                {{ $eq->last_reading_date ? \Carbon\Carbon::parse($eq->last_reading_date)->format('d M Y') : '-' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="ap-empty">
                                Tidak ada data equipment.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        @if($equipments->hasPages())
            <div style="padding:12px 24px;border-top:1px solid rgba(0, 0, 0, .06);">
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

