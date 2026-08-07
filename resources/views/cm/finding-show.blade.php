@extends('layouts.app')

@section('title', $finding->kode_finding . ' — Detail Finding')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('cm.overview') }}" class="hover:text-slate-700">Condition Monitoring</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('cm.findings') }}" class="hover:text-slate-700">Finding CM</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">{{ $finding->kode_finding }}</span>
@endsection

@section('content')
    @include('cm._tabs')

    @php
        $sevColors = ['low' => 'bg-blue-100 text-blue-700', 'medium' => 'bg-amber-100 text-amber-700', 'high' => 'bg-red-100 text-red-700'];
        $sevColor = $sevColors[$finding->severity] ?? 'bg-slate-100 text-slate-600';
        $wo = $finding->workOrder;
    @endphp

    {{-- Header Finding --}}
    <div class="bg-white rounded-xl border border-slate-200 p-6 mb-6">
        <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
            <div class="min-w-0">
                <div class="flex items-center gap-3 flex-wrap">
                    <h2 class="text-2xl font-bold font-mono" style="color: #0E9E8E;">{{ $finding->kode_finding }}</h2>
                    <span class="inline-flex px-2.5 py-0.5 text-xs font-medium rounded-full {{ $sevColor }}">{{ ucfirst($finding->severity) }}</span>
                    @if($finding->status === 'open')
                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-700">
                            <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                            Open
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">
                            <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>
                            Closed
                        </span>
                    @endif
                </div>
                <p class="text-sm text-slate-500 mt-2">
                    Equipment:
                    @if($finding->equipment)
                        <a href="{{ route('cm.equipment-show', $finding->equipment->equipment_tag) }}"
                           class="font-mono font-semibold text-teal-600 hover:text-teal-800 hover:underline">
                            {{ $finding->equipment->equipment_tag }}
                        </a>
                        &middot; {{ $finding->equipment->pt_location }}
                    @else
                        <span class="text-slate-400">(equipment dihapus)</span>
                    @endif
                </p>
            </div>
            <div class="shrink-0 flex items-center gap-2">
                <a href="{{ route('cm.findings') }}" class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors inline-flex items-center gap-1.5">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                    Kembali
                </a>
                @if(!$wo && $finding->status === 'open')
                    <a href="{{ route('work-orders.create', ['equipment_tag' => $finding->equipment?->equipment_tag, 'linked_finding_id' => $finding->id]) }}"
                       class="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white text-sm font-medium rounded-lg transition-colors inline-flex items-center gap-1.5">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        + Work Order
                    </a>
                @elseif($wo)
                    <a href="{{ route('work-orders.show', $wo) }}"
                       class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white text-sm font-medium rounded-lg transition-colors inline-flex items-center gap-1.5">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                        Lihat WO
                    </a>
                @endif
            </div>
        </div>
    </div>

    {{-- Detail Finding --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        {{-- Kolom Kiri: Info Finding --}}
        <div class="bg-white rounded-xl border border-slate-200 p-5">
            <h3 class="text-sm font-semibold text-slate-900 mb-4">Informasi Finding</h3>
            <div class="space-y-3">
                <div>
                    <p class="text-xs text-slate-400 uppercase tracking-wide">Kategori</p>
                    <p class="text-sm font-medium text-slate-800">{{ $finding->kategori ?? '—' }}</p>
                </div>
                <div>
                    <p class="text-xs text-slate-400 uppercase tracking-wide">PIC</p>
                    <p class="text-sm font-medium text-slate-800">{{ $finding->pic ?? '—' }}</p>
                </div>
                <div>
                    <p class="text-xs text-slate-400 uppercase tracking-wide">Tanggal Temuan</p>
                    <p class="text-sm font-medium text-slate-800">{{ $finding->tanggal_temuan ? $finding->tanggal_temuan->format('d M Y') : '—' }}</p>
                </div>
                <div>
                    <p class="text-xs text-slate-400 uppercase tracking-wide">Hari Open</p>
                    <p class="text-sm font-medium {{ $finding->status === 'open' && $finding->hari_open > 7 ? 'text-red-600' : 'text-slate-800' }}">
                        {{ $finding->status === 'open' ? ($finding->hari_open . ' hari') : '—' }}
                    </p>
                </div>
                @if($finding->date_action)
                <div>
                    <p class="text-xs text-slate-400 uppercase tracking-wide">Tanggal Tindakan</p>
                    <p class="text-sm font-medium text-slate-800">{{ $finding->date_action->format('d M Y') }}</p>
                </div>
                @endif
            </div>
        </div>

        {{-- Kolom Kanan: Deskripsi & Analisis --}}
        <div class="lg:col-span-2 space-y-6">
            @if($finding->deskripsi)
            <div class="bg-white rounded-xl border border-slate-200 p-5">
                <h3 class="text-sm font-semibold text-slate-900 mb-3">Deskripsi</h3>
                <p class="text-sm text-slate-700 leading-relaxed">{{ $finding->deskripsi }}</p>
            </div>
            @endif

            @if($finding->analysis)
            <div class="bg-white rounded-xl border border-slate-200 p-5">
                <h3 class="text-sm font-semibold text-slate-900 mb-3">Analisis</h3>
                <p class="text-sm text-slate-700 leading-relaxed">{{ $finding->analysis }}</p>
            </div>
            @endif

            @if($finding->action)
            <div class="bg-white rounded-xl border border-slate-200 p-5">
                <h3 class="text-sm font-semibold text-slate-900 mb-3">Tindakan</h3>
                <p class="text-sm text-slate-700 leading-relaxed">{{ $finding->action }}</p>
            </div>
            @endif
        </div>
    </div>

    {{-- Foto --}}
    @php
        $allPhotos = [];
        if ($finding->foto_url) {
            $allPhotos[] = $finding->foto_url;
        }
        if ($finding->foto_urls && is_array($finding->foto_urls)) {
            foreach ($finding->foto_urls as $url) {
                if (!in_array($url, $allPhotos)) {
                    $allPhotos[] = $url;
                }
            }
        }
        $photoCount = count($allPhotos);
    @endphp
    @if($photoCount > 0)
    <div class="bg-white rounded-xl border border-slate-200 p-5 mb-6">
        <h3 class="text-sm font-semibold text-slate-900 mb-4">
            Foto
            @if($photoCount > 1)
                <span class="text-xs font-normal text-slate-400">({{ $photoCount }} foto)</span>
            @endif
        </h3>
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
            @foreach($allPhotos as $idx => $photoUrl)
            <div class="relative group rounded-lg border border-slate-200 overflow-hidden bg-slate-50">
                <img src="{{ $photoUrl }}"
                     alt="Foto Finding {{ $idx + 1 }}"
                     class="w-full aspect-square object-cover cursor-pointer hover:opacity-90 transition-opacity"
                     onclick="openModal('findingDetailPhoto{{ $finding->id }}_{{ $idx }}')">
                @if($photoCount > 1)
                <div class="absolute top-1.5 left-1.5 bg-black/50 text-white text-[10px] font-medium px-1.5 py-0.5 rounded">
                    {{ $idx + 1 }}/{{ $photoCount }}
                </div>
                @endif
            </div>
            @endforeach
        </div>
    </div>

    {{-- Modal untuk setiap foto --}}
    @foreach($allPhotos as $idx => $photoUrl)
    <x-modal name="findingDetailPhoto{{ $finding->id }}_{{ $idx }}"
             title="Foto Finding — {{ $finding->kode_finding }} ({{ $idx + 1 }}/{{ $photoCount }})"
             maxWidth="lg">
        <div class="space-y-4">
            <img src="{{ $photoUrl }}" alt="Foto Finding {{ $idx + 1 }}" class="w-full rounded-lg">
            <div class="flex items-center justify-between">
                <div class="text-xs text-slate-400">
                    @if($idx > 0)
                    <button onclick="openModal('findingDetailPhoto{{ $finding->id }}_{{ $idx - 1 }}'); closeModal('findingDetailPhoto{{ $finding->id }}_{{ $idx }}')"
                            class="px-3 py-1.5 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors inline-flex items-center gap-1">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                        Sebelumnya
                    </button>
                    @endif
                    @if($idx < $photoCount - 1)
                    <button onclick="openModal('findingDetailPhoto{{ $finding->id }}_{{ $idx + 1 }}'); closeModal('findingDetailPhoto{{ $finding->id }}_{{ $idx }}')"
                            class="px-3 py-1.5 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors inline-flex items-center gap-1">
                        Selanjutnya
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    </button>
                    @endif
                </div>
                <button onclick="closeModal('findingDetailPhoto{{ $finding->id }}_{{ $idx }}')"
                        class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                    Tutup
                </button>
            </div>
        </div>
    </x-modal>
    @endforeach
    @endif

    {{-- Linked Work Order --}}
    @if($wo)
    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden mb-6">
        <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-slate-900">Work Order Terkait</h3>
        </div>
        <div class="p-5">
            <div class="flex items-center justify-between">
                <div>
                    <a href="{{ route('work-orders.show', $wo) }}" class="font-mono font-semibold text-teal-600 hover:text-teal-800 hover:underline">
                        {{ $wo->wo_number ?? '#' . $wo->id }}
                    </a>
                    <p class="text-xs text-slate-500 mt-0.5">{{ $wo->jenis_pekerjaan ?? '—' }}</p>
                </div>
                <span class="inline-flex items-center px-2.5 py-0.5 text-xs font-medium rounded-full
                    {{ $wo->status === 'open' ? 'bg-amber-100 text-amber-700' : ($wo->status === 'closed' ? 'bg-green-100 text-green-700' : 'bg-blue-100 text-blue-700') }}">
                    {{ ucfirst($wo->status ?? '—') }}
                </span>
            </div>
        </div>
    </div>
    @endif
@endsection
