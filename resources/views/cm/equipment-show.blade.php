@extends("layouts.app")

@section("page-title", $equipment->equipment_tag . " — Detail Equipment")
@section("page-sub", "Informasi detail & riwayat pembacaan equipment")

@section("content")

<div class="bg-white rounded-xl border border-slate-200 p-6 mb-6">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div class="min-w-0">
            <div class="flex items-center gap-3 flex-wrap">
                <h2 class="text-2xl font-bold font-mono" style="color: #0E9E8E;">{{ $equipment->equipment_tag }}</h2>
                @php
                    $sc = ["good" => "bg-green-100 text-green-700 border-green-300", "alarm" => "bg-amber-100 text-amber-700 border-amber-300", "danger" => "bg-red-100 text-red-700 border-red-300", "visual_bad" => "bg-purple-100 text-purple-700 border-purple-300"];
                    $sdc = ["good" => "bg-green-500", "alarm" => "bg-amber-500", "danger" => "bg-red-500", "visual_bad" => "bg-purple-500"];
                    $cs = $equipment->current_status;
                    $stColor = $sc[$cs] ?? "bg-slate-100 text-slate-600";
                    $dotColor = $sdc[$cs] ?? "bg-slate-500";
                    $stLabel = ucfirst(str_replace("_", " ", $cs));
                @endphp
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium border {{ $stColor }}">
                    <span class="w-2 h-2 rounded-full mr-1.5 {{ $dotColor }}"></span>
                    {{ $stLabel }}
                </span>
            </div>
            <p class="text-sm text-slate-500 mt-1">
                {{ $equipment->pt_location }}
                @if($equipment->plant)
                    &middot; {{ $equipment->plant }}
                @endif
            </p>
            <p class="text-sm text-slate-700 mt-1">
                @if($equipment->asset)
                    {{ $equipment->asset->description }}
                @else
                    <span class="text-amber-600">Asset belum terdaftar di Asset Management.</span>
                    <a href="{{ route('assets.create') }}?equipment_no={{ urlencode($equipment->equipment_tag) }}"
                       class="inline-flex items-center gap-1 text-teal-600 hover:text-teal-800 hover:underline font-medium ml-1">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        Tambah Asset
                    </a>
                @endif
            </p>
        </div>
        <div class="shrink-0 flex items-center gap-2">
            <a href="{{ route('cm.report-analysis') }}"
               class="inline-flex items-center gap-1.5 px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                Report Analysis
            </a>
            <a href="{{ route('cm.equipment-status') }}" class="inline-flex items-center gap-1.5 px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                Equipment Status
            </a>
        </div>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-5 pt-5 border-t border-slate-100">
        <div>
            <p class="text-xs text-slate-400 uppercase tracking-wide">Tipe Lubrikasi</p>
            <p class="text-sm font-medium text-slate-800 mt-0.5">{{ $equipment->tipe_lubrikasi ?? "—" }}</p>
        </div>
        <div>
            <p class="text-xs text-slate-400 uppercase tracking-wide">PT / Plant</p>
            <p class="text-sm font-medium text-slate-800 mt-0.5">{{ $equipment->pt_location }} / {{ $equipment->plant ?? "—" }}</p>
        </div>
    </div>
</div>

{{-- Card Ringkasan Equipment: Spek + Statistik dalam satu card besar --}}
<div class="bg-white rounded-xl border border-slate-200 overflow-hidden mb-6">
    <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between flex-wrap gap-2">
        <h3 class="text-sm font-semibold text-slate-900">Ringkasan Equipment</h3>
        <button onclick="document.getElementById('editSpekModal').classList.remove('hidden')"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium text-slate-600 hover:text-teal-600 hover:bg-teal-50 border border-slate-200 transition-colors"
                title="Edit Informasi Spek">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
            </svg>
            Edit Spek
        </button>
    </div>
    <div class="p-6">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            {{-- Informasi Spek --}}
            <div class="lg:col-span-1">
                <div class="grid grid-cols-2 gap-x-4 gap-y-3">
                    <div>
                        <p class="text-[11px] text-slate-400 uppercase tracking-wide">Company</p>
                        <p class="text-sm text-slate-800 font-medium mt-0.5">{{ $equipment->asset?->company?->name ?? $equipment->asset?->company?->code ?? "—" }}</p>
                    </div>
                    <div>
                        <p class="text-[11px] text-slate-400 uppercase tracking-wide">Equipment No (SAP)</p>
                        <p class="text-sm font-mono text-slate-800 mt-0.5">{{ $equipment->asset?->equipment_no ?? "—" }}</p>
                    </div>
                    <div>
                        <p class="text-[11px] text-slate-400 uppercase tracking-wide">Tech Ident No (SAP)</p>
                        <p class="text-sm font-mono text-slate-800 mt-0.5">{{ $equipment->asset?->tech_ident_no ?? "—" }}</p>
                    </div>
                    <div>
                        <p class="text-[11px] text-slate-400 uppercase tracking-wide">Construct Year</p>
                        <p class="text-sm text-slate-800 mt-0.5">{{ $equipment->asset?->construct_year ?? "—" }}</p>
                    </div>
                    <div>
                        <p class="text-[11px] text-slate-400 uppercase tracking-wide">Manufacturer</p>
                        <p class="text-sm text-slate-800 mt-0.5">{{ $equipment->asset?->manufacturer ?? "—" }}</p>
                    </div>
                    <div>
                        <p class="text-[11px] text-slate-400 uppercase tracking-wide">Model Number</p>
                        <p class="text-sm text-slate-800 mt-0.5">{{ $equipment->asset?->model_number ?? "—" }}</p>
                    </div>
                    <div class="col-span-2">
                        <p class="text-[11px] text-slate-400 uppercase tracking-wide">Description</p>
                        <p class="text-sm text-slate-800 mt-0.5">{{ $equipment->asset?->description ?? "—" }}</p>
                    </div>
                </div>
                @if(!$equipment->asset)
                <div class="mt-4 pt-4 border-t border-slate-100">
                    <a href="{{ route('assets.create') }}?equipment_no={{ urlencode($equipment->equipment_tag) }}"
                       class="inline-flex items-center gap-1.5 text-sm text-teal-600 hover:text-teal-800 hover:underline font-medium">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        Tambah Asset ke Asset Management
                    </a>
                </div>
                @endif
            </div>
            {{-- Stat Cards --}}
            <div class="lg:col-span-2">
                @php
                    $statIconBox = "w-10 h-10 rounded-lg flex items-center justify-center shrink-0";
                    $statIconColor = "color: #0E9E8E;";
                    $statIconBg = "background: rgba(14,158,142,0.1);";
                @endphp
                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3">
                    {{-- Total Readings --}}
                    <div class="bg-slate-50 rounded-xl border border-slate-200 p-4 flex items-center gap-3">
                        <div class="{{ $statIconBox }}" style="{{ $statIconBg }}">
                            <svg class="w-5 h-5" style="{{ $statIconColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-[11px] text-slate-400 uppercase tracking-wide font-medium truncate">Total Readings</p>
                            <div class="flex items-baseline gap-1.5 mt-0.5 flex-wrap">
                                <p class="text-lg font-bold text-slate-900 leading-tight">{{ $totalReadings }}</p>
                                @if($totalVisualBad > 0)
                                    <p class="text-[11px] text-slate-400"><span class="text-purple-600 font-medium">{{ $totalVisualBad }} visual bad</span></p>
                                @endif
                            </div>
                        </div>
                    </div>
                    {{-- Total Findings --}}
                    <div class="bg-slate-50 rounded-xl border border-slate-200 p-4 flex items-center gap-3">
                        <div class="{{ $statIconBox }}" style="{{ $statIconBg }}">
                            <svg class="w-5 h-5" style="{{ $statIconColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-[11px] text-slate-400 uppercase tracking-wide font-medium truncate">Total Findings</p>
                            <div class="flex items-baseline gap-1.5 mt-0.5 flex-wrap">
                                <p class="text-lg font-bold text-slate-900 leading-tight">{{ $totalFindingsOpen + $totalFindingsClosed }}</p>
                                <p class="text-[11px] text-slate-400"><span class="text-amber-600 font-medium">{{ $totalFindingsOpen }} open</span><span class="text-slate-300 mx-0.5">/</span><span class="text-green-600 font-medium">{{ $totalFindingsClosed }} closed</span></p>
                            </div>
                        </div>
                    </div>
                    {{-- Last Reading --}}
                    <div class="bg-slate-50 rounded-xl border border-slate-200 p-4 flex items-center gap-3">
                        <div class="{{ $statIconBox }}" style="{{ $statIconBg }}">
                            <svg class="w-5 h-5" style="{{ $statIconColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-[11px] text-slate-400 uppercase tracking-wide font-medium truncate">Last Reading</p>
                            @if($lastReading)
                                <p class="text-lg font-bold text-slate-900 leading-tight mt-0.5">{{ \Carbon\Carbon::parse($lastReading->tanggal)->format("d M Y") }}</p>
                                <span class="inline-flex items-center gap-1 text-[11px] font-medium {{ $lastReading->kondisi === "good" ? "text-green-600" : ($lastReading->kondisi === "alarm" ? "text-amber-600" : ($lastReading->kondisi === "danger" ? "text-red-600" : "text-purple-600")) }}">
                                    <span class="w-1.5 h-1.5 rounded-full {{ $lastReading->kondisi === "good" ? "bg-green-500" : ($lastReading->kondisi === "alarm" ? "bg-amber-500" : ($lastReading->kondisi === "danger" ? "bg-red-500" : "bg-purple-500")) }}"></span>
                                    {{ ucfirst(str_replace("_", " ", $lastReading->kondisi)) }}
                                </span>
                            @else
                                <p class="text-lg font-bold text-slate-900 leading-tight mt-0.5">—</p>
                            @endif
                        </div>
                    </div>
                    {{-- Vibrasi Maks --}}
                    <div class="bg-slate-50 rounded-xl border border-slate-200 p-4 flex items-center gap-3">
                        <div class="{{ $statIconBox }}" style="{{ $statIconBg }}">
                            <svg class="w-5 h-5" style="{{ $statIconColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m8-3v-2a2 2 0 00-2-2h-2a2 2 0 00-2 2v2m0 0v6a2 2 0 002 2h2a2 2 0 002-2v-6"/></svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-[11px] text-slate-400 uppercase tracking-wide font-medium truncate">Vibrasi Maks</p>
                            <p class="text-lg font-bold {{ $vibMax !== "-" && (float) $vibMax > 10 ? "text-red-600" : "text-slate-900" }} leading-tight mt-0.5">{{ $vibMax }} <span class="text-xs font-normal text-slate-400">mm/s</span></p>
                        </div>
                    </div>
                    {{-- Temp Maks --}}
                    <div class="bg-slate-50 rounded-xl border border-slate-200 p-4 flex items-center gap-3">
                        <div class="{{ $statIconBox }}" style="{{ $statIconBg }}">
                            <svg class="w-5 h-5" style="{{ $statIconColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m8.66-14.66l-.7.7M5.64 19.36l-.7.7m15.02 0l-.7-.7M5.64 5.64l-.7.7M21 12h-1M4 12H3m15.66 7.34l-.7-.7M6.34 6.34l-.7-.7M12 8a4 4 0 110 8 4 4 0 010-8z"/></svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-[11px] text-slate-400 uppercase tracking-wide font-medium truncate">Temp Maks</p>
                            <p class="text-lg font-bold {{ $tempMax !== "-" && (float) $tempMax > 80 ? "text-red-600" : "text-slate-900" }} leading-tight mt-0.5">{{ $tempMax }} <span class="text-xs font-normal text-slate-400">&deg;C</span></p>
                        </div>
                    </div>
                    {{-- Ampere --}}
                    <div class="bg-slate-50 rounded-xl border border-slate-200 p-4 flex items-center gap-3">
                        <div class="{{ $statIconBox }}" style="{{ $statIconBg }}">
                            <svg class="w-5 h-5" style="{{ $statIconColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-[11px] text-slate-400 uppercase tracking-wide font-medium truncate">Ampere</p>
                            <p class="text-lg font-bold text-slate-900 leading-tight mt-0.5">{{ $lastReading?->ampere ? number_format($lastReading->ampere, 1) : "—" }} <span class="text-xs font-normal text-slate-400">A</span></p>
                        </div>
                    </div>
                    {{-- MTBF --}}
                    <div class="bg-slate-50 rounded-xl border border-slate-200 p-4 flex items-center gap-3">
                        <div class="{{ $statIconBox }}" style="{{ $statIconBg }}">
                            <svg class="w-5 h-5" style="{{ $statIconColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-[11px] text-slate-400 uppercase tracking-wide font-medium truncate">MTBF</p>
                            <p class="text-lg font-bold leading-tight mt-0.5" style="color: #0E9E8E;">@if($mtbfData["mtbf"] !== "-"){{ number_format($mtbfData["mtbf"], 1) }} <span class="text-xs font-normal text-slate-400">hari</span>@else-@endif</p>
                        </div>
                    </div>
                    {{-- Total Failures --}}
                    <div class="bg-slate-50 rounded-xl border border-slate-200 p-4 flex items-center gap-3">
                        <div class="{{ $statIconBox }}" style="{{ $statIconBg }}">
                            <svg class="w-5 h-5" style="{{ $statIconColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-[11px] text-slate-400 uppercase tracking-wide font-medium truncate">Total Failures</p>
                            <p class="text-lg font-bold text-slate-900 leading-tight mt-0.5">{{ $mtbfData["total_failures"] }}</p>
                        </div>
                    </div>
                    {{-- Last Failure --}}
                    <div class="bg-slate-50 rounded-xl border border-slate-200 p-4 flex items-center gap-3">
                        <div class="{{ $statIconBox }}" style="{{ $statIconBg }}">
                            <svg class="w-5 h-5" style="{{ $statIconColor }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-[11px] text-slate-400 uppercase tracking-wide font-medium truncate">Last Failure</p>
                            @if($mtbfData["last_failure"])
                                <p class="text-lg font-bold text-slate-900 leading-tight mt-0.5">{{ $mtbfData["last_failure"] }}</p>
                                <p class="text-[11px] text-slate-500">({{ $mtbfData["days_since"] }} hari lalu)</p>
                            @else
                                <p class="text-lg font-bold text-slate-900 leading-tight mt-0.5">—</p>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Card Trend & Analisa: filter + chart dalam satu card --}}
<div class="bg-white rounded-xl border border-slate-200 overflow-hidden mb-6">
    <div class="px-6 py-4 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <h3 class="text-sm font-semibold text-slate-900">Trend & Analisa</h3>
        <form method="GET" class="flex items-center gap-2">
            <label for="yearFilter" class="text-xs text-slate-500">Tahun:</label>
            <select name="range" id="yearFilter"
                    onchange="this.form.submit()"
                    class="px-3 py-2 text-sm border border-slate-300 rounded-lg bg-white text-slate-700 focus:outline-none focus:ring-2 focus:ring-teal-400 focus:border-teal-400">
                <option value="all" {{ $range === "all" ? "selected" : "" }}>Semua</option>
                @foreach($availableYears as $year)
                <option value="{{ $year }}" {{ $range === (string) $year ? "selected" : "" }}>{{ $year }}</option>
                @endforeach
            </select>
            <noscript><button type="submit" class="px-3 py-2 text-xs font-medium rounded-lg border border-slate-300 bg-white text-slate-600 hover:bg-slate-50">Terapkan</button></noscript>
        </form>
    </div>
    <div class="p-6">
        @if(count($chartData) === 0)
            <p class="text-sm text-slate-400 text-center py-10">Belum ada data pembacaan untuk rentang waktu ini.</p>
        @else
            {{-- Trending Vibrasi Motor --}}
            <div class="mb-6">
                <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3 mb-4">
                    <h4 class="text-sm font-semibold text-slate-900">Trending Vibrasi Motor</h4>
                    <div class="flex items-center gap-4 text-xs flex-wrap">
                        <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full" style="background: #0E9E8E"></span> NDEV</span>
                        <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full" style="background: #F59E0B"></span> NDEH</span>
                        <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full" style="background: #EF4444"></span> NDEA</span>
                        <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full" style="background: #8B5CF6"></span> DEV</span>
                        <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full" style="background: #EC4899"></span> DEH</span>
                        <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full" style="background: #06B6D4"></span> DEA</span>
                        <span class="flex items-center gap-1.5"><span class="w-4 border-t-2 border-dashed" style="border-color: #F59E0B"></span> Alarm {{ $vibThresholds['alarm'] }}</span>
                        <span class="flex items-center gap-1.5"><span class="w-4 border-t-2 border-dashed" style="border-color: #EF4444"></span> Danger {{ $vibThresholds['danger'] }}</span>
                    </div>
                </div>
                <div class="relative" style="height: 300px;">
                    <canvas id="vibrationMotorChart" name="vibrationMotorChart"></canvas>
                </div>
            </div>

            {{-- Trending Vibrasi Mesin --}}
            <div class="pt-6 border-t border-slate-100">
                <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3 mb-4">
                    <h4 class="text-sm font-semibold text-slate-900">Trending Vibrasi Mesin</h4>
                    <div class="flex items-center gap-4 text-xs flex-wrap">
                        <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full" style="background: #0E9E8E"></span> NDEV Mesin</span>
                        <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full" style="background: #F59E0B"></span> NDEH Mesin</span>
                        <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full" style="background: #EF4444"></span> NDEA Mesin</span>
                        <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full" style="background: #8B5CF6"></span> DEV Mesin</span>
                        <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full" style="background: #EC4899"></span> DEH Mesin</span>
                        <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full" style="background: #06B6D4"></span> DEA Mesin</span>
                        <span class="flex items-center gap-1.5"><span class="w-4 border-t-2 border-dashed" style="border-color: #F59E0B"></span> Alarm {{ $vibThresholds['alarm'] }}</span>
                        <span class="flex items-center gap-1.5"><span class="w-4 border-t-2 border-dashed" style="border-color: #EF4444"></span> Danger {{ $vibThresholds['danger'] }}</span>
                    </div>
                </div>
                <div class="relative" style="height: 300px;">
                    <canvas id="vibrationPompaChart" name="vibrationPompaChart"></canvas>
                </div>
            </div>

            {{-- Trending Temperature --}}
            <div class="pt-6 border-t border-slate-100 mt-6">
                <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3 mb-4">
                    <h4 class="text-sm font-semibold text-slate-900">Trending Temperature</h4>
                    <div class="flex items-center gap-4 text-xs flex-wrap">
                        <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full" style="background: #EF4444"></span> DE Motor</span>
                        <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full" style="background: #F97316"></span> NDE Motor</span>
                        <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full" style="background: #3B82F6"></span> DE Mesin</span>
                        <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full" style="background: #8B5CF6"></span> NDE Mesin</span>
                        <span class="flex items-center gap-1.5"><span class="w-4 border-t-2 border-dashed" style="border-color: #EF4444"></span> Threshold {{ $tempThreshold }}&deg;C</span>
                    </div>
                </div>
                <div class="relative" style="height: 300px;">
                    <canvas id="temperatureChart" name="temperatureChart"></canvas>
                </div>
            </div>
        @endif
    </div>
</div>

{{-- Card Insight Trend Vibrasi -- hanya muncul jika ada kenaikan signifikan --}}
@if($vibrationInsight)
<div class="rounded-xl border border-amber-300 bg-amber-50 p-5 mb-6">
    <div class="flex items-start gap-3">
        <div class="w-9 h-9 rounded-full bg-amber-200 flex items-center justify-center shrink-0 mt-0.5">
            <svg class="w-5 h-5 text-amber-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
        </div>
        <div class="flex-1 min-w-0">
            <h4 class="text-sm font-semibold text-amber-900">Insight — Tren Vibrasi</h4>
            <p class="text-sm text-amber-800 mt-1">{{ $vibrationInsight['message'] }}</p>
            <div class="flex flex-wrap gap-2 mt-3">
                <a href="{{ route('cm.findings') }}?equipment_tag={{ urlencode($equipment->equipment_tag) }}"
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-amber-600 hover:bg-amber-700 text-white text-xs font-medium rounded-lg transition-colors">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    Buat Finding Baru
                </a>
                <span class="text-xs text-amber-600 self-center">atau <a href="#vibrationMotorChart" class="underline hover:text-amber-800">Lihat detail trend motor</a></span>
            </div>
            @if($vibrationInsight['projected_days_to_danger'])
                <p class="text-xs text-amber-600 mt-2">
                    <span class="font-medium">Rekomendasi:</span> jadwalkan PdM inspection / tindak lanjut
                    sebelum ~{{ $vibrationInsight['projected_days_to_danger'] }} hari ke depan (proyeksi menyentuh batas Danger).
                </p>
            @elseif($vibrationInsight['projected_days_to_alarm'])
                <p class="text-xs text-amber-600 mt-2">
                    <span class="font-medium">Rekomendasi:</span> jadwalkan PdM inspection / tindak lanjut
                    sebelum ~{{ $vibrationInsight['projected_days_to_alarm'] }} hari ke depan (proyeksi menyentuh batas Alarm).
                </p>
            @else
                <p class="text-xs text-amber-600 mt-2">
                    <span class="font-medium">Rekomendasi:</span> lakukan PdM inspection untuk tindak lanjut.
                </p>
            @endif
        </div>
    </div>
</div>
@endif

<div class="bg-white rounded-xl border border-slate-200 overflow-hidden mb-6">
    <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
        <h3 class="text-sm font-semibold text-slate-900">Riwayat Pembacaan</h3>
        <span class="text-xs text-slate-400">10 entri per halaman</span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-slate-50 border-b border-slate-100">
                    <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">Tanggal</th>
                    <th class="text-center text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">Kondisi</th>
                    <th class="text-center text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">Status</th>
                    <th class="text-center text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">Motor Vib</th>
                    <th class="text-center text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">Mesin Vib</th>
                    <th class="text-center text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">Temp</th>
                    <th class="text-right text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">Ampere</th>
                    <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">Remark</th>
                    <th class="text-center text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                @forelse($readings as $r)
                    @php
                        $mv = collect([$r->ndev_motor, $r->ndeh_motor, $r->ndea_motor, $r->dev_motor, $r->deh_motor, $r->dea_motor])->filter(fn($v) => $v !== null)->values();
                        $mvStr = $mv->isNotEmpty() ? $mv->take(3)->map(fn($v) => number_format($v, 1))->implode(" / ") : "—";
                        $pv = collect([$r->ndev_pompa, $r->ndeh_pompa, $r->ndea_pompa, $r->dev_pompa, $r->deh_pompa, $r->dea_pompa])->filter(fn($v) => $v !== null)->values();
                        $pvStr = $pv->isNotEmpty() ? $pv->take(3)->map(fn($v) => number_format($v, 1))->implode(" / ") : "—";
                        $temps = collect([$r->temp_de_motor, $r->temp_nde_motor, $r->temp_de_pompa, $r->temp_nde_pompa])->filter(fn($t) => $t !== null)->values();
                        $tempStr = $temps->isNotEmpty() ? $temps->max() . "&deg;C" : "—";
                        $rs = $r->kondisi;
                        $bc = ["good" => "bg-green-100 text-green-700", "alarm" => "bg-amber-100 text-amber-700", "danger" => "bg-red-100 text-red-700", "visual_bad" => "bg-purple-100 text-purple-700"];
                        $bcColor = $bc[$rs] ?? "bg-slate-100 text-slate-600";
                        $delDate = \Carbon\Carbon::parse($r->tanggal)->format("d/m/Y");
                    @endphp
                    <tr class="hover:bg-slate-50 transition-colors">
                        <td class="px-4 py-3 text-xs text-slate-600 whitespace-nowrap">{{ \Carbon\Carbon::parse($r->tanggal)->format("d M Y") }}</td>
                        <td class="px-4 py-3 text-center"><span class="inline-flex px-2.5 py-0.5 text-xs font-medium rounded-full {{ $bcColor }}">{{ ucfirst(str_replace("_", " ", $rs)) }}</span></td>
                        @php
                            $isRunning = $r->status && strtoupper(trim((string) $r->status)) === "START";
                        @endphp
                        <td class="px-4 py-3 text-center">
                            @if($r->status)
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium rounded-full {{ $isRunning ? "bg-green-100 text-green-700" : "bg-slate-100 text-slate-600" }}">
                                    <span class="w-1.5 h-1.5 rounded-full {{ $isRunning ? "bg-green-500" : "bg-slate-400" }}"></span>
                                    {{ trim((string) $r->status) }}
                                </span>
                            @else
                                <span class="text-slate-300">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center font-mono text-xs text-slate-700">{{ $mvStr }}</td>
                        <td class="px-4 py-3 text-center font-mono text-xs text-slate-700">{{ $pvStr }}</td>
                        <td class="px-4 py-3 text-center font-mono text-xs text-slate-700">{!! $tempStr !!}</td>
                        <td class="px-4 py-3 text-right font-mono text-xs text-slate-700">{{ $r->ampere ? number_format($r->ampere, 1) : "—" }}</td>
                        <td class="px-4 py-3 text-xs text-slate-500 max-w-[200px] truncate" title="{{ $r->remark ?? "" }}">{{ $r->remark ?? "—" }}</td>
                        <td class="px-4 py-3 text-center whitespace-nowrap">
                            <form method="POST" action="{{ route('cm.readings.destroy', $r) }}" class="inline"
                                  onsubmit="return confirm('Hapus reading tanggal {{ $delDate }}? Data tidak bisa dikembalikan.')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-red-500 hover:text-red-700 transition-colors" title="Hapus reading tanggal {{ $delDate }}">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                    </svg>
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-5 py-8 text-center text-slate-400 text-sm">Belum ada data pembacaan untuk equipment ini.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($readings->hasPages())
        <div class="px-5 py-3 border-t border-slate-100">{{ $readings->appends(["range" => $range])->links() }}</div>
    @endif
</div>
<div class="bg-white rounded-xl border border-slate-200 overflow-hidden mb-6">
    <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
        <h3 class="text-sm font-semibold text-slate-900">Temuan (Findings)</h3>
        <span class="text-xs text-slate-400">{{ $equipment->findings->count() }} total</span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-slate-50 border-b border-slate-100">
                    <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">Kode Finding</th>
                    <th class="text-center text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">Severity</th>
                    <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">Kategori</th>
                    <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">Deskripsi</th>
                    <th class="text-center text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">Status</th>
                    <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">PIC</th>
                    <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">Tgl Temuan</th>
                    <th class="text-right text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">Hari Open</th>
                    <th class="text-center text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                @forelse($equipment->findings->sortByDesc('tanggal_temuan') as $f)
                    @php
                        $sevColors = ["low" => "bg-blue-100 text-blue-700", "medium" => "bg-amber-100 text-amber-700", "high" => "bg-red-100 text-red-700"];
                        $sevColor = $sevColors[$f->severity] ?? "bg-slate-100 text-slate-600";
                        $isOpen = $f->status === "open";
                    @endphp
                    <tr class="hover:bg-slate-50 transition-colors">
                        <td class="px-4 py-3"><a href="{{ route('cm.findings.show', $f->kode_finding) }}" class="font-mono text-xs text-teal-700 font-medium hover:text-teal-900 hover:underline transition-colors">{{ $f->kode_finding }}</a></td>
                        <td class="px-4 py-3 text-center"><span class="inline-flex px-2.5 py-0.5 text-xs font-medium rounded-full {{ $sevColor }}">{{ ucfirst($f->severity) }}</span></td>
                        <td class="px-4 py-3 text-xs text-slate-600">{{ $f->kategori ?? "—" }}</td>
                        <td class="px-4 py-3 text-xs text-slate-700 max-w-[250px] truncate" title="{{ $f->deskripsi }}">{{ $f->deskripsi ?? "—" }}</td>
                        <td class="px-4 py-3 text-center">@if($isOpen)<span class="inline-flex items-center px-2.5 py-0.5 text-xs font-medium rounded-full bg-amber-100 text-amber-700"><span class="w-1.5 h-1.5 rounded-full bg-amber-500 mr-1.5"></span>Open</span>@else<span class="inline-flex items-center px-2.5 py-0.5 text-xs font-medium rounded-full bg-green-100 text-green-700"><span class="w-1.5 h-1.5 rounded-full bg-green-500 mr-1.5"></span>Closed</span>@endif</td>
                        <td class="px-4 py-3 text-xs text-slate-600">{{ $f->pic ?? "—" }}</td>
                        <td class="px-4 py-3 text-xs text-slate-500 whitespace-nowrap">{{ $f->tanggal_temuan ? \Carbon\Carbon::parse($f->tanggal_temuan)->format("d M Y") : "—" }}</td>
                        <td class="px-4 py-3 text-right text-xs {{ $isOpen && $f->hari_open > 7 ? "text-red-600 font-semibold" : "text-slate-600" }}">{{ $f->hari_open ?? "—" }}</td>
                        <td class="px-4 py-3 text-center whitespace-nowrap">
                            <form method="POST" action="{{ route('cm.findings.destroy', $f) }}" class="inline"
                                  onsubmit="return confirm('Hapus finding {{ $f->kode_finding }}? Data tidak bisa dikembalikan.')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-red-500 hover:text-red-700 transition-colors" title="Hapus finding {{ $f->kode_finding }}">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                    </svg>
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-5 py-8 text-center text-slate-400 text-sm">Belum ada temuan untuk equipment ini.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
{{-- Modal Edit Informasi Spek --}}
<div id="editSpekModal" class="fixed inset-0 z-50 hidden bg-black/40 flex items-center justify-center p-4" onclick="if(event.target===this)this.classList.add('hidden')">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="text-sm font-semibold text-slate-900">Edit Informasi Spek</h3>
            <button onclick="document.getElementById('editSpekModal').classList.add('hidden')" class="p-1 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <form method="POST" action="{{ route('cm.equipment-update', $equipment->equipment_tag) }}" class="p-6 space-y-4">
            @csrf
            @method('PUT')
            <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Data dari Asset Management (SAP)</p>
            <p class="text-xs text-slate-400">Field yang berasal dari SAP bersifat read-only dan hanya bisa diubah melalui import Asset Management.</p>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Company</label>
                <input type="text" value="{{ $equipment->asset?->company?->name ?? $equipment->asset?->company?->code ?? "—" }}"
                       readonly
                       class="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm text-slate-500 bg-slate-50 focus:outline-none cursor-not-allowed"
                       placeholder="—">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Equipment No (SAP)</label>
                <input type="text" value="{{ $equipment->asset?->equipment_no ?? "—" }}"
                       readonly
                       class="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm font-mono text-slate-500 bg-slate-50 focus:outline-none cursor-not-allowed"
                       placeholder="—">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Tech Ident No (SAP)</label>
                <input type="text" value="{{ $equipment->asset?->tech_ident_no ?? "—" }}"
                       readonly
                       class="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm font-mono text-slate-500 bg-slate-50 focus:outline-none cursor-not-allowed"
                       placeholder="—">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Description</label>
                <textarea rows="2" readonly
                          class="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm text-slate-500 bg-slate-50 focus:outline-none cursor-not-allowed resize-none"
                          placeholder="—">{{ $equipment->asset?->description }}</textarea>
            </div>
            <hr class="border-slate-100">
            <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">Data yang bisa diedit</p>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Tipe Lubrikasi</label>
                <input type="text" name="tipe_lubrikasi" value="{{ old('tipe_lubrikasi', $equipment->tipe_lubrikasi) }}"
                       class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-teal-400 focus:border-teal-400"
                       placeholder="—">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Manufacturer</label>
                <input type="text" name="manufacturer" value="{{ old('manufacturer', $equipment->asset?->manufacturer) }}"
                       class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-teal-400 focus:border-teal-400"
                       placeholder="—">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Model Number</label>
                <input type="text" name="model_number" value="{{ old('model_number', $equipment->asset?->model_number) }}"
                       class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-teal-400 focus:border-teal-400"
                       placeholder="—">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Construct Year</label>
                <input type="number" name="construct_year" value="{{ old('construct_year', $equipment->asset?->construct_year) }}"
                       class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-teal-400 focus:border-teal-400"
                       min="1900" max="2099" placeholder="—">
            </div>
            <div class="flex items-center justify-end gap-2 pt-2">
                <button type="button" onclick="document.getElementById('editSpekModal').classList.add('hidden')"
                        class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                    Batal
                </button>
                <button type="submit"
                        class="px-4 py-2 text-white text-sm font-medium rounded-lg transition-colors" style="background: #0E9E8E;">
                    Simpan
                </button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function() {
    function hasNonNullData(data, keys) {
        return data.some(function(d) { return keys.some(function(k) { return d[k] !== null && d[k] !== undefined; }); });
    }

    function filterNullKeys(data, keys) {
        var filtered = [];
        keys.forEach(function(k) {
            if (data.some(function(d) { return d[k] !== null && d[k] !== undefined; })) {
                filtered.push(k);
            }
        });
        return filtered;
    }

    var chartData = @json($chartData);
    if (!chartData || chartData.length === 0) return;

    var labels = chartData.map(function(d) { return d.tanggal; });

    var vibThresholds = @json($vibThresholds);
    var tempThreshold = @json($tempThreshold);

    var colorMap = {
        'ndev_motor': { color: '#0E9E8E', label: 'NDEV Motor' },
        'ndeh_motor': { color: '#F59E0B', label: 'NDEH Motor' },
        'ndea_motor': { color: '#EF4444', label: 'NDEA Motor' },
        'dev_motor':  { color: '#8B5CF6', label: 'DEV Motor' },
        'deh_motor':  { color: '#EC4899', label: 'DEH Motor' },
        'dea_motor':  { color: '#06B6D4', label: 'DEA Motor' },
        'ndev_pompa': { color: '#0E9E8E', label: 'NDEV Mesin' },
        'ndeh_pompa': { color: '#F59E0B', label: 'NDEH Mesin' },
        'ndea_pompa': { color: '#EF4444', label: 'NDEA Mesin' },
        'dev_pompa':  { color: '#8B5CF6', label: 'DEV Mesin' },
        'deh_pompa':  { color: '#EC4899', label: 'DEH Mesin' },
        'dea_pompa':  { color: '#06B6D4', label: 'DEA Mesin' },
        'temp_de_motor':  { color: '#EF4444', label: 'DE Motor' },
        'temp_nde_motor': { color: '#F97316', label: 'NDE Motor' },
        'temp_de_pompa':  { color: '#3B82F6', label: 'DE Mesin' },
        'temp_nde_pompa': { color: '#8B5CF6', label: 'NDE Mesin' },
    };

    function buildDatasets(data, keys, unit) {
        var activeKeys = filterNullKeys(data, keys);
        return activeKeys.map(function(k) {
            var meta = colorMap[k] || { color: '#94A3B8', label: k };
            return {
                label: meta.label,
                data: data.map(function(d) { return d[k]; }),
                borderColor: meta.color,
                backgroundColor: meta.color + '20',
                borderWidth: 2,
                pointRadius: 3,
                pointHoverRadius: 5,
                tension: 0.3,
                fill: false,
                spanGaps: true
            };
        });
    }

    function buildThresholdLine(value, label, color) {
        return {
            label: label,
            data: labels.map(function() { return value; }),
            borderColor: color,
            borderWidth: 1.5,
            borderDash: [6, 4],
            pointRadius: 0,
            pointHoverRadius: 0,
            spanGaps: true,
            fill: false
        };
    }

    function renderChart(canvasId, keys, yLabel, thresholdLines) {
        var canvas = document.getElementById(canvasId);
        if (!canvas) return;
        if (!hasNonNullData(chartData, keys)) return;

        var datasets = buildDatasets(chartData, keys, yLabel);
        if (datasets.length === 0) return;

        if (thresholdLines && thresholdLines.length > 0) {
            thresholdLines.forEach(function(t) {
                datasets.push(buildThresholdLine(t.value, t.label, t.color));
            });
        }

        new Chart(canvas, {
            type: 'line',
            data: { labels: labels, datasets: datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { intersect: false, mode: 'index' },
                plugins: {
                    legend: { display: false },
                    tooltip: { backgroundColor: '#1E293B', titleFont: { size: 12 }, bodyFont: { size: 12 }, padding: 10, cornerRadius: 8 }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { font: { size: 10 }, color: '#94A3B8', maxRotation: 45, maxTicksLimit: 12 } },
                    y: { beginAtZero: true, grid: { color: 'rgba(148,163,184,0.15)' }, ticks: { font: { size: 10 }, color: '#94A3B8' }, title: { display: true, text: yLabel, color: '#94A3B8', font: { size: 11 } } }
                }
            }
    });
    }

    var vibLines = [
        { value: vibThresholds.alarm, label: 'Alarm', color: '#F59E0B' },
        { value: vibThresholds.danger, label: 'Danger', color: '#EF4444' }
    ];
    var tempLines = [
        { value: tempThreshold, label: 'Threshold Temp', color: '#EF4444' }
    ];

    // Chart 1: Vibrasi Motor
    renderChart('vibrationMotorChart', [
        'ndev_motor', 'ndeh_motor', 'ndea_motor',
        'dev_motor', 'deh_motor', 'dea_motor'
    ], 'mm/s', vibLines);

    // Chart 2: Vibrasi Mesin
    renderChart('vibrationPompaChart', [
        'ndev_pompa', 'ndeh_pompa', 'ndea_pompa',
        'dev_pompa', 'deh_pompa', 'dea_pompa'
    ], 'mm/s', vibLines);

    // Chart 3: Temperature
    renderChart('temperatureChart', [
        'temp_de_motor', 'temp_nde_motor',
        'temp_de_pompa', 'temp_nde_pompa'
    ], '°C', tempLines);
})();
</script>
@endpush
