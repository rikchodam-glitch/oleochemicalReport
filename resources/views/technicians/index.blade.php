@extends('layouts.app')

@section('title', 'Manajemen Teknisi')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Teknisi</span>
@endsection

@section('header-actions')
    <a href="{{ route('technicians.create') }}"
       class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        Tambah Teknisi
    </a>
@endsection

@section('content')
    <!-- Filters -->
    <div class="bg-white rounded-xl border border-slate-200 p-4 mb-6">
        <form method="GET" action="{{ route('technicians.index') }}" class="grid grid-cols-1 md:grid-cols-6 gap-4">
            <div>
                <input type="text" name="search" value="{{ request('search') }}"
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                       placeholder="Cari nama, NIK, atau username...">
            </div>
            <div>
                <select name="status" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white">
                    <option value="">Semua Status</option>
                    <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Pending</option>
                    <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Aktif</option>
                    <option value="suspended" {{ request('status') === 'suspended' ? 'selected' : '' }}>Suspended</option>
                </select>
            </div>
            <div>
                <select name="group" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white">
                    <option value="">Semua Group</option>
                    @foreach(\App\Models\Technician::GROUPS as $val => $label)
                        <option value="{{ $val }}" {{ request('group') === $val ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <select name="section" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white">
                    <option value="">Semua Bagian</option>
                    @foreach(\App\Models\Technician::SECTIONS as $val => $label)
                        <option value="{{ $val }}" {{ request('section') === $val ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <select name="department_id" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white">
                    <option value="">Semua Departemen</option>
                    @foreach($departments as $dept)
                        <option value="{{ $dept->id }}" {{ request('department_id') == $dept->id ? 'selected' : '' }}>{{ $dept->code }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">Filter</button>
                <a href="{{ route('technicians.index') }}" class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">Reset</a>
            </div>
        </form>
    </div>

    <!-- Table -->
    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-100">
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Nama</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">NIK</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Group</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Bagian</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Departemen</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Status</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Telegram</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($technicians as $technician)
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-5 py-3.5">
                                <a href="{{ route('technicians.show', $technician) }}" class="font-medium text-slate-900 hover:text-blue-600">{{ $technician->name }}</a>
                            </td>
                            <td class="px-5 py-3.5 font-mono text-slate-600">{{ $technician->nik ?? '-' }}</td>
                            <td class="px-5 py-3.5">
                                @if($technician->group)
                                    <span class="inline-flex px-2 py-0.5 text-xs font-medium bg-slate-100 text-slate-700 rounded">{{ \App\Models\Technician::GROUPS[$technician->group] ?? $technician->group }}</span>
                                @else
                                    <span class="text-slate-400">-</span>
                                @endif
                            </td>
                            <td class="px-5 py-3.5">
                                @if($technician->section)
                                    <span class="inline-flex px-2 py-0.5 text-xs font-medium bg-blue-50 text-blue-700 rounded">{{ \App\Models\Technician::SECTIONS[$technician->section] ?? $technician->section }}</span>
                                @else
                                    <span class="text-slate-400">-</span>
                                @endif
                            </td>
                            <td class="px-5 py-3.5 text-slate-600">{{ $technician->department?->code ?? '-' }}</td>
                            <td class="px-5 py-3.5"><x-status-badge :status="$technician->status" /></td>
                            <td class="px-5 py-3.5">
                                @if($technician->telegram_id)
                                    @php
                                        $lastActive = $technician->last_active_at;
                                        if ($lastActive && $lastActive->gt(now()->subDays(7))) {
                                            $botKelas = 'bg-green-100 text-green-700';
                                            $botLabel = $technician->telegram_username ? '@' . $technician->telegram_username : 'Terhubung';
                                            $dotKelas = 'bg-green-500';
                                            $titleLabel = 'Aktif dalam 7 hari terakhir';
                                        } elseif ($lastActive) {
                                            $botKelas = 'bg-amber-100 text-amber-700';
                                            $botLabel = $technician->telegram_username ? '@' . $technician->telegram_username : 'Tidak Aktif';
                                            $dotKelas = 'bg-amber-400';
                                            $titleLabel = 'Terakhir aktif ' . $lastActive->diffForHumans();
                                        } else {
                                            $botKelas = 'bg-slate-100 text-slate-500';
                                            $botLabel = $technician->telegram_username ? '@' . $technician->telegram_username : 'Belum Aktif';
                                            $dotKelas = 'bg-slate-400';
                                            $titleLabel = 'Belum pernah aktif';
                                        }
                                    @endphp
                                    <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium {{ $botKelas }}"
                                          title="{{ $titleLabel }}">
                                        <span class="w-1.5 h-1.5 rounded-full {{ $dotKelas }}"></span>
                                        {{ $botLabel }}
                                    </span>
                                @else
                                    <span class="text-slate-400 text-xs">-</span>
                                @endif
                            </td>
                            <td class="px-5 py-3.5">
                                <div class="flex items-center gap-2">
                                    <a href="{{ route('technicians.show', $technician) }}" class="text-blue-600 hover:text-blue-700 text-xs font-medium">Detail</a>
                                    @if($technician->status === 'pending')
                                        <form method="POST" action="{{ route('technicians.approve', $technician) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="text-green-600 hover:text-green-700 text-xs font-medium">Setujui</button>
                                        </form>
                                    @endif
                                    @if($technician->status === 'active')
                                        <form method="POST" action="{{ route('technicians.suspend', $technician) }}" class="inline"
                                              onsubmit="return confirm('Tangguhkan teknisi ini?')">
                                            @csrf
                                            <button type="submit" class="text-amber-600 hover:text-amber-700 text-xs font-medium">Tangguhkan</button>
                                        </form>
                                    @endif
                                    @if($technician->status === 'suspended')
                                        <form method="POST" action="{{ route('technicians.reactivate', $technician) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="text-green-600 hover:text-green-700 text-xs font-medium">Aktifkan</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-5 py-8 text-center text-sm text-slate-400">Belum ada teknisi.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($technicians->hasPages())
            <div class="px-5 py-3 border-t border-slate-100">{{ $technicians->links() }}</div>
        @endif
    </div>
@endsection
