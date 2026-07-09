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
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
        </svg>
        Export CSV
    </a>
@endsection

@section('content')
    <!-- Filters -->
    <div class="bg-white rounded-xl border border-slate-200 p-4 mb-6">
        <form method="GET" action="{{ route('reports.index') }}" class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
                <input type="text" name="search" value="{{ request('search') }}"
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                       placeholder="Cari deskripsi...">
            </div>
            <div>
                <input type="text" name="report_code" value="{{ request('report_code') }}"
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                       placeholder="Cari kode laporan...">
            </div>
            <div>
                <input type="text" name="asset_code" value="{{ request('asset_code') }}"
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                       placeholder="Cari kode alat...">
            </div>
            <div>
                <input type="date" name="date_from" value="{{ request('date_from') }}"
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <div>
                <input type="date" name="date_to" value="{{ request('date_to') }}"
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <div>
                <select name="status" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white">
                    <option value="">Semua Status</option>
                    <option value="draft" {{ request('status') === 'draft' ? 'selected' : '' }}>Draft</option>
                    <option value="needs_review" {{ request('status') === 'needs_review' ? 'selected' : '' }}>Perlu Review</option>
                    <option value="completed" {{ request('status') === 'completed' ? 'selected' : '' }}>Selesai</option>
                </select>
            </div>
            <div>
                <select name="report_type" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white">
                    <option value="">Semua Tipe</option>
                    <option value="equipment_repair" {{ request('report_type') === 'equipment_repair' ? 'selected' : '' }}>Perbaikan Equipment</option>
                    <option value="area_work" {{ request('report_type') === 'area_work' ? 'selected' : '' }}>Pekerjaan Area</option>
                    <option value="general" {{ request('report_type') === 'general' ? 'selected' : '' }}>Umum</option>
                </select>
            </div>
            <div>
                <select name="has_photo" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white">
                    <option value="">Semua Foto</option>
                    <option value="1" {{ request('has_photo') === '1' ? 'selected' : '' }}>Ada Foto</option>
                    <option value="0" {{ request('has_photo') === '0' ? 'selected' : '' }}>Tidak Ada Foto</option>
                </select>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">Filter</button>
                <a href="{{ route('reports.index') }}" class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">Reset</a>
            </div>
        </form>
    </div>

    <!-- Table -->
    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-100">
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Kode</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Tanggal</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Teknisi</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Deskripsi</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Area</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Kode Alat</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Tipe</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Durasi</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Foto</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">AI</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Status</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($reports as $report)
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-5 py-3.5 text-xs font-mono text-slate-600 whitespace-nowrap">{{ $report->report_code ?? '-' }}</td>
                            <td class="px-5 py-3.5 text-slate-600 whitespace-nowrap">{{ $report->report_date->format('d/m/Y') }}</td>
                            <td class="px-5 py-3.5">
                                <span class="font-medium text-slate-700">{{ $report->technician->name ?? '-' }}</span>
                                @if($report->collaborator_of)
                                    <div class="mt-1">
                                        <x-status-badge status="collaboration" label="Kolaborasi" />
                                    </div>
                                @endif
                                @if($report->creator_id && $report->creator_id !== $report->technician_id)
                                    <p class="text-xs text-slate-400 mt-1">Dibuat oleh: {{ $report->creator->name ?? '-' }}</p>
                                @endif
                            </td>
                            <td class="px-5 py-3.5">
                                <p class="text-slate-600 truncate max-w-xs">{{ Str::limit($report->work_description, 60) }}</p>
                            </td>
                            <td class="px-5 py-3.5 text-xs text-slate-600">{{ $report->area?->code ?? '-' }}</td>
                            <td class="px-5 py-3.5 text-xs text-slate-600 whitespace-nowrap">{{ $report->asset->tech_ident_no ?? '-' }}</td>
                            <td class="px-5 py-3.5">
                                <x-status-badge :status="$report->report_type" />
                            </td>
                            <td class="px-5 py-3.5 text-xs text-slate-600 whitespace-nowrap">
                                @if($report->work_duration_minutes)
                                    {{ floor($report->work_duration_minutes / 60) }}j {{ $report->work_duration_minutes % 60 }}m
                                @else
                                    -
                                @endif
                            </td>
                            <td class="px-5 py-3.5 text-xs text-slate-600 whitespace-nowrap">
                                Doc:{{ $report->photo_doc_count }} <span class="text-slate-300">│</span> HC:{{ $report->photo_hyg_count }}
                            </td>
                            <td class="px-5 py-3.5 text-xs">
                                @if($report->is_manually_edited)
                                    <x-status-badge status="edited" label="Edited" />
                                @elseif($report->ai_analyzed)
                                    <span class="{{ $report->ai_confidence >= 70 ? 'text-green-600' : ($report->ai_confidence >= 40 ? 'text-amber-600' : 'text-red-600') }}">
                                        {{ $report->ai_confidence }}%
                                    </span>
                                @else
                                    <span class="text-slate-400">-</span>
                                @endif
                            </td>
                            <td class="px-5 py-3.5">
                                <x-status-badge :status="$report->status" />
                            </td>
                            <td class="px-5 py-3.5">
                                <div class="flex items-center gap-3">
                                    <a href="{{ route('reports.show', $report) }}"
                                       class="text-blue-600 hover:text-blue-700 text-xs font-medium">Detail</a>
                                    <a href="{{ route('reports.edit', $report) }}"
                                       class="text-slate-500 hover:text-slate-700 text-xs font-medium">Edit</a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="12" class="px-5 py-8 text-center text-sm text-slate-400">Belum ada laporan.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($reports->hasPages())
            <div class="px-5 py-3 border-t border-slate-100">{{ $reports->links() }}</div>
        @endif
    </div>
@endsection
