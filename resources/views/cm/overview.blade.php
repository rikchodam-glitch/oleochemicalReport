@extends('layouts.app')

@section('title', 'Overview — Condition Monitoring')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('cm.overview') }}" class="hover:text-slate-700">Condition Monitoring</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Overview</span>
@endsection

@section('content')
    {{-- Tab Navigasi --}}
    @include('cm._tabs')

    {{-- Summary Cards --}}
    <div id="summaryCards" class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
        @include('cm._overview-cards', [
            'totalRecords' => $totalRecords,
            'goodCount' => $goodCount, 'goodPct' => $goodPct,
            'alarmCount' => $alarmCount, 'alarmPct' => $alarmPct,
            'dangerCount' => $dangerCount, 'dangerPct' => $dangerPct,
            'visualBadCount' => $visualBadCount, 'visualBadPct' => $visualBadPct,
        ])
    </div>

    {{-- Filters --}}
    <div class="bg-white rounded-xl border border-slate-200 p-4 mb-6">
        <form id="filterForm" class="flex flex-wrap gap-4 items-end">
            <div class="space-y-1">
                <label class="text-xs font-medium text-slate-500">PT</label>
                <select name="pt" class="filter-select border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-teal-500">
                    <option value="">Semua PT</option>
                    @foreach($ptList as $pt)
                        <option value="{{ $pt }}" {{ $filterPt === $pt ? 'selected' : '' }}>{{ $pt }}</option>
                    @endforeach
                </select>
            </div>
            <div class="space-y-1">
                <label class="text-xs font-medium text-slate-500">Tahun</label>
                <select name="tahun" class="filter-select border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-teal-500">
                    @foreach($tahunList as $th)
                        <option value="{{ $th }}" {{ $filterTahun == $th ? 'selected' : '' }}>{{ $th }}</option>
                    @endforeach
                </select>
            </div>
            <div class="space-y-1">
                <label class="text-xs font-medium text-slate-500">Bulan</label>
                <select name="bulan" class="filter-select border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-teal-500">
                    <option value="">Semua Bulan</option>
                    @foreach(range(1, 12) as $b)
                        <option value="{{ $b }}" {{ $filterBulan == $b ? 'selected' : '' }}>{{ DateTime::createFromFormat('!m', $b)->format('F') }}</option>
                    @endforeach
                </select>
            </div>
            <div class="space-y-1">
                <label class="text-xs font-medium text-slate-500">Status</label>
                <select name="status" class="filter-select border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-teal-500">
                    <option value="">Semua Status</option>
                    <option value="good" {{ $filterStatus === 'good' ? 'selected' : '' }}>Good</option>
                    <option value="alarm" {{ $filterStatus === 'alarm' ? 'selected' : '' }}>Alarm</option>
                    <option value="danger" {{ $filterStatus === 'danger' ? 'selected' : '' }}>Danger</option>
                    <option value="visual_bad" {{ $filterStatus === 'visual_bad' ? 'selected' : '' }}>Visual Bad</option>
                </select>
            </div>
            <a href="{{ route('cm.overview') }}" class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                Reset
            </a>
            <button type="button" id="syncSheetsBtn" onclick="syncGoogleSheets()"
                    class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors inline-flex items-center gap-1.5 disabled:opacity-50 disabled:cursor-not-allowed">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                Sync Google Sheets
            </button>
        </form>
    </div>

    {{-- Loading indicator --}}
    <div id="loadingIndicator" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/10">
        <div class="bg-white rounded-xl p-6 shadow-xl flex items-center gap-3">
            <svg class="animate-spin w-5 h-5 text-teal-600" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
            </svg>
            <span class="text-sm text-slate-700">Memuat data...</span>
        </div>
    </div>

    {{-- Charts Row --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        {{-- Donut Chart: Breakdown per PT --}}
        <div class="bg-white rounded-xl border border-slate-200 p-5">
            <h3 class="font-medium text-slate-900 mb-4">Breakdown Status per PT</h3>
            <canvas id="donutChart" height="250"></canvas>
        </div>

        {{-- Bar Chart: Trend Bulanan --}}
        <div class="bg-white rounded-xl border border-slate-200 p-5">
            <h3 class="font-medium text-slate-900 mb-4">Trend Status Bulanan</h3>
            <canvas id="trendChart" height="250"></canvas>
        </div>
    </div>

    {{-- Top 10 Vibrasi Tertinggi --}}
    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden mb-6">
        <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
            <h3 class="font-medium text-slate-900">Top 10 Vibrasi Tertinggi (Danger)</h3>
            <span class="text-xs text-slate-400">Berdasarkan nilai vibrasi tertinggi</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-100">
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Equipment Tag</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">PT</th>
                        <th class="text-right text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Motor</th>
                        <th class="text-right text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Mesin</th>
                        <th class="text-right text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Temp Motor</th>
                        <th class="text-right text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Temp Mesin</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Tanggal</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($topVibrasi as $r)
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-5 py-3">
                                <a href="{{ route('cm.equipment-show', $r->equipment_tag) }}"
                                   class="font-mono text-sm font-medium text-teal-700 hover:text-teal-900 hover:underline transition-colors">
                                    {{ $r->equipment_tag }}
                                </a>
                            </td>
                            <td class="px-5 py-3 text-slate-600">{{ $r->pt_location }}</td>
                            <td class="px-5 py-3 text-right font-mono text-red-600 font-semibold">{{ $r->max_motor }}</td>
                            <td class="px-5 py-3 text-right font-mono text-red-600 font-semibold">{{ $r->max_mesin }}</td>
                            <td class="px-5 py-3 text-right font-mono text-slate-700">{{ $r->max_temp_motor }}°C</td>
                            <td class="px-5 py-3 text-right font-mono text-slate-700">{{ $r->max_temp_mesin }}°C</td>
                            <td class="px-5 py-3 text-slate-500">{{ $r->tanggal->format('d M Y') }}</td>
                            <td class="px-5 py-3">
                                <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700">
                                    <span class="w-1.5 h-1.5 rounded-full bg-red-500"></span>
                                    Danger
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-5 py-8 text-center text-slate-400 text-sm">
                                Tidak ada data vibrasi danger.
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
var kondisiLabels = {'good': 'Good', 'alarm': 'Alarm', 'danger': 'Danger', 'visual_bad': 'Visual Bad'};
var kondisiColors = {'good': '#10B981', 'alarm': '#F59E0B', 'danger': '#EF4444', 'visual_bad': '#8B5CF6'};
var kondisiOrder = ['good', 'alarm', 'danger', 'visual_bad'];

document.addEventListener('DOMContentLoaded', function() {
    loadChartData();

    document.querySelectorAll('.filter-select').forEach(function(sel) {
        sel.addEventListener('change', function() {
            loadChartData();
        });
    });
});

function syncGoogleSheets() {
    if (!confirm('Sync data dari Google Sheets? Data CM & Finding CM akan ditarik dari spreadsheet online.')) return;

    var btn = document.getElementById('syncSheetsBtn');
    var originalHtml = btn ? btn.innerHTML : '';

    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Menyinkronkan...';
    }

    fetch('{{ route('cm.sync-google-sheets') }}', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            'Accept': 'application/json'
        }
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        alert(data.message || 'Sync selesai.');
        if (data.success) window.location.reload();
    })
    .catch(function(err) {
        alert('Terjadi kesalahan saat sync: ' + err.message);
    })
    .finally(function() {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
    });
}

function loadChartData() {
    showLoading(true);

    var params = new URLSearchParams();
    var form = document.getElementById('filterForm');
    var formData = new FormData(form);
    formData.forEach(function(value, key) {
        if (value) params.set(key, value);
    });

    var queryString = params.toString();
    var url = '{{ route("cm.trend-chart-data") }}' + (queryString ? '?' + queryString : '');

    window.history.replaceState({}, '', window.location.pathname + (queryString ? '?' + queryString : ''));

    Promise.all([
        fetch(url).then(function(r) { return r.json(); }),
        loadDonutData(queryString),
        loadSummaryCards(queryString)
    ]).then(function(results) {
        renderTrendChart(results[0]);
    }).catch(function(err) {
        console.error('Error loading data:', err);
    }).finally(function() {
        showLoading(false);
    });
}

function loadSummaryCards(queryString) {
    var url = '{{ route("cm.overview-summary") }}' + (queryString ? '?' + queryString : '');
    return fetch(url)
        .then(function(r) { return r.text(); })
        .then(function(html) {
            document.getElementById('summaryCards').innerHTML = html;
        });
}

function loadDonutData(queryString) {
    var canvas = document.getElementById('donutChart');
    if (!canvas) return Promise.resolve();

    var url = '{{ route("cm.donut-data") }}' + (queryString ? '?' + queryString : '');
    return fetch(url)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            renderDonutChart(data);
        });
}

function showLoading(show) {
    var el = document.getElementById('loadingIndicator');
    if (el) el.classList.toggle('hidden', !show);
}

function renderDonutChart(donutData) {
    var canvas = document.getElementById('donutChart');
    if (!canvas) return;

    var ctx = canvas.getContext('2d');
    canvas.width = canvas.parentElement.clientWidth;
    canvas.height = 250;

    var cx = 120, cy = 125, legendX = 230;

    if (!donutData || !Object.keys(donutData).length) {
        ctx.fillStyle = '#94A3B8';
        ctx.font = '14px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('Tidak ada data', cx, cy);
        return;
    }

    var pts = Object.keys(donutData);
    var grandTotal = 0;
    pts.forEach(function(pt) {
        Object.values(donutData[pt]).forEach(function(v) { grandTotal += v; });
    });

    if (grandTotal === 0) {
        ctx.fillStyle = '#94A3B8';
        ctx.font = '14px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('Tidak ada data', cx, cy);
        return;
    }

    ctx.clearRect(0, 0, canvas.width, canvas.height);

    var ringSpacing = 22, baseRadius = 40;
    pts.forEach(function(pt, idx) {
        var r = baseRadius + (idx * ringSpacing);
        var innerR = r - 14;
        var data = donutData[pt];
        var startAngle = -Math.PI / 2;

        kondisiOrder.forEach(function(kondisi) {
            var val = data[kondisi] || 0;
            if (val === 0) return;
            var sliceAngle = (val / grandTotal) * 2 * Math.PI;
            ctx.beginPath();
            ctx.arc(cx, cy, r, startAngle, startAngle + sliceAngle);
            ctx.arc(cx, cy, innerR, startAngle + sliceAngle, startAngle, true);
            ctx.closePath();
            ctx.fillStyle = kondisiColors[kondisi] || '#CBD5E1';
            ctx.fill();
            startAngle += sliceAngle;
        });
    });

    var ly = 20;
    ctx.font = '12px sans-serif';
    ctx.textAlign = 'left';
    ctx.textBaseline = 'middle';
    ctx.fillStyle = '#1E293B';
    ctx.font = 'bold 12px sans-serif';
    ctx.fillText('Status:', legendX, ly);
    ly += 22;
    kondisiOrder.forEach(function(k) {
        ctx.fillStyle = kondisiColors[k];
        ctx.fillRect(legendX, ly - 4, 12, 12);
        ctx.fillStyle = '#475569';
        ctx.font = '12px sans-serif';
        ctx.fillText(kondisiLabels[k], legendX + 18, ly + 2);
        ly += 20;
    });
    ly += 10;
    ctx.fillStyle = '#1E293B';
    ctx.font = 'bold 12px sans-serif';
    ctx.fillText('PT:', legendX, ly);
    ly += 22;
    pts.forEach(function(pt) {
        var totalPt = 0;
        Object.values(donutData[pt]).forEach(function(v) { totalPt += v; });
        var pct = grandTotal > 0 ? Math.round((totalPt / grandTotal) * 100) : 0;
        ctx.fillStyle = '#0E9E8E';
        ctx.fillRect(legendX, ly - 4, 12, 12);
        ctx.fillStyle = '#475569';
        ctx.font = '12px sans-serif';
        ctx.textAlign = 'left';
        ctx.fillText(pt + ' (' + pct + '%)', legendX + 18, ly + 2);
        ly += 20;
    });
}

function renderTrendChart(data) {
    var canvas = document.getElementById('trendChart');
    if (!canvas) return;
    if (!data || !data.length) {
        var ctx = canvas.getContext('2d');
        canvas.width = canvas.parentElement.clientWidth;
        canvas.height = 250;
        ctx.fillStyle = '#94A3B8';
        ctx.font = '14px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('Tidak ada data', canvas.width/2, 125);
        return;
    }

    var ctx = canvas.getContext('2d');
    canvas.width = canvas.parentElement.clientWidth;
    canvas.height = 250;

    var padding = {top: 20, right: 20, bottom: 40, left: 50};
    var chartW = canvas.width - padding.left - padding.right;
    var chartH = canvas.height - padding.top - padding.bottom;
    var barGroups = data.length;
    var groupWidth = chartW / barGroups;
    var barWidth = Math.min(groupWidth * 0.7, 40);
    var warna = {'good': '#10B981', 'alarm': '#F59E0B', 'danger': '#EF4444', 'visual_bad': '#8B5CF6'};

    var maxVal = 0;
    data.forEach(function(d) {
        kondisiOrder.forEach(function(k) {
            if (d[k] > maxVal) maxVal = d[k];
        });
    });
    maxVal = Math.ceil(maxVal * 1.2) || 10;

    ctx.clearRect(0, 0, canvas.width, canvas.height);

    ctx.strokeStyle = '#E2E8F0';
    ctx.lineWidth = 1;
    for (var i = 0; i <= 4; i++) {
        var y = padding.top + (chartH - (chartH * i / 4));
        ctx.beginPath();
        ctx.moveTo(padding.left, y);
        ctx.lineTo(canvas.width - padding.right, y);
        ctx.stroke();
        ctx.fillStyle = '#94A3B8';
        ctx.font = '10px sans-serif';
        ctx.textAlign = 'right';
        ctx.fillText(Math.round(maxVal * i / 4), padding.left - 5, y + 3);
    }

    ctx.save();
    ctx.translate(14, padding.top + chartH/2);
    ctx.rotate(-Math.PI/2);
    ctx.fillStyle = '#94A3B8';
    ctx.font = '10px sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText('Jumlah Record', 0, 0);
    ctx.restore();

    data.forEach(function(d, idx) {
        var x = padding.left + idx * groupWidth + (groupWidth - barWidth) / 2;
        var yOffset = 0;
        kondisiOrder.forEach(function(k) {
            var val = d[k] || 0;
            if (val === 0) return;
            var barH = (val / maxVal) * chartH;
            var y = padding.top + chartH - yOffset - barH;
            ctx.fillStyle = warna[k];
            ctx.fillRect(x, y, barWidth, barH);
            yOffset += barH;
        });
        ctx.fillStyle = '#64748B';
        ctx.font = '9px sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText(d.period || '', x + barWidth/2, canvas.height - padding.bottom + 15);
    });

    var lx = padding.left;
    var ly = canvas.height - 6;
    kondisiOrder.forEach(function(k) {
        ctx.fillStyle = warna[k];
        ctx.fillRect(lx, ly - 8, 10, 10);
        ctx.fillStyle = '#64748B';
        ctx.font = '10px sans-serif';
        ctx.textAlign = 'left';
        ctx.fillText(k.charAt(0).toUpperCase() + k.slice(1).replace('_', ' '), lx + 14, ly + 1);
        lx += ctx.measureText(k.charAt(0).toUpperCase() + k.slice(1).replace('_', ' ') + '  ').width + 24;
    });
}
</script>
@endpush
