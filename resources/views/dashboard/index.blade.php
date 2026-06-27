@extends('layouts.app')

@section('title', 'Dashboard')
@section('breadcrumb')
    <span class="text-slate-900 font-medium">Dashboard</span>
@endsection

@section('content')
    <!-- Stat Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-5 mb-6">
        <x-stat-card
            label="Total Asset"
            :value="number_format($totalAssets)"
            subtext="{{ number_format($activeAssets) }} aktif"
            value-color="text-blue-600"
            icon="box" />

        <x-stat-card
            label="Teknisi Aktif"
            :value="number_format($activeTechnicians)"
            subtext="{{ number_format($pendingTechnicians) }} menunggu persetujuan"
            value-color="text-green-600"
            icon="users" />

        <x-stat-card
            label="Laporan Hari Ini"
            :value="number_format($reportsToday)"
            subtext="{{ number_format($reportsCompletedToday) }} selesai"
            value-color="text-violet-600"
            icon="document" />

        <x-stat-card
            label="Perlu Review"
            :value="number_format($reportsPendingReview)"
            subtext="Laporan menunggu review"
            value-color="text-amber-600"
            icon="alert" />
    </div>

    <!-- Alerts -->
    @if($unknownAssets > 0 || $pendingRegistrations > 0)
        <div class="mb-6 space-y-3">
            @if($unknownAssets > 0)
                <x-alert type="warning" title="Perhatian" dismissible>
                    Terdapat {{ $unknownAssets }} unknown asset yang belum di-mapping.
                    <a href="{{ route('bot.index') }}" class="underline font-medium">Kelola Sekarang</a>
                </x-alert>
            @endif

            @if($pendingRegistrations > 0)
                <x-alert type="info" title="Pendaftaran Baru" dismissible>
                    Terdapat {{ $pendingRegistrations }} teknisi menunggu persetujuan.
                    <a href="{{ route('bot.index') }}" class="underline font-medium">Review Sekarang</a>
                </x-alert>
            @endif
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Recent Reports -->
        <div class="lg:col-span-2">
            <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
                    <h3 class="font-medium text-slate-900">Laporan Terbaru</h3>
                    <a href="{{ route('reports.index') }}" class="text-sm text-blue-600 hover:text-blue-700">Lihat Semua</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-slate-50 border-b border-slate-100">
                                <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Teknisi</th>
                                <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Deskripsi</th>
                                <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Status</th>
                                <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Tanggal</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            @forelse($recentReports as $report)
                                <tr class="hover:bg-slate-50 transition-colors">
                                    <td class="px-5 py-3.5">
                                        <span class="font-medium text-slate-700">{{ $report->technician->name ?? '-' }}</span>
                                    </td>
                                    <td class="px-5 py-3.5">
                                        <p class="text-slate-600 truncate max-w-xs">{{ Str::limit($report->work_description, 60) }}</p>
                                    </td>
                                    <td class="px-5 py-3.5">
                                        <x-status-badge :status="$report->status" />
                                    </td>
                                    <td class="px-5 py-3.5 text-slate-500">{{ $report->report_date->format('d/m/Y') }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-5 py-8 text-center text-sm text-slate-400">Belum ada laporan.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- AI Provider Summary -->
        <div>
            <div class="bg-white rounded-xl border border-slate-200 p-5">
                <h3 class="font-medium text-slate-900 mb-4">Ringkasan AI Provider</h3>
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-slate-600">Provider Terdaftar</span>
                        <span class="font-semibold text-slate-900">{{ $totalProviders }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-slate-600">Sehat</span>
                        <span class="font-semibold text-green-600">{{ $healthyProviders }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-slate-600">Pending Registrasi Teknisi</span>
                        <span class="font-semibold text-amber-600">{{ $pendingRegistrations }}</span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-slate-600">Unknown Assets</span>
                        <span class="font-semibold text-red-600">{{ $unknownAssets }}</span>
                    </div>
                </div>
                <a href="{{ route('ai-providers.index') }}" class="mt-4 inline-flex items-center gap-1 text-sm text-blue-600 hover:text-blue-700">
                    Kelola Provider
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                </a>
            </div>
        </div>
    </div>
@endsection
