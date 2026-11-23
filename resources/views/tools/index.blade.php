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
            <p class="text-xs text-slate-500">Card ringkas per unit, dikelompokkan menurut Lokasi dan Rak.</p>
        </div>
        @if($bolehTulis)
            <button type="button" id="tombol-tambah-alat"
                    class="px-4 py-2 rounded-lg bg-teal-600 text-white text-sm font-semibold hover:bg-teal-700">
                Tambah Alat
            </button>
        @endif
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-5 gap-3">
        <x-stat-card label="Total Alat" :value="$ringkasan['total']" icon="box" />
        <x-stat-card label="Tersedia" :value="$ringkasan['tersedia']" value-color="text-green-600" icon="box" />
        <x-stat-card label="Dipakai" :value="$ringkasan['dipakai']" value-color="text-amber-600" icon="document" />
        <x-stat-card label="Rusak" :value="$ringkasan['rusak']" value-color="text-red-600" icon="alert" />
        <a href="{{ route('tools.returns.index') }}">
            <x-stat-card label="Barang Kembali" :value="$ringkasan['kembali']" value-color="text-amber-600" icon="document" />
        </a>
    </div>

    @include('partials._pencarian-entitas', [
        'aksi'              => route('tools.index'),
        'saranUrl'          => route('tools.cari'),
        'idInput'           => 'toolsSearch',
        'placeholder'       => 'Cari kelompok, kode unit, jenis, lokasi, atau rak...',
        'kataKunci'         => $kataKunci,
        'total'             => $daftar->total(),
        'satuan'            => 'alat',
        'petunjuk'          => 'Pencarian mencakup kelompok, kode, jenis, lokasi, dan rak.',
        'filterTersembunyi' => array_filter([
            'lokasi' => $filter['lokasi'],
            'rak'    => $filter['rak'],
            'jenis'  => $filter['jenis'],
            'grup'   => $filter['grup'],
            'status' => $filter['status'],
        ]),
    ])

    <form method="GET" action="{{ route('tools.index') }}" class="bg-white rounded-xl border border-slate-200 p-3 flex flex-wrap items-end gap-2">
        @if($kataKunci !== '')
            <input type="hidden" name="search" value="{{ $kataKunci }}">
        @endif
        <div>
            <label for="filter-lokasi" class="block text-[11px] font-medium text-slate-600 mb-1">Lokasi</label>
            <select id="filter-lokasi" name="lokasi" onchange="this.form.requestSubmit()"
                    class="rounded-lg border-slate-300 text-xs focus:border-teal-500 focus:ring-teal-500">
                <option value="">Semua lokasi</option>
                @foreach($daftarLokasi as $lokasi)
                    <option value="{{ $lokasi->id }}" @selected((string) $filter['lokasi'] === (string) $lokasi->id)>{{ $lokasi->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="filter-rak" class="block text-[11px] font-medium text-slate-600 mb-1">Rak</label>
            <select id="filter-rak" name="rak" onchange="this.form.requestSubmit()"
                    class="rounded-lg border-slate-300 text-xs focus:border-teal-500 focus:ring-teal-500">
                <option value="">Semua rak</option>
                @foreach($daftarRak->groupBy(fn($rak) => $rak->location?->name ?? 'Tanpa Lokasi') as $namaLokasi => $rakLokasi)
                    <optgroup label="{{ $namaLokasi }}">
                        @foreach($rakLokasi as $rak)
                            <option value="{{ $rak->id }}" @selected((string) $filter['rak'] === (string) $rak->id)>{{ $rak->name }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
        </div>
        <div>
            <label for="filter-jenis" class="block text-[11px] font-medium text-slate-600 mb-1">Jenis</label>
            <select id="filter-jenis" name="jenis" onchange="this.form.requestSubmit()"
                    class="rounded-lg border-slate-300 text-xs focus:border-teal-500 focus:ring-teal-500">
                <option value="">Semua jenis</option>
                @foreach($daftarJenis as $jenis)
                    <option value="{{ $jenis->id }}" @selected((string) $filter['jenis'] === (string) $jenis->id)>{{ $jenis->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="filter-grup" class="block text-[11px] font-medium text-slate-600 mb-1">Kelompok</label>
            <select id="filter-grup" name="grup" onchange="this.form.requestSubmit()"
                    class="rounded-lg border-slate-300 text-xs focus:border-teal-500 focus:ring-teal-500">
                <option value="">Semua kelompok</option>
                @foreach($daftarKelompok as $kelompok)
                    <option value="{{ $kelompok->id }}" @selected((string) $filter['grup'] === (string) $kelompok->id)>{{ $kelompok->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="filter-status" class="block text-[11px] font-medium text-slate-600 mb-1">Status</label>
            <select id="filter-status" name="status" onchange="this.form.requestSubmit()"
                    class="rounded-lg border-slate-300 text-xs focus:border-teal-500 focus:ring-teal-500">
                <option value="">Semua status</option>
                <option value="tersedia" @selected($filter['status'] === 'tersedia')>Tersedia</option>
                <option value="dipakai" @selected($filter['status'] === 'dipakai')>Dipakai</option>
                <option value="rusak" @selected($filter['status'] === 'rusak')>Rusak</option>
            </select>
        </div>
        <a href="{{ route('tools.index', $kataKunci !== '' ? ['search' => $kataKunci] : []) }}"
           class="px-3 py-2 rounded-lg border border-slate-300 text-xs text-slate-600 hover:bg-slate-50">
            Reset filter
        </a>
        <span class="ml-auto text-[11px] text-slate-400">{{ $daftar->total() }} alat</span>
    </form>

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

    <div id="wadah-grup" class="space-y-3">
        @forelse($grup as $lokasi)
            <section class="bg-white rounded-xl border border-slate-200 overflow-hidden" data-grup-lokasi="{{ $lokasi['id'] }}">
                <button type="button"
                        class="w-full flex items-center justify-between gap-3 px-3 py-2.5 bg-slate-50 hover:bg-slate-100 text-left"
                        data-collapse-toggle aria-expanded="true" aria-controls="lokasi-{{ $lokasi['id'] }}">
                    <span class="flex items-center gap-2 text-sm font-semibold text-slate-800">
                        <svg data-collapse-chevron class="w-4 h-4 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                        </svg>
                        {{ $lokasi['name'] }}
                    </span>
                    <span class="text-xs text-slate-500">{{ collect($lokasi['rak'])->sum(fn($rak) => count($rak['tools'])) }} alat</span>
                </button>

                <div id="lokasi-{{ $lokasi['id'] }}" class="px-3 py-2.5 space-y-3">
                    @foreach($lokasi['rak'] as $rak)
                        <div data-grup-rak="{{ $rak['id'] }}" class="space-y-1.5">
                            <button type="button"
                                    class="w-full flex items-center justify-between gap-2 text-left"
                                    data-collapse-toggle aria-expanded="true" aria-controls="rak-{{ $rak['id'] }}">
                                <span class="flex items-center gap-2">
                                    <svg data-collapse-chevron class="w-3.5 h-3.5 text-slate-400 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                    </svg>
                                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Rak: {{ $rak['name'] }}</span>
                                </span>
                                <span class="text-[11px] text-slate-400">{{ count($rak['tools']) }} alat</span>
                            </button>

                            <div id="rak-{{ $rak['id'] }}" class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-2">
                                @foreach($rak['tools'] as $alat)
                                    @include('tools.partials._kartu-ringkas', [
                                        'alat' => $alat,
                                        'mode' => 'admin',
                                        'bolehTulis' => $bolehTulis,
                                        'lokasiName' => $lokasi['name'],
                                        'rakName' => $rak['name'],
                                        'loopLokasiId' => $lokasi['id'],
                                        'loopRakId' => $rak['id'],
                                    ])
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @empty
            <div class="bg-white rounded-xl border border-slate-200 p-8 text-center">
                <p class="text-sm text-slate-500">
                    {{ $kataKunci !== '' || array_filter($filter) ? 'Tidak ada alat yang cocok dengan pencarian/filter.' : 'Belum ada lokasi/rak/alat.' }}
                </p>
                @if($bolehTulis && $kataKunci === '' && ! array_filter($filter))
                    <a href="{{ route('tools.catalog.index') }}" class="inline-block mt-3 text-sm font-semibold text-teal-700 hover:text-teal-800">
                        Buat Jenis, Kelompok, Lokasi, dan Rak lebih dulu
                    </a>
                @endif
            </div>
        @endforelse
    </div>

    @if($daftar->hasPages())
        <div class="bg-white rounded-xl border border-slate-200 px-4 py-3">
            {{ $daftar->links() }}
        </div>
    @endif
</div>

@if($bolehTulis)
    @include('tools.partials._modal-alat', ['grup' => $grup, 'daftarKelompok' => $daftarKelompok])
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
    const filterLokasi = document.getElementById('filter-lokasi');
    const filterRak = document.getElementById('filter-rak');

    if (filterLokasi && filterRak) {
        filterLokasi.addEventListener('change', function () {
            filterRak.value = '';
        });
    }
})();
</script>
@endpush
