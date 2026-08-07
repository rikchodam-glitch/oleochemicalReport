@extends('layouts.app')

@section('title', 'Detail Laporan Kerjaan')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('work-orders.index') }}" class="hover:text-slate-700">Laporan Kerjaan</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Detail</span>
@endsection

@section('content')
    <div class="max-w-3xl mx-auto space-y-6">
        {{-- Header Card --}}
        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <div class="flex items-center gap-3 flex-wrap">
                        <h2 class="text-xl font-bold font-mono" style="color: #0E9E8E;">{{ $workOrder->equipment_tag }}</h2>
                        @php
                            $jc = ['CM' => 'bg-red-100 text-red-700 border-red-200', 'dCM' => 'bg-amber-100 text-amber-700 border-amber-200', 'PM' => 'bg-blue-100 text-blue-700 border-blue-200'];
                        @endphp
                        <span class="inline-flex px-3 py-1 text-xs font-medium rounded-full border {{ $jc[$workOrder->jenis_pekerjaan] ?? 'bg-slate-100 text-slate-600' }}">
                            {{ $workOrder->jenis_pekerjaan }}
                        </span>
                    </div>
                    @if($workOrder->cmEquipment)
                        <p class="text-sm text-slate-500 mt-1">
                            {{ $workOrder->cmEquipment->pt_location }}
                            @if($workOrder->cmEquipment->plant)
                                &middot; {{ $workOrder->cmEquipment->plant }}
                            @endif
                        </p>
                    @endif
                </div>
                <div class="flex gap-2">
                    <a href="{{ route('work-orders.edit', $workOrder) }}"
                       class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
                        Edit
                    </a>
                    <form method="POST" action="{{ route('work-orders.destroy', $workOrder) }}"
                          onsubmit="return confirm('Hapus laporan kerjaan ini?')" class="inline">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="px-4 py-2 border border-red-300 hover:bg-red-50 text-red-600 text-sm font-medium rounded-lg transition-colors">
                            Hapus
                        </button>
                    </form>
                </div>
            </div>
        </div>

        {{-- Detail Info --}}
        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <h3 class="text-sm font-semibold text-slate-900 mb-4">Informasi Pekerjaan</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div>
                    <p class="text-xs text-slate-400 uppercase tracking-wide">Tanggal Kejadian</p>
                    <p class="text-sm font-medium text-slate-800 mt-0.5">{{ $workOrder->tanggal_kejadian->format('d M Y') }}</p>
                </div>
                <div>
                    <p class="text-xs text-slate-400 uppercase tracking-wide">Tanggal Eksekusi Selesai</p>
                    <p class="text-sm font-medium text-slate-800 mt-0.5">{{ $workOrder->tanggal_eksekusi_selesai->format('d M Y') }}</p>
                </div>
                <div>
                    <p class="text-xs text-slate-400 uppercase tracking-wide">Jenis Pekerjaan</p>
                    <p class="text-sm font-medium text-slate-800 mt-0.5">{{ $workOrder->jenis_pekerjaan }}</p>
                </div>
                <div>
                    <p class="text-xs text-slate-400 uppercase tracking-wide">Sesuai Rencana</p>
                    <p class="text-sm font-medium text-slate-800 mt-0.5">
                        @if($workOrder->jenis_pekerjaan === 'dCM')
                            {{ $workOrder->sesuai_rencana === 'ya' ? 'Ya' : ($workOrder->sesuai_rencana === 'tidak' ? 'Tidak' : '—') }}
                        @else
                            <span class="text-slate-300">— (tidak berlaku)</span>
                        @endif
                    </p>
                </div>
                <div>
                    <p class="text-xs text-slate-400 uppercase tracking-wide">Linked Finding</p>
                    <p class="text-sm font-medium text-slate-800 mt-0.5">
                        @if($workOrder->linkedFinding)
                            <span class="font-mono text-teal-600">{{ $workOrder->linkedFinding->kode_finding ?? '#' . $workOrder->linked_finding_id }}</span>
                            <span class="text-xs text-slate-400">({{ $workOrder->linkedFinding->kategori }})</span>
                        @else
                            <span class="text-slate-300">—</span>
                        @endif
                    </p>
                </div>
                <div>
                    <p class="text-xs text-slate-400 uppercase tracking-wide">Dibuat Oleh</p>
                    <p class="text-sm font-medium text-slate-800 mt-0.5">{{ $workOrder->creator?->name ?? '—' }}</p>
                </div>
            </div>

            @if($workOrder->deskripsi)
                <div class="mt-5 pt-5 border-t border-slate-100">
                    <p class="text-xs text-slate-400 uppercase tracking-wide mb-1">Deskripsi</p>
                    <p class="text-sm text-slate-700 whitespace-pre-wrap">{{ $workOrder->deskripsi }}</p>
                </div>
            @endif

            @if($workOrder->root_cause)
                <div class="mt-4 pt-4 border-t border-slate-100">
                    <p class="text-xs text-slate-400 uppercase tracking-wide mb-1">Root Cause</p>
                    <p class="text-sm text-slate-800 font-medium">{{ $workOrder->root_cause }}</p>
                </div>
            @endif
        </div>

        {{-- Back --}}
        <div class="text-center">
            <a href="{{ route('work-orders.index') }}"
               class="inline-flex items-center gap-1.5 text-sm text-teal-600 hover:text-teal-700 font-medium">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                Kembali ke Daftar Laporan Kerjaan
            </a>
        </div>
    </div>
@endsection
