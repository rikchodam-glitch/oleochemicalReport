@extends('layouts.app')

@section('title', 'Report & Analysis - Condition Monitoring')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-[#1d1d1f]">Dashboard</a>
    <span class="text-[#d2d2d7]">/</span>
    <a href="{{ route('cm.overview') }}" class="hover:text-[#1d1d1f]">Condition Monitoring</a>
    <span class="text-[#d2d2d7]">/</span>
    <span class="text-[#1d1d1f] font-medium">Report & Analysis</span>
@endsection

@section('content')
    @include('partials._apple-ui')

    <div class="ap">
        <div class="ap-wrap">
            @include('cm._tabs')

            <section class="ap-hero" style="padding:36px 40px;margin-bottom:24px;">
                <p class="ap-eyebrow">Condition Monitoring</p>
                <h2 class="ap-display" style="font-size:clamp(26px,3.2vw,40px);margin-top:8px;">Laporan &amp; analisis kondisi.</h2>
                <p class="ap-lede" style="margin-top:10px;">
                    Rekap reading, finding, dan kepatuhan pengambilan data per periode.
                </p>
            </section>

    {{-- Filters --}}
    <div class="ap-card p-4 mb-6">
        <form method="GET" class="ap-toolbar">
            <div class="ap-field">
                <label class="ap-field-label" for="analysisTahun">Tahun</label>
                <select id="analysisTahun" name="tahun" class="ap-select">
                    @foreach($tahunList as $th)
                        <option value="{{ $th }}" {{ $filterTahun == $th ? 'selected' : '' }}>{{ $th }}</option>
                    @endforeach
                </select>
            </div>
            <div class="ap-field">
                <label class="ap-field-label" for="analysisBulan">Bulan</label>
                <select id="analysisBulan" name="bulan" class="ap-select">
                    <option value="">Semua Bulan</option>
                    @foreach($bulanList as $bl => $blName)
                        <option value="{{ $bl }}" {{ $filterBulan == $bl ? 'selected' : '' }}>{{ $blName }}</option>
                    @endforeach
                </select>
            </div>
            <div class="ap-field">
                <label class="ap-field-label" for="analysisPt">PT</label>
                <select id="analysisPt" name="pt" class="ap-select">
                    <option value="">Semua PT</option>
                    @foreach($ptList as $pt)
                        <option value="{{ $pt }}" {{ $filterPt === $pt ? 'selected' : '' }}>{{ $pt }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="ap-btn ap-btn--primary">
                Terapkan
            </button>
            <a href="{{ route('cm.report-analysis') }}" class="ap-btn ap-btn--glass">
                Reset
            </a>
        </form>
    </div>
    {{-- Summary Cards --}}
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
        <div class="ap-tile p-4">
            <p class="ap-micro">Total Equipment</p>
            <p class="ap-num" style="margin-top:8px;">{{ number_format($totalEquipment) }}</p>
        </div>
        <div class="ap-tile p-4">
            <p class="ap-micro">Total Readings</p>
            <p class="ap-num" style="margin-top:8px;">{{ number_format($totalReadings) }}</p>
        </div>
        <div class="ap-tile p-4">
            <p class="ap-micro">Total Findings</p>
            <p class="ap-num" style="margin-top:8px;">{{ number_format($totalFindings) }}</p>
        </div>
        <div class="ap-tile p-4">
            <p class="ap-micro"><span class="ap-dot ap-dot--bad"></span> Finding Open</p>
            <p class="ap-num" style="margin-top:8px;color:#d70015;">{{ number_format($openFindings) }}</p>
        </div>
        <div class="ap-tile p-4">
            <p class="ap-micro"><span class="ap-dot ap-dot--ok"></span> Monitoring {{ $filterTahun }}</p>
            <p class="ap-num" style="margin-top:8px;color:#248a3d;">{{ $monitoringPct }}<span class="ap-faint" style="font-size:14px;">%</span></p>
            <p class="ap-micro" style="margin-top:2px;">{{ $monitoringSudah }}/{{ $monitoringTotal }} bulan</p>
        </div>
    </div>

    {{-- INSIGHT ANALISA OTOMATIS (Section 3) --}}
    @if($insight)
    <div class="ap-card" style="margin-bottom:24px;">
        <div class="flex items-start gap-4">
            <span class="ap-dot ap-dot--warn" style="margin-top:6px;"></span>
            <div class="flex-1 min-w-0">
                <h4 class="text-sm font-semibold text-[#1d1d1f]">Insight analisa vibrasi &mdash; {{ $filterTahun }}</h4>
                <p class="text-sm text-[#6e6e73] mt-1">
                    <strong>{{ $insight['dominant_category'] }}</strong> adalah kategori masalah paling dominan tahun ini
                    (Total: {{ $insight['dominant_total'] }} kasus, {{ $insight['trend_direction'] }}).
                    @if($insight['top_pt'])
                        {{ $insight['top_pt'] }} memiliki proporsi {{ $insight['dominant_category'] }} tertinggi
                        ({{ $insight['top_pt_pct'] }}% dari equipment Alarm/Danger di PT ini).
                    @endif
                </p>
            </div>
        </div>
    </div>
    @endif
    {{-- SECTION 1: EQUIPMENT VIBRASI TINGGI PER PT --}}
    <div class="ap-card p-6 mb-6">
        <h3 class="font-semibold text-[#1d1d1f] mb-1">Equipment Vibrasi Tinggi per PT</h3>
        <p class="text-xs text-[#6e6e73] mb-5">
            Status ALARM/DANGER dengan vibrasi &gt; 4.5 mm/s - dikelompokkan berdasarkan kategori analisa
        </p>

        <div class="grid grid-cols-1 {{ $filterPt ? '' : 'lg:grid-cols-3' }} gap-6">
            @forelse($ptBreakdown as $pt => $ptData)
                <div class="border border-black/5 rounded-xl overflow-hidden">
                    {{-- Header PT --}}
                    <div class="bg-[#fafafc] px-4 py-3 border-b border-black/5">
                        <div class="flex items-center justify-between">
                            <h4 class="font-semibold text-[#1d1d1f] text-sm">{{ $pt }}</h4>
                            <span class="text-xs text-[#6e6e73] bg-white px-2 py-0.5 rounded-full border border-black/5">
                                {{ $ptData['total'] }} equipment
                            </span>
                        </div>
                    </div>

                    {{-- Daftar Kategori --}}
                    <div class="divide-y divide-black/5">
                        @forelse($ptData['categories'] as $cat)
                            <div class="px-4 py-3">
                                {{-- Header kategori --}}
                                <div class="flex items-center justify-between mb-1.5">
                                    <div class="flex items-center gap-2">
                                        <span class="w-5 h-5 rounded-full bg-[#e8e8ed] text-[#0066cc] text-xs font-bold flex items-center justify-center">
                                            {{ $cat['rank'] }}
                                        </span>
                                        <span class="text-sm font-medium text-[#1d1d1f]">{{ $cat['name'] }}</span>
                                    </div>
                                    <span class="text-xs text-[#6e6e73]">{{ $cat['count'] }} equipment</span>
                                </div>

                                {{-- Progress bar persentase --}}
                                <div class="flex items-center gap-2 mb-2">
                                    <div class="flex-1 bg-[#e8e8ed] rounded-full h-1.5">
                                        <div class="bg-[#f5f5f7]0 h-1.5 rounded-full" style="width: {{ $cat['percentage'] }}%"></div>
                                    </div>
                                    <span class="text-xs font-medium text-[#0066cc] w-12 text-right">{{ $cat['percentage'] }}%</span>
                                </div>

                                {{-- Badge equipment --}}
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach($cat['equipments'] as $eq)
                                        <a href="{{ route('cm.equipment-show', $eq['tag']) }}"
                                           class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs font-medium hover:opacity-80 transition-opacity
                                            {{ $eq['status'] === 'danger' ? 'bg-red-50 text-red-700 border border-red-200' : 'bg-amber-50 text-amber-700 border border-amber-200' }}">
                                            <span class="font-mono">{{ $eq['tag'] }}</span>
                                            <span class="w-1.5 h-1.5 rounded-full inline-block
                                                {{ $eq['status'] === 'danger' ? 'bg-red-500' : 'bg-amber-500' }}"></span>
                                            <span class="uppercase text-[10px]">{{ $eq['status'] === 'danger' ? 'DGR' : 'ALR' }}</span>
                                            @if($eq['bulan'])
                                                <span class="text-[#6e6e73]">-</span>
                                                <span class="text-[#6e6e73]">{{ $eq['bulan'] }}</span>
                                            @endif
                                        </a>
                                    @endforeach
                                </div>
                            </div>
                        @empty
                            <div class="px-4 py-6 text-center text-sm text-[#6e6e73]">
                                Tidak ada data vibrasi tinggi di PT ini.
                            </div>
                        @endforelse
                    </div>
                </div>
            @empty
                <div class="col-span-full text-center py-8 text-sm text-[#6e6e73]">
                    Tidak ada data equipment dengan vibrasi tinggi untuk filter yang dipilih.
                </div>
            @endforelse
        </div>
    </div>
    {{-- SECTION 2: RANKING ANALISA BULANAN (TABEL TREND) --}}
    <div class="ap-card p-6 mb-6">
        <h3 class="font-semibold text-[#1d1d1f] mb-1">Ranking Analisa Bulanan</h3>
        <p class="text-xs text-[#6e6e73] mb-5">
            Trend jumlah equipment vibrasi tinggi per kategori analisa - semua PT (filter: Alarm/Danger, vibrasi &gt; 4.5 mm/s)
        </p>

        <div class="overflow-x-auto">
            <table class="ap-table w-full text-sm">
                <thead>
                    <tr class="border-b border-black/5">
                        <th class="text-left px-3 py-2 text-xs font-semibold text-[#6e6e73] uppercase tracking-wider">Rank</th>
                        <th class="text-left px-3 py-2 text-xs font-semibold text-[#6e6e73] uppercase tracking-wider">Kategori Analisa</th>
                        @php $labels = ['','Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des']; @endphp
                        @foreach($allMonths as $m)
                            <th class="text-center px-2 py-2 text-xs font-semibold text-[#6e6e73] uppercase tracking-wider">{{ $labels[$m] ?? $m }}</th>
                        @endforeach
                        <th class="text-center px-3 py-2 text-xs font-semibold text-[#6e6e73] uppercase tracking-wider bg-[#fafafc]">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-black/5">
                    @php
                        $totalsPerBulan = array_fill_keys($allMonths, 0);
                        $grandTotal = 0;
                        foreach ($rankedTable as $row) {
                            foreach ($allMonths as $m) {
                                $totalsPerBulan[$m] += $row[$m] ?? 0;
                            }
                            $grandTotal += $row['total'];
                        }
                    @endphp
                    @forelse($rankedTable as $row)
                        @php
                            $isTop3 = $row['rank'] <= 3;
                            $rowBg = $isTop3 ? 'bg-[#f5f5f7]/40' : '';
                        @endphp
                        <tr class="{{ $rowBg }} hover:bg-[#fafafc] transition-colors">
                            <td class="px-3 py-2.5 text-sm font-bold {{ $isTop3 ? 'text-[#0066cc]' : 'text-[#6e6e73]' }}">
                                #{{ $row['rank'] }}
                            </td>
                            <td class="px-3 py-2.5 text-sm font-medium text-[#1d1d1f]">
                                {{ $row['analysis'] }}
                            </td>
                            @foreach($allMonths as $m)
                                <td class="text-center px-2 py-2.5 text-sm text-[#3a3a3c]">
                                    {{ $row[$m] ?? 0 }}
                                </td>
                            @endforeach
                            <td class="text-center px-3 py-2.5 text-sm font-bold text-[#1d1d1f] bg-[#fafafc]/50">
                                {{ $row['total'] }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($allMonths) + 3 }}" class="text-center py-8 text-sm text-[#6e6e73]">
                                Tidak ada data analisa vibrasi untuk filter yang dipilih.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-black/10 bg-[#fafafc]">
                        <td colspan="2" class="px-3 py-2.5 text-sm font-bold text-[#1d1d1f]">Total</td>
                        @foreach($allMonths as $m)
                            <td class="text-center px-2 py-2.5 text-sm font-bold text-[#1d1d1f]">{{ $totalsPerBulan[$m] }}</td>
                        @endforeach
                        <td class="text-center px-3 py-2.5 text-sm font-bold text-[#1d1d1f] bg-[#e8e8ed]">{{ $grandTotal }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    {{-- Laporan Ringkasan --}}
    <div class="ap-card p-6 mb-6">
        <h3 class="font-medium text-[#1d1d1f] mb-4">Ringkasan Periodik</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <h4 class="text-sm font-medium text-[#1d1d1f] mb-2">Distribusi Monitoring ({{ $filterTahun }})</h4>
                <div class="space-y-2">
                    <div>
                        <div class="flex justify-between text-xs text-[#6e6e73] mb-1">
                            <span>Sudah Diambil</span>
                            <span>{{ $monitoringSudah }} bulan ({{ $monitoringPct }}%)</span>
                        </div>
                        <div class="w-full bg-[#e8e8ed] rounded-full h-2">
                            <div class="bg-green-500 h-2 rounded-full" style="width: {{ $monitoringPct }}%"></div>
                        </div>
                    </div>
                    <div>
                        <div class="flex justify-between text-xs text-[#6e6e73] mb-1">
                            <span>Belum Diambil</span>
                            <span>{{ $monitoringTotal - $monitoringSudah }} bulan ({{ 100 - $monitoringPct }}%)</span>
                        </div>
                        <div class="w-full bg-[#e8e8ed] rounded-full h-2">
                            <div class="bg-amber-500 h-2 rounded-full" style="width: {{ 100 - $monitoringPct }}%"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div>
                <h4 class="text-sm font-medium text-[#1d1d1f] mb-2">Status Equipment Overview</h4>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span>Total Equipment Terpantau</span>
                        <span class="font-semibold">{{ number_format($totalEquipment) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Total Reading Tersedia</span>
                        <span class="font-semibold">{{ number_format($totalReadings) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Finding Masih Open</span>
                        <span class="font-semibold text-[#d70015]">{{ number_format($openFindings) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Finding Sudah Closed</span>
                        <span class="font-semibold text-[#248a3d]">{{ number_format($totalFindings - $openFindings) }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Export Laporan --}}
    <div class="ap-card p-6">
        <h3 class="font-medium text-[#1d1d1f] mb-4">Export Laporan Rekap</h3>
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <a href="{{ route('cm.export-readings', ['pt' => $filterPt, 'tahun' => $filterTahun]) }}"
               class="flex items-center gap-3 p-4 border border-black/5 rounded-xl hover:border-[#0071e3] hover:bg-[#f5f5f7] transition-colors group">
                <div class="w-10 h-10 rounded-lg bg-green-100 flex items-center justify-center group-hover:bg-green-200 transition-colors">
                    <svg class="w-5 h-5 text-[#248a3d]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                </div>
                <div>
                    <p class="text-sm font-medium text-[#1d1d1f] group-hover:text-[#0066cc] transition-colors">Export Readings</p>
                    <p class="text-xs text-[#6e6e73]">Data vibrasi & temperatur</p>
                </div>
            </a>
            <a href="{{ route('cm.export-findings', ['pt' => $filterPt]) }}"
               class="flex items-center gap-3 p-4 border border-black/5 rounded-xl hover:border-[#0071e3] hover:bg-[#f5f5f7] transition-colors group">
                <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center group-hover:bg-amber-200 transition-colors">
                    <svg class="w-5 h-5 text-[#bf4800]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                </div>
                <div>
                    <p class="text-sm font-medium text-[#1d1d1f] group-hover:text-[#0066cc] transition-colors">Export Findings</p>
                    <p class="text-xs text-[#6e6e73]">Data temuan & severity</p>
                </div>
            </a>
            <a href="{{ route('cm.export-monitoring', ['tahun' => $filterTahun, 'pt' => $filterPt]) }}"
               class="flex items-center gap-3 p-4 border border-black/5 rounded-xl hover:border-[#0071e3] hover:bg-[#f5f5f7] transition-colors group">
                <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center group-hover:bg-blue-200 transition-colors">
                    <svg class="w-5 h-5 text-[#0071e3]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                </div>
                <div>
                    <p class="text-sm font-medium text-[#1d1d1f] group-hover:text-[#0066cc] transition-colors">Export Monitoring</p>
                    <p class="text-xs text-[#6e6e73]">Data tracking bulanan</p>
                </div>
            </a>
            <a href="{{ route('cm.export-analysis', ['tahun' => $filterTahun, 'bulan' => $filterBulan, 'pt' => $filterPt]) }}"
               class="flex items-center gap-3 p-4 border border-black/5 rounded-xl hover:border-[#0071e3] hover:bg-[#f5f5f7] transition-colors group">
                <div class="w-10 h-10 rounded-lg bg-purple-100 flex items-center justify-center group-hover:bg-purple-200 transition-colors">
                    <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                    </svg>
                </div>
                <div>
                    <p class="text-sm font-medium text-[#1d1d1f] group-hover:text-[#0066cc] transition-colors">Export Analisa Vibrasi</p>
                    <p class="text-xs text-[#6e6e73]">Breakdown per PT &amp; ranking</p>
                </div>
            </a>
        </div>
        </div>
    </div>
@endsection
