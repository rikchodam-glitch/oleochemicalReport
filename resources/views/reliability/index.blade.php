@extends('layouts.app')

@section('title', 'Reliability Dashboard — Corrective Maintenance')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Reliability Dashboard</span>
@endsection

@section('content')
    {{-- Filter --}}
    <div class="bg-white rounded-xl border border-slate-200 p-4 mb-6">
        <form method="GET" class="flex flex-wrap gap-4 items-end">
            <div class="space-y-1">
                <label class="text-xs font-medium text-slate-500">PT</label>
                <select name="pt" class="border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-teal-500">
                    <option value="">Semua PT</option>
                    @foreach($ptList as $pt)
                        <option value="{{ $pt }}" {{ $filterPt === $pt ? 'selected' : '' }}>{{ $pt }}</option>
                    @endforeach
                </select>
            </div>
            <div class="space-y-1">
                <label class="text-xs font-medium text-slate-500">Tahun</label>
                <select name="tahun" class="border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-teal-500">
                    @foreach($tahunList as $th)
                        <option value="{{ $th }}" {{ $filterTahun == $th ? 'selected' : '' }}>{{ $th }}</option>
                    @endforeach
                </select>
            </div>
            <div class="space-y-1">
                <label class="text-xs font-medium text-slate-500">Bulan</label>
                <select name="bulan" class="border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-teal-500">
                    <option value="">Tahunan (semua bulan)</option>
                    @foreach(range(1, 12) as $b)
                        <option value="{{ $b }}" {{ $filterBulan == $b ? 'selected' : '' }}>{{ DateTime::createFromFormat('!m', $b)->format('F') }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white text-sm font-medium rounded-lg transition-colors">
                Terapkan
            </button>
            <a href="{{ route('reliability.index') }}" class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                Reset
            </a>
        </form>
    </div>

    {{-- Cards Ringkasan Atas --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        {{-- Fleet MTBF --}}
        <div class="bg-white rounded-xl border border-slate-200 p-5">
            <p class="text-xs text-slate-400 uppercase tracking-wide font-medium">Fleet MTBF</p>
            <p class="text-3xl font-bold mt-1" style="color: #0E9E8E;">
                {{ $fleetMtbf > 0 ? number_format($fleetMtbf, 1) : '-' }}
                <span class="text-sm font-normal text-slate-400">jam</span>
            </p>
            <p class="text-xs text-slate-500 mt-1">{{ $totalEquipment }} equipment, {{ $totalFailures }} failure(s)</p>
        </div>

        {{-- CM : dCM : PM Ratio --}}
        <div class="bg-white rounded-xl border border-slate-200 p-5">
            <p class="text-xs text-slate-400 uppercase tracking-wide font-medium">CM : dCM : PM</p>
            <div class="flex items-end gap-3 mt-1">
                <div>
                    <p class="text-2xl font-bold text-red-600">{{ $countCM }}</p>
                    <p class="text-xs text-slate-500">CM</p>
                </div>
                <div>
                    <p class="text-2xl font-bold text-amber-600">{{ $countDCM }}</p>
                    <p class="text-xs text-slate-500">dCM</p>
                </div>
                <div>
                    <p class="text-2xl font-bold text-blue-600">{{ $countPM }}</p>
                    <p class="text-xs text-slate-500">PM</p>
                </div>
            </div>
            <p class="text-xs text-slate-400 mt-1">Total: {{ $countCM + $countDCM + $countPM }} work orders</p>
        </div>

        {{-- Defer Success Rate --}}
        <div class="bg-white rounded-xl border border-slate-200 p-5">
            <p class="text-xs text-slate-400 uppercase tracking-wide font-medium">Defer Success Rate</p>
            <p class="text-3xl font-bold mt-1 {{ $deferSuccessRate >= 80 ? 'text-green-600' : ($deferSuccessRate >= 50 ? 'text-amber-600' : 'text-red-600') }}">
                {{ $dcmTotal > 0 ? number_format($deferSuccessRate, 1) : '-' }}<span class="text-sm font-normal text-slate-400">%</span>
            </p>
            <p class="text-xs text-slate-500 mt-1">{{ $dcmSuccess }} dari {{ $dcmTotal }} dCM sesuai rencana</p>
        </div>

        {{-- Undetected Failure Rate --}}
        <div class="bg-white rounded-xl border border-slate-200 p-5">
            <p class="text-xs text-slate-400 uppercase tracking-wide font-medium">Undetected Failure Rate</p>
            <p class="text-3xl font-bold mt-1 {{ $undetectedRate > 50 ? 'text-red-600' : ($undetectedRate > 20 ? 'text-amber-600' : 'text-green-600') }}">
                {{ $cmTotal > 0 ? number_format($undetectedRate, 1) : '-' }}<span class="text-sm font-normal text-slate-400">%</span>
            </p>
            <p class="text-xs text-slate-500 mt-1">{{ $cmUndetected }} dari {{ $cmTotal }} CM tanpa peringatan dini</p>
        </div>
    </div>

    {{-- Chart Row 1: Trend MTBF + CM/dCM/PM Distribution --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        {{-- Trend MTBF Bulanan --}}
        <div class="bg-white rounded-xl border border-slate-200 p-5">
            <h3 class="text-sm font-semibold text-slate-900 mb-4">Trend MTBF Bulanan (Rolling 3 Bulan)</h3>
            <div class="relative" style="height: 280px;">
                <canvas id="mtbfChart"></canvas>
            </div>
            <p class="text-xs text-slate-400 mt-2">Nilai MTBF fleet dalam jam, dihitung per rolling window 3 bulan.</p>
        </div>

        {{-- CM/dCM/PM Distribution --}}
        <div class="bg-white rounded-xl border border-slate-200 p-5">
            <h3 class="text-sm font-semibold text-slate-900 mb-4">Distribusi CM / dCM / PM per Bulan</h3>
            <div class="relative" style="height: 280px;">
                <canvas id="distributionChart"></canvas>
            </div>
        </div>
    </div>

    {{-- Chart Row 2: Pareto --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        {{-- Failure Mode Pareto --}}
        <div class="bg-white rounded-xl border border-slate-200 p-5">
            <h3 class="text-sm font-semibold text-slate-900 mb-4">Failure Mode Pareto (Root Cause)</h3>
            @if(count($paretoData) > 0)
                <div class="relative" style="height: 280px;">
                    <canvas id="paretoChart"></canvas>
                </div>
            @else
                <div class="flex items-center justify-center h-[280px] text-sm text-slate-400">
                    Belum ada data root cause untuk ditampilkan.
                </div>
            @endif
        </div>

        {{-- Top 10 Worst MTBF Equipment --}}
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-slate-900">Top 10 Equipment — MTBF Terendah</h3>
                <span class="text-xs text-slate-400">Paling sering failure</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-100">
                            <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">#</th>
                            <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">Equipment Tag</th>
                            <th class="text-right text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">Failures</th>
                            <th class="text-right text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">MTBF (jam)</th>
                            <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">Last Failure</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @forelse($worstMtbf as $i => $item)
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="px-4 py-3 text-xs text-slate-400">{{ $i + 1 }}</td>
                                <td class="px-4 py-3">
                                    <span class="font-mono text-sm font-medium text-slate-900">{{ $item['equipment_tag'] }}</span>
                                    <p class="text-xs text-slate-400">{{ $item['pt_location'] }}</p>
                                </td>
                                <td class="px-4 py-3 text-right text-sm font-semibold text-red-600">{{ $item['total_failures'] }}</td>
                                <td class="px-4 py-3 text-right text-sm font-semibold text-slate-800">{{ number_format($item['mtbf'], 1) }}</td>
                                <td class="px-4 py-3 text-xs text-slate-500 whitespace-nowrap">{{ $item['last_failure'] }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-5 py-8 text-center text-slate-400 text-sm">
                                    Belum ada data failure untuk ditampilkan.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script>
    var mtbfTrend = @json($mtbfTrend);
    var distribution = @json($distribution);
    var paretoData = @json($paretoData);

    document.addEventListener('DOMContentLoaded', function() {
        renderMtbfChart();
        renderDistributionChart();
        renderParetoChart();
    });

    function renderMtbfChart() {
        var canvas = document.getElementById('mtbfChart');
        if (!canvas) return;

        if (!mtbfTrend || mtbfTrend.length === 0) {
            var ctx = canvas.getContext('2d');
            ctx.fillStyle = '#94A3B8';
            ctx.font = '14px sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText('Belum ada data', canvas.width/2, 140);
            return;
        }

        var labels = mtbfTrend.map(function(d) { return d.label; });
        var mtbfData = mtbfTrend.map(function(d) { return d.mtbf; });
        var failureCounts = mtbfTrend.map(function(d) { return d.failures; });

        new Chart(canvas, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'MTBF (jam)',
                        data: mtbfData,
                        borderColor: '#0E9E8E',
                        backgroundColor: 'rgba(14,158,142,0.1)',
                        borderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        tension: 0.3,
                        fill: true,
                        yAxisID: 'y',
                    },
                    {
                        label: 'Jumlah Failure',
                        data: failureCounts,
                        borderColor: '#EF4444',
                        backgroundColor: 'rgba(239,68,68,0.1)',
                        borderWidth: 1.5,
                        borderDash: [5, 3],
                        pointRadius: 3,
                        pointHoverRadius: 5,
                        tension: 0.3,
                        fill: false,
                        yAxisID: 'y1',
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { intersect: false, mode: 'index' },
                plugins: {
                    legend: {
                        position: 'top',
                        labels: { font: { size: 11 }, color: '#475569', boxWidth: 15, padding: 15 }
                    },
                    tooltip: {
                        backgroundColor: '#1E293B',
                        titleFont: { size: 12 },
                        bodyFont: { size: 12 },
                        padding: 10,
                        cornerRadius: 8,
                        callbacks: {
                            afterBody: function(items) {
                                var idx = items[0].dataIndex;
                                var d = mtbfTrend[idx];
                                return 'Periode: ' + d.periode;
                            }
                        }
                    }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { font: { size: 10 }, color: '#94A3B8' } },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(148,163,184,0.15)' },
                        ticks: { font: { size: 10 }, color: '#94A3B8' },
                        title: { display: true, text: 'MTBF (jam)', color: '#94A3B8', font: { size: 11 } }
                    },
                    y1: {
                        beginAtZero: true,
                        position: 'right',
                        grid: { display: false },
                        ticks: { font: { size: 10 }, color: '#94A3B8' },
                        title: { display: true, text: 'Jumlah Failure', color: '#94A3B8', font: { size: 11 } }
                    }
                }
            }
        });
    }

    function renderDistributionChart() {
        var canvas = document.getElementById('distributionChart');
        if (!canvas) return;

        if (!distribution || distribution.length === 0) {
            var ctx = canvas.getContext('2d');
            ctx.fillStyle = '#94A3B8';
            ctx.font = '14px sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText('Belum ada data', canvas.width/2, 140);
            return;
        }

        var labels = distribution.map(function(d) { return d.label; });
        var cmData = distribution.map(function(d) { return d.CM; });
        var dcmData = distribution.map(function(d) { return d.dCM; });
        var pmData = distribution.map(function(d) { return d.PM; });

        new Chart(canvas, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    { label: 'CM', data: cmData, backgroundColor: '#EF4444', borderRadius: 2 },
                    { label: 'dCM', data: dcmData, backgroundColor: '#F59E0B', borderRadius: 2 },
                    { label: 'PM', data: pmData, backgroundColor: '#3B82F6', borderRadius: 2 },
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { intersect: false, mode: 'index' },
                plugins: {
                    legend: {
                        position: 'top',
                        labels: { font: { size: 11 }, color: '#475569', boxWidth: 15, padding: 15 }
                    },
                    tooltip: {
                        backgroundColor: '#1E293B',
                        titleFont: { size: 12 },
                        bodyFont: { size: 12 },
                        padding: 10,
                        cornerRadius: 8
                    }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { font: { size: 10 }, color: '#94A3B8' } },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(148,163,184,0.15)' },
                        ticks: { font: { size: 10 }, color: '#94A3B8', stepSize: 1 },
                        title: { display: true, text: 'Jumlah Work Order', color: '#94A3B8', font: { size: 11 } }
                    }
                }
            }
        });
    }

    function renderParetoChart() {
        var canvas = document.getElementById('paretoChart');
        if (!canvas) return;

        if (!paretoData || paretoData.length === 0) return;

        var labels = paretoData.map(function(d) { return d.cause.length > 20 ? d.cause.substring(0, 20) + '...' : d.cause; });
        var values = paretoData.map(function(d) { return d.total; });
        var pcts = paretoData.map(function(d) { return d.pct; });

        // Cumulative percentage
        var cumulative = [];
        var running = 0;
        pcts.forEach(function(p) {
            running += p;
            cumulative.push(parseFloat(running.toFixed(1)));
        });

        new Chart(canvas, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Jumlah Kejadian',
                        data: values,
                        backgroundColor: '#0E9E8E',
                        borderRadius: 2,
                        order: 2,
                        yAxisID: 'y',
                    },
                    {
                        label: 'Kumulatif %',
                        data: cumulative,
                        type: 'line',
                        borderColor: '#EF4444',
                        backgroundColor: 'rgba(239,68,68,0.1)',
                        borderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        tension: 0.3,
                        fill: false,
                        order: 1,
                        yAxisID: 'y1',
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { intersect: false, mode: 'index' },
                plugins: {
                    legend: {
                        position: 'top',
                        labels: { font: { size: 11 }, color: '#475569', boxWidth: 15, padding: 15 }
                    },
                    tooltip: {
                        backgroundColor: '#1E293B',
                        titleFont: { size: 12 },
                        bodyFont: { size: 12 },
                        padding: 10,
                        cornerRadius: 8,
                        callbacks: {
                            label: function(context) {
                                var label = context.dataset.label || '';
                                var val = context.raw;
                                if (context.dataset.label === 'Jumlah Kejadian') {
                                    var idx = context.dataIndex;
                                    return label + ': ' + val + ' (' + pcts[idx] + '%)';
                                }
                                return label + ': ' + val + '%';
                            }
                        }
                    }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { font: { size: 9 }, color: '#94A3B8' } },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(148,163,184,0.15)' },
                        ticks: { font: { size: 10 }, color: '#94A3B8', stepSize: 1 },
                        title: { display: true, text: 'Jumlah Kejadian', color: '#94A3B8', font: { size: 11 } }
                    },
                    y1: {
                        beginAtZero: true,
                        max: 100,
                        position: 'right',
                        grid: { display: false },
                        ticks: { font: { size: 10 }, color: '#94A3B8', callback: function(v) { return v + '%'; } },
                        title: { display: true, text: 'Kumulatif %', color: '#94A3B8', font: { size: 11 } }
                    }
                }
            }
        });
    }
    </script>
    @endpush
@endsection
