@extends('layouts.app')

@section('title', 'Kolaborasi')

@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Kolaborasi</span>
@endsection

@section('content')

{{-- Penjelasan singkat cara kerja otomasi kolaborasi --}}
<div class="bg-teal-50 border border-teal-100 rounded-xl p-5 mb-5">
    <h3 class="text-sm font-semibold text-teal-900 mb-1">Kolaborasi otomatis per kelompok</h3>
    <p class="text-xs text-teal-800 leading-relaxed">
        Anggota pada kelompok yang <span class="font-semibold">aktif</span> dan
        <span class="font-semibold">otomatis</span> akan ikut andil ketika salah satu anggotanya membuat laporan
        di bot Telegram: nama rekan satu kelompok langsung muncul pada tahap kolaborasi laporan
        (tidak perlu ditambahkan manual) dan tetap bisa dihapus lewat menu "Sesuaikan" pada laporan itu.
        Susunan anggota di halaman ini <span class="font-semibold">tidak mengubah</span> grup kerja pada data teknisi,
        jadi bisa disesuaikan bebas.
    </p>
</div>

{{-- Form tambah kelompok baru --}}
<div class="bg-white rounded-xl border border-slate-200 mb-5">
    <details {{ $groups->isEmpty() ? 'open' : '' }}>
        <summary class="px-5 py-4 cursor-pointer text-sm font-medium text-slate-700">Tambah Kelompok Baru</summary>
        <form method="POST" action="{{ route('collaboration.groups.store') }}" class="px-5 pb-5 pt-1 border-t border-slate-100 space-y-4">
            @csrf

            <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                <div>
                    <label class="block text-xs text-slate-500 mb-1">Nama Kelompok</label>
                    <input type="text" name="name" value="{{ old('name') }}" placeholder="mis. Grub D"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500"
                           required>
                    @error('name') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs text-slate-500 mb-1">Kode</label>
                    <input type="text" name="code" value="{{ old('code') }}" placeholder="mis. grub_d"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500"
                           required>
                    <p class="text-xs text-slate-400 mt-1">Kode = penanda grup teknisi untuk tombol "Selaraskan".</p>
                    @error('code') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-xs text-slate-500 mb-1">Keterangan (opsional)</label>
                    <input type="text" name="description" value="{{ old('description') }}"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                </div>
            </div>

            <div class="flex flex-wrap items-end gap-5">
                <div class="w-28">
                    <label class="block text-xs text-slate-500 mb-1">Urutan</label>
                    <input type="number" name="sort_order" value="{{ old('sort_order', 0) }}" min="0"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                </div>
                <label class="inline-flex items-center gap-2 pb-2 text-sm text-slate-700">
                    <input type="checkbox" name="is_active" value="1" checked
                           class="rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                    Aktif
                </label>
                <label class="inline-flex items-center gap-2 pb-2 text-sm text-slate-700">
                    <input type="checkbox" name="auto_collaborate" value="1" checked
                           class="rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                    Otomatis ikut andil
                </label>
                <button type="submit"
                        class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white text-sm font-medium rounded-lg transition-colors">
                    Simpan Kelompok
                </button>
            </div>
        </form>
    </details>
</div>

{{-- Daftar kelompok --}}
@forelse($groups as $group)
    <div class="bg-white rounded-xl border border-slate-200 mb-4">
        <div class="px-5 py-4 flex flex-wrap items-start justify-between gap-3 border-b border-slate-100">
            <div>
                <div class="flex items-center gap-2 flex-wrap">
                    <h3 class="text-sm font-semibold text-slate-800">{{ $group->name }}</h3>
                    <span class="px-2 py-0.5 rounded-md text-xs font-mono bg-slate-100 text-slate-600">{{ $group->code }}</span>

                    @if($group->is_active)
                        <span class="px-2 py-0.5 rounded-md text-xs font-medium bg-emerald-50 text-emerald-700 border border-emerald-100">Aktif</span>
                    @else
                        <span class="px-2 py-0.5 rounded-md text-xs font-medium bg-slate-100 text-slate-500 border border-slate-200">Nonaktif</span>
                    @endif

                    @if($group->auto_collaborate)
                        <span class="px-2 py-0.5 rounded-md text-xs font-medium bg-teal-50 text-teal-700 border border-teal-100">Otomatis ikut andil</span>
                    @else
                        <span class="px-2 py-0.5 rounded-md text-xs font-medium bg-amber-50 text-amber-700 border border-amber-100">Manual (tanpa otomasi)</span>
                    @endif
                </div>

                <p class="text-xs text-slate-400 mt-1">
                    {{ $group->members->count() }} anggota
                    @if($group->description)
                        &middot; {{ $group->description }}
                    @endif
                </p>
            </div>

            <div class="flex items-center gap-2">
                <form method="POST" action="{{ route('collaboration.groups.sync', $group) }}">
                    @csrf
                    <button type="submit"
                            class="px-3 py-1.5 border border-teal-200 bg-teal-50 hover:bg-teal-100 text-teal-700 text-xs font-medium rounded-lg transition-colors">
                        Selaraskan dari grup teknisi
                    </button>
                </form>

                <form method="POST" action="{{ route('collaboration.groups.destroy', $group) }}"
                      onsubmit="return confirm('Hapus kelompok {{ $group->name }}? Pengaturan otomasinya akan hilang, laporan lama tidak terpengaruh.');">
                    @csrf
                    @method('DELETE')
                    <button type="submit"
                            class="px-3 py-1.5 border border-red-200 text-red-600 hover:bg-red-50 text-xs font-medium rounded-lg transition-colors">
                        Hapus
                    </button>
                </form>
            </div>
        </div>

        <div class="p-5 space-y-4">
            {{-- Anggota saat ini --}}
            @if($group->members->isEmpty())
                <p class="text-xs text-slate-400">
                    Belum ada anggota. Tambahkan lewat dropdown di bawah, atau klik "Selaraskan dari grup teknisi"
                    untuk mengisi otomatis dari teknisi bergrup {{ $group->code }}.
                </p>
            @else
                <div class="flex flex-wrap gap-2">
                    @foreach($group->members as $anggota)
                        <form method="POST"
                              action="{{ route('collaboration.groups.members.remove', [$group, $anggota]) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit"
                                    title="Klik untuk menghapus {{ $anggota->name }} dari kelompok ini"
                                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-medium bg-slate-100 hover:bg-red-50 text-slate-700 hover:text-red-600 border border-slate-200 hover:border-red-200 transition-colors">
                                {{ $anggota->name }}
                                @if($anggota->group)
                                    <span class="text-slate-400 font-normal">({{ \App\Models\Technician::GROUPS[$anggota->group] ?? $anggota->group }})</span>
                                @endif
                                <span class="text-slate-400">&times;</span>
                            </button>
                        </form>
                    @endforeach
                </div>
            @endif


            {{-- Tambah anggota --}}
            <form method="POST" action="{{ route('collaboration.groups.members.add', $group) }}"
                  class="flex flex-wrap items-end gap-2">
                @csrf
                <div class="min-w-[16rem]">
                    <label class="block text-xs text-slate-500 mb-1">Tambah anggota</label>
                    <select name="technician_id"
                            class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-teal-500">
                        @foreach($semuaTeknisi as $teknisi)
                            @continue($group->members->contains('id', $teknisi->id))
                            <option value="{{ $teknisi->id }}">
                                {{ $teknisi->name }}{{ $teknisi->nik ? ' (' . $teknisi->nik . ')' : '' }}
                                @if($teknisi->group)
                                    - {{ \App\Models\Technician::GROUPS[$teknisi->group] ?? $teknisi->group }}
                                @endif
                            </option>
                        @endforeach
                    </select>
                </div>
                <button type="submit"
                        class="px-3 py-2 bg-slate-800 hover:bg-slate-900 text-white text-sm font-medium rounded-lg transition-colors">
                    Tambah
                </button>
            </form>

            {{-- Ubah pengaturan kelompok --}}
            <details class="pt-1">
                <summary class="text-xs font-medium text-slate-600 cursor-pointer">Ubah pengaturan kelompok</summary>
                <form method="POST" action="{{ route('collaboration.groups.update', $group) }}"
                      class="mt-3 pt-3 border-t border-slate-100 grid grid-cols-1 sm:grid-cols-4 gap-3">
                    @csrf
                    @method('PUT')

                    <div class="sm:col-span-2">
                        <label class="block text-xs text-slate-500 mb-1">Nama</label>
                        <input type="text" name="name" value="{{ $group->name }}"
                               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500"
                               required>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-xs text-slate-500 mb-1">Keterangan</label>
                        <input type="text" name="description" value="{{ $group->description }}"
                               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1">Urutan</label>
                        <input type="number" name="sort_order" value="{{ $group->sort_order }}" min="0"
                               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                    </div>
                    <label class="inline-flex items-center gap-2 pt-5 text-sm text-slate-700">
                        <input type="checkbox" name="is_active" value="1" {{ $group->is_active ? 'checked' : '' }}
                               class="rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                        Aktif
                    </label>
                    <label class="inline-flex items-center gap-2 pt-5 text-sm text-slate-700">
                        <input type="checkbox" name="auto_collaborate" value="1" {{ $group->auto_collaborate ? 'checked' : '' }}
                               class="rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                        Otomatis ikut andil
                    </label>
                    <div class="pt-4">
                        <button type="submit"
                                class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                            Simpan Perubahan
                        </button>
                    </div>
                </form>
            </details>
        </div>
    </div>
@empty
    <div class="bg-white rounded-xl border border-slate-200 p-8 text-center">
        <p class="text-sm font-medium text-slate-500">Belum ada kelompok kolaborasi</p>
        <p class="text-xs text-slate-400 mt-1">
            Buat kelompok baru di atas, atau jalankan <code class="font-mono">php artisan collab:sync-groups</code>
            untuk membuat kelompok Grub A sampai D dari data teknisi.
        </p>
    </div>
@endforelse

@endsection

