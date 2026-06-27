@extends('layouts.app')

@section('title', 'Detail Laporan')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('reports.index') }}" class="hover:text-slate-700">Laporan</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Detail Laporan #{{ $report->id }}</span>
@endsection

@section('content')
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Info -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Header Laporan -->
            <div class="bg-white rounded-xl border border-slate-200 p-6">
                <div class="flex items-start justify-between flex-wrap gap-3">
                    <div>
                        <p class="text-xs text-slate-500">Kode Laporan</p>
                        <h2 class="text-xl font-semibold text-slate-900 font-mono">{{ $report->report_code ?? '-' }}</h2>
                    </div>
                    <x-status-badge :status="$report->status" />
                </div>
                <div class="mt-4 grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
                    <div>
                        <p class="text-xs text-slate-500">Tanggal</p>
                        <p class="font-medium text-slate-700">{{ $report->report_date->format('d F Y') }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-500">Durasi Pengerjaan</p>
                        <p class="font-medium text-slate-700">
                            @if($report->work_duration_minutes)
                                {{ floor($report->work_duration_minutes / 60) }}j {{ $report->work_duration_minutes % 60 }}m
                            @else
                                -
                            @endif
                        </p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-500">Tipe Laporan</p>
                        <x-status-badge :status="$report->report_type" />
                    </div>
                </div>
            </div>

            <!-- Description -->
            <div class="bg-white rounded-xl border border-slate-200 p-6">
                <div class="flex items-start justify-between mb-4">
                    <div>
                        <h3 class="font-medium text-slate-900">Deskripsi Laporan</h3>
                        <p class="text-xs text-slate-500 mt-1">{{ $report->report_date->format('l, d F Y') }}</p>
                    </div>
                    <x-status-badge :status="$report->status" />
                </div>

                <div class="bg-slate-50 rounded-lg p-4 text-sm text-slate-700 whitespace-pre-wrap">
                    {{ $report->work_description }}
                </div>

                <div class="mt-4 flex items-center gap-4 text-sm">
                    <span class="text-slate-500">
                        <span class="font-medium">Teknisi:</span>
                        {{ $report->technician->name ?? '-' }}
                    </span>
                    <span class="text-slate-500">
                        <span class="font-medium">Tipe:</span>
                        <x-status-badge :status="$report->report_type" />
                    </span>
                    <span class="text-slate-500">
                        <span class="font-medium">Area:</span>
                        {{ $report->area?->code ?? '-' }}
                    </span>
                    @if($report->asset)
                        <span class="text-slate-500">
                            <span class="font-medium">Asset:</span>
                            {{ $report->asset->equipment_no }} - {{ Str::limit($report->asset->description, 30) }}
                        </span>
                    @endif
                </div>
            </div>

            <!-- Root Cause -->
            <div class="bg-white rounded-xl border border-slate-200 p-6">
                <h3 class="font-medium text-slate-900 mb-3">Root Cause</h3>
                <div class="bg-slate-50 rounded-lg p-4 text-sm text-slate-700 whitespace-pre-wrap">{{ $report->root_cause ?: '-' }}</div>
            </div>

            <!-- Asset / Equipment -->
            @if($report->asset_id && $report->asset)
                <div class="bg-white rounded-xl border border-slate-200 p-6">
                    <h3 class="font-medium text-slate-900 mb-3">Asset / Equipment</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                        <div>
                            <p class="text-xs text-slate-500">Equipment No</p>
                            <p class="font-medium text-slate-700">{{ $report->asset->equipment_no ?? '-' }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-500">Technical Identification No</p>
                            <p class="font-medium text-slate-700">{{ $report->asset->tech_ident_no ?? '-' }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-500">Functional Location</p>
                            <p class="font-medium text-slate-700">{{ $report->asset->functional_loc ?? '-' }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-500">Deskripsi Asset</p>
                            <p class="font-medium text-slate-700">{{ $report->asset->description ?? '-' }}</p>
                        </div>
                    </div>
                </div>
            @endif

            <!-- Foto Dokumentasi -->
            <div class="bg-white rounded-xl border border-slate-200 p-6">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="font-medium text-slate-900">Foto Dokumentasi</h3>
                    <span class="text-xs text-slate-400">{{ count($report->photo_documentation_urls) }} foto</span>
                </div>
                @if(count($report->photo_documentation_urls))
                    <div class="grid grid-cols-3 sm:grid-cols-4 gap-3">
                        @foreach($report->photo_documentation_urls as $url)
                            <a href="{{ $url }}" target="_blank" rel="noopener"
                               class="block aspect-square rounded-lg overflow-hidden border border-slate-200 hover:opacity-80 transition-opacity">
                                <img src="{{ $url }}" alt="Foto dokumentasi" class="w-full h-full object-cover">
                            </a>
                        @endforeach
                    </div>
                @else
                    <p class="text-sm text-slate-400">Belum ada foto dokumentasi.</p>
                @endif

                <form method="POST" action="{{ route('reports.add-photo', $report) }}" enctype="multipart/form-data" class="mt-4 flex items-center gap-2">
                    @csrf
                    <input type="hidden" name="type" value="documentation">
                    <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required
                           class="flex-1 text-xs text-slate-600 border border-slate-300 rounded-lg px-3 py-2">
                    <button type="submit" class="px-3 py-2 bg-blue-600 hover:bg-blue-700 text-white text-xs font-medium rounded-lg transition-colors whitespace-nowrap">
                        Tambah Foto
                    </button>
                </form>
                @error('photo')
                    <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                @enderror
            </div>

            <!-- Foto Hygiene Clearance -->
            <div class="bg-white rounded-xl border border-slate-200 p-6">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="font-medium text-slate-900">Foto Hygiene Clearance</h3>
                    <span class="text-xs text-slate-400">{{ count($report->photo_hygiene_urls) }} foto</span>
                </div>
                @if(count($report->photo_hygiene_urls))
                    <div class="grid grid-cols-3 sm:grid-cols-4 gap-3">
                        @foreach($report->photo_hygiene_urls as $url)
                            <a href="{{ $url }}" target="_blank" rel="noopener"
                               class="block aspect-square rounded-lg overflow-hidden border border-slate-200 hover:opacity-80 transition-opacity">
                                <img src="{{ $url }}" alt="Foto hygiene clearance" class="w-full h-full object-cover">
                            </a>
                        @endforeach
                    </div>
                @else
                    <p class="text-sm text-slate-400">Belum ada foto hygiene clearance.</p>
                @endif

                <form method="POST" action="{{ route('reports.add-photo', $report) }}" enctype="multipart/form-data" class="mt-4 flex items-center gap-2">
                    @csrf
                    <input type="hidden" name="type" value="hygiene">
                    <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required
                           class="flex-1 text-xs text-slate-600 border border-slate-300 rounded-lg px-3 py-2">
                    <button type="submit" class="px-3 py-2 bg-blue-600 hover:bg-blue-700 text-white text-xs font-medium rounded-lg transition-colors whitespace-nowrap">
                        Tambah Foto
                    </button>
                </form>
            </div>

            <!-- AI Suggestion -->
            @if($report->ai_analyzed)
                <div class="bg-white rounded-xl border border-slate-200 p-6">
                    <h3 class="font-medium text-slate-900 mb-4">Analisa AI</h3>
                    <div class="mb-4">
                        <div class="flex items-center gap-2 mb-2">
                            <span class="text-sm font-medium text-slate-700">Confidence:</span>
                            <span class="text-lg font-bold {{ $report->ai_confidence >= 70 ? 'text-green-600' : ($report->ai_confidence >= 40 ? 'text-amber-600' : 'text-red-600') }}">
                                {{ $report->ai_confidence }}%
                            </span>
                        </div>
                    </div>

                    @if($report->aiSuggestions->count())
                        <div class="space-y-3">
                            @foreach($report->aiSuggestions as $suggestion)
                                <div class="flex items-start gap-3 p-3 rounded-lg {{ $suggestion->accepted === true ? 'bg-green-50 border border-green-200' : ($suggestion->accepted === false ? 'bg-red-50 border border-red-200' : 'bg-slate-50') }}">
                                    <div class="flex-1">
                                        <p class="text-sm font-medium text-slate-900">
                                            {{ $suggestion->suggestion_type === 'area' ? 'Area: ' . ($suggestion->suggestedArea?->name ?? '-') : 'Asset: ' . ($suggestion->suggestedAsset?->equipment_no ?? '-') }}
                                        </p>
                                        @if($suggestion->reasoning)
                                            <p class="text-xs text-slate-500 mt-1">{{ $suggestion->reasoning }}</p>
                                        @endif
                                        <p class="text-xs text-slate-400 mt-1">Confidence: {{ $suggestion->confidence }}%</p>
                                    </div>
                                    @if($suggestion->accepted === null)
                                        <span class="text-xs text-amber-600 font-medium">Menunggu</span>
                                    @elseif($suggestion->accepted === true)
                                        <span class="text-xs text-green-600 font-medium">Diterima</span>
                                    @else
                                        <span class="text-xs text-red-600 font-medium">Ditolak</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif

            <!-- Timeline -->
            <div class="bg-white rounded-xl border border-slate-200 p-6">
                <h3 class="font-medium text-slate-900 mb-4">Timeline</h3>
                <div class="space-y-4">
                    @if($report->wizard_started_at)
                        <div class="flex items-start gap-3">
                            <div class="w-2 h-2 mt-2 rounded-full bg-slate-400 shrink-0"></div>
                            <div>
                                <p class="text-sm font-medium text-slate-900">Wizard Dimulai</p>
                                <p class="text-xs text-slate-500">{{ $report->wizard_started_at->format('d/m/Y H:i') }}</p>
                            </div>
                        </div>
                    @endif
                    <div class="flex items-start gap-3">
                        <div class="w-2 h-2 mt-2 rounded-full bg-blue-500 shrink-0"></div>
                        <div>
                            <p class="text-sm font-medium text-slate-900">Laporan Disubmit</p>
                            <p class="text-xs text-slate-500">
                                {{ $report->submitted_at?->format('d/m/Y H:i') ?? $report->created_at->format('d/m/Y H:i') }}
                            </p>
                        </div>
                    </div>
                    @if($report->ai_analyzed)
                        <div class="flex items-start gap-3">
                            <div class="w-2 h-2 mt-2 rounded-full bg-violet-500 shrink-0"></div>
                            <div>
                                <p class="text-sm font-medium text-slate-900">Analisa AI Selesai</p>
                                <p class="text-xs text-slate-500">Confidence: {{ $report->ai_confidence }}%</p>
                            </div>
                        </div>
                    @endif
                    @if($report->completed_at)
                        <div class="flex items-start gap-3">
                            <div class="w-2 h-2 mt-2 rounded-full bg-green-500 shrink-0"></div>
                            <div>
                                <p class="text-sm font-medium text-slate-900">Selesai</p>
                                <p class="text-xs text-slate-500">{{ $report->completed_at->format('d/m/Y H:i') }}</p>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <!-- Actions -->
        <div>
            <div class="bg-white rounded-xl border border-slate-200 p-6">
                <h3 class="font-medium text-slate-900 mb-4">Aksi Admin</h3>
                <div class="space-y-3">
                    <form method="POST" action="{{ route('reports.update-status', $report) }}">
                        @csrf
                        <input type="hidden" name="status" value="completed">
                        <button type="submit" class="w-full px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded-lg transition-colors"
                                {{ $report->status === 'completed' ? 'disabled' : '' }}>
                            Tandai Selesai
                        </button>
                    </form>

                    @if($report->status !== 'needs_review')
                        <form method="POST" action="{{ route('reports.update-status', $report) }}">
                            @csrf
                            <input type="hidden" name="status" value="needs_review">
                            <button type="submit" class="w-full px-4 py-2 border border-amber-300 hover:bg-amber-50 text-amber-700 text-sm font-medium rounded-lg transition-colors">
                                Tandai Perlu Review
                            </button>
                        </form>
                    @endif

                    <form method="POST" action="{{ route('reports.destroy', $report) }}"
                          onsubmit="return confirm('Hapus laporan ini?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="w-full px-4 py-2 border border-red-300 hover:bg-red-50 text-red-600 text-sm font-medium rounded-lg transition-colors">
                            Hapus Laporan
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
