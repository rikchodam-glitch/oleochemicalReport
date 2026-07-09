@extends('layouts.app')

@section('title', 'Functional Location')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Functional Location</span>
@endsection

@section('header-actions')
    <a href="{{ route('func-locs.create') }}"
       class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        Tambah FuncLoc
    </a>
@endsection

@section('content')
    <!-- Stat Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <x-stat-card label="Total FuncLoc" :value="$stats['total']" icon="box" />
        <x-stat-card label="Aktif" :value="$stats['active']" value-color="text-green-600" icon="box" />
        <x-stat-card label="Nonaktif" :value="$stats['inactive']" value-color="text-slate-500" icon="box" />
        <x-stat-card label="Tanpa Asset" :value="$stats['without_asset']" value-color="text-amber-600" icon="alert" />
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-xl border border-slate-200 p-4 mb-6">
        <form method="GET" action="{{ route('func-locs.index') }}" class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
                <select name="level" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white">
                    <option value="">Semua Level</option>
                    @foreach($levelLabels as $value => $label)
                        <option value="{{ $value }}" {{ (string) $filters['level'] === (string) $value ? 'selected' : '' }}>
                            L{{ $value }} - {{ $label }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <select name="parent_id" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white">
                    <option value="">Semua Parent</option>
                    @foreach($parentOptions as $parent)
                        <option value="{{ $parent->id }}" {{ (string) $filters['parent_id'] === (string) $parent->id ? 'selected' : '' }}>
                            {{ $parent->code }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <select name="status" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white">
                    <option value="">Semua Status</option>
                    <option value="active" {{ $filters['status'] === 'active' ? 'selected' : '' }}>Aktif</option>
                    <option value="inactive" {{ $filters['status'] === 'inactive' ? 'selected' : '' }}>Nonaktif</option>
                </select>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">Filter</button>
                <a href="{{ route('func-locs.index') }}" class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">Reset</a>
            </div>
        </form>
    </div>

    @if($tree !== null)
        <!-- Tampilan Pohon Hierarki -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="flex items-center gap-3 px-5 py-3 bg-slate-50 border-b border-slate-100 text-xs font-medium text-slate-500 uppercase tracking-wide">
                <div class="w-5 shrink-0"></div>
                <div class="w-40 shrink-0">Code</div>
                <div class="flex-1">Nama</div>
                <div class="w-24 shrink-0">Level</div>
                <div class="w-20 shrink-0">Asset</div>
                <div class="w-24 shrink-0">Status</div>
                <div class="w-44 shrink-0 text-right">Aksi</div>
            </div>

            @forelse($tree as $node)
                @include('func-locs.partials._tree-node', ['node' => $node])
            @empty
                <div class="px-5 py-8 text-center text-sm text-slate-400">Belum ada Functional Location.</div>
            @endforelse
        </div>
    @else
        <!-- Tampilan Daftar Datar (hasil filter) -->
        @php
            $rows = $items->map(fn ($item) => view('func-locs.partials._row-cells', ['item' => $item])->render())->all();
        @endphp

        <x-data-table
            :headers="['Code', 'Nama', 'Level', 'Asset', 'Status', 'Aksi']"
            :rows="$rows"
            :searchable="false"
            empty-message="Tidak ada Functional Location yang cocok dengan filter."
        />
    @endif
@endsection
