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

                    {{-- Rantai pekerjaan lanjutan: laporan 'continue' berhari-hari --}}
                    @if($report->continuedFrom || $report->continuedBy->isNotEmpty())
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            @if($report->continuedFrom)
                                <span class="inline-flex px-2 py-0.5 rounded-md text-xs font-medium bg-amber-100 text-amber-700 border border-amber-200">
                                    Lanjutan dari
                                </span>
                                <a href="{{ route('reports.show', $report->continuedFrom) }}"
                                   class="text-xs font-medium font-mono text-blue-600 hover:text-blue-700">
                                    {{ $report->continuedFrom->report_code ?? ('#' . $report->continuedFrom->id) }}
                                </a>
                                <span class="text-xs text-slate-400">
                                    ({{ $report->continuedFrom->report_date?->format('d/m/Y') ?? '-' }})
                                </span>
                            @endif

                            @foreach($report->continuedBy as $lanjutan)
                                <span class="inline-flex px-2 py-0.5 rounded-md text-xs font-medium bg-teal-50 text-teal-700 border border-teal-100">
                                    Dilanjutkan oleh
                                </span>
                                <a href="{{ route('reports.show', $lanjutan) }}"
                                   class="text-xs font-medium font-mono text-blue-600 hover:text-blue-700">
                                    {{ $lanjutan->report_code ?? ('#' . $lanjutan->id) }}
                                </a>
                                <span class="text-xs text-slate-400">
                                    ({{ $lanjutan->report_date?->format('d/m/Y') ?? '-' }})
                                </span>
                            @endforeach
                        </div>
                    @endif
                    {{-- Bagian dari pekerjaan multi alat/lokasi --}}
                    @if($report->multiOf)
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <span class="inline-flex px-2 py-0.5 rounded-md text-xs font-medium bg-teal-50 text-teal-700 border border-teal-100">
                                Bagian dari pekerjaan bersama
                            </span>
                            <a href="{{ route('reports.show', $report->multiOf) }}"
                               class="text-xs font-medium font-mono text-blue-600 hover:text-blue-700">
                                {{ $report->multiOf->report_code ?? ('#' . $report->multiOf->id) }}
                            </a>
                        </div>
                    @endif

                    {{-- Daftar alat/lokasi lain pada pekerjaan ini --}}
                    @if($report->multiItems->isNotEmpty())
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <span class="inline-flex px-2 py-0.5 rounded-md text-xs font-medium bg-teal-50 text-teal-700 border border-teal-100">
                                Mencakup {{ $report->multiItems->count() }} alat/lokasi lain
                            </span>
                            @foreach($report->multiItems as $item)
                                <a href="{{ $item->asset
                                        ? route('assets.show', $item->asset)
                                        : ($item->functionalLocation
                                            ? route('locations.show', $item->functionalLocation)
                                            : route('reports.show', $report)) }}"
                                   title="{{ $item->asset ? 'Detail equipment' : 'Detail lokasi' }}"
                                   class="text-xs font-medium font-mono text-teal-700 hover:underline">
                                    {{ $item->asset?->tech_ident_no ?? $item->functionalLocation?->code ?? ('#' . $item->id) }}
                                </a>
                            @endforeach
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
                    {{-- Rentang jam kerja ditampilkan bersama durasi supaya mode
                         lihat dan mode edit memakai keterangan yang sama
                         (lihat partial _waktu-kerja pada mode edit). --}}
                    @if($report->time_range)
                        <span class="font-medium text-slate-700">{{ $report->time_range }}</span>
                        <span class="text-slate-300">&middot;</span>
                    @endif


                    <span class="font-medium text-slate-700">
                        @if($report->work_duration_minutes)
                            {{ $report->duration_label ?? '-' }}
                        @else
                            -
                        @endif
                    </span>
                </div>

                {{-- Jam kerja (mulai/selesai) diisi di kartu "Waktu Pengerjaan"
                     pada kolom kiri, memakai partial _waktu-kerja. --}}

                {{-- Tipe Laporan: selalu tampil, tidak diedit dari sini --}}
                <div class="flex items-center gap-1.5">
                    <x-status-badge :status="$report->report_type" />
                </div>

                {{-- Klasifikasi Pekerjaan (ZPM): selalu tampil, diubah lewat
                     kartu "Pengaturan Laporan" pada mode edit. --}}
                <div class="flex items-center gap-1.5">
                    @if($report->work_classification)
                        <x-status-badge :status="$report->work_classification"
                            :label="['zpm1' => 'ZPM 1', 'zpm3' => 'ZPM 3', 'zpm6' => 'ZPM 6', 'zpm5' => 'ZPM 5', 'zpm7' => 'ZPM 7', 'zpm8' => 'ZPM 8'][$report->work_classification] ?? null" />
                    @else
                        <span class="text-xs text-slate-400">Klasifikasi: -</span>
                    @endif
                </div>

                {{-- Jenis Pekerjaan --}}
                @if($report->jenis_pekerjaan)
                    <div class="flex items-center gap-1.5">
                        @php
                            $jenisColors = [
                                'CM'        => 'bg-red-100 text-red-700 border-red-200',
                                'dCM'       => 'bg-amber-100 text-amber-700 border-amber-200',
                                'PM'        => 'bg-blue-100 text-blue-700 border-blue-200',
                                'EI'        => 'bg-cyan-100 text-cyan-700 border-cyan-200',
                                'Mekanik'   => 'bg-slate-100 text-slate-700 border-slate-200',
                                'CM/PM'     => 'bg-emerald-100 text-emerald-700 border-emerald-200',
                                'Fabrikasi' => 'bg-violet-100 text-violet-700 border-violet-200',
                                'Sipil'     => 'bg-orange-100 text-orange-700 border-orange-200',
                                'IT'        => 'bg-indigo-100 text-indigo-700 border-indigo-200',
                                'Project'   => 'bg-rose-100 text-rose-700 border-rose-200',
                            ];
                            $jColor = $jenisColors[$report->jenis_pekerjaan] ?? 'bg-slate-100 text-slate-400';
                        @endphp
                        <span class="inline-flex px-2.5 py-0.5 text-xs font-medium rounded-full border {{ $jColor }}">
                            {{ $report->jenis_pekerjaan }}
                        </span>
                    </div>
                @endif
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
                        @if($report->functionalLocation)
                            <a href="{{ route('locations.show', $report->functionalLocation) }}"
                               class="text-teal-700 hover:underline">{{ $report->functionalLocation->code }}</a>
                        @elseif($report->area)
                            <a href="{{ route('assets.index', ['area_id' => $report->area->id]) }}"
                               class="text-slate-700 hover:text-teal-700 hover:underline">{{ $report->area->code }} - {{ $report->area->name }}</a>
                        @else
                            -
                        @endif
                    </p>
                </div>
                <div>
                    <p class="text-xs text-slate-500">Functional Location</p>
                    <p class="font-medium text-slate-700">
                        @if($report->functionalLocation)
                            <a href="{{ route('locations.show', $report->functionalLocation) }}"
                               class="font-mono text-teal-700 hover:underline">{{ $report->functionalLocation->code }}</a>
                        @elseif($report->asset?->functional_loc)
                            <span class="font-mono">{{ $report->asset->functional_loc }}</span>
                        @else
                            -
                        @endif
                    </p>
                </div>
                <div>
                    <p class="text-xs text-slate-500">Kode Alat</p>
                    <p class="font-medium text-slate-700">
                        @if($report->asset)
                            <a href="{{ route('assets.show', $report->asset) }}"
                               class="font-mono text-teal-700 hover:underline">{{ $report->asset->tech_ident_no ?? $report->asset->equipment_no ?? ('#' . $report->asset->id) }}</a>
                        @elseif($report->equipment_tag)
                            <span class="font-mono">{{ $report->equipment_tag }}</span>
                        @else
                            -
                        @endif
                    </p>
                </div>
            </div>

            {{-- Lokasi & Alat: EDIT — satu ringkasan pilihan + pemilih hierarki.
                 Kolom Area / Functional Location / Kode Alat yang lama tetap ada
                 di form (sumber nilai yang dikirim) tetapi disembunyikan supaya
                 tidak ada dua tampilan pilihan yang sama-sama bisa diubah. --}}
            <div x-show="editMode" x-cloak class="mt-4 space-y-3">
                {{-- Ringkasan lokasi & alat yang tersimpan: satu-satunya tampilan
                     pilihan lokasi pada mode edit. --}}
                <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                    <p class="text-xs font-medium text-slate-700 mb-1">Lokasi &amp; Alat Terpilih</p>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 text-sm">
                        <div>
                            <p class="text-xs text-slate-400">Area</p>
                            <p class="font-medium text-slate-700" x-text="namaArea(areaId)"></p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-400">Functional Location</p>
                            <p class="font-medium text-slate-700" x-text="namaFuncLoc(funcLocId)"></p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-400">Kode Alat</p>
                            <p class="font-medium text-slate-700" x-text="namaAlat(assetId)"></p>
                        </div>
                    </div>

                    @error('area_id')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                    @error('funcloc_id')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                    @error('asset_id')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>
                {{-- Kolom asli form (disembunyikan): nilai Area / Functional
                     Location / Kode Alat yang dikirim saat menyimpan. --}}
                <div class="hidden grid grid-cols-1 sm:grid-cols-3 gap-3">
                    {{-- Area --}}
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">Area</label>
                        <select id="area_id" name="area_id" form="form-edit-laporan"
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
                    </div>

                    {{-- Functional Location --}}
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">Functional Location</label>
                        <select id="funcloc_id" name="funcloc_id" form="form-edit-laporan"
                                x-model="funcLocId"
                                @change="onFuncLocChange()"
                                :disabled="!areaId"
                                class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-slate-50 disabled:text-slate-400">
                            <option value="">- Pilih Functional Location -</option>
                            <template x-for="fl in funcLocs" :key="fl.id">
                                <option :value="fl.id" x-text="fl.code + ' - ' + fl.name"></option>
                            </template>
                        </select>
                    </div>

                    {{-- Asset --}}
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">Kode Alat</label>
                        <select id="asset_id" name="asset_id" form="form-edit-laporan"
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
                    </div>
                </div>
                <p class="text-xs text-slate-400">
                    Pilih alat atau lokasi lewat penelusuran hierarki di bawah, atau pakai kotak pencarian alat bila kodenya sudah diketahui.
                </p>

                @include('reports.partials._cari-alat', ['report' => $report])
                {{-- Pemilih lokasi bertingkat: PT -> Departemen -> Area -> Section
                     -> Alat, meniru alur wizard Telegram. Memilih node/alat
                     mengisi Area + Functional Location + Kode Alat di atas. --}}
                @include('reports.partials._pilih-lokasi-hirarki', ['report' => $report])

                {{-- Alat/lokasi tambahan ditempatkan TEPAT di bawah alat/lokasi
                     utama supaya pekerjaan multi item terbaca satu tempat dengan
                     lokasinya (dulu jauh di bawah, setelah Kolaborasi Teknisi). --}}
                @include('reports.partials._alat-tambahan', [
                    'report'       => $report,
                    'laporanInduk' => $laporanInduk,
                    'alatTambahan' => $alatTambahan,
                    'funcLocs'     => $funcLocs,
                ])


            </div>
        </div>

        {{-- Temuan lebih dulu daripada Root Cause, mengikuti urutan wizard (Step 5a lalu 5b). --}}
        @include('reports.partials._temuan-kartu', ['report' => $report])


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

        {{-- Temuan sudah ditampilkan di atas kartu Root Cause. --}}

        {{-- ── Pengaturan Laporan (mode edit) ──────────────── --}}
        <div x-show="editMode" x-cloak class="bg-white rounded-xl border border-slate-200 p-6">
            <h3 class="font-medium text-slate-900 mb-4">Pengaturan Laporan</h3>

            @include('reports.partials._pelengkap', ['report' => $report])

            {{-- Jam mulai/selesai: durasi man hour dihitung server dari rentang
                 jam ini (lihat Report::durasiDariJam()). --}}
            @include('reports.partials._waktu-kerja', ['report' => $report])
        </div>

        {{-- ── Kolaborasi Teknisi (mode edit) ─────────────── --}}
        <div x-show="editMode" x-cloak class="bg-white rounded-xl border border-slate-200 p-6">
            @include('reports.partials._kolaborasi', [
                'report'         => $report,
                'laporanInduk'   => $laporanInduk,
                'kolaborator'    => $kolaborator,
                'teknisiPilihan' => $teknisiPilihan,
            ])
        </div>

        {{-- ── Detail CM / Pekerjaan ──────────────────────── --}}
        @if($report->jenis_pekerjaan || $report->tanggal_kejadian || $report->linkedFinding || $report->equipment_tag)
            <div class="bg-white rounded-xl border border-slate-200 p-6">
                <h3 class="font-medium text-slate-900 mb-3">Detail Pekerjaan</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                    @if($report->jenis_pekerjaan)
                        <div>
                            <p class="text-xs text-slate-500">Jenis Pekerjaan</p>
                            @php
                                $jenisColors = [
                                    'CM'        => 'bg-red-100 text-red-700 border-red-200',
                                    'dCM'       => 'bg-amber-100 text-amber-700 border-amber-200',
                                    'PM'        => 'bg-blue-100 text-blue-700 border-blue-200',
                                    'EI'        => 'bg-cyan-100 text-cyan-700 border-cyan-200',
                                    'Mekanik'   => 'bg-slate-100 text-slate-700 border-slate-200',
                                    'CM/PM'     => 'bg-emerald-100 text-emerald-700 border-emerald-200',
                                    'Fabrikasi' => 'bg-violet-100 text-violet-700 border-violet-200',
                                    'Sipil'     => 'bg-orange-100 text-orange-700 border-orange-200',
                                    'IT'        => 'bg-indigo-100 text-indigo-700 border-indigo-200',
                                    'Project'   => 'bg-rose-100 text-rose-700 border-rose-200',
                                ];
                                $jColor = $jenisColors[$report->jenis_pekerjaan] ?? 'bg-slate-100 text-slate-400';
                            @endphp
                            <span class="inline-flex px-2.5 py-0.5 text-xs font-medium rounded-full border {{ $jColor }}">
                                {{ $report->jenis_pekerjaan }}
                            </span>
                        </div>
                    @endif
                    @if($report->tanggal_kejadian)
                        <div>
                            <p class="text-xs text-slate-500">Tanggal Kejadian</p>
                            <p class="font-medium text-slate-700">{{ $report->tanggal_kejadian->format('d/m/Y') }}</p>
                        </div>
                    @endif
                    @if($report->sesuai_rencana)
                        <div>
                            <p class="text-xs text-slate-500">Sesuai Rencana</p>
                            @if($report->sesuai_rencana === 'ya')
                                <span class="inline-flex px-2 py-0.5 text-xs font-medium rounded-full bg-green-100 text-green-700">Ya</span>
                            @else
                                <span class="inline-flex px-2 py-0.5 text-xs font-medium rounded-full bg-red-100 text-red-700">Tidak</span>
                            @endif
                        </div>
                    @endif
                    @if($report->equipment_tag)
                        <div>
                            <p class="text-xs text-slate-500">Equipment Tag</p>
                            <p class="font-medium text-slate-700 font-mono">{{ $report->equipment_tag }}</p>
                        </div>
                    @endif
                    @if($report->linkedFinding)
                        <div>
                            <p class="text-xs text-slate-500">Linked Finding</p>
                            <p class="font-medium text-slate-700 font-mono">{{ $report->linkedFinding->kode_finding ?? '#' . $report->linked_finding_id }}</p>
                            @if($report->linkedFinding->kategori)
                                <p class="text-xs text-slate-400">{{ $report->linkedFinding->kategori }}</p>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        @endif

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

        {{-- ── Foto Laporan ───────────────────────────────── --}}
        <div class="bg-white rounded-xl border border-slate-200 p-6" id="bagian_foto">
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-medium text-slate-900">Foto Laporan</h3>
                <span class="flex items-center gap-2 text-xs text-slate-400"><button type="button" @click="galeriBuka('documentation', 0)" class="text-teal-700 hover:underline font-medium">Lihat semua</button><span x-text="photoGroups.documentation.length + ' foto'"></span></span>
            </div>

            @if($laporanInduk->id !== $report->id)
                <p class="text-xs text-amber-600 mb-3">
                    Laporan ini baris salinan. Foto dikelola dari halaman detail laporan induk
                    <a href="{{ route('reports.show', $laporanInduk) }}"
                       class="font-medium text-blue-600 hover:underline">{{ $laporanInduk->report_code ?? ('#' . $laporanInduk->id) }}</a>
                    karena baris salinan memakai berkas foto yang sama.
                </p>
            @endif

            <p class="text-xs font-medium text-slate-700 mb-2">Foto Dokumentasi</p>

            {{-- Grid foto dengan tombol hapus (muncul di mode edit) --}}
            <template x-if="photoGroups.documentation.length > 0">
                <div class="grid grid-cols-3 sm:grid-cols-4 gap-3 mb-4">
                    <template x-for="(url, idx) in photoGroups.documentation" :key="idx">
                        <div class="relative group">
                            <a :href="url" target="_blank" rel="noopener"
                               class="block aspect-square rounded-lg overflow-hidden border border-slate-200 hover:opacity-80 transition-opacity">
                                <img :src="url" alt="Foto dokumentasi" @click.prevent="galeriBuka('documentation', idx)" class="w-full h-full object-cover">
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

            @if($laporanInduk->id === $report->id)
                {{-- Form tambah foto dokumentasi: boleh banyak berkas sekaligus --}}
                <form method="POST" action="{{ route('reports.add-photo', $report) }}" enctype="multipart/form-data"
                      @submit.prevent="unggahFoto('documentation', $event)"
                      class="flex flex-wrap items-center gap-2">
                    @csrf
                    <input type="hidden" name="type" value="documentation">
                    <input type="file" id="foto_berkas" name="photos[]" multiple
                           accept="image/jpeg,image/png,image/webp" required
                           class="flex-1 min-w-[14rem] text-xs text-slate-600 border border-slate-300 rounded-lg px-3 py-2">
                    <button type="submit" id="foto_unggah"
                            :disabled="uploading.documentation"
                            class="px-3 py-2 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white text-xs font-medium rounded-lg transition-colors whitespace-nowrap">
                        Tambah Foto
                    </button>
                </form>
                <p x-show="uploadError.documentation" x-text="uploadError.documentation" x-cloak
                   class="text-xs text-red-600 mt-1"></p>
                @error('photos')
                    <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                @enderror
                @error('photos.*')
                    <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                @enderror
            @endif
        </div>

        {{-- ── Foto Hygiene Clearance ──────────────────────── --}}
        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-medium text-slate-900">Foto Hygiene Clearance</h3>
                <span class="flex items-center gap-2 text-xs text-slate-400"><button type="button" @click="galeriBuka('hygiene', 0)" class="text-teal-700 hover:underline font-medium">Lihat semua</button><span x-text="photoGroups.hygiene.length + ' foto'"></span></span>
            </div>

            {{-- Grid foto dengan tombol hapus (muncul di mode edit) --}}
            <template x-if="photoGroups.hygiene.length > 0">
                <div class="grid grid-cols-3 sm:grid-cols-4 gap-3 mb-4">
                    <template x-for="(url, idx) in photoGroups.hygiene" :key="idx">
                        <div class="relative group">
                            <a :href="url" target="_blank" rel="noopener"
                               class="block aspect-square rounded-lg overflow-hidden border border-slate-200 hover:opacity-80 transition-opacity">
                                <img :src="url" alt="Foto hygiene clearance" @click.prevent="galeriBuka('hygiene', idx)" class="w-full h-full object-cover">
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

            @if($laporanInduk->id === $report->id)
                {{-- Form tambah foto hygiene: boleh banyak berkas sekaligus --}}
                <form method="POST" action="{{ route('reports.add-photo', $report) }}" enctype="multipart/form-data"
                      @submit.prevent="unggahFoto('hygiene', $event)"
                      class="flex flex-wrap items-center gap-2">
                    @csrf
                    <input type="hidden" name="type" value="hygiene">
                    <input type="file" id="foto_berkas_hygiene" name="photos[]" multiple
                           accept="image/jpeg,image/png,image/webp" required
                           class="flex-1 min-w-[14rem] text-xs text-slate-600 border border-slate-300 rounded-lg px-3 py-2">
                    <button type="submit" id="foto_unggah_hygiene"
                            :disabled="uploading.hygiene"
                            class="px-3 py-2 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white text-xs font-medium rounded-lg transition-colors whitespace-nowrap">
                        Tambah Foto
                    </button>
                </form>
                <p x-show="uploadError.hygiene" x-text="uploadError.hygiene" x-cloak
                   class="text-xs text-red-600 mt-1"></p>
            @endif
        </div>

        {{-- Tombol popup Analisa AI & Timeline --}}
        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <h3 class="font-medium text-slate-900 mb-1">Analisa AI dan Timeline</h3>
            <p class="text-xs text-slate-500 mb-4">
                Detail analisa AI dan riwayat laporan dibuka lewat tombol berikut supaya halaman tetap ringkas.
            </p>
            <div class="flex flex-wrap gap-3">
                <button type="button" @click="bukaPopup('analisa')"
                        class="inline-flex items-center gap-2 px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
                    </svg>
                    Analisa AI
                </button>
                <button type="button" @click="bukaPopup('timeline')"
                        class="inline-flex items-center gap-2 px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Timeline
                </button>
            </div>
        </div>

        {{-- Popup Analisa AI: kartu Analisa AI lama dipindah ke body lewat x-teleport --}}
        <template x-teleport="body">
            <div x-show="popup === 'analisa'" x-cloak
                 @click.self="tutupPopup()"
                 @keydown.escape.window="tutupPopup()"
                 class="fixed inset-0 z-50 overflow-y-auto bg-black/50 p-4">
                <div class="mx-auto w-full max-w-2xl my-8 space-y-3">
                    <div class="flex justify-end">
                        <button type="button" @click="tutupPopup()"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 text-xs font-medium rounded-lg transition-colors">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                            Tutup
                        </button>
                    </div>
                    @if(!$report->ai_analyzed && !$report->is_manually_edited)
                        <div class="bg-white rounded-xl border border-slate-200 p-6 text-sm text-slate-500">
                            Belum ada analisa AI untuk laporan ini.
                        </div>
                    @endif

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

                </div>
            </div>
        </template>

        {{-- Popup Timeline: kartu Timeline lama dipindah ke body lewat x-teleport --}}
        <template x-teleport="body">
            <div x-show="popup === 'timeline'" x-cloak
                 @click.self="tutupPopup()"
                 @keydown.escape.window="tutupPopup()"
                 class="fixed inset-0 z-50 overflow-y-auto bg-black/50 p-4">
                <div class="mx-auto w-full max-w-2xl my-8 space-y-3">
                    <div class="flex justify-end">
                        <button type="button" @click="tutupPopup()"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 text-xs font-medium rounded-lg transition-colors">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                            Tutup
                        </button>
                    </div>

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
                </div>
            </div>
        </template>


        {{-- Popup galeri foto: markup-nya di-include SEKALI di sini (masih di
             dalam komponen Alpine reportDetail) supaya tidak tampil berganda.
             Tombol hapus di dalamnya hanya dirender untuk laporan INDUK karena
             baris salinan memakai berkas foto yang sama. --}}
        @include('reports.partials._galeri-foto', ['bolehHapusFoto' => $laporanInduk->id === $report->id])


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

                {{-- Tombol pintas status (Tandai Selesai / Tandai Perlu Review) dihapus 06/10/2026.
                     Status laporan kini diubah lewat pilihan Status pada mode edit. --}}

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
        {{-- ── Kartu Teknisi Pelaksana (profil + grafik kinerja) ──────────── --}}
        {{-- Saat laporan berkolaborasi, chip teknisi di dalam kartu ini bisa
             diklik untuk berpindah profil/grafik tanpa memuat ulang halaman. --}}
        @include('reports.partials._kartu-teknisi', ['kartuTeknisi' => $kartuTeknisi])



    </div>{{-- /sidebar --}}

</div>{{-- /grid --}}
@endsection

@php
    // Ringkasan lokasi pada mode edit: daftar Area (dropdown lamanya dirender
    // server sehingga Alpine tidak punya daftarnya) plus label cadangan bila
    // nilai laporan tidak ada pada daftar prefetch area laporan.
    // Disiapkan di sini, bukan langsung di dalam direktif JSON, karena direktif
    // itu memecah argumen pada koma pertama (array bersarang tidak bisa).
    $areaDaftar = $areas->mapWithKeys(
        fn ($area) => [$area->id => trim($area->code . ' - ' . $area->name, ' -')]
    )->all();

    $labelLokasiAwal = [
        'area'    => $report->area
            ? [$report->area->id => trim($report->area->code . ' - ' . $report->area->name, ' -')]
            : [],
        'funcloc' => $report->functionalLocation
            ? [$report->functionalLocation->id => trim($report->functionalLocation->code . ' - ' . $report->functionalLocation->name, ' -')]
            : [],
        'alat'    => $report->asset
            ? [$report->asset->id => trim(($report->asset->tech_ident_no ?? $report->asset->equipment_no ?? ('#' . $report->asset->id)) . ' - ' . ($report->asset->description ?? ''), ' -')]
            : [],
    ];
@endphp


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

        // State unggah foto — key per tipe: documentation | hygiene
        uploading: {
            documentation: false,
            hygiene: false,
        },

        // Pesan error unggah per tipe
        uploadError: {
            documentation: '',
            hygiene: '',
        },

        // ── State dropdown lokasi (dipakai kartu "Pengaturan Laporan") ─
        areaId: {{ old('area_id', $report->area_id) ?? 'null' }},
        funcLocId: {{ old('funcloc_id', $report->funcloc_id) ?? 'null' }},
        assetId: {{ old('asset_id', $report->asset_id) ?? 'null' }},
        funcLocs: @json($funcLocs),

        // Ringkasan lokasi mode edit: daftar Area dan label cadangan disiapkan di
        // blok PHP sebelum script ini (direktif JSON Blade memecah argumen pada
        // koma pertama sehingga array bersarang tidak boleh ditulis langsung).
        areaDaftar: @json($areaDaftar),
        labelLokasiAwal: @json($labelLokasiAwal),
        equipmentTagAwal: @json($report->equipment_tag),
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
        // Popup detail di bagian bawah halaman: Analisa AI dan Timeline.
        // Nilai: null (tertutup) | 'analisa' | 'timeline'.
        popup: null,

        /**
         * Buka popup detail Analisa AI atau Timeline.
         *
         * @param {string} nama Nama popup: 'analisa' atau 'timeline'
         */
        bukaPopup(nama) {
            this.popup = nama;
        },

        /**
         * Tutup popup yang sedang terbuka.
         */
        tutupPopup() {
            this.popup = null;
        },

        // ── Popup galeri foto ─────────────────────────────────
        // Foto yang diklik pada grid kecil dibuka BESAR di popup (partial
        // _galeri-foto): tombol sebelumnya/berikutnya, panah kiri-kanan pada
        // keyboard, geser (swipe) pada layar sentuh, dan strip thumbnail.
        // Nilai `jenis`: 'documentation' atau 'hygiene'.
        galeri: {
            buka: false,
            jenis: 'documentation',
            indeks: 0,
            geserDari: null,
        },

        /**
         * Buka galeri foto pada jenis dan urutan tertentu.
         *
         * @param {string} jenis  'documentation' atau 'hygiene'
         * @param {number} indeks Posisi foto yang diklik (0-based)
         */
        galeriBuka(jenis, indeks) {
            var daftar = this.photoGroups[jenis] || [];

            if (!daftar.length) {
                return;
            }

            this.galeri.jenis  = jenis;
            this.galeri.indeks = Math.min(Math.max(indeks || 0, 0), daftar.length - 1);
            this.galeri.buka   = true;
        },

        /**
         * Tutup popup galeri foto.
         */
        galeriTutup() {
            this.galeri.buka = false;
        },

        /**
         * Pindah ke galeri jenis lain (dokumentasi ke hygiene atau sebaliknya)
         * bila jenis itu memang punya foto.
         *
         * @param {string} jenis 'documentation' atau 'hygiene'
         */
        galeriJenisBuka(jenis) {
            if (!(this.photoGroups[jenis] || []).length) {
                return;
            }

            this.galeri.jenis  = jenis;
            this.galeri.indeks = 0;
        },

        /**
         * URL foto yang sedang ditampilkan (string kosong bila tidak ada).
         */
        galeriFoto() {
            var daftar = this.photoGroups[this.galeri.jenis] || [];

            return daftar[this.galeri.indeks] || '';
        },

        /**
         * Teks posisi foto pada popup, contoh "3 / 12".
         */
        galeriPosisi() {
            var jumlah = (this.photoGroups[this.galeri.jenis] || []).length;

            return jumlah ? (this.galeri.indeks + 1) + ' / ' + jumlah : '0 / 0';
        },


        /**
         * Pindah satu foto ke belakang; dari foto pertama melingkar ke terakhir.
         */
        galeriSebelum() {
            if (!this.galeri.buka) {
                return;
            }

            var jumlah = (this.photoGroups[this.galeri.jenis] || []).length;

            if (!jumlah) {
                return;
            }

            this.galeri.indeks = (this.galeri.indeks - 1 + jumlah) % jumlah;
        },

        /**
         * Pindah satu foto ke depan; dari foto terakhir melingkar ke pertama.
         */
        galeriBerikut() {
            if (!this.galeri.buka) {
                return;
            }

            var jumlah = (this.photoGroups[this.galeri.jenis] || []).length;

            if (!jumlah) {
                return;
            }

            this.galeri.indeks = (this.galeri.indeks + 1) % jumlah;
        },

        /**
         * Lompat langsung ke satu foto lewat strip thumbnail.
         *
         * @param {number} indeks Posisi foto tujuan (0-based)
         */
        galeriKe(indeks) {
            this.galeri.indeks = indeks;
        },

        /**
         * Catat titik awal sentuhan untuk mendeteksi gerakan geser (swipe).
         *
         * @param {TouchEvent} kejadian Kejadian touchstart pada area foto
         */
        galeriGeserMulai(kejadian) {
            this.galeri.geserDari = kejadian.touches && kejadian.touches.length
                ? kejadian.touches[0].clientX
                : null;
        },

        /**
         * Geser ke kiri/kanan bila jaraknya cukup jauh (minimal 40 piksel).
         *
         * @param {TouchEvent} kejadian Kejadian touchend pada area foto
         */
        galeriGeserSelesai(kejadian) {
            if (this.galeri.geserDari === null || !kejadian.changedTouches.length) {
                this.galeri.geserDari = null;

                return;
            }

            var jarak = kejadian.changedTouches[0].clientX - this.galeri.geserDari;
            this.galeri.geserDari = null;

            if (Math.abs(jarak) < 40) {
                return;
            }

            if (jarak < 0) {
                this.galeriBerikut();
            } else {
                this.galeriSebelum();
            }
        },

        /**
         * Hapus foto yang sedang dibuka di galeri (tombolnya hanya tampil di
         * laporan INDUK pada mode edit). Bila foto terakhir terhapus, posisi
         * digeser ke foto sebelumnya supaya popup tidak menampilkan gambar kosong.
         */
        async galeriHapus() {
            var jenis  = this.galeri.jenis;
            var indeks = this.galeri.indeks;

            await this.deletePhoto(jenis, indeks);

            var jumlah = (this.photoGroups[jenis] || []).length;

            if (!this.deleteError[jenis] && this.galeri.indeks >= jumlah) {
                this.galeri.indeks = Math.max(0, jumlah - 1);
            }
        },


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
            this.uploadError.documentation = '';
            this.uploadError.hygiene = '';
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

        // ── Unggah foto ───────────────────────────────────────

        /**
         * Unggah foto lewat fetch supaya isian form edit yang belum disimpan
         * tidak hilang. Server membalas daftar URL terbaru (lihat
         * ReportController::addPhoto()), daftar di layar diperbarui dari itu.
         *
         * @param {string} type   'documentation' atau 'hygiene'
         * @param {Event}  event  Kejadian submit pada form unggah
         */
        async unggahFoto(type, event) {
            const form   = event.target;
            const berkas = form.querySelector('input[type="file"]');

            this.uploadError[type] = '';

            if (!berkas || berkas.files.length === 0) {
                this.uploadError[type] = 'Pilih berkas foto terlebih dahulu.';
                return;
            }

            this.uploading[type] = true;

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: new FormData(form),
                });

                const data = await response.json();

                if (response.ok && data.success) {
                    this.photoGroups[type] = data.foto?.[type] ?? [];
                    berkas.value = '';
                } else {
                    this.uploadError[type] = data.message ?? 'Gagal mengunggah foto. Coba lagi.';
                }
            } catch {
                this.uploadError[type] = 'Terjadi kesalahan jaringan. Coba lagi.';
            } finally {
                this.uploading[type] = false;
            }
        },

        // ── Dropdown lokasi halaman detail ────────────────────

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

        /**
         * Isi Area + Functional Location + Kode Alat sekaligus dari satu hasil
         * pencarian alat lintas area (partial _cari-alat). Daftar lokasi & alat
         * dimuat ulang untuk area alat itu supaya pilihan pada dropdown tetap
         * konsisten dengan nilai yang dipasang.
         *
         * @param {object} aset Hasil pencarian: id, area_id, funcloc_id, dst.
         */
        async terapkanAset(aset) {
            this.areaId = aset.area_id ?? null;

            if (this.areaId) {
                await Promise.all([
                    fetch(`{{ route('reports.locations.funclocs') }}?area_id=${this.areaId}`, {
                        headers: { 'Accept': 'application/json' },
                    })
                        .then(res => res.json())
                        .then(data => { this.funcLocs = data; }),

                    fetch(`{{ route('reports.locations.assets') }}?area_id=${this.areaId}`, {
                        headers: { 'Accept': 'application/json' },
                    })
                        .then(res => res.json())
                        .then(data => { this.assets = data; }),
                ]);
            } else {
                this.funcLocs = [];
                this.assets = [];
            }

            // Alat/lokasi terpilih wajib ada di daftar dropdown, kalau tidak
            // pilihannya tampak kosong walau nilainya sudah tersimpan.
            if (!this.assets.some(item => item.id === aset.id)) {
                this.assets.unshift(aset);
            }

            if (aset.funcloc_id && !this.funcLocs.some(item => item.id === aset.funcloc_id)) {
                this.funcLocs.unshift({
                    id: aset.funcloc_id,
                    code: aset.functional_loc || 'FuncLoc #' + aset.funcloc_id,
                    name: aset.area_label || '',
                    level: null,
                });
            }

            this.funcLocId = aset.funcloc_id ?? null;
            this.assetId   = aset.id;
        },

        /**
         * Isi Area + Functional Location dari satu node lokasi hasil penelusuran
         * hierarki (tanpa memilih alat). Dipakai pemilih lokasi bertingkat pada
         * partial _pilih-lokasi-hirarki.
         *
         * @param {object} node Node lokasi: id, area_id, code, label
         */
        async terapkanLokasi(node) {
            this.areaId = node.area_id ?? null;

            if (this.areaId) {
                await Promise.all([
                    fetch(`{{ route('reports.locations.funclocs') }}?area_id=${this.areaId}`, {
                        headers: { 'Accept': 'application/json' },
                    })
                        .then(res => res.json())
                        .then(data => { this.funcLocs = data; }),

                    fetch(`{{ route('reports.locations.assets') }}?area_id=${this.areaId}`, {
                        headers: { 'Accept': 'application/json' },
                    })
                        .then(res => res.json())
                        .then(data => { this.assets = data; }),
                ]);
            } else {
                this.funcLocs = [];
                this.assets   = [];
            }

            // Node terpilih wajib ada di daftar dropdown; kalau tidak, pilihannya
            // tampak kosong walau nilainya sudah terpasang di form.
            if (!this.funcLocs.some(item => item.id === node.id)) {
                this.funcLocs.unshift({
                    id: node.id,
                    code: node.code || 'FuncLoc #' + node.id,
                    name: node.label || '',
                    level: null,
                });
            }

            this.funcLocId = node.id;
            this.assetId   = null;
        },


        // ── Ringkasan lokasi & alat (mode edit) ───────────────
        // Kolom Area / Functional Location / Kode Alat yang lama disembunyikan
        // dan diganti satu ringkasan ini, jadi labelnya dicari dari daftar yang
        // tersedia (perbandingan longgar supaya tipe data dari form tidak jadi
        // masalah).

        /**
         * Cari label pada kumpulan pasangan id => label.
         *
         * @param {object} daftar Kumpulan pasangan id => label
         * @param {number|string|null} id Id yang dicari
         * @returns {string|null} Label bila ada, atau null
         */
        cariLabelLokasi(daftar, id) {
            if (!daftar) {
                return null;
            }

            const label = daftar[String(id)];

            return label === undefined ? null : label;
        },

        /**
         * Nama Area terpilih untuk ringkasan lokasi.
         *
         * @param {number|string|null} id Id area
         * @returns {string} Label area atau '-'
         */
        namaArea(id) {
            if (!id) {
                return '-';
            }

            return this.cariLabelLokasi(this.areaDaftar, id) ?? ('Area #' + id);
        },

        /**
         * Nama Functional Location terpilih untuk ringkasan lokasi.
         *
         * @param {number|string|null} id Id Functional Location
         * @returns {string} Label lokasi atau '-'
         */
        namaFuncLoc(id) {
            if (!id) {
                return '-';
            }

            const baris = this.funcLocs.find(item => String(item.id) === String(id));

            if (baris) {
                return baris.code + (baris.name ? ' - ' + baris.name : '');
            }

            return this.cariLabelLokasi(this.labelLokasiAwal.funcloc, id) ?? ('FuncLoc #' + id);
        },

        /**
         * Nama Kode Alat terpilih untuk ringkasan lokasi.
         *
         * @param {number|string|null} id Id aset
         * @returns {string} Label alat atau '-'
         */
        namaAlat(id) {
            if (!id) {
                return this.equipmentTagAwal || '-';
            }

            const baris = this.assets.find(item => String(item.id) === String(id));

            if (baris) {
                const kode = baris.tech_ident_no || baris.equipment_no || ('#' + baris.id);

                return kode + (baris.description ? ' - ' + baris.description : '');
            }

            return this.cariLabelLokasi(this.labelLokasiAwal.alat, id) ?? ('Alat #' + id);
        },

        /**
         * Kosongkan Area, Functional Location, dan Kode Alat pada form.
         */
        kosongkanLokasi() {
            this.areaId    = null;
            this.funcLocId = null;
            this.assetId   = null;
            this.funcLocs  = [];
            this.assets    = [];
        },
    };
}
</script>
@endpush
