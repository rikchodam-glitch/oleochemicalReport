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

    <!-- Jadwal Shift & Man Hour -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        <div class="lg:col-span-1 bg-white rounded-xl border border-slate-200 p-5">
            <h3 class="text-sm font-semibold text-slate-900">Jadwal Shift</h3>
            <p class="text-xs text-slate-400 mt-1 leading-snug">
                Pola rotasi 20 hari (5 hari Shift 1, 2 OFF, 5 hari Shift 3, 2 OFF, 5 hari Shift 2, 1 OFF)
                sudah dikenali sistem, jadi periode berikutnya tidak perlu upload lagi. Upload Excel hanya
                bila ada perubahan komposisi group atau koreksi manual.
            </p>

            @if($shiftTerakhir)
                <div class="mt-3 rounded-lg bg-teal-50 border border-teal-100 px-3 py-2">
                    <p class="text-xs font-semibold text-teal-800">
                        Periode terakhir: {{ $shiftTerakhir->periode_label ?? '-' }}
                    </p>
                    <p class="text-xs text-teal-700 mt-0.5">
                        {{ $shiftTerakhir->jumlah_baris }} orang dibaca,
                        {{ $shiftTerakhir->jumlah_terpetakan }} terpetakan,
                        {{ $shiftTerakhir->jumlah_tidak_cocok }} belum cocok
                    </p>
                </div>
            @else
                <p class="text-xs text-amber-600 mt-3">Belum ada jadwal shift yang diimport.</p>
            @endif

            <form method="POST" action="{{ route('technicians.shift-schedule.generate') }}" class="mt-4">
                @csrf
                <button type="submit"
                        class="w-full px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white text-sm font-medium rounded-lg transition-colors">
                    Buat Jadwal Otomatis (30 hari)
                </button>
                <p class="text-xs text-slate-400 mt-1.5">
                    Mengisi jadwal teknisi group A-D dari pola rotasi, dimulai sehari setelah jadwal terakhir.
                </p>
            </form>

            <form method="POST" action="{{ route('technicians.shift-schedule.store') }}"
                  enctype="multipart/form-data" class="mt-4 pt-4 border-t border-slate-100 space-y-3">
                @csrf
                <label class="block text-xs font-medium text-slate-600">Upload jadwal resmi (opsional)</label>
                <input type="file" name="file" accept=".xlsx,.xls,.csv" required
                       class="w-full text-xs text-slate-600 border border-slate-300 rounded-lg px-3 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-teal-500">
                @error('file')
                    <p class="text-xs text-red-600">{{ $message }}</p>
                @enderror
                <button type="submit"
                        class="w-full px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                    Import Jadwal
                </button>
            </form>
        </div>

        <div class="lg:col-span-2 bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100">
                <h3 class="text-sm font-semibold text-slate-900">Riwayat Import Jadwal</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-100">
                            <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3 whitespace-nowrap">Waktu Import</th>
                            <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">File</th>
                            <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3 whitespace-nowrap">Periode</th>
                            <th class="text-center text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3 whitespace-nowrap">Orang</th>
                            <th class="text-center text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3 whitespace-nowrap">Terpetakan</th>
                            <th class="text-center text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3 whitespace-nowrap">Belum Cocok</th>
                            <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3 whitespace-nowrap">Oleh</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @forelse($riwayatJadwal as $jadwal)
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="px-5 py-3.5 align-top whitespace-nowrap">
                                    <p class="text-slate-700">{{ $jadwal->created_at?->format('d/m/Y H:i') ?? '-' }}</p>
                                    <p class="text-xs text-slate-400">{{ $jadwal->created_at?->diffForHumans() }}</p>
                                </td>
                                <td class="px-4 py-3.5 align-top">
                                    <p class="text-xs text-slate-600 break-words max-w-xs">{{ $jadwal->nama_file }}</p>
                                </td>
                                <td class="px-4 py-3.5 align-top whitespace-nowrap text-xs text-slate-600">
                                    {{ $jadwal->periode_label ?? '-' }}
                                </td>
                                <td class="px-4 py-3.5 align-top text-center text-xs text-slate-700">{{ $jadwal->jumlah_baris }}</td>
                                <td class="px-4 py-3.5 align-top text-center text-xs font-semibold text-teal-700">{{ $jadwal->jumlah_terpetakan }}</td>
                                <td class="px-4 py-3.5 align-top text-center text-xs {{ $jadwal->jumlah_tidak_cocok > 0 ? 'font-semibold text-amber-600' : 'text-slate-400' }}">
                                    {{ $jadwal->jumlah_tidak_cocok }}
                                </td>
                                <td class="px-4 py-3.5 align-top whitespace-nowrap text-xs text-slate-600">
                                    {{ $jadwal->uploader?->name ?? 'sistem' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-5 py-8 text-center text-sm text-slate-400">Belum ada riwayat import jadwal.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
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
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3 whitespace-nowrap">Jam Kerja</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($technicians as $technician)
                        <tr class="hover:bg-slate-50 transition-colors cursor-pointer"
                            title="Klik untuk membuka detail {{ $technician->name }}"
                            onclick="window.location='{{ route('technicians.show', $technician) }}'">
                            <td class="px-5 py-3.5">
                                <a href="{{ route('technicians.show', $technician) }}" class="font-medium text-slate-900 hover:text-teal-700">{{ $technician->name }}</a>
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
                            <td class="px-4 py-3.5 align-top">
                                @php
                                    $menitTeknisi  = (int) ($menitKerja[$technician->id] ?? 0);
                                    $targetTeknisi = (int) ($targetKerja[$technician->id] ?? 0);
                                    $persenTeknisi = $targetTeknisi > 0
                                        ? (int) round($menitTeknisi / $targetTeknisi * 100)
                                        : null;
                                @endphp
                                @if($targetTeknisi > 0)
                                    <p class="text-sm font-semibold text-slate-800 whitespace-nowrap">
                                        {{ number_format($menitTeknisi / 60, 1, ',', '.') }} j
                                        <span class="text-xs font-normal text-slate-400">/ {{ number_format($targetTeknisi / 60, 0, ',', '.') }} j</span>
                                    </p>
                                    <div class="mt-1 flex items-center gap-2">
                                        <div class="w-16 h-1.5 rounded-full bg-slate-100 overflow-hidden">
                                            <div class="h-full rounded-full {{ $persenTeknisi >= 100 ? 'bg-teal-500' : ($persenTeknisi >= 75 ? 'bg-amber-400' : 'bg-rose-400') }}"
                                                 style="width: {{ max(0, min(100, $persenTeknisi)) }}%"></div>
                                        </div>
                                        <span class="text-xs font-medium {{ $persenTeknisi >= 100 ? 'text-teal-700' : ($persenTeknisi >= 75 ? 'text-amber-600' : 'text-rose-600') }}">
                                            {{ $persenTeknisi }}%
                                        </span>
                                    </div>
                                @else
                                    <span class="text-xs text-slate-400">Tanpa jadwal</span>
                                @endif
                            </td>
                            <td class="px-5 py-3.5">
                                <div class="flex items-center gap-2" onclick="event.stopPropagation()">
                                    <a href="{{ route('technicians.show', $technician) }}" class="text-teal-700 hover:text-teal-800 text-xs font-medium">Detail</a>
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
                            <td colspan="7" class="px-5 py-8 text-center text-sm text-slate-400">Belum ada teknisi.</td>
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
