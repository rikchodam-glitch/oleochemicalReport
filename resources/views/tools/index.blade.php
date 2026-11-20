@extends('layouts.app')

@section('title', 'Management Tools')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Management Tools</span>
@endsection

@section('content')
<div class="space-y-4">
    @include('tools.partials._tabs')

    @if(session('success'))
        <x-alert type="success">{{ session('success') }}</x-alert>
    @endif

    @if($errors->any())
        <x-alert type="error" title="Periksa kembali isian">
            <ul class="list-disc ml-4 space-y-0.5">
                @foreach($errors->all() as $pesan)
                    <li>{{ $pesan }}</li>
                @endforeach
            </ul>
        </x-alert>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold text-slate-900">Dashboard Tools</h2>
            <p class="text-xs text-slate-500">Card per unit alat, dikelompokkan menurut Lokasi dan Rak.</p>
        </div>
        @if($bolehTulis)
            <button type="button" id="tombol-tambah-alat"
                    class="px-4 py-2 rounded-lg bg-teal-600 text-white text-sm font-semibold hover:bg-teal-700">
                Tambah Alat
            </button>
        @endif
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <x-stat-card label="Total Alat" :value="$ringkasan['total']" icon="box" />
        <x-stat-card label="Tersedia" :value="$ringkasan['tersedia']" value-color="text-green-600" icon="box" />
        <x-stat-card label="Dipakai" :value="$ringkasan['dipakai']" value-color="text-amber-600" icon="document" />
        <x-stat-card label="Rusak" :value="$ringkasan['rusak']" value-color="text-red-600" icon="alert" />
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-4 flex flex-wrap items-end gap-3">
        <div class="flex-1 min-w-[220px]">
            <label for="cari-tools" class="block text-xs font-medium text-slate-600 mb-1">Pencarian</label>
            <input type="search" id="cari-tools" placeholder="Nama atau kode alat..."
                   class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
        </div>
        <div>
            <label for="filter-lokasi" class="block text-xs font-medium text-slate-600 mb-1">Lokasi</label>
            <select id="filter-lokasi" class="rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                <option value="">Semua lokasi</option>
                @foreach($daftarLokasi as $lokasi)
                    <option value="{{ $lokasi->id }}">{{ $lokasi->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="filter-rak" class="block text-xs font-medium text-slate-600 mb-1">Rak</label>
            <select id="filter-rak" class="rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                <option value="">Semua rak</option>
                @foreach($daftarRak as $rak)
                    <option value="{{ $rak->id }}" data-lokasi="{{ $rak->tool_location_id }}">{{ $rak->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="filter-status" class="block text-xs font-medium text-slate-600 mb-1">Status</label>
            <select id="filter-status" class="rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                <option value="">Semua status</option>
                <option value="tersedia">Tersedia</option>
                <option value="dipakai">Dipakai</option>
                <option value="rusak">Rusak</option>
            </select>
        </div>
        <button type="button" id="reset-filter"
                class="px-3 py-2 rounded-lg border border-slate-300 text-sm text-slate-600 hover:bg-slate-50">
            Reset
        </button>
    </div>

    <div class="flex flex-wrap items-center justify-end gap-2">
        <button type="button" data-collapse-all="buka"
                class="px-3 py-1.5 rounded-lg border border-slate-300 text-xs font-semibold text-slate-600 hover:bg-slate-50">
            Buka semua
        </button>
        <button type="button" data-collapse-all="tutup"
                class="px-3 py-1.5 rounded-lg border border-slate-300 text-xs font-semibold text-slate-600 hover:bg-slate-50">
            Tutup semua
        </button>
    </div>

    <div id="wadah-grup" class="space-y-4">
        @forelse($grup as $lokasi)
            <section class="bg-white rounded-xl border border-slate-200 overflow-hidden" data-grup-lokasi="{{ $lokasi['id'] }}">
                <button type="button"
                        class="w-full flex items-center justify-between gap-3 px-4 py-3 bg-slate-50 hover:bg-slate-100 text-left"
                        data-collapse-toggle
                        aria-expanded="true"
                        aria-controls="lokasi-{{ $lokasi['id'] }}">
                    <span class="flex items-center gap-2 text-sm font-semibold text-slate-800">
                        <svg data-collapse-chevron class="w-4 h-4 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                        </svg>
                        {{ $lokasi['name'] }}
                    </span>
                    <span class="text-xs text-slate-500">{{ collect($lokasi['rak'])->sum(fn($rak) => count($rak['tools'])) }} alat</span>
                </button>

                <div id="lokasi-{{ $lokasi['id'] }}" class="px-4 py-3 space-y-4">
                    @foreach($lokasi['rak'] as $rak)
                        <div data-grup-rak="{{ $rak['id'] }}" class="space-y-2">
                            <button type="button"
                                    class="w-full flex items-center justify-between gap-2 text-left"
                                    data-collapse-toggle
                                    aria-expanded="true"
                                    aria-controls="rak-{{ $rak['id'] }}">
                                <span class="flex items-center gap-2">
                                    <svg data-collapse-chevron class="w-3.5 h-3.5 text-slate-400 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                    </svg>
                                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Rak: {{ $rak['name'] }}</span>
                                </span>
                                <span class="text-[11px] text-slate-400">{{ count($rak['tools']) }} alat</span>
                            </button>

                            <div id="rak-{{ $rak['id'] }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
                                @foreach($rak['tools'] as $alat)
                                    @php
                                        $warnaStatus = [
                                            'tersedia' => 'bg-green-100 text-green-700',
                                            'dipakai'  => 'bg-amber-100 text-amber-700',
                                            'rusak'    => 'bg-red-100 text-red-700',
                                        ][$alat['status']] ?? 'bg-slate-100 text-slate-600';
                                    @endphp

                                    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden flex flex-col cursor-pointer transition-colors hover:border-teal-300"
                                         data-kartu-tool
                                         data-href-card="{{ route('alat.show', $alat['id']) }}"
                                         tabindex="0"
                                         role="link"
                                         aria-label="Buka detail {{ $alat['name'] }} ({{ $alat['code'] }})"
                                         data-cari="{{ strtolower($alat['name'] . ' ' . $alat['code']) }}"
                                         data-lokasi="{{ $lokasi['id'] }}"
                                         data-rak="{{ $rak['id'] }}"
                                         data-status="{{ $alat['status'] }}">
                                        <div class="relative aspect-square bg-slate-100">
                                            @if($alat['foto_url'])
                                                <img src="{{ $alat['foto_url'] }}" alt="{{ $alat['name'] }}"
                                                     class="w-full h-full object-cover" loading="lazy">
                                            @else
                                                <div class="w-full h-full flex items-center justify-center text-slate-300">
                                                    <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                                                    </svg>
                                                </div>
                                            @endif
                                            <span class="absolute top-2 left-2 px-2 py-0.5 rounded-full text-[11px] font-semibold {{ $warnaStatus }}">
                                                {{ $alat['status_label'] }}
                                            </span>
                                        </div>

                                        <div class="p-3 space-y-2 flex-1 flex flex-col">
                                            <div class="flex items-start justify-between gap-2">
                                                <div class="min-w-0">
                                                    <a href="{{ route('alat.show', $alat['id']) }}"
                                                       class="block text-sm font-semibold text-slate-900 truncate hover:text-teal-700">
                                                        {{ $alat['name'] }}
                                                    </a>
                                                    <p class="text-xs text-slate-500">{{ $alat['code'] }}</p>
                                                </div>
                                                @if($bolehTulis && $alat['status'] === 'tersedia')
                                                    <button type="button"
                                                            class="tombol-pinjamkan shrink-0 px-2.5 py-1.5 rounded-lg bg-teal-600 text-white text-xs font-semibold hover:bg-teal-700"
                                                            data-id="{{ $alat['id'] }}"
                                                            data-nama="{{ $alat['name'] }} ({{ $alat['code'] }})">
                                                        Pinjamkan
                                                    </button>
                                                @endif
                                            </div>

                                            <p class="text-[11px] text-slate-400">{{ $lokasi['name'] }} / {{ $rak['name'] }}</p>

                                            <div class="text-xs text-slate-600 flex-1">
                                                @if($alat['status'] === 'dipakai')
                                                    <p>Dipakai oleh <span class="font-semibold">{{ $alat['peminjam'] ?? '-' }}</span></p>
                                                @elseif($alat['peminjam_terakhir'])
                                                    <p>Peminjam terakhir: <span class="font-medium">{{ $alat['peminjam_terakhir'] }}</span></p>
                                                @else
                                                    <p class="text-slate-400">Belum pernah dipinjam</p>
                                                @endif
                                            </div>

                                            <div class="flex items-center justify-between gap-2 pt-2 border-t border-slate-100">
                                                <span class="text-[11px] text-slate-400">Klik kartu untuk detail</span>
                                                <svg class="w-4 h-4 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                                </svg>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @empty
            <div class="bg-white rounded-xl border border-slate-200 p-8 text-center">
                <p class="text-sm text-slate-500">Belum ada lokasi/rak/alat.</p>
                @if($bolehTulis)
                    <a href="{{ route('tools.locations.index') }}" class="inline-block mt-3 text-sm font-semibold text-teal-700 hover:text-teal-800">
                        Buat Lokasi dan Rak lebih dulu
                    </a>
                @endif
            </div>
        @endforelse
    </div>

    <p id="pesan-kosong-filter" class="hidden text-center text-sm text-slate-500 py-6">
        Tidak ada alat yang cocok dengan pencarian/filter.
    </p>
</div>

@if($bolehTulis)
    @include('tools.partials._modal-alat', ['grup' => $grup])
    @include('tools.partials._modal-pinjam', [
        'daftarTeknisi' => $daftarTeknisi,
        'daftarDepartemen' => $daftarDepartemen,
    ])
@endif
@endsection

@push('scripts')
@include('tools.partials._collapse-script')
@include('tools.partials._card-klik-script')
<script>
(function () {
    const kartu = Array.from(document.querySelectorAll('[data-kartu-tool]'));
    const grupRak = Array.from(document.querySelectorAll('[data-grup-rak]'));
    const grupLokasi = Array.from(document.querySelectorAll('[data-grup-lokasi]'));
    const cari = document.getElementById('cari-tools');
    const filterLokasi = document.getElementById('filter-lokasi');
    const filterRak = document.getElementById('filter-rak');
    const filterStatus = document.getElementById('filter-status');
    const pesanKosong = document.getElementById('pesan-kosong-filter');

    function saringOpsiRak() {
        const lokasi = filterLokasi ? filterLokasi.value : '';
        Array.from(filterRak ? filterRak.options : []).forEach(function (opsi, index) {
            if (index === 0) return;
            const cocok = !lokasi || opsi.dataset.lokasi === lokasi;
            opsi.hidden = !cocok;
            if (!cocok && filterRak.value === opsi.value) filterRak.value = '';
        });
    }

    function terapkan() {
        const q = (cari ? cari.value : '').toLowerCase().trim();
        const lokasi = filterLokasi ? filterLokasi.value : '';
        const rak = filterRak ? filterRak.value : '';
        const status = filterStatus ? filterStatus.value : '';
        let jumlahTampil = 0;

        kartu.forEach(function (el) {
            const cocok = (!q || el.dataset.cari.includes(q))
                && (!lokasi || el.dataset.lokasi === lokasi)
                && (!rak || el.dataset.rak === rak)
                && (!status || el.dataset.status === status);

            el.style.display = cocok ? '' : 'none';
            if (cocok) jumlahTampil++;
        });

        grupRak.forEach(function (el) {
            const ada = Array.from(el.querySelectorAll('[data-kartu-tool]')).some(function (k) {
                return k.style.display !== 'none';
            });
            el.style.display = ada ? '' : 'none';
        });

        grupLokasi.forEach(function (el) {
            const ada = Array.from(el.querySelectorAll('[data-kartu-tool]')).some(function (k) {
                return k.style.display !== 'none';
            });
            el.style.display = ada ? '' : 'none';
        });

        if (pesanKosong) pesanKosong.classList.toggle('hidden', jumlahTampil > 0);

        if ((q || lokasi || rak || status) && typeof window.bukaSemuaCollapse === 'function') {
            window.bukaSemuaCollapse();
        }
    }

    [cari, filterStatus].forEach(function (el) {
        if (el) el.addEventListener('input', terapkan);
    });

    if (filterLokasi) {
        filterLokasi.addEventListener('change', function () {
            saringOpsiRak();
            terapkan();
        });
    }

    if (filterRak) filterRak.addEventListener('change', terapkan);

    const reset = document.getElementById('reset-filter');
    if (reset) {
        reset.addEventListener('click', function () {
            if (cari) cari.value = '';
            if (filterLokasi) filterLokasi.value = '';
            if (filterRak) filterRak.value = '';
            if (filterStatus) filterStatus.value = '';
            saringOpsiRak();
            terapkan();
        });
    }

    saringOpsiRak();
    terapkan();
})();
</script>
@endpush
