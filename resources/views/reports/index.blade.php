@extends('layouts.app')

@section('title', 'Laporan')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Laporan</span>
@endsection

@section('header-actions')
    <a href="{{ route('reports.export-csv', request()->query()) }}"
       class="inline-flex items-center gap-2 px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
        </svg>
        Export CSV
    </a>
@endsection

@section('content')

{{-- ============================================================
     Alpine.js component utama: mengelola state collapse filter
============================================================ --}}
<div x-data="reportIndex()" x-init="init()">

    {{-- ── Filter Panel ──────────────────────────────────────── --}}
    <div class="bg-white rounded-xl border border-slate-200 mb-6">

        {{-- Header panel filter: selalu tampil --}}
        <button type="button"
                @click="filterOpen = !filterOpen"
                class="w-full flex items-center justify-between px-5 py-4 text-left">
            <div class="flex items-center gap-2.5">
                <svg class="w-4 h-4 text-slate-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2a1 1 0 01-.293.707L13 13.414V19a1 1 0 01-.553.894l-4 2A1 1 0 017 21v-7.586L3.293 6.707A1 1 0 013 6V4z"/>
                </svg>
                <span class="text-sm font-medium text-slate-700">Filter Laporan</span>
                {{-- Badge indikator filter aktif --}}
                <span x-show="activeFilterCount > 0"
                      x-cloak
                      x-text="activeFilterCount + ' aktif'"
                      class="px-1.5 py-0.5 rounded-full text-xs font-medium bg-blue-600 text-white leading-none"></span>
            </div>
            <svg class="w-4 h-4 text-slate-400 transition-transform duration-200 shrink-0"
                 :class="{ 'rotate-180': filterOpen }"
                 fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
            </svg>
        </button>

        {{-- Body panel filter: collapsible --}}
        <div x-show="filterOpen"
             x-collapse
             class="border-t border-slate-100">
            <form method="GET" action="{{ route('reports.index') }}" class="p-5 space-y-4">

                {{-- Baris 1: pencarian teks --}}
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">Deskripsi</label>
                        <input type="text" name="search" value="{{ request('search') }}"
                               placeholder="Cari deskripsi..."
                               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">Kode Laporan</label>
                        <input type="text" name="report_code" value="{{ request('report_code') }}"
                               placeholder="Cari kode laporan..."
                               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">Kode Alat</label>
                        <input type="text" name="asset_code" value="{{ request('asset_code') }}"
                               placeholder="Cari kode alat..."
                               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                </div>

                {{-- Baris 2: tanggal, area, status, tipe, foto --}}
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">Tanggal Dari</label>
                        <input type="date" name="date_from" value="{{ request('date_from') }}"
                               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">Tanggal Sampai</label>
                        <input type="date" name="date_to" value="{{ request('date_to') }}"
                               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">Area</label>
                        <select name="area_id"
                                class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">Semua Area</option>
                            @foreach($areas as $area)
                                <option value="{{ $area->id }}"
                                    {{ request('area_id') == $area->id ? 'selected' : '' }}>
                                    {{ $area->code }} - {{ $area->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">Status</label>
                        <select name="status"
                                class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">Semua Status</option>
                            <option value="draft" {{ request('status') === 'draft' ? 'selected' : '' }}>Draft</option>
                            <option value="needs_review" {{ request('status') === 'needs_review' ? 'selected' : '' }}>Perlu Review</option>
                            <option value="completed" {{ request('status') === 'completed' ? 'selected' : '' }}>Selesai</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">Tipe</label>
                        <select name="report_type"
                                class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">Semua Tipe</option>
                            <option value="equipment_repair" {{ request('report_type') === 'equipment_repair' ? 'selected' : '' }}>Perbaikan Equipment</option>
                            <option value="area_work" {{ request('report_type') === 'area_work' ? 'selected' : '' }}>Pekerjaan Area</option>
                            <option value="general" {{ request('report_type') === 'general' ? 'selected' : '' }}>Umum</option>
                        </select>
                    </div>
                </div>

                {{-- Baris 3: filter foto + tombol aksi --}}
                <div class="flex flex-wrap items-end gap-3">
                    <div class="w-44">
                        <label class="block text-xs text-slate-500 mb-1">Foto</label>
                        <select name="has_photo"
                                class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">Semua</option>
                            <option value="1" {{ request('has_photo') === '1' ? 'selected' : '' }}>Ada Foto</option>
                            <option value="0" {{ request('has_photo') === '0' ? 'selected' : '' }}>Tanpa Foto</option>
                        </select>
                    </div>
                    <div class="flex items-center gap-2 pb-0.5">
                        <button type="submit"
                                class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
                            Terapkan Filter
                        </button>
                        <a href="{{ route('reports.index') }}"
                           class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                            Reset
                        </a>
                    </div>
                </div>

            </form>
        </div>
    </div>

    {{-- ── Summary Strip ─────────────────────────────────────── --}}
    @php
        // Angka dihitung dari paginator yang sudah ada — tanpa query tambahan.
        // $reports->total() = total semua baris (seluruh halaman) sesuai filter aktif.
        $totalLaporan = $reports->total();

        // Hitung draft dan selesai dari koleksi halaman ini (approximasi cepat).
        // Untuk akurasi full-dataset, query terpisah diperlukan; ini sengaja dihindari
        // agar tidak menambah beban query sesuai spesifikasi PLAN.
        $draftCount     = $reports->getCollection()->where('status', 'draft')->count();
        $completedCount = $reports->getCollection()->where('status', 'completed')->count();
        $reviewCount    = $reports->getCollection()->where('status', 'needs_review')->count();
    @endphp

    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
        <div class="bg-white rounded-xl border border-slate-200 px-4 py-3">
            <p class="text-xs text-slate-500">Total (filter)</p>
            <p class="text-xl font-semibold text-slate-900 mt-0.5">{{ number_format($totalLaporan) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 px-4 py-3">
            <p class="text-xs text-slate-500">Draft (halaman ini)</p>
            <p class="text-xl font-semibold text-slate-600 mt-0.5">{{ $draftCount }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 px-4 py-3">
            <p class="text-xs text-slate-500">Perlu Review (halaman ini)</p>
            <p class="text-xl font-semibold text-amber-600 mt-0.5">{{ $reviewCount }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 px-4 py-3">
            <p class="text-xs text-slate-500">Selesai (halaman ini)</p>
            <p class="text-xl font-semibold text-green-600 mt-0.5">{{ $completedCount }}</p>
        </div>
    </div>

    {{-- ── Tabel Laporan ─────────────────────────────────────── --}}
    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-100">
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3 whitespace-nowrap">Kode</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3 whitespace-nowrap">Tanggal</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Teknisi</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Deskripsi</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3 whitespace-nowrap">Lokasi / Alat</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3 whitespace-nowrap">Durasi & Foto</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3 whitespace-nowrap">Tipe & AI</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Status</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3 sr-only">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($reports as $report)
                        {{-- Seluruh baris bisa diklik ke halaman detail --}}
                        <tr class="hover:bg-slate-50 transition-colors cursor-pointer"
                            onclick="window.location='{{ route('reports.show', $report) }}'">

                            {{-- Kode laporan --}}
                            <td class="px-5 py-3.5 whitespace-nowrap">
                                <span class="text-xs font-mono font-medium text-slate-700">
                                    {{ $report->report_code ?? '-' }}
                                </span>
                            </td>

                            {{-- Tanggal --}}
                            <td class="px-5 py-3.5 text-xs text-slate-600 whitespace-nowrap">
                                {{ $report->report_date->format('d/m/Y') }}
                            </td>

                            {{-- Teknisi + indikator kolaborasi --}}
                            <td class="px-5 py-3.5">
                                <p class="font-medium text-slate-700 text-sm leading-snug">
                                    {{ $report->technician->name ?? '-' }}
                                </p>
                                @if($report->collaborator_of)
                                    <div class="mt-1">
                                        <x-status-badge status="collaboration" label="Kolaborasi" />
                                    </div>
                                @elseif($report->collaborator_count > 0)
                                    <p class="text-xs text-slate-400 mt-0.5">
                                        +{{ $report->collaborator_count }} kolaborator
                                    </p>
                                @endif
                                @if($report->creator_id && $report->creator_id !== $report->technician_id)
                                    <p class="text-xs text-slate-400 mt-0.5">
                                        Oleh: {{ $report->creator->name ?? '-' }}
                                    </p>
                                @endif
                            </td>

                            {{-- Deskripsi --}}
                            <td class="px-5 py-3.5 max-w-xs">
                                <p class="text-slate-600 text-sm leading-snug line-clamp-2">
                                    {{ Str::limit($report->work_description, 80) }}
                                </p>
                            </td>

                            {{-- Lokasi / Alat: dua baris dalam satu sel --}}
                            <td class="px-5 py-3.5 whitespace-nowrap">
                                <p class="text-xs font-medium text-slate-700">
                                    {{ $report->area?->code ?? '-' }}
                                </p>
                                <p class="text-xs text-slate-400 mt-0.5 font-mono">
                                    {{ $report->asset?->tech_ident_no ?? $report->asset?->equipment_no ?? '-' }}
                                </p>
                            </td>

                            {{-- Durasi & Foto: digabung --}}
                            <td class="px-5 py-3.5 whitespace-nowrap">
                                <p class="text-xs text-slate-600">
                                    @if($report->work_duration_minutes)
                                        {{ floor($report->work_duration_minutes / 60) }}j {{ $report->work_duration_minutes % 60 }}m
                                    @else
                                        <span class="text-slate-400">-</span>
                                    @endif
                                </p>
                                <p class="text-xs text-slate-400 mt-0.5">
                                    Dok:{{ $report->photo_doc_count }}
                                    <span class="text-slate-200 mx-0.5">|</span>
                                    HC:{{ $report->photo_hyg_count }}
                                </p>
                            </td>

                            {{-- Tipe & AI: digabung --}}
                            <td class="px-5 py-3.5">
                                <x-status-badge :status="$report->report_type" />
                                <div class="mt-1">
                                    @if($report->is_manually_edited)
                                        <x-status-badge status="edited" label="Edited" />
                                    @elseif($report->ai_analyzed)
                                        <span class="text-xs font-medium
                                            {{ $report->ai_confidence >= 70
                                                ? 'text-green-600'
                                                : ($report->ai_confidence >= 40 ? 'text-amber-600' : 'text-red-600') }}">
                                            AI {{ $report->ai_confidence }}%
                                        </span>
                                    @else
                                        <span class="text-xs text-slate-400">-</span>
                                    @endif
                                </div>
                            </td>

                            {{-- Status --}}
                            <td class="px-5 py-3.5">
                                <x-status-badge :status="$report->status" />
                            </td>

                            {{-- Aksi: hanya ikon mata — klik tidak propagate ke baris --}}
                            <td class="px-5 py-3.5">
                                <a href="{{ route('reports.show', $report) }}"
                                   onclick="event.stopPropagation()"
                                   title="Lihat detail"
                                   class="inline-flex items-center justify-center w-7 h-7 rounded-lg hover:bg-slate-100 text-slate-400 hover:text-slate-600 transition-colors">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                    </svg>
                                </a>
                            </td>
                        </tr>
                    @empty
                        {{-- Empty state dengan ilustrasi SVG sederhana --}}
                        <tr>
                            <td colspan="9" class="px-5 py-16 text-center">
                                <div class="flex flex-col items-center gap-3">
                                    <svg class="w-12 h-12 text-slate-200" fill="none" viewBox="0 0 48 48">
                                        <rect x="8" y="6" width="32" height="36" rx="4" fill="currentColor" opacity=".4"/>
                                        <rect x="8" y="6" width="32" height="36" rx="4" stroke="#94a3b8" stroke-width="1.5" fill="none"/>
                                        <line x1="16" y1="18" x2="32" y2="18" stroke="#94a3b8" stroke-width="1.5" stroke-linecap="round"/>
                                        <line x1="16" y1="24" x2="32" y2="24" stroke="#94a3b8" stroke-width="1.5" stroke-linecap="round"/>
                                        <line x1="16" y1="30" x2="24" y2="30" stroke="#94a3b8" stroke-width="1.5" stroke-linecap="round"/>
                                        <circle cx="36" cy="36" r="8" fill="#f1f5f9" stroke="#94a3b8" stroke-width="1.5"/>
                                        <line x1="33" y1="36" x2="39" y2="36" stroke="#94a3b8" stroke-width="1.5" stroke-linecap="round"/>
                                    </svg>
                                    <div>
                                        <p class="text-sm font-medium text-slate-500">Belum ada laporan ditemukan</p>
                                        <p class="text-xs text-slate-400 mt-1">Coba ubah atau reset filter pencarian</p>
                                    </div>
                                    @if(request()->hasAny(['search','report_code','asset_code','date_from','date_to','area_id','status','report_type','has_photo']))
                                        <a href="{{ route('reports.index') }}"
                                           class="mt-1 px-3 py-1.5 text-xs font-medium text-blue-600 hover:text-blue-700 border border-blue-200 hover:bg-blue-50 rounded-lg transition-colors">
                                            Reset filter
                                        </a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Pagination + info --}}
        @if($reports->hasPages() || $reports->total() > 0)
            <div class="px-5 py-3.5 border-t border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-3">
                {{-- Info: "Menampilkan X–Y dari Z laporan" --}}
                <p class="text-xs text-slate-500 shrink-0">
                    @if($reports->total() > 0)
                        Menampilkan
                        <span class="font-medium text-slate-700">{{ $reports->firstItem() }}–{{ $reports->lastItem() }}</span>
                        dari
                        <span class="font-medium text-slate-700">{{ number_format($reports->total()) }}</span>
                        laporan
                    @else
                        Tidak ada laporan
                    @endif
                </p>

                {{-- Link pagination bawaan Laravel --}}
                @if($reports->hasPages())
                    <div class="shrink-0">
                        {{ $reports->links() }}
                    </div>
                @endif
            </div>
        @endif
    </div>

</div>{{-- /x-data --}}
@endsection

@push('scripts')
<script>
function reportIndex() {
    return {
        // Panel filter terbuka secara default jika ada filter aktif dari URL
        filterOpen: {{ collect([
            request('search'),
            request('report_code'),
            request('asset_code'),
            request('date_from'),
            request('date_to'),
            request('area_id'),
            request('status'),
            request('report_type'),
            request('has_photo'),
        ])->filter()->isNotEmpty() ? 'true' : 'false' }},

        // Jumlah filter aktif — dipakai untuk badge di header panel
        activeFilterCount: {{ collect([
            request('search'),
            request('report_code'),
            request('asset_code'),
            request('date_from'),
            request('date_to'),
            request('area_id'),
            request('status'),
            request('report_type'),
            request('has_photo'),
        ])->filter()->count() }},

        init() {
            // Tidak ada setup tambahan yang diperlukan saat ini.
        },
    };
}
</script>
@endpush
