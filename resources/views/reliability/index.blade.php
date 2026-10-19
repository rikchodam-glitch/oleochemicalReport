@extends('layouts.app')

@section('title', 'Reliability Dashboard — Corrective Maintenance')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="text-[#6e6e73] hover:text-[#1d1d1f]">Dashboard</a>
    <span class="text-[#d2d2d7]">/</span>
    <span class="text-[#1d1d1f] font-medium">Reliability Dashboard</span>
@endsection

@section('content')
    @include('partials._apple-ui')

    @php
        $periode = $filterBulan
            ? \DateTime::createFromFormat('!m', $filterBulan)->format('F') . ' ' . $filterTahun
            : 'Sepanjang ' . $filterTahun;
    @endphp

    <div class="ap">
        <div class="ap-wrap">
            {{-- ============ HERO: KPI UTAMA + FILTER ============ --}}
            <section class="ap-hero">
                <div class="ap-hero-top">
                    <div>
                        <p class="ap-eyebrow">Reliability Dashboard &middot; {{ $periode }}</p>
                        <h2 class="ap-display" style="margin-top:10px;">
                            @if($fleetMtbf > 0)
                                Fleet MTBF {{ number_format($fleetMtbf, 1, ',', '.') }}
                                <span style="font-size:.42em;font-weight:400;color:#6e6e73;letter-spacing:-0.224px;">jam</span>
                            @else
                                Belum ada failure.
                            @endif
                        </h2>
                        <p class="ap-lede" style="margin-top:14px;">
                            {{ $totalEquipment }} equipment &middot; {{ $totalFailures }} failure &middot;
                            {{ $filterPt ?: 'Semua PT' }}
                        </p>
                    </div>

                    <form method="GET" class="ap-hero-tools" style="align-items:flex-end;">
                        <label class="ap-field">
                            <span class="ap-hero-label">PT</span>
                            <select name="pt" class="ap-select">
                                <option value="">Semua PT</option>
                                @foreach($ptList as $pt)
                                    <option value="{{ $pt }}" {{ $filterPt === $pt ? 'selected' : '' }}>{{ $pt }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="ap-field">
                            <span class="ap-hero-label">Tahun</span>
                            <select name="tahun" class="ap-select">
                                @foreach($tahunList as $th)
                                    <option value="{{ $th }}" {{ $filterTahun == $th ? 'selected' : '' }}>{{ $th }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="ap-field">
                            <span class="ap-hero-label">Bulan</span>
                            <select name="bulan" class="ap-select">
                                <option value="">Tahunan</option>
                                @foreach(range(1, 12) as $b)
                                    <option value="{{ $b }}" {{ $filterBulan == $b ? 'selected' : '' }}>{{ DateTime::createFromFormat('!m', $b)->format('F') }}</option>
                                @endforeach
                            </select>
                        </label>
                        <button type="submit" class="ap-btn ap-btn--primary">Terapkan</button>
                        <a href="{{ route('reliability.index') }}" class="ap-btn ap-btn--glass">Reset</a>
                    </form>
                </div>

                <div class="ap-stats ap-stats--4">
                    <div class="ap-stat ap-stat--static">
                        <span class="ap-stat-num ap-stat-num--sm">
                            {{ $fleetMtbf > 0 ? number_format($fleetMtbf, 1, ',', '.') : '—' }}<small> jam</small>
                        </span>
                        <span class="ap-stat-label">Fleet MTBF &mdash; {{ $totalEquipment }} equipment, {{ $totalFailures }} failure</span>
                    </div>
                    <div class="ap-stat ap-stat--static">
                        <span class="ap-stat-num ap-stat-num--sm">{{ $countCM }} : {{ $countDCM }} : {{ $countPM }}</span>
                        <span class="ap-stat-label">CM : dCM : PM &mdash; {{ $countCM + $countDCM + $countPM }} work order</span>
                    </div>
                    <div class="ap-stat ap-stat--static">
                        <span class="ap-stat-num ap-stat-num--sm">
                            {{ $dcmTotal > 0 ? number_format($deferSuccessRate, 1, ',', '.') : '—' }}<small>%</small>
                        </span>
                        <span class="ap-stat-label">Defer success rate &mdash; {{ $dcmSuccess }} dari {{ $dcmTotal }} dCM sesuai rencana</span>
                    </div>
                    <div class="ap-stat ap-stat--static">
                        <span class="ap-stat-num ap-stat-num--sm">
                            {{ $cmTotal > 0 ? number_format($undetectedRate, 1, ',', '.') : '—' }}<small>%</small>
                        </span>
                        <span class="ap-stat-label">Undetected failure &mdash; {{ $cmUndetected }} dari {{ $cmTotal }} CM tanpa peringatan dini</span>
                    </div>
                </div>
            </section>

            {{-- ============ CHARTS ============ --}}
            <section class="ap-section">
                <div class="ap-grid-2">
                    <div class="ap-card">
                        <h3 class="ap-h3">Trend MTBF bulanan</h3>
                        <p class="ap-caption" style="margin-top:4px;">Rolling window 3 bulan, dalam jam.</p>
                        <div class="ap-chart" style="margin-top:20px;">
                            <canvas id="mtbfChart"></canvas>
                        </div>
                    </div>

                    <div class="ap-card">
                        <h3 class="ap-h3">Distribusi CM / dCM / PM</h3>
                        <p class="ap-caption" style="margin-top:4px;">Jumlah work order per bulan.</p>
                        <div class="ap-chart" style="margin-top:20px;">
                            <canvas id="distributionChart"></canvas>
                        </div>
                    </div>
                </div>
            </section>

            <section class="ap-section" style="margin-top:16px;">
                <div class="ap-grid-2">
                    <div class="ap-card">
                        <h3 class="ap-h3">Failure mode Pareto</h3>
                        <p class="ap-caption" style="margin-top:4px;">Root cause dengan kejadian terbanyak.</p>
                        @if(count($paretoData) > 0)
                            <div class="ap-chart" style="margin-top:20px;">
                                <canvas id="paretoChart"></canvas>
                            </div>
                        @else
                            <div class="ap-empty" style="height:280px;display:flex;align-items:center;justify-content:center;">
                                Belum ada data root cause untuk ditampilkan.
                            </div>
                        @endif
                    </div>

                    <div class="ap-card ap-card--flush">
                        <div style="padding:24px 24px 12px;">
                            <h3 class="ap-h3">Top 10 equipment &mdash; MTBF terendah</h3>
                            <p class="ap-caption" style="margin-top:4px;">Paling sering failure pada periode ini.</p>
                        </div>
                        <div class="ap-table-wrap">
                            <table class="ap-table">
                                <thead>
                                    <tr>
                                        <th style="padding-left:24px;width:40px;">#</th>
                                        <th>Equipment</th>
                                        <th style="text-align:right;">Failures</th>
                                        <th style="text-align:right;">MTBF (jam)</th>
                                        <th style="padding-right:24px;">Last failure</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($worstMtbf as $i => $item)
                                        <tr>
                                            <td style="padding-left:24px;"><span class="ap-micro">{{ $i + 1 }}</span></td>
                                            <td>
                                                <p class="ap-tag ap-mono">{{ $item['equipment_tag'] }}</p>
                                                <p class="ap-micro" style="margin-top:2px;">{{ $item['pt_location'] }}</p>
                                            </td>
                                            <td style="text-align:right;">
                                                <span class="ap-num-sm" style="font-size:14px;color:#d70015;">{{ $item['total_failures'] }}</span>
                                            </td>
                                            <td style="text-align:right;">
                                                <span class="ap-mono" style="font-size:13px;">{{ number_format($item['mtbf'], 1, ',', '.') }}</span>
                                            </td>
                                            <td style="padding-right:24px;"><span class="ap-micro">{{ $item['last_failure'] }}</span></td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5" class="ap-empty">Belum ada data failure untuk ditampilkan.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
var mtbfTrend    = @json($mtbfTrend);
var distribution = @json($distribution);
var paretoData   = @json($paretoData);

var AP = {
    ink: '#1d1d1f',
    muted: '#6e6e73',
    grid: 'rgba(0, 0, 0, .06)',
    blue: '#0071e3',
    green: '#30d158',
    amber: '#ff9f0a',
    red: '#ff453a',
    font: "-apple-system, BlinkMacSystemFont, 'SF Pro Text', 'Segoe UI', Helvetica, Arial, sans-serif"
};

Chart.defaults.font.family = AP.font;
Chart.defaults.font.size = 11;
Chart.defaults.color = AP.muted;

document.addEventListener('DOMContentLoaded', function() {
    renderMtbfChart();
    renderDistributionChart();
    renderParetoChart();
});

function emptyNote(canvas) {
    var ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.fillStyle = '#6e6e73';
    ctx.font = '13px ' + AP.font;
    ctx.textAlign = 'center';
    ctx.fillText('Belum ada data', canvas.width / 2, 140);
}

function baseScales(yTitle, y1Title) {
    var scales = {
        x: { grid: { display: false }, ticks: { font: { size: 10 }, color: AP.muted } },
        y: {
            beginAtZero: true,
            grid: { color: AP.grid },
            border: { display: false },
            ticks: { font: { size: 10 }, color: AP.muted }
        }
    };
    if (yTitle) scales.y.title = { display: true, text: yTitle, color: AP.muted, font: { size: 10 } };
    if (y1Title) {
        scales.y1 = {
            beginAtZero: true,
            position: 'right',
            grid: { display: false },
            border: { display: false },
            ticks: { font: { size: 10 }, color: AP.muted, callback: function(v) { return v + '%'; } },
            title: { display: true, text: y1Title, color: AP.muted, font: { size: 10 } }
        };
    }
    return scales;
}

function tooltipStyle() {
    return {
        backgroundColor: '#ffffff',
        titleColor: AP.ink,
        bodyColor: AP.ink,
        borderColor: 'rgba(0, 0, 0, .12)',
        borderWidth: 1,
        titleFont: { size: 12, weight: '600' },
        bodyFont: { size: 12 },
        padding: 10,
        cornerRadius: 8,
        displayColors: false
    };
}

function renderMtbfChart() {
    var canvas = document.getElementById('mtbfChart');
    if (!canvas) return;

    if (!mtbfTrend || mtbfTrend.length === 0) {
        emptyNote(canvas);
        return;
    }

    var labels        = mtbfTrend.map(function(d) { return d.label; });
    var mtbfData      = mtbfTrend.map(function(d) { return d.mtbf; });
    var failureCounts = mtbfTrend.map(function(d) { return d.failures; });

    new Chart(canvas, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'MTBF (jam)',
                    data: mtbfData,
                    borderColor: AP.blue,
                    backgroundColor: 'rgba(0, 113, 227, .08)',
                    borderWidth: 2,
                    pointRadius: 3,
                    pointHoverRadius: 5,
                    pointBackgroundColor: AP.blue,
                    tension: 0.35,
                    fill: true,
                    yAxisID: 'y'
                },
                {
                    label: 'Jumlah failure',
                    data: failureCounts,
                    borderColor: AP.red,
                    borderWidth: 1.5,
                    borderDash: [5, 3],
                    pointRadius: 2.5,
                    pointHoverRadius: 4,
                    tension: 0.35,
                    fill: false,
                    yAxisID: 'y1'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { intersect: false, mode: 'index' },
            plugins: {
                legend: { position: 'top', align: 'end', labels: { boxWidth: 8, boxHeight: 8, usePointStyle: true, padding: 16 } },
                tooltip: Object.assign(tooltipStyle(), {
                    callbacks: {
                        afterBody: function(items) {
                            var d = mtbfTrend[items[0].dataIndex];
                            return 'Periode: ' + d.periode;
                        }
                    }
                })
            },
            scales: baseScales('MTBF (jam)', 'Failure')
        }
    });
}

function renderDistributionChart() {
    var canvas = document.getElementById('distributionChart');
    if (!canvas) return;

    if (!distribution || distribution.length === 0) {
        emptyNote(canvas);
        return;
    }

    var labels  = distribution.map(function(d) { return d.label; });
    var cmData  = distribution.map(function(d) { return d.CM; });
    var dcmData = distribution.map(function(d) { return d.dCM; });
    var pmData  = distribution.map(function(d) { return d.PM; });

    new Chart(canvas, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                { label: 'CM',  data: cmData,  backgroundColor: AP.red,   borderRadius: 4, maxBarThickness: 22 },
                { label: 'dCM', data: dcmData, backgroundColor: AP.amber, borderRadius: 4, maxBarThickness: 22 },
                { label: 'PM',  data: pmData,  backgroundColor: AP.blue,  borderRadius: 4, maxBarThickness: 22 }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { intersect: false, mode: 'index' },
            plugins: {
                legend: { position: 'top', align: 'end', labels: { boxWidth: 8, boxHeight: 8, usePointStyle: true, padding: 16 } },
                tooltip: tooltipStyle()
            },
            scales: baseScales('Work order')
        }
    });
}

function renderParetoChart() {
    var canvas = document.getElementById('paretoChart');
    if (!canvas) return;

    if (!paretoData || paretoData.length === 0) return;

    var labels = paretoData.map(function(d) { return d.cause.length > 20 ? d.cause.substring(0, 20) + '...' : d.cause; });
    var values = paretoData.map(function(d) { return d.total; });
    var pcts   = paretoData.map(function(d) { return d.pct; });

    // Kumulatif persen
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
                    label: 'Jumlah kejadian',
                    data: values,
                    backgroundColor: AP.ink,
                    borderRadius: 4,
                    maxBarThickness: 26,
                    order: 2,
                    yAxisID: 'y'
                },
                {
                    label: 'Kumulatif %',
                    data: cumulative,
                    type: 'line',
                    borderColor: AP.blue,
                    backgroundColor: 'rgba(0, 113, 227, .08)',
                    borderWidth: 2,
                    pointRadius: 3,
                    pointHoverRadius: 5,
                    pointBackgroundColor: AP.blue,
                    tension: 0.3,
                    fill: false,
                    order: 1,
                    yAxisID: 'y1'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { intersect: false, mode: 'index' },
            plugins: {
                legend: { position: 'top', align: 'end', labels: { boxWidth: 8, boxHeight: 8, usePointStyle: true, padding: 16 } },
                tooltip: Object.assign(tooltipStyle(), {
                    callbacks: {
                        label: function(context) {
                            var val = context.raw;
                            if (context.dataset.label === 'Jumlah kejadian') {
                                return context.dataset.label + ': ' + val + ' (' + pcts[context.dataIndex] + '%)';
                            }
                            return context.dataset.label + ': ' + val + '%';
                        }
                    }
                })
            },
            scales: baseScales('Kejadian', 'Kumulatif')
        }
    });
}
</script>
@endpush
