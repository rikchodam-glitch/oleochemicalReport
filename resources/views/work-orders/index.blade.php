@extends('layouts.app')

@section('title', 'Laporan Kerjaan — Corrective Maintenance')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Laporan Kerjaan</span>
@endsection

@section('content')
    {{-- Summary Cards --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-2xl font-bold text-red-600">{{ $workOrders->where('jenis_pekerjaan', 'CM')->count() }}</p>
            <p class="text-xs text-slate-500 mt-1">CM (Corrective)</p>
        </div>
        <div class="bg-white rounded-xl border border-amber-200 p-4">
            <p class="text-2xl font-bold text-amber-600">{{ $workOrders->where('jenis_pekerjaan', 'dCM')->count() }}</p>
            <p class="text-xs text-slate-500 mt-1">dCM (Deferred)</p>
        </div>
        <div class="bg-white rounded-xl border border-blue-200 p-4">
            <p class="text-2xl font-bold text-blue-600">{{ $workOrders->where('jenis_pekerjaan', 'PM')->count() }}</p>
            <p class="text-xs text-slate-500 mt-1">PM (Preventive)</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-2xl font-bold text-slate-900">{{ $workOrders->total() }}</p>
            <p class="text-xs text-slate-500 mt-1">Total Work Order</p>
        </div>
    </div>

    {{-- Filters --}}
    <div class="bg-white rounded-xl border border-slate-200 p-4 mb-6">
        <form method="GET" class="flex flex-wrap gap-4 items-end">
            <div class="space-y-1">
                <label class="text-xs font-medium text-slate-500">PT</label>
                <select name="pt" class="border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-teal-500">
                    <option value="">Semua PT</option>
                    @foreach($ptList as $pt)
                        <option value="{{ $pt }}" {{ $filterPt === $pt ? 'selected' : '' }}>{{ $pt }}</option>
                    @endforeach
                </select>
            </div>
            <div class="space-y-1">
                <label class="text-xs font-medium text-slate-500">Jenis Pekerjaan</label>
                <select name="jenis" class="border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-teal-500">
                    <option value="">Semua Jenis</option>
                    <option value="CM" {{ $filterJenis === 'CM' ? 'selected' : '' }}>CM</option>
                    <option value="dCM" {{ $filterJenis === 'dCM' ? 'selected' : '' }}>dCM</option>
                    <option value="PM" {{ $filterJenis === 'PM' ? 'selected' : '' }}>PM</option>
                </select>
            </div>
            <div class="space-y-1">
                <label class="text-xs font-medium text-slate-500">Equipment Tag</label>
                <select name="equipment_tag" class="border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-teal-500">
                    <option value="">Semua Equipment</option>
                    @foreach($equipmentList as $tag)
                        <option value="{{ $tag }}" {{ $filterTag === $tag ? 'selected' : '' }}>{{ $tag }}</option>
                    @endforeach
                </select>
            </div>
            <div class="space-y-1">
                <label class="text-xs font-medium text-slate-500">Dari Tanggal</label>
                <input type="date" name="dari" value="{{ $filterDari }}"
                       class="border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
            </div>
            <div class="space-y-1">
                <label class="text-xs font-medium text-slate-500">Sampai Tanggal</label>
                <input type="date" name="sampai" value="{{ $filterSampai }}"
                       class="border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
            </div>
            <div class="space-y-1 flex-1 min-w-[200px]">
                <label class="text-xs font-medium text-slate-500">Cari</label>
                <input type="text" name="search" value="{{ $search }}"
                       placeholder="Cari tag equipment, deskripsi, root cause..."
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
            </div>
            <button type="submit" class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white text-sm font-medium rounded-lg transition-colors">
                Filter
            </button>
            <a href="{{ route('work-orders.index') }}" class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                Reset
            </a>
            <a href="{{ route('work-orders.create') }}"
               class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white text-sm font-medium rounded-lg transition-colors inline-flex items-center gap-1.5">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                Tambah Laporan
            </a>
        </form>
    </div>

    {{-- Work Orders Table --}}
    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden mb-6">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-100">
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">Equipment Tag</th>
                        <th class="text-center text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">Jenis</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">Tgl Kejadian</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">Tgl Eksekusi</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">Linked Finding</th>
                        <th class="text-center text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">Sesuai Rencana</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">Dibuat</th>
                        <th class="text-right text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($workOrders as $wo)
                        @php
                            $jenisColors = [
                                'CM'  => 'bg-red-100 text-red-700 border-red-200',
                                'dCM' => 'bg-amber-100 text-amber-700 border-amber-200',
                                'PM'  => 'bg-blue-100 text-blue-700 border-blue-200',
                            ];
                            $jColor = $jenisColors[$wo->jenis_pekerjaan] ?? 'bg-slate-100 text-slate-600';
                            $sesuaiLabel = match($wo->sesuai_rencana) {
                                'ya'    => '<span class="inline-flex px-2 py-0.5 text-xs font-medium rounded-full bg-green-100 text-green-700">Ya</span>',
                                'tidak' => '<span class="inline-flex px-2 py-0.5 text-xs font-medium rounded-full bg-red-100 text-red-700">Tidak</span>',
                                default => '<span class="text-slate-300 text-xs">—</span>',
                            };
                        @endphp
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-4 py-3">
                                <span class="font-mono text-sm font-medium text-slate-900">{{ $wo->equipment_tag }}</span>
                                @if($wo->cmEquipment)
                                    <p class="text-xs text-slate-400">{{ $wo->cmEquipment->pt_location }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span class="inline-flex px-2.5 py-0.5 text-xs font-medium rounded-full border {{ $jColor }}">
                                    {{ $wo->jenis_pekerjaan }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-600 whitespace-nowrap">
                                {{ $wo->tanggal_kejadian->format('d M Y') }}
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-600 whitespace-nowrap">
                                {{ $wo->tanggal_eksekusi_selesai->format('d M Y') }}
                            </td>
                            <td class="px-4 py-3 text-xs">
                                @if($wo->linkedFinding)
                                    <span class="font-mono text-teal-600">{{ $wo->linkedFinding->kode_finding ?? '#' . $wo->linked_finding_id }}</span>
                                    <p class="text-slate-400 text-xs">{{ $wo->linkedFinding->kategori ?? '' }}</p>
                                @else
                                    <span class="text-slate-300">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-center text-xs">{!! $sesuaiLabel !!}</td>
                            <td class="px-4 py-3 text-xs text-slate-500 whitespace-nowrap">
                                {{ $wo->creator?->name ?? '—' }}
                                <p class="text-slate-400">{{ $wo->created_at->format('d M Y') }}</p>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ route('work-orders.show', $wo) }}"
                                       class="text-xs text-teal-600 hover:text-teal-700 font-medium">
                                        Detail
                                    </a>
                                    <a href="{{ route('work-orders.edit', $wo) }}"
                                       class="text-xs text-blue-600 hover:text-blue-700 font-medium">
                                        Edit
                                    </a>
                                    <form method="POST" action="{{ route('work-orders.destroy', $wo) }}"
                                          onsubmit="return confirm('Hapus laporan kerjaan ini?')" class="inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-xs text-red-500 hover:text-red-600 font-medium">
                                            Hapus
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-5 py-8 text-center text-slate-400 text-sm">
                                Belum ada laporan kerjaan. 
                                <a href="{{ route('work-orders.create') }}" class="text-teal-600 hover:underline">Buat laporan baru</a>.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($workOrders->hasPages())
            <div class="px-5 py-3 border-t border-slate-100">
                {{ $workOrders->links() }}
            </div>
        @endif
    </div>
@endsection
