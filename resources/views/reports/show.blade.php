@extends('layouts.app')

@section('title', 'Detail Laporan')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('reports.index') }}" class="hover:text-slate-700">Laporan</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">{{ $report->report_code ?? 'Detail Laporan #' . $report->id }}</span>
@endsection

@section('content')
<div
    x-data="reportDetail()"
    x-init="init()"
    class="grid grid-cols-1 lg:grid-cols-3 gap-6"
>
    {{-- ======================================================
         KOLOM KIRI (main content, 2/3 lebar)
    ====================================================== --}}
    <div class="lg:col-span-2 space-y-6">

        {{-- ── Header Laporan ─────────────────────────────── --}}
        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <div class="flex items-start justify-between flex-wrap gap-3">
                <div>
                    <p class="text-xs text-slate-500 mb-0.5">Kode Laporan</p>
                    <h2 class="text-xl font-semibold text-slate-900 font-mono tracking-wide">
                        {{ $report->report_code ?? '-' }}
                    </h2>
                    @if($report->collaborator_of)
                        <div class="mt-2 flex items-center gap-2">
                            <x-status-badge status="collaboration" label="Laporan Kolaborasi" />
                            <a href="{{ route('reports.show', $report->collaborator_of) }}"
                               class="text-xs text-blue-600 hover:text-blue-700 font-medium">
                                Lihat laporan induk
                            </a>
                        </div>
                    @endif
                </div>
                <x-status-badge :status="$report->status" />
            </div>

            {{-- Strip info singkat ─ selalu tampil di kedua mode --}}
            <div class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm">
                {{-- Tanggal: VIEW --}}
                <div x-show="!editMode" class="flex items-center gap-1.5 text-slate-600">
                    <svg class="w-4 h-4 text-slate-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                    <span class="font-medium text-slate-700">{{ $report->report_date->format('d F Y') }}</span>
                </div>

                {{-- Tanggal: EDIT --}}
                <div x-show="editMode" x-cloak>
                    <input type="date" name="report_date" form="form-edit-laporan"
                           value="{{ old('report_date', $report->report_date->format('Y-m-d')) }}"
                           class="border border-slate-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    @error('report_date')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Durasi: VIEW --}}
                <div x-show="!editMode" class="flex items-center gap-1.5 text-slate-600">
                    <svg class="w-4 h-4 text-slate-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span class="font-medium text-slate-700">
                        @if($report->work_duration_minutes)
                            {{ floor($report->work_duration_minutes / 60) }}j {{ $report->work_duration_minutes % 60 }}m
                        @else
                            -
                        @endif
                    </span>
                </div>

                {{-- Durasi: EDIT --}}
                <div x-show="editMode" x-cloak class="flex items-center gap-2">
                    <input type="number" name="work_duration_minutes" form="form-edit-laporan"
                           min="0" placeholder="Durasi (menit)"
                           value="{{ old('work_duration_minutes', $report->work_duration_minutes) }}"
                           class="w-36 border border-slate-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <span class="text-xs text-slate-400">menit</span>
                    @error('work_duration_minutes')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Tipe Laporan: selalu tampil, tidak diedit dari sini --}}
                <div class="flex items-center gap-1.5">
                    <x-status-badge :status="$report->report_type" />
                </div>
            </div>
        </div>

        {{-- ── Informasi Kolaborasi ────────────────────────── --}}
        @if(($report->creator_id && $report->creator_id !== $report->technician_id) || $report->parentReport || $report->collaboratorReports->count())
            <div class="bg-white rounded-xl border border-slate-200 p-6">
                <h3 class="font-medium text-slate-900 mb-3">Informasi Kolaborasi</h3>
                <div class="space-y-4 text-sm">
                    @if($report->creator_id && $report->creator_id !== $report->technician_id)
                        <div>
                            <p class="text-xs text-slate-500">Dibuat oleh</p>
                            <p class="font-medium text-slate-700">{{ $report->creator->name ?? '-' }}</p>
                        </div>
                    @endif

                    @if($report->parentReport)
                        <div>
                            <p class="text-xs text-slate-500">Laporan Induk</p>
                            <a href="{{ route('reports.show', $report->parentReport) }}"
                               class="text-blue-600 hover:text-blue-700 font-medium font-mono text-xs">
                                {{ $report->parentReport->report_code ?? '#' . $report->parentReport->id }}
                            </a>
                        </div>
                    @endif

                    @if($report->collaboratorReports->count())
                        <div>
                            <p class="text-xs text-slate-500 mb-2">
                                Laporan Kolaborator ({{ $report->collaboratorReports->count() }})
                            </p>
                            <div class="space-y-2">
                                @foreach($report->collaboratorReports as $colReport)
                                    <a href="{{ route('reports.show', $colReport) }}"
                                       class="flex items-center justify-between px-3 py-2 rounded-lg border border-slate-200 hover:bg-slate-50 transition-colors">
                                        <span class="text-slate-700 font-medium">
                                            {{ $colReport->technician->name ?? '-' }}
                                        </span>
                                        <span class="text-xs text-slate-400 font-mono">
                                            {{ $colReport->report_code ?? '#' . $colReport->id }}
                                        </span>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        @endif

        {{-- ── Deskripsi + Lokasi / Alat ──────────────────── --}}
        <div class="bg-white rounded-xl border border-slate-200 p-6">
            {{-- Header section --}}
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-medium text-slate-900">Deskripsi Pekerjaan</h3>
                <div class="flex items-center gap-1.5 text-xs text-slate-500">
                    <span>Teknisi:</span>
                    <span class="font-medium text-slate-700">{{ $report->technician->name ?? '-' }}</span>
                    <span class="text-slate-300 mx-1">|</span>
                    <span>Area:</span>
                    <span class="font-medium text-slate-700">{{ $report->area?->code ?? '-' }}</span>
                </div>
            </div>

            {{-- Deskripsi: VIEW --}}
            <div x-show="!editMode" class="bg-slate-50 rounded-lg p-4 text-sm text-slate-700 whitespace-pre-wrap leading-relaxed">{{ $report->work_description }}</div>

            {{-- Deskripsi: EDIT --}}
            <div x-show="editMode" x-cloak>
                <textarea name="work_description" form="form-edit-laporan" rows="6"
                          class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 leading-relaxed">{{ old('work_description', $report->work_description) }}</textarea>
                @error('work_description')
                    <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                @enderror
            </div>

            {{-- Lokasi & Alat: VIEW --}}
            <div x-show="!editMode" class="mt-4 grid grid-cols-2 sm:grid-cols-3 gap-3 text-sm">
                <div>
                    <p class="text-xs text-slate-500">Area</p>
                    <p class="font-medium text-slate-700">
                        {{ $report->area ? $report->area->code . ' - ' . $report->area->name : '-' }}
                    </p>
                </div>
                <div>
                    <p class="text-xs text-slate-500">Functional Location</p>
                    <p class="font-medium text-slate-700">
                        {{ $report->asset?->functional_loc ?? '-' }}
                    </p>
                </div>
                <div>
                    <p class="text-xs text-slate-500">Kode Alat</p>
                    <p class="font-medium text-slate-700">
                        {{ $report->asset?->tech_ident_no ?? $report->asset?->equipment_no ?? '-' }}
                    </p>
                </div>
            </div>

            {{-- Lokasi & Alat: EDIT (dropdown dinamis) --}}
            <div x-show="editMode" x-cloak class="mt-4 space-y-3">
                <p class="text-xs font-medium text-slate-700">Lokasi & Alat</p>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    {{-- Area --}}
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">Area</label>
                        <select name="area_id" form="form-edit-laporan"
                                x-model="areaId"
                                @change="onAreaChange()"
                                class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">- Pilih Area -</option>
                            @foreach($areas as $area)
                                <option value="{{ $area->id }}"
                                    {{ old('area_id', $report->area_id) == $area->id ? 'selected' : '' }}>
                                    {{ $area->code }} - {{ $area->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('area_id')
                            <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Functional Location --}}
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">Functional Location</label>
                        <select name="funcloc_id" form="form-edit-laporan"
                                x-model="funcLocId"
                                @change="onFuncLocChange()"
                                :disabled="!areaId"
                                class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-slate-50 disabled:text-slate-400">
                            <option value="">- Pilih Functional Location -</option>
                            <template x-for="fl in funcLocs" :key="fl.id">
                                <option :value="fl.id" x-text="fl.code + ' - ' + fl.name"></option>
                            </template>
                        </select>
                        @error('funcloc_id')
                            <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Asset --}}
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">Kode Alat</label>
                        <select name="asset_id" form="form-edit-laporan"
                                x-model="assetId"
                                :disabled="!areaId"
                                class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-slate-50 disabled:text-slate-400">
                            <option value="">- Pilih Asset -</option>
                            <template x-for="asset in assets" :key="asset.id">
                                <option :value="asset.id"
                                        x-text="(asset.tech_ident_no || asset.equipment_no || '-') + ' - ' + (asset.description || '')">
                                </option>
                            </template>
                        </select>
                        @error('asset_id')
                            <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
                <p class="text-xs text-slate-400">
                    Pilih Area terlebih dahulu untuk memunculkan pilihan Functional Location dan Asset.
                </p>
            </div>
        </div>

        {{-- ── Root Cause ─────────────────────────────────── --}}
        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <h3 class="font-medium text-slate-900 mb-3">Root Cause</h3>

            {{-- VIEW --}}
            <div x-show="!editMode"
                 class="bg-slate-50 rounded-lg p-4 text-sm text-slate-700 whitespace-pre-wrap leading-relaxed">
                {{ $report->root_cause ?: '-' }}
            </div>

            {{-- EDIT --}}
            <div x-show="editMode" x-cloak>
                <textarea name="root_cause" form="form-edit-laporan" rows="4"
                          class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 leading-relaxed">{{ old('root_cause', $report->root_cause) }}</textarea>
                @error('root_cause')
                    <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                @enderror
            </div>
        </div>

        {{-- ── Asset / Equipment Detail ────────────────────── --}}
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

        {{-- ── Foto Dokumentasi ───────────────────────────── --}}
        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-medium text-slate-900">Foto Dokumentasi</h3>
                <span class="text-xs text-slate-400" x-text="photoGroups.documentation.length + ' foto'"></span>
            </div>

            {{-- Grid foto dengan tombol hapus (muncul di mode edit) --}}
            <template x-if="photoGroups.documentation.length > 0">
                <div class="grid grid-cols-3 sm:grid-cols-4 gap-3 mb-4">
                    <template x-for="(url, idx) in photoGroups.documentation" :key="idx">
                        <div class="relative group">
                            <a :href="url" target="_blank" rel="noopener"
                               class="block aspect-square rounded-lg overflow-hidden border border-slate-200 hover:opacity-80 transition-opacity">
                                <img :src="url" alt="Foto dokumentasi" class="w-full h-full object-cover">
                            </a>
                            {{-- Tombol hapus: hanya muncul di mode edit --}}
                            <button
                                x-show="editMode"
                                x-cloak
                                type="button"
                                @click="deletePhoto('documentation', idx)"
                                :disabled="deleting['documentation_' + idx]"
                                class="absolute top-1 right-1 w-6 h-6 flex items-center justify-center rounded-full bg-red-600 hover:bg-red-700 text-white shadow-md transition-colors disabled:opacity-50"
                                title="Hapus foto ini"
                            >
                                <template x-if="!deleting['documentation_' + idx]">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </template>
                                <template x-if="deleting['documentation_' + idx]">
                                    <svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                                    </svg>
                                </template>
                            </button>
                        </div>
                    </template>
                </div>
            </template>

            <template x-if="photoGroups.documentation.length === 0">
                <p class="text-sm text-slate-400 mb-4">Belum ada foto dokumentasi.</p>
            </template>

            {{-- Pesan error hapus foto --}}
            <p x-show="deleteError.documentation"
               x-text="deleteError.documentation"
               x-cloak
               class="text-xs text-red-600 mb-3"></p>

            {{-- Form tambah foto dokumentasi --}}
            <form method="POST" action="{{ route('reports.add-photo', $report) }}" enctype="multipart/form-data"
                  class="flex items-center gap-2">
                @csrf
                <input type="hidden" name="type" value="documentation">
                <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required
                       class="flex-1 text-xs text-slate-600 border border-slate-300 rounded-lg px-3 py-2">
                <button type="submit"
                        class="px-3 py-2 bg-blue-600 hover:bg-blue-700 text-white text-xs font-medium rounded-lg transition-colors whitespace-nowrap">
                    Tambah Foto
                </button>
            </form>
            @error('photo')
                <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
            @enderror
        </div>

        {{-- ── Foto Hygiene Clearance ──────────────────────── --}}
        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-medium text-slate-900">Foto Hygiene Clearance</h3>
                <span class="text-xs text-slate-400" x-text="photoGroups.hygiene.length + ' foto'"></span>
            </div>

            {{-- Grid foto dengan tombol hapus (muncul di mode edit) --}}
            <template x-if="photoGroups.hygiene.length > 0">
                <div class="grid grid-cols-3 sm:grid-cols-4 gap-3 mb-4">
                    <template x-for="(url, idx) in photoGroups.hygiene" :key="idx">
                        <div class="relative group">
                            <a :href="url" target="_blank" rel="noopener"
                               class="block aspect-square rounded-lg overflow-hidden border border-slate-200 hover:opacity-80 transition-opacity">
                                <img :src="url" alt="Foto hygiene clearance" class="w-full h-full object-cover">
                            </a>
                            {{-- Tombol hapus: hanya muncul di mode edit --}}
                            <button
                                x-show="editMode"
                                x-cloak
                                type="button"
                                @click="deletePhoto('hygiene', idx)"
                                :disabled="deleting['hygiene_' + idx]"
                                class="absolute top-1 right-1 w-6 h-6 flex items-center justify-center rounded-full bg-red-600 hover:bg-red-700 text-white shadow-md transition-colors disabled:opacity-50"
                                title="Hapus foto ini"
            >
                                <template x-if="!deleting['hygiene_' + idx]">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </template>
                                <template x-if="deleting['hygiene_' + idx]">
                                    <svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                                    </svg>
                                </template>
                            </button>
                        </div>
                    </template>
                </div>
            </template>

            <template x-if="photoGroups.hygiene.length === 0">
                <p class="text-sm text-slate-400 mb-4">Belum ada foto hygiene clearance.</p>
            </template>

            {{-- Pesan error hapus foto --}}
            <p x-show="deleteError.hygiene"
               x-text="deleteError.hygiene"
               x-cloak
               class="text-xs text-red-600 mb-3"></p>

            {{-- Form tambah foto hygiene --}}
            <form method="POST" action="{{ route('reports.add-photo', $report) }}" enctype="multipart/form-data"
                  class="flex items-center gap-2">
                @csrf
                <input type="hidden" name="type" value="hygiene">
                <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required
                       class="flex-1 text-xs text-slate-600 border border-slate-300 rounded-lg px-3 py-2">
                <button type="submit"
                        class="px-3 py-2 bg-blue-600 hover:bg-blue-700 text-white text-xs font-medium rounded-lg transition-colors whitespace-nowrap">
                    Tambah Foto
                </button>
            </form>
        </div>

        {{-- ── Analisa AI ──────────────────────────────────── --}}
        @if($report->is_manually_edited)
            <div class="bg-white rounded-xl border border-slate-200 p-6">
                <h3 class="font-medium text-slate-900 mb-3">Analisa AI</h3>
                <div class="flex items-center gap-2">
                    <x-status-badge status="edited" label="Edited" />
                    <p class="text-sm text-slate-500">Laporan ini telah diedit secara manual.</p>
                </div>
            </div>
        @elseif($report->ai_analyzed)
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
                            <div class="flex items-start gap-3 p-3 rounded-lg
                                {{ $suggestion->accepted === true
                                    ? 'bg-green-50 border border-green-200'
                                    : ($suggestion->accepted === false
                                        ? 'bg-red-50 border border-red-200'
                                        : 'bg-slate-50') }}">
                                <div class="flex-1">
                                    <p class="text-sm font-medium text-slate-900">
                                        {{ $suggestion->suggestion_type === 'area'
                                            ? 'Area: ' . ($suggestion->suggestedArea?->name ?? '-')
                                            : 'Asset: ' . ($suggestion->suggestedAsset?->equipment_no ?? '-') }}
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

        {{-- ── Timeline ────────────────────────────────────── --}}
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

    </div>{{-- /lg:col-span-2 --}}

    {{-- ======================================================
         KOLOM KANAN (sidebar aksi)
    ====================================================== --}}
    <div class="space-y-6">

        {{-- ── Form tersembunyi: target semua input edit --}}
        {{--
            Form ini kosong — semua input di kolom kiri menggunakan atribut
            form="form-edit-laporan" sehingga tetap masuk ke submission form
            ini meskipun berada di luar elemen <form>.
        --}}
        <form id="form-edit-laporan"
              method="POST"
              action="{{ route('reports.update', $report) }}"
              class="hidden">
            @csrf
            @method('PUT')
        </form>

        {{-- ── Panel Aksi Admin ────────────────────────────── --}}
        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <h3 class="font-medium text-slate-900 mb-4">Aksi Admin</h3>

            {{-- Tombol MODE VIEW --}}
            <div x-show="!editMode" class="space-y-3">
                <button type="button"
                        @click="enableEditMode()"
                        class="w-full inline-flex items-center justify-center gap-2 px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.172-8.172z" />
                    </svg>
                    Edit Laporan
                </button>

                <form method="POST" action="{{ route('reports.update-status', $report) }}">
                    @csrf
                    <input type="hidden" name="status" value="completed">
                    <button type="submit"
                            {{ $report->status === 'completed' ? 'disabled' : '' }}
                            class="w-full px-4 py-2 bg-green-600 hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm font-medium rounded-lg transition-colors">
                        Tandai Selesai
                    </button>
                </form>

                @if($report->status !== 'needs_review')
                    <form method="POST" action="{{ route('reports.update-status', $report) }}">
                        @csrf
                        <input type="hidden" name="status" value="needs_review">
                        <button type="submit"
                                class="w-full px-4 py-2 border border-amber-300 hover:bg-amber-50 text-amber-700 text-sm font-medium rounded-lg transition-colors">
                            Tandai Perlu Review
                        </button>
                    </form>
                @endif

                <form method="POST" action="{{ route('reports.destroy', $report) }}"
                      onsubmit="return confirm('Hapus laporan ini? Tindakan tidak dapat dibatalkan.')">
                    @csrf
                    @method('DELETE')
                    <button type="submit"
                            class="w-full px-4 py-2 border border-red-300 hover:bg-red-50 text-red-600 text-sm font-medium rounded-lg transition-colors">
                        Hapus Laporan
                    </button>
                </form>
            </div>

            {{-- Tombol MODE EDIT --}}
            <div x-show="editMode" x-cloak class="space-y-3">
                <button type="submit"
                        form="form-edit-laporan"
                        class="w-full inline-flex items-center justify-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                    </svg>
                    Simpan Perubahan
                </button>

                <button type="button"
                        @click="cancelEditMode()"
                        class="w-full px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                    Batal
                </button>

                <p class="text-xs text-slate-400 text-center pt-1">
                    Tombol hapus foto aktif di mode ini
                </p>
            </div>
        </div>

        {{-- ── Info Ringkas Laporan (sidebar) ─────────────── --}}
        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <h3 class="font-medium text-slate-900 mb-4">Info Laporan</h3>
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between gap-2">
                    <dt class="text-slate-500 shrink-0">Teknisi</dt>
                    <dd class="font-medium text-slate-700 text-right">{{ $report->technician->name ?? '-' }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt class="text-slate-500 shrink-0">Area</dt>
                    <dd class="font-medium text-slate-700 text-right">{{ $report->area?->code ?? '-' }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt class="text-slate-500 shrink-0">Kode Alat</dt>
                    <dd class="font-medium text-slate-700 text-right font-mono text-xs">
                        {{ $report->asset?->tech_ident_no ?? $report->asset?->equipment_no ?? '-' }}
                    </dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt class="text-slate-500 shrink-0">Foto Dok.</dt>
                    <dd class="font-medium text-slate-700" x-text="photoGroups.documentation.length + ' foto'"></dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt class="text-slate-500 shrink-0">Foto Hygiene</dt>
                    <dd class="font-medium text-slate-700" x-text="photoGroups.hygiene.length + ' foto'"></dd>
                </div>
                @if($report->created_at)
                    <div class="flex justify-between gap-2">
                        <dt class="text-slate-500 shrink-0">Dibuat</dt>
                        <dd class="font-medium text-slate-700 text-right">{{ $report->created_at->format('d/m/Y') }}</dd>
                    </div>
                @endif
            </dl>
        </div>

    </div>{{-- /sidebar --}}

</div>{{-- /grid --}}
@endsection

@push('scripts')
<script>
function reportDetail() {
    return {
        // ── State utama ───────────────────────────────────────
        editMode: {{ $errors->any() ? 'true' : 'false' }},

        // Data foto disimpan di sini agar bisa dimanipulasi tanpa reload
        photoGroups: {
            documentation: @json($report->photo_documentation_urls ?? []),
            hygiene: @json($report->photo_hygiene_urls ?? []),
        },

        // State loading per foto — key: "{type}_{index}"
        deleting: {},

        // Pesan error hapus per tipe
        deleteError: {
            documentation: '',
            hygiene: '',
        },

        // ── State dropdown lokasi (sama dengan edit.blade.php) ─
        areaId: {{ old('area_id', $report->area_id) ?? 'null' }},
        funcLocId: {{ old('funcloc_id', $report->funcloc_id) ?? 'null' }},
        assetId: {{ old('asset_id', $report->asset_id) ?? 'null' }},
        funcLocs: @json($funcLocs),
        assets: @json($assets),

        // ── Inisialisasi ──────────────────────────────────────
        init() {
            // Jika ada validation error dari server, langsung masuk mode edit
            // supaya pesan error terlihat tanpa harus klik tombol Edit dulu.
            // Nilai editMode sudah di-set di atas saat render.
        },

        // ── Toggle mode ───────────────────────────────────────

        /**
         * Aktifkan mode edit.
         */
        enableEditMode() {
            this.editMode = true;
        },

        /**
         * Batalkan mode edit — kembali ke mode view tanpa menyimpan.
         */
        cancelEditMode() {
            this.editMode = false;
            this.deleteError.documentation = '';
            this.deleteError.hygiene = '';
        },

        // ── Hapus foto ────────────────────────────────────────

        /**
         * Hapus satu foto via fetch ke endpoint DELETE.
         * Setelah berhasil, elemen dihapus dari array lokal tanpa reload.
         *
         * @param {string} type  'documentation' atau 'hygiene'
         * @param {number} index Index dalam array photoGroups[type]
         */
        async deletePhoto(type, index) {
            const key = type + '_' + index;
            this.deleting[key] = true;
            this.deleteError[type] = '';

            // Susun URL endpoint DELETE foto dari Sesi 1
            const url = '{{ route('reports.photos.delete', [$report, '__INDEX__']) }}'
                .replace('__INDEX__', index)
                + '?type=' + type;

            try {
                const response = await fetch(url, {
                    method: 'DELETE',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                });

                const data = await response.json();

                if (response.ok && data.success) {
                    // Hapus elemen dari array lokal — DOM diperbarui otomatis oleh Alpine
                    this.photoGroups[type].splice(index, 1);
                } else {
                    this.deleteError[type] = data.message ?? 'Gagal menghapus foto. Coba lagi.';
                }
            } catch {
                this.deleteError[type] = 'Terjadi kesalahan jaringan. Coba lagi.';
            } finally {
                this.deleting[key] = false;
            }
        },

        // ── Dropdown lokasi (port dari edit.blade.php) ────────

        /**
         * Dipanggil saat Area berubah.
         * Reset funcLoc dan asset, lalu fetch data baru berdasarkan area terpilih.
         */
        onAreaChange() {
            this.funcLocId = null;
            this.assetId = null;
            this.funcLocs = [];
            this.assets = [];

            if (!this.areaId) {
                return;
            }

            fetch(`{{ route('reports.locations.funclocs') }}?area_id=${this.areaId}`, {
                headers: { 'Accept': 'application/json' },
            })
                .then(res => res.json())
                .then(data => { this.funcLocs = data; });

            fetch(`{{ route('reports.locations.assets') }}?area_id=${this.areaId}`, {
                headers: { 'Accept': 'application/json' },
            })
                .then(res => res.json())
                .then(data => { this.assets = data; });
        },

        /**
         * Dipanggil saat Functional Location berubah.
         * Filter ulang daftar asset berdasarkan area + funcloc terpilih.
         */
        onFuncLocChange() {
            this.assetId = null;

            if (!this.areaId) {
                this.assets = [];
                return;
            }

            let url = `{{ route('reports.locations.assets') }}?area_id=${this.areaId}`;
            if (this.funcLocId) {
                url += `&funcloc_id=${this.funcLocId}`;
            }

            fetch(url, {
                headers: { 'Accept': 'application/json' },
            })
                .then(res => res.json())
                .then(data => { this.assets = data; });
        },
    };
}
</script>
@endpush
