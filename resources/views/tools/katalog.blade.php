@extends('layouts.app')

@section('title', 'Jenis & Kelompok Alat')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('tools.index') }}" class="hover:text-slate-700">Management Tools</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Jenis &amp; Kelompok</span>
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

    <div>
        <h2 class="text-lg font-semibold text-slate-900">Jenis &amp; Kelompok Alat</h2>
        <p class="text-xs text-slate-500">
            Jenis adalah kategori bebas (mis. Bor, Kunci, Alat Ukur). Kelompok menaungi beberapa unit
            (mis. Bor Batrai berisi BRBT001 dan BRBT002).
        </p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-200">
                <h3 class="text-sm font-semibold text-slate-900">Jenis</h3>
                <p class="text-xs text-slate-500">Kategori alat yang dapat dipilih kelompok.</p>
            </div>

            @if($bolehTulis)
                <form method="POST" action="{{ route('tools.types.store') }}" class="p-4 border-b border-slate-100 space-y-2">
                    @csrf
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        <input type="text" name="name" value="{{ old('name') }}" required maxlength="100"
                               placeholder="Nama jenis (mis. Bor)"
                               class="rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        <input type="text" name="description" value="{{ old('description') }}" maxlength="2000"
                               placeholder="Keterangan (opsional)"
                               class="rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                    </div>
                    <label class="flex items-center gap-2 text-xs text-slate-600">
                        <input type="checkbox" name="is_active" value="1" checked class="rounded border-slate-300">
                        Aktif
                    </label>
                    <button type="submit" class="px-3 py-2 rounded-lg bg-teal-600 text-white text-xs font-semibold hover:bg-teal-700">
                        Tambah Jenis
                    </button>
                </form>
            @endif

            <div class="divide-y divide-slate-100">
                @forelse($daftarJenis as $jenis)
                    <div class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-sm font-semibold text-slate-800">{{ $jenis->name }}</p>
                                <p class="text-[11px] text-slate-400">
                                    {{ $jenis->groups_count }} kelompok
                                    @if($jenis->description) / {{ $jenis->description }} @endif
                                    @if(! $jenis->is_active) / nonaktif @endif
                                </p>
                            </div>

                            @if($bolehTulis)
                                <div class="flex items-center gap-2 shrink-0">
                                    <details class="relative">
                                        <summary class="list-none cursor-pointer text-xs font-semibold text-blue-600 hover:text-blue-700">Edit</summary>
                                        <form method="POST" action="{{ route('tools.types.update', $jenis->id) }}"
                                              class="absolute right-0 z-20 mt-2 w-64 bg-white border border-slate-200 rounded-xl shadow-lg p-3 space-y-2">
                                            @csrf
                                            @method('PUT')
                                            <input type="text" name="name" value="{{ $jenis->name }}" required maxlength="100"
                                                   class="w-full rounded-lg border-slate-300 text-xs focus:border-teal-500 focus:ring-teal-500">
                                            <input type="text" name="description" value="{{ $jenis->description }}" maxlength="2000"
                                                   placeholder="Keterangan"
                                                   class="w-full rounded-lg border-slate-300 text-xs focus:border-teal-500 focus:ring-teal-500">
                                            <label class="flex items-center gap-2 text-xs text-slate-600">
                                                <input type="checkbox" name="is_active" value="1" @checked($jenis->is_active) class="rounded border-slate-300">
                                                Aktif
                                            </label>
                                            <button type="submit" class="w-full px-2 py-1.5 rounded-lg bg-blue-600 text-white text-xs font-semibold hover:bg-blue-700">
                                                Simpan
                                            </button>
                                        </form>
                                    </details>

                                    @if($jenis->groups_count === 0)
                                        <form method="POST" action="{{ route('tools.types.destroy', $jenis->id) }}"
                                              onsubmit="return confirm('Hapus jenis {{ $jenis->name }}?')">
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
                    <p class="p-6 text-sm text-slate-500 text-center">Belum ada jenis alat.</p>
                @endforelse
            </div>
        </div>

        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-200">
                <h3 class="text-sm font-semibold text-slate-900">Kelompok</h3>
                <p class="text-xs text-slate-500">Satu kelompok menaungi beberapa kode unit.</p>
            </div>

            @if($bolehTulis)
                <form method="POST" action="{{ route('tools.groups.store') }}" class="p-4 border-b border-slate-100 space-y-2">
                    @csrf
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        <input type="text" name="name" value="{{ old('name') }}" required maxlength="150"
                               placeholder="Nama kelompok (mis. Bor Batrai)"
                               class="rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        <select name="tool_type_id"
                                class="rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                            <option value="">- Tanpa jenis -</option>
                            @foreach($daftarJenis as $jenis)
                                <option value="{{ $jenis->id }}" @selected((string) old('tool_type_id') === (string) $jenis->id)>{{ $jenis->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <input type="text" name="description" value="{{ old('description') }}" maxlength="2000"
                           placeholder="Keterangan (opsional)"
                           class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                    <button type="submit" class="px-3 py-2 rounded-lg bg-teal-600 text-white text-xs font-semibold hover:bg-teal-700">
                        Tambah Kelompok
                    </button>
                </form>
            @endif

            <div class="divide-y divide-slate-100">
                @forelse($daftarKelompok as $kelompok)
                    <div class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-sm font-semibold text-slate-800">{{ $kelompok->name }}</p>
                                <p class="text-[11px] text-slate-400">
                                    {{ $kelompok->type?->name ?? 'Tanpa jenis' }} / {{ $kelompok->tools_count }} unit
                                    @if($kelompok->description) / {{ $kelompok->description }} @endif
                                </p>
                            </div>

                            @if($bolehTulis)
                                <div class="flex items-center gap-2 shrink-0">
                                    <details class="relative">
                                        <summary class="list-none cursor-pointer text-xs font-semibold text-blue-600 hover:text-blue-700">Edit</summary>
                                        <form method="POST" action="{{ route('tools.groups.update', $kelompok->id) }}"
                                              class="absolute right-0 z-20 mt-2 w-72 bg-white border border-slate-200 rounded-xl shadow-lg p-3 space-y-2">
                                            @csrf
                                            @method('PUT')
                                            <input type="text" name="name" value="{{ $kelompok->name }}" required maxlength="150"
                                                   class="w-full rounded-lg border-slate-300 text-xs focus:border-teal-500 focus:ring-teal-500">
                                            <select name="tool_type_id"
                                                    class="w-full rounded-lg border-slate-300 text-xs focus:border-teal-500 focus:ring-teal-500">
                                                <option value="">- Tanpa jenis -</option>
                                                @foreach($daftarJenis as $jenis)
                                                    <option value="{{ $jenis->id }}" @selected((string) $kelompok->tool_type_id === (string) $jenis->id)>{{ $jenis->name }}</option>
                                                @endforeach
                                            </select>
                                            <input type="text" name="description" value="{{ $kelompok->description }}" maxlength="2000"
                                                   placeholder="Keterangan"
                                                   class="w-full rounded-lg border-slate-300 text-xs focus:border-teal-500 focus:ring-teal-500">
                                            <button type="submit" class="w-full px-2 py-1.5 rounded-lg bg-blue-600 text-white text-xs font-semibold hover:bg-blue-700">
                                                Simpan
                                            </button>
                                        </form>
                                    </details>

                                    @if($kelompok->tools_count === 0)
                                        <form method="POST" action="{{ route('tools.groups.destroy', $kelompok->id) }}"
                                              onsubmit="return confirm('Hapus kelompok {{ $kelompok->name }}?')">
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
                    <p class="p-6 text-sm text-slate-500 text-center">Belum ada kelompok alat.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection
