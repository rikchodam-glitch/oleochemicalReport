@extends('layouts.app')

@section('title', 'Report & Analysis - Condition Monitoring')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('cm.overview') }}" class="hover:text-slate-700">Condition Monitoring</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Report & Analysis</span>
@endsection

@section('content')
    @include('cm._tabs')

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
            <div class="space-y-1">
                <label class="text-xs font-medium text-slate-500">Bulan</label>
                <select name="bulan" class="border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-teal-500">
                    <option value="">Semua Bulan</option>
                    @foreach($bulanList as $bl => $blName)
                        <option value="{{ $bl }}" {{ $filterBulan == $bl ? 'selected' : '' }}>{{ $blName }}</option>
                    @endforeach
                </select>
            </div>
            <div class="space-y-1">
                <label class="text-xs font-medium text-slate-500">PT</label>
                <select name="pt" class="border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-teal-500">
                    <option value="">Semua PT</option>
                    @foreach($ptList as $pt)
                        <option value="{{ $pt }}" {{ $filterPt === $pt ? 'selected' : '' }}>{{ $pt }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white text-sm font-medium rounded-lg transition-colors">
                Terapkan
            </button>
            <a href="{{ route('cm.report-analysis') }}" class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                Reset
            </a>
        </form>
    </div>
    {{-- Summary Cards --}}
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-xs text-slate-400">Total Equipment</p>
            <p class="text-2xl font-bold text-slate-900 mt-1">{{ number_format($totalEquipment) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-xs text-slate-400">Total Readings</p>
            <p class="text-2xl font-bold text-blue-600 mt-1">{{ number_format($totalReadings) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-xs text-slate-400">Total Findings</p>
            <p class="text-2xl font-bold text-amber-600 mt-1">{{ number_format($totalFindings) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-red-200 p-4">
            <p class="text-xs text-slate-400">Finding Open</p>
            <p class="text-2xl font-bold text-red-600 mt-1">{{ number_format($openFindings) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-green-200 p-4">
            <p class="text-xs text-slate-400">Monitoring {{ $filterTahun }}</p>
            <p class="text-2xl font-bold text-green-600 mt-1">{{ $monitoringPct }}%</p>
            <p class="text-xs text-slate-400">{{ $monitoringSudah }}/{{ $monitoringTotal }} bulan</p>
        </div>
    </div>

    {{-- INSIGHT ANALISA OTOMATIS (Section 3) --}}
    @if($insight)
    <div class="bg-gradient-to-r from-teal-50 to-emerald-50 rounded-xl border border-teal-200 p-5 mb-6">
        <div class="flex items-start gap-4">
            <div class="w-10 h-10 rounded-lg bg-teal-100 flex items-center justify-center flex-shrink-0">
                <svg class="w-5 h-5 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <div class="flex-1 min-w-0">
                <h4 class="text-sm font-semibold text-teal-900">Insight Analisa Vibrasi - {{ $filterTahun }}</h4>
                <p class="text-sm text-teal-800 mt-1">
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
    <div class="bg-white rounded-xl border border-slate-200 p-6 mb-6">
        <h3 class="font-semibold text-slate-900 mb-1">Equipment Vibrasi Tinggi per PT</h3>
        <p class="text-xs text-slate-400 mb-5">
            Status ALARM/DANGER dengan vibrasi &gt; 4.5 mm/s - dikelompokkan berdasarkan kategori analisa
        </p>

        <div class="grid grid-cols-1 {{ $filterPt ? '' : 'lg:grid-cols-3' }} gap-6">
            @forelse($ptBreakdown as $pt => $ptData)
                <div class="border border-slate-200 rounded-xl overflow-hidden">
                    {{-- Header PT --}}
                    <div class="bg-slate-50 px-4 py-3 border-b border-slate-200">
                        <div class="flex items-center justify-between">
                            <h4 class="font-semibold text-slate-900 text-sm">{{ $pt }}</h4>
                            <span class="text-xs text-slate-500 bg-white px-2 py-0.5 rounded-full border border-slate-200">
                                {{ $ptData['total'] }} equipment
                            </span>
                        </div>
                    </div>

                    {{-- Daftar Kategori --}}
                    <div class="divide-y divide-slate-100">
                        @forelse($ptData['categories'] as $cat)
                            <div class="px-4 py-3">
                                {{-- Header kategori --}}
                                <div class="flex items-center justify-between mb-1.5">
                                    <div class="flex items-center gap-2">
                                        <span class="w-5 h-5 rounded-full bg-teal-100 text-teal-700 text-xs font-bold flex items-center justify-center">
                                            {{ $cat['rank'] }}
                                        </span>
                                        <span class="text-sm font-medium text-slate-800">{{ $cat['name'] }}</span>
                                    </div>
                                    <span class="text-xs text-slate-500">{{ $cat['count'] }} equipment</span>
                                </div>

                                {{-- Progress bar persentase --}}
                                <div class="flex items-center gap-2 mb-2">
                                    <div class="flex-1 bg-slate-100 rounded-full h-1.5">
                                        <div class="bg-teal-500 h-1.5 rounded-full" style="width: {{ $cat['percentage'] }}%"></div>
                                    </div>
                                    <span class="text-xs font-medium text-teal-600 w-12 text-right">{{ $cat['percentage'] }}%</span>
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
                                                <span class="text-slate-400">-</span>
                                                <span class="text-slate-400">{{ $eq['bulan'] }}</span>
                                            @endif
                                        </a>
                                    @endforeach
                                </div>
                            </div>
                        @empty
                            <div class="px-4 py-6 text-center text-sm text-slate-400">
                                Tidak ada data vibrasi tinggi di PT ini.
                            </div>
                        @endforelse
                    </div>
                </div>
            @empty
                <div class="col-span-full text-center py-8 text-sm text-slate-400">
                    Tidak ada data equipment dengan vibrasi tinggi untuk filter yang dipilih.
                </div>
            @endforelse
        </div>
    </div>
    {{-- SECTION 2: RANKING ANALISA BULANAN (TABEL TREND) --}}
    <div class="bg-white rounded-xl border border-slate-200 p-6 mb-6">
        <h3 class="font-semibold text-slate-900 mb-1">Ranking Analisa Bulanan</h3>
        <p class="text-xs text-slate-400 mb-5">
            Trend jumlah equipment vibrasi tinggi per kategori analisa - semua PT (filter: Alarm/Danger, vibrasi &gt; 4.5 mm/s)
        </p>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200">
                        <th class="text-left px-3 py-2 text-xs font-semibold text-slate-500 uppercase tracking-wider">Rank</th>
                        <th class="text-left px-3 py-2 text-xs font-semibold text-slate-500 uppercase tracking-wider">Kategori Analisa</th>
                        @php $labels = ['','Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des']; @endphp
                        @foreach($allMonths as $m)
                            <th class="text-center px-2 py-2 text-xs font-semibold text-slate-500 uppercase tracking-wider">{{ $labels[$m] ?? $m }}</th>
                        @endforeach
                        <th class="text-center px-3 py-2 text-xs font-semibold text-slate-500 uppercase tracking-wider bg-slate-50">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
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
                            $rowBg = $isTop3 ? 'bg-teal-50/40' : '';
                        @endphp
                        <tr class="{{ $rowBg }} hover:bg-slate-50 transition-colors">
                            <td class="px-3 py-2.5 text-sm font-bold {{ $isTop3 ? 'text-teal-600' : 'text-slate-400' }}">
                                #{{ $row['rank'] }}
                            </td>
                            <td class="px-3 py-2.5 text-sm font-medium text-slate-800">
                                {{ $row['analysis'] }}
                            </td>
                            @foreach($allMonths as $m)
                                <td class="text-center px-2 py-2.5 text-sm text-slate-600">
                                    {{ $row[$m] ?? 0 }}
                                </td>
                            @endforeach
                            <td class="text-center px-3 py-2.5 text-sm font-bold text-slate-900 bg-slate-50/50">
                                {{ $row['total'] }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($allMonths) + 3 }}" class="text-center py-8 text-sm text-slate-400">
                                Tidak ada data analisa vibrasi untuk filter yang dipilih.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-slate-300 bg-slate-50">
                        <td colspan="2" class="px-3 py-2.5 text-sm font-bold text-slate-900">Total</td>
                        @foreach($allMonths as $m)
                            <td class="text-center px-2 py-2.5 text-sm font-bold text-slate-900">{{ $totalsPerBulan[$m] }}</td>
                        @endforeach
                        <td class="text-center px-3 py-2.5 text-sm font-bold text-slate-900 bg-slate-100">{{ $grandTotal }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    {{-- Laporan Ringkasan --}}
    <div class="bg-white rounded-xl border border-slate-200 p-6 mb-6">
        <h3 class="font-medium text-slate-900 mb-4">Ringkasan Periodik</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <h4 class="text-sm font-medium text-slate-700 mb-2">Distribusi Monitoring ({{ $filterTahun }})</h4>
                <div class="space-y-2">
                    <div>
                        <div class="flex justify-between text-xs text-slate-500 mb-1">
                            <span>Sudah Diambil</span>
                            <span>{{ $monitoringSudah }} bulan ({{ $monitoringPct }}%)</span>
                        </div>
                        <div class="w-full bg-slate-100 rounded-full h-2">
                            <div class="bg-green-500 h-2 rounded-full" style="width: {{ $monitoringPct }}%"></div>
                        </div>
                    </div>
                    <div>
                        <div class="flex justify-between text-xs text-slate-500 mb-1">
                            <span>Belum Diambil</span>
                            <span>{{ $monitoringTotal - $monitoringSudah }} bulan ({{ 100 - $monitoringPct }}%)</span>
                        </div>
                        <div class="w-full bg-slate-100 rounded-full h-2">
                            <div class="bg-amber-500 h-2 rounded-full" style="width: {{ 100 - $monitoringPct }}%"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div>
                <h4 class="text-sm font-medium text-slate-700 mb-2">Status Equipment Overview</h4>
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
                        <span class="font-semibold text-red-600">{{ number_format($openFindings) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Finding Sudah Closed</span>
                        <span class="font-semibold text-green-600">{{ number_format($totalFindings - $openFindings) }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Export Laporan --}}
    <div class="bg-white rounded-xl border border-slate-200 p-6">
        <h3 class="font-medium text-slate-900 mb-4">Export Laporan Rekap</h3>
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <a href="{{ route('cm.export-readings', ['pt' => $filterPt, 'tahun' => $filterTahun]) }}"
               class="flex items-center gap-3 p-4 border border-slate-200 rounded-xl hover:border-teal-300 hover:bg-teal-50 transition-colors group">
                <div class="w-10 h-10 rounded-lg bg-green-100 flex items-center justify-center group-hover:bg-green-200 transition-colors">
                    <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-900 group-hover:text-teal-700 transition-colors">Export Readings</p>
                    <p class="text-xs text-slate-500">Data vibrasi & temperatur</p>
                </div>
            </a>
            <a href="{{ route('cm.export-findings', ['pt' => $filterPt]) }}"
               class="flex items-center gap-3 p-4 border border-slate-200 rounded-xl hover:border-teal-300 hover:bg-teal-50 transition-colors group">
                <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center group-hover:bg-amber-200 transition-colors">
                    <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-900 group-hover:text-teal-700 transition-colors">Export Findings</p>
                    <p class="text-xs text-slate-500">Data temuan & severity</p>
                </div>
            </a>
            <a href="{{ route('cm.export-monitoring', ['tahun' => $filterTahun, 'pt' => $filterPt]) }}"
               class="flex items-center gap-3 p-4 border border-slate-200 rounded-xl hover:border-teal-300 hover:bg-teal-50 transition-colors group">
                <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center group-hover:bg-blue-200 transition-colors">
                    <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-900 group-hover:text-teal-700 transition-colors">Export Monitoring</p>
                    <p class="text-xs text-slate-500">Data tracking bulanan</p>
                </div>
            </a>
            <a href="{{ route('cm.export-analysis', ['tahun' => $filterTahun, 'bulan' => $filterBulan, 'pt' => $filterPt]) }}"
               class="flex items-center gap-3 p-4 border border-slate-200 rounded-xl hover:border-teal-300 hover:bg-teal-50 transition-colors group">
                <div class="w-10 h-10 rounded-lg bg-purple-100 flex items-center justify-center group-hover:bg-purple-200 transition-colors">
                    <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                    </svg>
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-900 group-hover:text-teal-700 transition-colors">Export Analisa Vibrasi</p>
                    <p class="text-xs text-slate-500">Breakdown per PT & ranking</p>
                </div>
            </a>
        </div>
    </div>
@endsection
