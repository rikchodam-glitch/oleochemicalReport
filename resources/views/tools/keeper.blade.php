@extends('layouts.app')

@section('title', 'Tool Keeper')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('tools.index') }}" class="hover:text-slate-700">Management Tools</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Tool Keeper</span>
@endsection

@section('content')
<div class="space-y-4">
    @include('tools.partials._tabs')

    @if(session('success'))
        <x-alert type="success">{{ session('success') }}</x-alert>
    @endif

    @if($errors->any())
        <x-alert type="error" title="Periksa kembali">
            <ul class="list-disc ml-4 space-y-0.5">
                @foreach($errors->all() as $pesan)
                    <li>{{ $pesan }}</li>
                @endforeach
            </ul>
        </x-alert>
    @endif

    <div>
        <h2 class="text-lg font-semibold text-slate-900">Tool Keeper</h2>
        <p class="text-xs text-slate-500">
            Teknisi aktif yang boleh memakai /pinjam untuk mencatat peminjaman orang lain.
            Keeper tetap bisa meminjam alat untuk dirinya lewat /pakai.
        </p>
    </div>

    <form method="GET" action="{{ route('tools.keepers.index') }}"
          class="bg-white rounded-xl border border-slate-200 p-4 flex flex-wrap items-end gap-3">
        <div class="flex-1 min-w-[220px]">
            <label class="block text-xs font-medium text-slate-600 mb-1">Pencarian</label>
            <input type="search" name="q" value="{{ $cari }}" placeholder="Nama, NIK, atau username Telegram..."
                   class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
        </div>
        <button type="submit" class="px-4 py-2 rounded-lg bg-teal-600 text-white text-sm font-semibold hover:bg-teal-700">
            Cari
        </button>
        <a href="{{ route('tools.keepers.index') }}" class="px-3 py-2 rounded-lg border border-slate-300 text-sm text-slate-600 hover:bg-slate-50">
            Reset
        </a>
    </form>

    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-slate-600">
                    <tr>
                        <th class="text-left px-4 py-2 font-medium">Teknisi</th>
                        <th class="text-left px-4 py-2 font-medium">Bagian / Group</th>
                        <th class="text-left px-4 py-2 font-medium">Departemen</th>
                        <th class="text-left px-4 py-2 font-medium">Telegram</th>
                        <th class="text-left px-4 py-2 font-medium">Status Keeper</th>
                        <th class="text-right px-4 py-2 font-medium">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($daftar as $teknisi)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-2.5">
                                <p class="font-medium text-slate-800">{{ $teknisi->name }}</p>
                                <p class="text-[11px] text-slate-400">NIK {{ $teknisi->nik ?: '-' }}</p>
                            </td>
                            <td class="px-4 py-2.5 text-xs text-slate-600">
                                {{ \App\Models\Technician::SECTIONS[$teknisi->section] ?? ($teknisi->section ?: '-') }}
                                / {{ \App\Models\Technician::GROUPS[$teknisi->group] ?? ($teknisi->group ?: '-') }}
                            </td>
                            <td class="px-4 py-2.5 text-xs text-slate-600">{{ $teknisi->department?->name ?? '-' }}</td>
                            <td class="px-4 py-2.5 text-xs text-slate-600">
                                {{ $teknisi->telegram_username ? '@' . $teknisi->telegram_username : $teknisi->telegram_id }}
                            </td>
                            <td class="px-4 py-2.5">
                                @if($teknisi->is_tool_keeper)
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold bg-teal-100 text-teal-700">Keeper</span>
                                @else
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold bg-slate-100 text-slate-600">Bukan keeper</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                @if($bolehAtur)
                                    <form method="POST" action="{{ route('tools.keepers.toggle', $teknisi->id) }}">
                                        @csrf
                                        <button type="submit"
                                                class="text-xs font-semibold {{ $teknisi->is_tool_keeper ? 'text-rose-600 hover:text-rose-700' : 'text-teal-700 hover:text-teal-800' }}">
                                            {{ $teknisi->is_tool_keeper ? 'Lepas keeper' : 'Jadikan keeper' }}
                                        </button>
                                    </form>
                                @else
                                    <span class="text-xs text-slate-300">-</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-sm text-slate-500">
                                Tidak ada teknisi aktif dengan Telegram pada filter ini.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($daftar->hasPages())
            <div class="px-4 py-3 border-t border-slate-200">
                {{ $daftar->links() }}
            </div>
        @endif
    </div>

    @if(! $bolehAtur)
        <p class="text-xs text-slate-500">Penunjukan keeper hanya bisa dilakukan administrator.</p>
    @endif
</div>
@endsection
