@extends('layouts.app')

@section('title', 'Barang Kembali')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    @if($tampilTabs)
        <a href="{{ route('tools.index') }}" class="hover:text-slate-700">Management Tools</a>
    @else
        <a href="{{ route('alat.index') }}" class="hover:text-slate-700">Peminjaman Alat</a>
    @endif
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Barang Kembali</span>
@endsection

@section('content')
<div class="space-y-4">
    @if($tampilTabs)
        @include('tools.partials._tabs')
    @else
        <div class="flex items-center justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold text-slate-900">Barang Kembali</h2>
                <p class="text-xs text-slate-500">Periksa fisik alat sebelum menekan Terima.</p>
            </div>
            <a href="{{ route('alat.index') }}" class="text-sm font-semibold text-teal-700 hover:text-teal-800">Kembali ke Peminjaman Alat</a>
        </div>
    @endif

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

    @if($tampilTabs)
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <x-stat-card label="Total Alat" :value="\App\Models\Tool::query()->count()" icon="box" />
            <x-stat-card label="Tersedia" :value="\App\Models\Tool::query()->where('status', 'tersedia')->count()" value-color="text-green-600" icon="box" />
            <x-stat-card label="Dipakai" :value="\App\Models\Tool::query()->where('status', 'dipakai')->count()" value-color="text-amber-600" icon="document" />
            <x-stat-card label="Menunggu Diterima" :value="\App\Models\ToolLoan::query()->menungguKembali()->count()" value-color="text-amber-600" icon="alert" />
        </div>
    @endif

    <form method="GET" action="{{ route($tampilTabs ? 'tools.returns.index' : 'alat.returns.index') }}"
          class="bg-white rounded-xl border border-slate-200 p-3 flex flex-wrap items-end gap-2">
        <div class="flex-1 min-w-[200px]">
            <label class="block text-[11px] font-medium text-slate-600 mb-1">Cari pengajuan</label>
            <input type="search" name="search" value="{{ $kataKunci }}" placeholder="Nama alat, kode, atau peminjam..."
                   class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
        </div>
        <div>
            <label class="block text-[11px] font-medium text-slate-600 mb-1">Lokasi</label>
            <select name="lokasi" class="rounded-lg border-slate-300 text-xs focus:border-teal-500 focus:ring-teal-500">
                <option value="">Semua lokasi</option>
                @foreach($daftarLokasi as $lokasi)
                    <option value="{{ $lokasi->id }}" @selected((string) $filter['lokasi'] === (string) $lokasi->id)>{{ $lokasi->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-[11px] font-medium text-slate-600 mb-1">Jenis</label>
            <select name="jenis" class="rounded-lg border-slate-300 text-xs focus:border-teal-500 focus:ring-teal-500">
                <option value="">Semua jenis</option>
                @foreach($daftarJenis as $jenis)
                    <option value="{{ $jenis->id }}" @selected((string) $filter['jenis'] === (string) $jenis->id)>{{ $jenis->name }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="px-3 py-2 rounded-lg bg-teal-600 text-white text-sm font-semibold hover:bg-teal-700">Saring</button>
        <a href="{{ route($tampilTabs ? 'tools.returns.index' : 'alat.returns.index') }}"
           class="px-3 py-2 rounded-lg border border-slate-300 text-sm text-slate-600 hover:bg-slate-50">Reset</a>
        <span class="ml-auto text-[11px] text-slate-400">{{ $daftar->total() }} pengajuan</span>
    </form>

    <div class="flex flex-wrap items-center justify-end gap-2">
        <button type="button" data-collapse-all="buka"
                class="px-3 py-1.5 rounded-lg border border-slate-300 text-xs font-semibold text-slate-600 hover:bg-slate-50">Buka semua</button>
        <button type="button" data-collapse-all="tutup"
                class="px-3 py-1.5 rounded-lg border border-slate-300 text-xs font-semibold text-slate-600 hover:bg-slate-50">Tutup semua</button>
    </div>

    <div class="space-y-3">
        @forelse($grup as $lokasi)
            <section class="bg-white rounded-xl border border-slate-200 overflow-hidden">
                <button type="button" class="w-full flex items-center justify-between gap-3 px-3 py-2.5 bg-slate-50 hover:bg-slate-100 text-left"
                        data-collapse-toggle aria-expanded="true" aria-controls="kembali-lokasi-{{ $lokasi['id'] }}">
                    <span class="flex items-center gap-2 text-sm font-semibold text-slate-800">
                        <svg data-collapse-chevron class="w-4 h-4 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                        </svg>
                        {{ $lokasi['name'] }}
                    </span>
                    <span class="text-xs text-slate-500">{{ collect($lokasi['rak'])->sum(fn($rak) => count($rak['loans'])) }} pengajuan</span>
                </button>

                <div id="kembali-lokasi-{{ $lokasi['id'] }}" class="px-3 py-2.5 space-y-3">
                    @foreach($lokasi['rak'] as $rak)
                        <div class="space-y-1.5">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Rak: {{ $rak['name'] }}</p>
                            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-2">
                                @foreach($rak['loans'] as $pinjaman)
                                    <div class="bg-white rounded-xl border border-slate-200 p-2.5 flex gap-3">
                                        <div class="w-20 h-20 rounded-lg bg-slate-100 overflow-hidden shrink-0">
                                            @if($pinjaman['foto_url'])
                                                <img src="{{ $pinjaman['foto_url'] }}" alt="{{ $pinjaman['group'] }}" class="w-full h-full object-cover">
                                            @endif
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-start justify-between gap-1">
                                                <div class="min-w-0">
                                                    @if($pinjaman['tool_id'])
                                                        <a href="{{ route('alat.show', $pinjaman['tool_id']) }}"
                                                           class="block text-sm font-semibold text-slate-900 truncate hover:text-teal-700">{{ $pinjaman['group'] }}</a>
                                                    @else
                                                        <p class="text-sm font-semibold text-slate-900">{{ $pinjaman['group'] }}</p>
                                                    @endif
                                                    <p class="text-[11px] font-mono text-slate-500">{{ $pinjaman['code'] }}</p>
                                                </div>
                                                <span class="px-1.5 py-0.5 rounded-full text-[10px] font-semibold bg-amber-100 text-amber-700">Menunggu diterima</span>
                                            </div>
                                            <p class="mt-1 text-[11px] text-slate-600">
                                                Peminjam: <span class="font-medium">{{ $pinjaman['peminjam'] }}</span><br>
                                                Diajukan: {{ $pinjaman['diminta'] }} · {{ $pinjaman['durasi'] }}<br>
                                                Oleh: {{ $pinjaman['pengaju'] }}
                                            </p>
                                            @if($bolehTulis)
                                                <form method="POST" action="{{ route($namaRuteTerima, $pinjaman['id']) }}"
                                                      class="mt-1.5"
                                                      onsubmit="return confirm('Terima kembali {{ $pinjaman['tool'] }} ({{ $pinjaman['code'] }})?')">
                                                    @csrf
                                                    <button type="submit" class="px-2.5 py-1.5 rounded-md bg-teal-600 text-white text-[11px] font-semibold hover:bg-teal-700">
                                                        Terima
                                                    </button>
                                                </form>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @empty
            <div class="bg-white rounded-xl border border-slate-200 p-8 text-center text-sm text-slate-500">
                Tidak ada pengajuan pengembalian pada filter ini.
            </div>
        @endforelse
    </div>

    @if($daftar->hasPages())
        <div class="bg-white rounded-xl border border-slate-200 px-4 py-3">{{ $daftar->links() }}</div>
    @endif
</div>
@endsection

@push('scripts')
@include('tools.partials._collapse-script')
@endpush
