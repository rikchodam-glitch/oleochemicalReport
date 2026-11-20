@extends('layouts.app')

@section('title', 'Lokasi & Rak Alat')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('tools.index') }}" class="hover:text-slate-700">Management Tools</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Lokasi &amp; Rak</span>
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

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-200">
                <h2 class="text-sm font-semibold text-slate-900">Lokasi Penyimpanan</h2>
                <p class="text-xs text-slate-500">Lokasi fisik tempat rak berada.</p>
            </div>

            @if($bolehTulis)
                <form method="POST" action="{{ route('tools.locations.store') }}" class="p-4 border-b border-slate-100 space-y-2">
                    @csrf
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        <input type="text" name="name" value="{{ old('name') }}" required maxlength="150"
                               placeholder="Nama lokasi"
                               class="rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        <input type="text" name="description" value="{{ old('description') }}" maxlength="2000"
                               placeholder="Keterangan (opsional)"
                               class="rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                    </div>
                    <button type="submit" class="px-3 py-2 rounded-lg bg-teal-600 text-white text-xs font-semibold hover:bg-teal-700">
                        Tambah Lokasi
                    </button>
                </form>
            @endif

            <div class="divide-y divide-slate-100">
                @forelse($daftarLokasi as $lokasi)
                    <div class="p-4 {{ $terpilih && $terpilih->id === $lokasi->id ? 'bg-teal-50/60' : '' }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <a href="{{ route('tools.locations.index', ['lokasi' => $lokasi->id]) }}"
                                   class="text-sm font-semibold {{ $terpilih && $terpilih->id === $lokasi->id ? 'text-teal-800' : 'text-slate-800' }} hover:text-teal-700">
                                    {{ $lokasi->name }}
                                </a>
                                <p class="text-[11px] text-slate-400">
                                    {{ $lokasi->racks_count }} rak
                                    @if($lokasi->description) / {{ $lokasi->description }} @endif
                                    @if(! $lokasi->is_active) / nonaktif @endif
                                </p>
                            </div>

                            @if($bolehTulis)
                                <div class="flex items-center gap-2 shrink-0">
                                    <details class="relative">
                                        <summary class="list-none cursor-pointer text-xs font-semibold text-blue-600 hover:text-blue-700">Edit</summary>
                                        <form method="POST" action="{{ route('tools.locations.update', $lokasi->id) }}"
                                              class="absolute right-0 z-10 mt-2 w-64 bg-white border border-slate-200 rounded-xl shadow-lg p-3 space-y-2">
                                            @csrf
                                            @method('PUT')
                                            <input type="text" name="name" value="{{ $lokasi->name }}" required maxlength="150"
                                                   class="w-full rounded-lg border-slate-300 text-xs focus:border-teal-500 focus:ring-teal-500">
                                            <input type="text" name="description" value="{{ $lokasi->description }}" maxlength="2000"
                                                   placeholder="Keterangan"
                                                   class="w-full rounded-lg border-slate-300 text-xs focus:border-teal-500 focus:ring-teal-500">
                                            <label class="flex items-center gap-2 text-xs text-slate-600">
                                                <input type="checkbox" name="is_active" value="1" @checked($lokasi->is_active) class="rounded border-slate-300">
                                                Aktif
                                            </label>
                                            <button type="submit" class="w-full px-2 py-1.5 rounded-lg bg-blue-600 text-white text-xs font-semibold hover:bg-blue-700">
                                                Simpan
                                            </button>
                                        </form>
                                    </details>

                                    @if($lokasi->racks_count === 0)
                                        <form method="POST" action="{{ route('tools.locations.destroy', $lokasi->id) }}"
                                              onsubmit="return confirm('Hapus lokasi {{ $lokasi->name }}?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-xs font-semibold text-rose-600 hover:text-rose-700">Hapus</button>
                                        </form>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="p-6 text-sm text-slate-500 text-center">Belum ada lokasi.</p>
                @endforelse
            </div>
        </div>

        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-200">
                <h2 class="text-sm font-semibold text-slate-900">Rak</h2>
                <p class="text-xs text-slate-500">
                    @if($terpilih)
                        Rak pada lokasi <span class="font-medium">{{ $terpilih->name }}</span>.
                    @else
                        Pilih lokasi lebih dulu.
                    @endif
                </p>
            </div>

            @if($terpilih)
                @if($bolehTulis)
                    <form method="POST" action="{{ route('tools.racks.store') }}" class="p-4 border-b border-slate-100 space-y-2">
                        @csrf
                        <input type="hidden" name="tool_location_id" value="{{ $terpilih->id }}">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                            <input type="text" name="name" value="{{ old('name') }}" required maxlength="150"
                                   placeholder="Nama rak"
                                   class="rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                            <input type="text" name="description" value="{{ old('description') }}" maxlength="2000"
                                   placeholder="Keterangan (opsional)"
                                   class="rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        </div>
                        <button type="submit" class="px-3 py-2 rounded-lg bg-teal-600 text-white text-xs font-semibold hover:bg-teal-700">
                            Tambah Rak
                        </button>
                    </form>
                @endif

                <div class="divide-y divide-slate-100">
                    @forelse($terpilih->racks as $rak)
                        <div class="p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-sm font-semibold text-slate-800">{{ $rak->name }}</p>
                                    <p class="text-[11px] text-slate-400">
                                        {{ $rak->tools_count }} alat
                                        @if($rak->description) / {{ $rak->description }} @endif
                                    </p>
                                </div>

                                @if($bolehTulis)
                                    <div class="flex items-center gap-2 shrink-0">
                                        <details class="relative">
                                            <summary class="list-none cursor-pointer text-xs font-semibold text-blue-600 hover:text-blue-700">Edit</summary>
                                            <form method="POST" action="{{ route('tools.racks.update', $rak->id) }}"
                                                  class="absolute right-0 z-10 mt-2 w-64 bg-white border border-slate-200 rounded-xl shadow-lg p-3 space-y-2">
                                                @csrf
                                                @method('PUT')
                                                <input type="text" name="name" value="{{ $rak->name }}" required maxlength="150"
                                                       class="w-full rounded-lg border-slate-300 text-xs focus:border-teal-500 focus:ring-teal-500">
                                                <input type="text" name="description" value="{{ $rak->description }}" maxlength="2000"
                                                       placeholder="Keterangan"
                                                       class="w-full rounded-lg border-slate-300 text-xs focus:border-teal-500 focus:ring-teal-500">
                                                <button type="submit" class="w-full px-2 py-1.5 rounded-lg bg-blue-600 text-white text-xs font-semibold hover:bg-blue-700">
                                                    Simpan
                                                </button>
                                            </form>
                                        </details>

                                        @if($rak->tools_count === 0)
                                            <form method="POST" action="{{ route('tools.racks.destroy', $rak->id) }}"
                                                  onsubmit="return confirm('Hapus rak {{ $rak->name }}?')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="text-xs font-semibold text-rose-600 hover:text-rose-700">Hapus</button>
                                            </form>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="p-6 text-sm text-slate-500 text-center">Lokasi ini belum punya rak.</p>
                    @endforelse
                </div>
            @else
                <p class="p-6 text-sm text-slate-500 text-center">Belum ada lokasi untuk dipilih.</p>
            @endif
        </div>
    </div>
</div>
@endsection
