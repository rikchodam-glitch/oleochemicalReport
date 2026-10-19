@extends('layouts.app')

@section('title', 'Overview — Condition Monitoring')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="text-[#6e6e73] hover:text-[#1d1d1f]">Dashboard</a>
    <span class="text-[#d2d2d7]">/</span>
    <a href="{{ route('cm.overview') }}" class="text-[#6e6e73] hover:text-[#1d1d1f]">Condition Monitoring</a>
    <span class="text-[#d2d2d7]">/</span>
    <span class="text-[#1d1d1f] font-medium">Overview</span>
@endsection

@section('content')
    @include('partials._apple-ui')

    @php
        $periodeOverview = $filterBulan
            ? \DateTime::createFromFormat('!m', $filterBulan)->format('F') . ' ' . $filterTahun
            : 'Semua bulan ' . $filterTahun;
        $perluPerhatian = $alarmCount + $dangerCount + $visualBadCount;
    @endphp

    <div class="ap">
        <div class="ap-wrap">
            @include('cm._tabs')

            {{-- ============ HERO: RINGKASAN PERIODE ============ --}}
            <section class="ap-hero">
                <div class="ap-hero-top">
                    <div>
                        <p class="ap-eyebrow">Condition Monitoring &middot; {{ $periodeOverview }}</p>
                        <h2 class="ap-display" style="margin-top:10px;">{{ $goodPct }}% kondisi good.</h2>
                        <p class="ap-lede" style="margin-top:14px;">
                            {{ number_format($totalRecords) }} reading tercatat &mdash;
                            {{ number_format($alarmCount) }} alarm, {{ number_format($dangerCount) }} danger,
                            {{ number_format($visualBadCount) }} visual bad.
                        </p>
                    </div>
                </div>

                <div class="ap-stats">
                    <div class="ap-stat ap-stat--static">
                        <span class="ap-stat-num">{{ number_format($totalRecords) }}</span>
                        <span class="ap-stat-label">Total reading pada periode ini</span>
                    </div>
                    <div class="ap-stat ap-stat--static">
                        <span class="ap-stat-num">{{ $goodPct }}<small>%</small></span>
                        <span class="ap-stat-label">{{ number_format($goodCount) }} reading berstatus good</span>
                    </div>
                    <div class="ap-stat ap-stat--static">
                        <span class="ap-stat-num">{{ number_format($perluPerhatian) }}</span>
                        <span class="ap-stat-label">Perlu perhatian &mdash; alarm, danger, visual bad</span>
                    </div>
                </div>
            </section>

            {{-- ============ FILTER ============ --}}
            <div class="ap-section" style="margin-top:24px;">
                <div class="ap-card">
                    <form id="filterForm" class="ap-toolbar">
                        <label class="ap-field">
                            <span class="ap-field-label">PT</span>
                            <select name="pt" class="ap-select filter-select">
                                <option value="">Semua PT</option>
                                @foreach($ptList as $pt)
                                    <option value="{{ $pt }}" {{ $filterPt === $pt ? 'selected' : '' }}>{{ $pt }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="ap-field">
                            <span class="ap-field-label">Tahun</span>
                            <select name="tahun" class="ap-select filter-select">
                                @foreach($tahunList as $th)
                                    <option value="{{ $th }}" {{ $filterTahun == $th ? 'selected' : '' }}>{{ $th }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="ap-field">
                            <span class="ap-field-label">Bulan</span>
                            <select name="bulan" class="ap-select filter-select">
                                <option value="all" {{ $filterBulan === null ? 'selected' : '' }}>Semua bulan</option>
                                @foreach(range(1, 12) as $b)
                                    <option value="{{ $b }}" {{ $filterBulan == $b ? 'selected' : '' }}>{{ DateTime::createFromFormat('!m', $b)->format('F') }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="ap-field">
                            <span class="ap-field-label">Status</span>
                            <select name="status" class="ap-select filter-select">
                                <option value="">Semua status</option>
                                <option value="good" {{ $filterStatus === 'good' ? 'selected' : '' }}>Good</option>
                                <option value="alarm" {{ $filterStatus === 'alarm' ? 'selected' : '' }}>Alarm</option>
                                <option value="danger" {{ $filterStatus === 'danger' ? 'selected' : '' }}>Danger</option>
                                <option value="visual_bad" {{ $filterStatus === 'visual_bad' ? 'selected' : '' }}>Visual Bad</option>
                            </select>
                        </label>

                        <a href="{{ route('cm.overview') }}" class="ap-btn ap-btn--quiet">Reset</a>

                        <button type="button" id="syncSheetsBtn" onclick="syncGoogleSheets()"
                                class="ap-btn ap-btn--primary" style="margin-left:auto;">
                            Sync Google Sheets
                        </button>
                    </form>
                </div>
            </div>

            {{-- ============ KARTU RINGKASAN ============ --}}
            <div id="summaryCards" class="ap-grid-5" style="margin-top:16px;">
                @include('cm._overview-cards', [
                    'totalRecords' => $totalRecords,
                    'goodCount' => $goodCount, 'goodPct' => $goodPct,
                    'alarmCount' => $alarmCount, 'alarmPct' => $alarmPct,
                    'dangerCount' => $dangerCount, 'dangerPct' => $dangerPct,
                    'visualBadCount' => $visualBadCount, 'visualBadPct' => $visualBadPct,
                ])
            </div>

            {{-- Loading indicator --}}
            <div id="loadingIndicator" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/10">
                <div class="ap-card" style="display:flex;align-items:center;gap:12px;">
                    <svg class="animate-spin" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#0071e3" stroke-width="2" aria-hidden="true">
                        <circle cx="12" cy="12" r="9" opacity=".25"/>
                        <path d="M21 12a9 9 0 00-9-9" stroke-linecap="round"/>
                    </svg>
                    <span class="ap-caption" style="color:#1d1d1f;">Memuat data...</span>
                </div>
            </div>

            {{-- ============ CHART ============ --}}
            <section class="ap-section">
                <div class="ap-section-head">
                    <div>
                        <h3 class="ap-h2">Status &amp; tren</h3>
                        <p class="ap-caption" style="margin-top:6px;">Breakdown status per PT dan pergerakan bulanan.</p>
                    </div>
                    <p class="ap-micro">Periode: {{ $periodeOverview }}</p>
                </div>

                <div class="ap-grid-2">
                    <div class="ap-card">
                        <h3 class="ap-h3">Breakdown status per PT</h3>
                        <div style="margin-top:16px;">
                            <canvas id="donutChart" height="250"></canvas>
                        </div>
                    </div>
                    <div class="ap-card">
                        <h3 class="ap-h3">Trend status bulanan</h3>
                        <div style="margin-top:16px;">
                            <canvas id="trendChart" height="250"></canvas>
                        </div>
                    </div>
                </div>
            </section>

            {{-- ============ TOP 10 VIBRASI ============ --}}
            <section class="ap-section">
                <div class="ap-card ap-card--flush">
                    <div style="display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:8px 24px;padding:24px 24px 12px;">
                        <div>
                            <h3 class="ap-h3">Top 10 vibrasi tertinggi</h3>
                            <p class="ap-caption" style="margin-top:4px;">Reading berstatus danger pada periode ini.</p>
                        </div>
                        <p class="ap-micro">Klik tag untuk detail equipment</p>
                    </div>

                    <div class="ap-table-wrap">
                        <table class="ap-table">
                            <thead>
                                <tr>
                                    <th style="padding-left:24px;min-width:150px;">Equipment</th>
                                    <th>PT</th>
                                    <th style="text-align:right;">Motor</th>
                                    <th style="text-align:right;">Mesin</th>
                                    <th style="text-align:right;">Temp motor</th>
                                    <th style="text-align:right;">Temp mesin</th>
                                    <th>Tanggal</th>
                                    <th style="padding-right:24px;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($topVibrasi as $r)
                                    <tr>
                                        <td style="padding-left:24px;">
                                            <a href="{{ route('cm.equipment-show', $r->equipment_tag) }}" class="ap-tag ap-mono">
                                                {{ $r->equipment_tag }}
                                            </a>
                                        </td>
                                        <td><span class="ap-micro">{{ $r->pt_location }}</span></td>
                                        <td style="text-align:right;"><span class="ap-mono" style="font-size:13px;font-weight:600;color:#d70015;">{{ $r->max_motor }}</span></td>
                                        <td style="text-align:right;"><span class="ap-mono" style="font-size:13px;font-weight:600;color:#d70015;">{{ $r->max_mesin }}</span></td>
                                        <td style="text-align:right;"><span class="ap-mono" style="font-size:13px;">{{ $r->max_temp_motor }}&deg;C</span></td>
                                        <td style="text-align:right;"><span class="ap-mono" style="font-size:13px;">{{ $r->max_temp_mesin }}&deg;C</span></td>
                                        <td><span class="ap-micro">{{ $r->tanggal->format('d M Y') }}</span></td>
                                        <td style="padding-right:24px;">
                                            <span class="ap-badge"><span class="ap-dot ap-dot--bad"></span>Danger</span>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="ap-empty">Tidak ada data vibrasi danger.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </div>
    </div>
@endsection

@push('scripts')
<script>
var kondisiLabels = {'good': 'Good', 'alarm': 'Alarm', 'danger': 'Danger', 'visual_bad': 'Visual Bad'};
var kondisiColors = {'good': '#30d158', 'alarm': '#ff9f0a', 'danger': '#ff453a', 'visual_bad': '#bf5af2'};
var kondisiOrder = ['good', 'alarm', 'danger', 'visual_bad'];

document.addEventListener('DOMContentLoaded', function() {
    loadChartData();

    document.querySelectorAll('.filter-select').forEach(function(sel) {
        sel.addEventListener('change', function() {
            // Muat ulang halaman agar seluruh bagian (termasuk Top 10 Vibrasi)
            // ikut mengikuti filter yang dipilih. Chart tetap digambar oleh JS
            // saat halaman selesai dimuat ulang.
            var form = document.getElementById('filterForm');
            if (!form) return;

            var params = new URLSearchParams();
            new FormData(form).forEach(function(value, key) {
                if (value) params.append(key, value);
            });

            var qs = params.toString();
            window.location.href = window.location.pathname + (qs ? '?' + qs : '');
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
