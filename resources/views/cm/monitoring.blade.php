@extends('layouts.app')

@section('title', 'CM Monitoring — Condition Monitoring')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('cm.overview') }}" class="hover:text-slate-700">Condition Monitoring</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">CM Monitoring</span>
@endsection

@section('content')
    @include('cm._tabs')

    {{-- Ringkasan Status Data Bulan Berjalan --}}
    @php
        $bulanNama = \Carbon\Carbon::create()->month($bulanIni)->format('F');
        $belumCount = $alertEquipments->count();
        $sudahBulanIni = max($totalEquipments - $belumCount, 0);
        $pctDone = $totalEquipments > 0 ? round(($sudahBulanIni / $totalEquipments) * 100, 0) : 0;
        $groupBelum = $alertEquipments->groupBy('pt_location');
    @endphp

    {{-- Insight: Equipment 2 Bulan Berturut Belum Diambil --}}
    @if(isset($insightEntities) && $insightEntities->isNotEmpty())
        @php $maxShow = 6; $totalInsight = $insightEntities->count(); @endphp
        <div class="bg-red-50 border border-red-200 rounded-xl p-5 mb-6">
            <div class="flex items-start justify-between gap-4 flex-wrap">
                <div class="flex items-start gap-3">
                    <svg class="w-8 h-8 text-red-600 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                    </svg>
                    <div>
                        <h4 class="font-semibold text-red-900 text-base">
                            Perlu Tindakan: {{ $totalInsight }} Equipment Belum Diambil 2 Bulan Berturut
                        </h4>
                        <p class="text-sm text-red-700">
                            Equipment ini belum diambil datanya {{ $bulanNama }} {{ $tahunIni }} dan bulan sebelumnya.
                            Tindak lanjuti agar di-arrange untuk running pada bulan berikutnya.
                        </p>
                    </div>
                </div>
            </div>
            <div class="mt-4 bg-white rounded-xl border border-red-100 divide-y divide-red-50">
                @foreach($insightEntities as $index => $eq)
                    <div class="insight-row flex flex-wrap items-center gap-3 px-4 py-3 {{ $index >= $maxShow ? 'hidden' : '' }}">
                        <a href="{{ route('cm.equipment-show', $eq->equipment_tag) }}"
                           class="font-mono text-sm font-medium text-red-700 hover:text-red-900 hover:underline">
                            {{ $eq->equipment_tag }}
                        </a>
                        <span class="text-xs text-slate-500">{{ $eq->pt_location }}</span>
                        <span class="text-xs text-slate-600 ml-auto">
                            @php
                                $ket = $eq->insight_keterangan ?? 'belum 2 bulan berturut';
                                $badgeStop = str_contains($ket, 'stop');
                            @endphp
                            <span class="{{ $badgeStop ? 'inline-flex items-center px-2 py-0.5 rounded-full bg-slate-200 text-slate-700' : '' }}">{{ $ket }}</span>
                            <span class="text-slate-400">:</span>
                            <span class="font-semibold text-red-600">{{ $eq->insight_bulan_label }}</span>
                        </span>
                    </div>
                @endforeach
            </div>
            @if($totalInsight > $maxShow)
                <div class="mt-3 text-center">
                    <button type="button" id="insight-more-btn" data-open="0"
                            class="inline-flex items-center gap-1 text-sm font-medium text-red-700 hover:text-red-900 hover:underline transition-colors">
                        <span id="insight-more-label">Lihat semua {{ $totalInsight }}</span>
                        <svg id="insight-more-arrow" class="w-3 h-3 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>
                </div>
            @endif
        </div>
    @endif

    @if($belumCount === 0)
        {{-- Banner positif: semua sudah diambil --}}
        <div class="bg-green-50 border border-green-200 rounded-xl p-5 mb-6">
            <div class="flex items-center gap-3">
                <svg class="w-7 h-7 text-green-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <div class="flex-1">
                    <h4 class="font-semibold text-green-800 text-sm">Semua equipment sudah diambil data {{ $bulanNama }} {{ $tahunIni }}</h4>
                    <p class="text-sm text-green-700">Tidak ada equipment yang belum diambil data bulan ini. Semua {{ $totalEquipments }} equipment tercakup.</p>
                </div>
            </div>
        </div>
    @else
        {{-- Card peringatan: ada yang belum diambil --}}
        <div class="bg-amber-50 border border-amber-200 rounded-xl p-5 mb-6">
            {{-- Header ringkasan --}}
            <div class="flex items-start justify-between gap-4 flex-wrap">
                <div class="flex items-start gap-3">
                    <svg class="w-8 h-8 text-amber-600 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                    </svg>
                    <div>
                        <h4 class="font-semibold text-amber-900 text-base">
                            Belum Diambil Data {{ $bulanNama }} {{ $tahunIni }}
                        </h4>
                        <p class="text-sm text-amber-800">
                            <span class="font-bold">{{ $belumCount }}</span> dari
                            <span class="font-bold">{{ $totalEquipments }}</span> equipment belum diambil data bulan ini.
                            <span class="ml-1 text-amber-700">Compliance {{ $pctDone }}% terpenuhi.</span>
                        </p>
                    </div>
                </div>
                @if($groupBelum->isNotEmpty())
                    <div class="text-right shrink-0">
                        <div class="text-3xl font-extrabold text-amber-700 leading-none">{{ $pctDone }}%</div>
                        <div class="text-xs text-amber-700 mt-1">sudah diambil</div>
                    </div>
                @endif
            </div>

            {{-- Progress bar keseluruhan --}}
            <div class="w-full bg-amber-200 h-2 rounded-full mt-4 overflow-hidden">
                <div class="h-2 rounded-full {{ $pctDone >= 80 ? 'bg-green-500' : 'bg-amber-500' }}"
                     style="width: {{ $pctDone }}%"></div>
            </div>

            {{-- Breakdown jumlah belum per PT --}}
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3 mt-5">
                @foreach($groupBelum as $pt => $items)
                    <div class="bg-white rounded-xl border border-amber-100 p-4">
                        <div class="flex items-center justify-between">
                            <p class="text-sm font-semibold text-slate-800">{{ $pt }}</p>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-100 text-amber-800">
                                {{ $items->count() }} belum
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Filters --}}
    <div class="bg-white rounded-xl border border-slate-200 p-4 mb-6">
        <form method="GET" class="flex flex-wrap gap-4 items-end">
            <div class="space-y-1">
                <label class="text-xs font-medium text-slate-500">Tahun</label>
                <select name="tahun" class="border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-teal-500">
                    @foreach($tahunList as $th)
                        <option value="{{ $th }}" {{ $filterTahun == $th ? 'selected' : '' }}>{{ $th }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-center gap-2 pt-5">
                <label class="inline-flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="hide_done" value="1" {{ $hideDone ? 'checked' : '' }}
                           class="rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                    <span class="text-sm text-slate-700">Sembunyikan 100% Done</span>
                </label>
            </div>
            <button type="submit" class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white text-sm font-medium rounded-lg transition-colors">
                Terapkan
            </button>
            <a href="{{ route('cm.monitoring') }}" class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                Reset
            </a>
            <a href="{{ route('cm.export-monitoring', ['tahun' => $filterTahun]) }}"
               class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded-lg transition-colors inline-flex items-center gap-1.5">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                Export Excel
            </a>
        </form>
    </div>

    {{-- Tabel Monitoring Bulanan --}}
    <div id="eq-table" class="bg-white rounded-xl border border-slate-200 overflow-hidden mb-6">
        {{-- Toolbar: filter PT + pencarian live --}}
        <div class="flex flex-wrap items-center gap-3 px-4 py-3 border-b border-slate-100 bg-slate-50/50">
            <div class="flex items-center gap-2">
                <label for="filter-pt" class="text-xs font-medium text-slate-500">PT</label>
                <select id="filter-pt"
                        class="border border-slate-300 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-teal-500">
                    <option value="">Semua PT</option>
                    @foreach($ptList as $pt)
                        <option value="{{ $pt }}">{{ $pt }}</option>
                    @endforeach
                </select>
            </div>
            <div class="relative flex-1 min-w-[200px] max-w-sm">
                <svg class="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 10a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <input type="text" id="search-eq" placeholder="Cari tag equipment..."
                       class="w-full border border-slate-300 rounded-lg pl-9 pr-3 py-1.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-teal-500">
            </div>
            <span id="table-count" class="text-xs text-slate-400 ml-auto"></span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-100">
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3 sticky left-0 bg-slate-50 min-w-[180px]">
                            Equipment
                        </th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">PT</th>
                        @foreach(range($bulanMulai, 12) as $b)
                            <th class="text-center text-xs font-medium text-slate-500 uppercase tracking-wide px-2 py-3 min-w-[36px]">
                                {{ Carbon\Carbon::create()->month($b)->format('M') }}
                            </th>
                        @endforeach
                        <th class="text-center text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3 min-w-[80px]">Done %</th>
                    </tr>
                </thead>
                <tbody id="eq-tbody" class="divide-y divide-slate-50">
                    @forelse($equipments as $eq)
                        <tr class="hover:bg-slate-50 transition-colors"
                            data-tag="{{ strtolower($eq->equipment_tag) }}"
                            data-pt="{{ strtolower($eq->pt_location) }}">
                            <td class="px-4 py-3 font-mono text-sm font-medium sticky left-0 bg-white">
                                <a href="{{ route('cm.equipment-show', $eq->equipment_tag) }}"
                                   class="text-teal-700 hover:text-teal-900 hover:underline transition-colors">
                                    {{ $eq->equipment_tag }}
                                </a>
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-600">{{ $eq->pt_location }}</td>
                            @foreach(range($bulanMulai, 12) as $b)
                                @php
                                    $track = $eq->monthlyTrackings->firstWhere('bulan', $b);
                                    $isDone = $track && $track->status === 'sudah';
                                    $isFree = $track && $track->status === 'free';
                                @endphp
                                <td class="text-center px-2 py-3">
                                    @if($isDone)
                                        <span class="text-green-500" title="Sudah">&#10003;</span>
                                    @elseif($isFree)
                                        <span class="text-slate-400" title="Equipment tidak running (Stop)">&#9679;</span>
                                    @elseif($track && $b <= $bulanIni)
                                        <span class="text-slate-300" title="Belum">&mdash;</span>
                                    @else
                                        <span class="text-slate-200">&middot;</span>
                                    @endif
                                </td>
                            @endforeach
                            <td class="text-center px-4 py-3">
                                @php
                                    $pct = $eq->progress_pct;
                                    $color = $pct >= 80 ? 'text-green-600' : ($pct >= 50 ? 'text-amber-600' : 'text-red-500');
                                @endphp
                                <span class="font-semibold {{ $color }}">{{ $pct }}%</span>
                                <div class="w-full bg-slate-100 rounded-full h-1 mt-1 max-w-[60px] mx-auto">
                                    <div class="h-1 rounded-full {{ $pct >= 80 ? 'bg-green-500' : ($pct >= 50 ? 'bg-amber-500' : 'bg-red-500') }}"
                                         style="width: {{ $pct }}%"></div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="15" class="px-5 py-8 text-center text-slate-400 text-sm">
                                Tidak ada data monitoring.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const selectPt = document.getElementById('filter-pt');
        const inputSearch = document.getElementById('search-eq');
        const tbody = document.getElementById('eq-tbody');
        const countEl = document.getElementById('table-count');
        const rows = Array.from(tbody.querySelectorAll('tr'));

        function applyFilter() {
            const pt = (selectPt.value || '').toLowerCase();
            const q = (inputSearch.value || '').toLowerCase().trim();
            let visible = 0;

            rows.forEach(function (row) {
                const rowPt = row.getAttribute('data-pt') || '';
                const rowTag = row.getAttribute('data-tag') || '';
                const matchPt = !pt || rowPt === pt;
                const matchQ = !q || rowTag.includes(q);
                const show = matchPt && matchQ;
                row.style.display = show ? '' : 'none';
                if (show) visible++;
            });

            if (countEl) {
                countEl.textContent = visible + ' dari ' + rows.length + ' equipment';
            }
        }

        if (selectPt) selectPt.addEventListener('change', applyFilter);
        if (inputSearch) inputSearch.addEventListener('input', applyFilter);

        applyFilter();

        // Toggle "Lihat semua" pada daftar insight
        const insightRows = Array.from(document.querySelectorAll('.insight-row'));
        const insightBtn = document.getElementById('insight-more-btn');
        if (insightBtn) {
            const insightLabel = document.getElementById('insight-more-label');
            const insightArrow = document.getElementById('insight-more-arrow');
            insightBtn.addEventListener('click', function () {
                const isOpen = insightBtn.classList.toggle('open');
                const showAll = insightBtn.classList.contains('open');
                insightRows.forEach(function (r, i) {
                    r.classList.toggle('hidden', showAll ? false : i >= 6);
                });
                if (insightLabel) insightLabel.textContent = showAll ? 'Tutup' : 'Lihat semua ' + insightRows.length;
                if (insightArrow) insightArrow.style.transform = showAll ? 'rotate(180deg)' : '';
            });
        }
    });
</script>
@endpush
