@extends('layouts.app')

@section('title', $location->name ?? $location->code)

@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('assets.index') }}" class="hover:text-slate-700">Asset Management</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">{{ $location->code }}</span>
@endsection

@section('header-actions')
    <a href="{{ route('assets.index', ['search' => $location->code]) }}"
       class="inline-flex items-center gap-2 px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
        Buka di Asset Management
    </a>
@endsection

@section('content')

    @php
        // Label status laporan agar konsisten dengan halaman laporan.
        $labelStatus = [
            'draft'        => ['Draft', 'bg-slate-100 text-slate-600 border-slate-200'],
            'needs_review' => ['Perlu Diperiksa', 'bg-amber-50 text-amber-700 border-amber-100'],
            'completed'    => ['Selesai', 'bg-teal-50 text-teal-700 border-teal-100'],
        ];
    @endphp

    {{-- Navigasi jalur lokasi: PT > Departemen > Area > Section --}}
    <div class="flex flex-wrap items-center gap-1.5 text-xs">
        @foreach($jalur as $node)
            @if(!$loop->first)
                <span class="text-slate-300">/</span>
            @endif
            @if($node->id === $location->id)
                <span class="px-2 py-1 rounded-md bg-teal-50 text-teal-700 border border-teal-100 font-mono font-medium">
                    {{ $node->code }}
                </span>
            @else
                <a href="{{ route('locations.show', $node) }}"
                   class="px-2 py-1 rounded-md bg-white text-slate-600 border border-slate-200 hover:border-teal-300 hover:text-teal-700 font-mono transition-colors">
                    {{ $node->code }}
                </a>
            @endif
        @endforeach
    </div>

    {{-- Statistik lokasi --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl border border-slate-200 px-4 py-3">
            <p class="text-xl font-bold text-slate-800">{{ number_format($statistik['alat']) }}</p>
            <p class="text-xs text-slate-500 mt-0.5">Alat Terdaftar</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 px-4 py-3">
            <p class="text-xl font-bold text-slate-800">{{ number_format($statistik['laporan']) }}</p>
            <p class="text-xs text-slate-500 mt-0.5">Total Laporan</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 px-4 py-3">
            <p class="text-xl font-bold text-teal-600">{{ number_format($statistik['laporan_baru']) }}</p>
            <p class="text-xs text-slate-500 mt-0.5">Laporan 30 Hari</p>
        </div>
        <div class="bg-white rounded-xl border border-teal-200 px-4 py-3">
            <p class="text-xl font-bold text-amber-600">{{ number_format($statistik['belum_selesai']) }}</p>
            <p class="text-xs text-slate-500 mt-0.5">Belum Selesai</p>
        </div>
    </div>

    {{-- Informasi lokasi --}}
    <div class="bg-white rounded-xl border border-slate-200 p-6">
        <div class="flex items-start justify-between gap-4 mb-4">
            <div>
                <h3 class="text-base font-semibold text-slate-900">{{ $location->name ?? '(tanpa nama)' }}</h3>
                <p class="text-xs font-mono text-slate-400 mt-0.5">{{ $location->code }}</p>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <span class="inline-flex px-2 py-0.5 rounded-md text-xs font-medium bg-slate-100 text-slate-600 border border-slate-200">
                    {{ $location->level_label }}
                </span>
                @if($location->is_active)
                    <span class="inline-flex px-2 py-0.5 rounded-md text-xs font-medium bg-teal-50 text-teal-700 border border-teal-100">
                        Aktif
                    </span>
                @else
                    <span class="inline-flex px-2 py-0.5 rounded-md text-xs font-medium bg-rose-50 text-rose-600 border border-rose-100">
                        Tidak Aktif
                    </span>
                @endif
            </div>
        </div>

        <dl class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-3 text-sm">
            <div>
                <dt class="text-xs text-slate-400">PT / Site</dt>
                <dd class="text-slate-700">{{ $location->company?->name ?? '-' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-slate-400">Departemen</dt>
                <dd class="text-slate-700">{{ $location->department?->name ?? '-' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-slate-400">Area</dt>
                <dd class="text-slate-700">
                    @if($location->area)
                        {{ $location->area->code }} - {{ $location->area->name }}
                    @else
                        -
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-xs text-slate-400">Sub Area</dt>
                <dd class="text-slate-700">{{ $location->subArea?->name ?? '-' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-slate-400">Lokasi Induk</dt>
                <dd class="text-slate-700">
                    @if($location->parent)
                        <a href="{{ route('locations.show', $location->parent) }}"
                           class="font-mono text-teal-700 hover:underline">{{ $location->parent->code }}</a>
                    @else
                        -
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-xs text-slate-400">Sub Lokasi</dt>
                <dd class="text-slate-700">{{ number_format($statistik['sub_lokasi']) }} node turunan</dd>
            </div>
        </dl>
    </div>

    {{-- Daftar laporan pada lokasi ini --}}
    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 flex flex-wrap items-center justify-between gap-2">
            <div>
                <h3 class="text-sm font-semibold text-slate-900">Laporan Lokasi Ini</h3>
                <p class="text-xs text-slate-400 mt-0.5">
                    Termasuk laporan perbaikan alat yang berlokasi di sini
                    ({{ number_format($asalLaporan['lokasi']) }} laporan ber-lokasi langsung,
                    {{ number_format($asalLaporan['per_alat']) }} laporan lewat data alat).
                </p>
            </div>
            <a href="{{ route('reports.index', array_filter([
                   'search'    => $location->code,
                   'date_from' => $rentangLaporan['dari'],
                   'date_to'   => $rentangLaporan['sampai'],
               ])) }}"
               class="text-xs font-medium text-teal-700 hover:underline">Lihat semua di halaman Laporan</a>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-100">
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3 whitespace-nowrap">Tanggal</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">Pekerjaan</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3 whitespace-nowrap">Alat</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3 whitespace-nowrap">Teknisi</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3 whitespace-nowrap">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($laporan as $report)
                        @php
                            $status = $labelStatus[$report->status] ?? [ucfirst((string) $report->status), 'bg-slate-100 text-slate-500 border-slate-200'];
                        @endphp
                        <tr class="hover:bg-slate-50 transition-colors cursor-pointer"
                            onclick="window.location='{{ route('reports.show', $report) }}'">
                            <td class="px-5 py-3.5 align-top whitespace-nowrap">
                                <p class="text-xs text-slate-600">
                                    {{ $report->report_date ? $report->report_date->format('d/m/Y') : '-' }}
                                </p>
                                <p class="text-xs text-slate-400 mt-0.5 font-mono">{{ $report->report_code ?? '-' }}</p>
                            </td>
                            <td class="px-4 py-3.5 align-top">
                                <p class="text-slate-800 leading-snug whitespace-normal break-words">
                                    {{ \Illuminate\Support\Str::limit($report->work_description, 120) ?: '(tanpa deskripsi pekerjaan)' }}
                                </p>
                                @if($report->jenis_pekerjaan)
                                    <span class="inline-flex mt-1 px-2 py-0.5 rounded-md text-xs font-medium bg-slate-100 text-slate-600 border border-slate-200">
                                        {{ $report->jenis_pekerjaan }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3.5 align-top whitespace-nowrap">
                                @if($report->asset)
                                    <a href="{{ route('assets.show', $report->asset) }}"
                                       onclick="event.stopPropagation()"
                                       class="text-xs font-mono text-teal-700 hover:underline">
                                        {{ $report->asset->tech_ident_no ?? $report->asset->equipment_no ?? ('#' . $report->asset->id) }}
                                    </a>
                                @elseif($report->functionalLocation)
                                    <span class="text-xs font-mono text-slate-400">{{ $report->functionalLocation->code }}</span>
                                @else
                                    <span class="text-xs text-slate-300">-</span>
                                @endif
                            </td>
                            <td class="px-4 py-3.5 align-top whitespace-nowrap">
                                <p class="text-xs text-slate-700">{{ $report->technician?->name ?? '-' }}</p>
                            </td>
                            <td class="px-4 py-3.5 align-top whitespace-nowrap">
                                <span class="inline-flex px-2 py-0.5 rounded-md text-xs font-medium border {{ $status[1] }}">
                                    {{ $status[0] }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-10 text-center text-sm text-slate-400">
                                Belum ada laporan pada lokasi ini.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($laporan->hasPages())
            <div class="px-5 py-4 border-t border-slate-100">
                {{ $laporan->links() }}
            </div>
        @endif
    </div>

    {{-- Daftar alat pada lokasi ini --}}
    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100">
            <h3 class="text-sm font-semibold text-slate-900">Alat di Lokasi Ini</h3>
            <p class="text-xs text-slate-400 mt-0.5">
                Klik kode alat untuk membuka detail dan riwayat laporannya.
            </p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-100">
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3 whitespace-nowrap">Tech Ident No</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3 whitespace-nowrap">Equipment No</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">Deskripsi</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3 whitespace-nowrap">Tipe</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3 whitespace-nowrap">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($alat as $item)
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-5 py-3.5 align-top whitespace-nowrap">
                                <a href="{{ route('assets.show', $item) }}"
                                   class="text-xs font-mono font-medium text-teal-700 hover:underline">
                                    {{ $item->tech_ident_no ?? '(tanpa Tech Ident No)' }}
                                </a>
                            </td>
                            <td class="px-4 py-3.5 align-top whitespace-nowrap font-mono text-xs text-slate-500">
                                {{ $item->equipment_no ?? '-' }}
                            </td>
                            <td class="px-4 py-3.5 align-top">
                                <p class="text-slate-800 leading-snug whitespace-normal break-words">
                                    {{ $item->description ?? '-' }}
                                </p>
                            </td>
                            <td class="px-4 py-3.5 align-top whitespace-nowrap">
                                @if($item->equipment_type)
                                    <span class="text-xs text-slate-600">{{ $item->equipment_type }}</span>
                                @else
                                    <span class="text-xs text-slate-300">TANPA TIPE</span>
                                @endif
                            </td>
                            <td class="px-4 py-3.5 align-top whitespace-nowrap">
                                <span class="text-xs text-slate-600">{{ $item->status ?? '-' }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-10 text-center text-sm text-slate-400">
                                Belum ada alat terdaftar pada lokasi ini.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($alat->hasPages())
            <div class="px-5 py-4 border-t border-slate-100">
                {{ $alat->links() }}
            </div>
        @endif
    </div>

@endsection
