@extends('layouts.app')

@section('title', 'Semua Cuti Karyawan')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Semua Cuti</span>
@endsection

@section('content')
    <div class="space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-2xl font-bold text-slate-900">Semua Cuti Karyawan</h2>
                <p class="text-sm text-slate-500 mt-1">
                    Daftar cuti seluruh teknisi. Halaman ini hanya untuk melihat; pencatatan dilakukan Admin Reguler.
                </p>
            </div>
            <a href="{{ route('dashboard') }}" class="text-xs font-semibold text-teal-700 hover:text-teal-800">Kembali ke dashboard</a>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div class="bg-white rounded-xl border border-slate-200 p-4">
                <p class="text-xs text-slate-500">Total catatan cuti</p>
                <p class="text-2xl font-bold text-slate-900 mt-1">{{ number_format($total, 0, ',', '.') }}</p>
            </div>
            <div class="bg-white rounded-xl border border-slate-200 p-4">
                <p class="text-xs text-slate-500">Teknisi dengan cuti</p>
                <p class="text-2xl font-bold text-slate-900 mt-1">{{ number_format($teknisiUnik, 0, ',', '.') }}</p>
            </div>
        </div>

        <form method="GET" action="{{ route('cuti.semua') }}"
              class="bg-white rounded-xl border border-slate-200 p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3 items-end">
            <div class="lg:col-span-2">
                <label for="q" class="block text-xs font-medium text-slate-600 mb-1">Cari teknisi / NIK</label>
                <input type="text" name="q" id="q" value="{{ $cari }}"
                       class="w-full rounded-lg border-slate-200 text-sm focus:border-teal-500 focus:ring-teal-500">
            </div>
            <div>
                <label for="grup" class="block text-xs font-medium text-slate-600 mb-1">Group</label>
                <select name="grup" id="grup" class="w-full rounded-lg border-slate-200 text-sm focus:border-teal-500 focus:ring-teal-500">
                    <option value="">Semua</option>
                    @foreach($groups as $nilai => $label)
                        <option value="{{ $nilai }}" @selected($grup === $nilai)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="bagian" class="block text-xs font-medium text-slate-600 mb-1">Bagian</label>
                <select name="bagian" id="bagian" class="w-full rounded-lg border-slate-200 text-sm focus:border-teal-500 focus:ring-teal-500">
                    <option value="">Semua</option>
                    @foreach($sections as $nilai => $label)
                        <option value="{{ $nilai }}" @selected($bagian === $nilai)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="dari" class="block text-xs font-medium text-slate-600 mb-1">Dari tanggal</label>
                <input type="date" name="dari" id="dari" value="{{ $dari }}"
                       class="w-full rounded-lg border-slate-200 text-sm focus:border-teal-500 focus:ring-teal-500">
            </div>
            <div>
                <label for="sampai" class="block text-xs font-medium text-slate-600 mb-1">Sampai tanggal</label>
                <input type="date" name="sampai" id="sampai" value="{{ $sampai }}"
                       class="w-full rounded-lg border-slate-200 text-sm focus:border-teal-500 focus:ring-teal-500">
            </div>
            <div class="lg:col-span-6 flex items-center gap-3">
                <button type="submit" class="px-4 py-2 rounded-lg text-sm font-semibold text-white" style="background-color:#0E9E8E;">Terapkan</button>
                <a href="{{ route('cuti.semua') }}" class="text-sm text-slate-500 hover:text-slate-700">Bersihkan</a>
                <span class="ml-auto text-xs text-slate-400">Menampilkan {{ number_format($daftar->count(), 0, ',', '.') }} dari {{ number_format($daftar->total(), 0, ',', '.') }} catatan</span>
            </div>
        </form>

        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-[10px] uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="px-4 py-3 text-left font-semibold">Teknisi</th>
                            <th class="px-4 py-3 text-left font-semibold">Periode</th>
                            <th class="px-4 py-3 text-left font-semibold">Jenis</th>
                            <th class="px-4 py-3 text-left font-semibold">Alasan</th>
                            <th class="px-4 py-3 text-left font-semibold">Dicatat</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($daftar as $cuti)
                            <tr class="hover:bg-slate-50/70">
                                <td class="px-4 py-3">
                                    <p class="font-medium text-slate-800">{{ $cuti->technician?->name ?? '-' }}</p>
                                    <p class="text-xs text-slate-500">
                                        NIK {{ $cuti->technician?->nik ?? '-' }} ·
                                        {{ $groups[$cuti->technician?->group] ?? ($cuti->technician?->group ?? '-') }} ·
                                        {{ $sections[$cuti->technician?->section] ?? ($cuti->technician?->section ?? '-') }}
                                    </p>
                                </td>
                                <td class="px-4 py-3 text-xs text-slate-600 whitespace-nowrap">
                                    {{ $cuti->tanggal_mulai->format('d/m/Y') }} – {{ $cuti->tanggal_selesai->format('d/m/Y') }}
                                    <p class="text-slate-400">{{ $cuti->jumlahHari() }} hari</p>
                                </td>
                                <td class="px-4 py-3 text-xs font-medium text-slate-700">{{ $cuti->labelJenis() }}</td>
                                <td class="px-4 py-3 text-xs text-slate-500 max-w-sm">{{ $cuti->alasan ?: '-' }}</td>
                                <td class="px-4 py-3 text-xs text-slate-500 whitespace-nowrap">
                                    {{ $cuti->created_at?->format('d/m/Y H:i') ?? '-' }}
                                    <p class="text-slate-400">{{ $cuti->diajukan_via === 'telegram' ? 'Telegram' : ($cuti->diajukan_via === 'admin' ? 'Admin Reguler' : 'Web') }}</p>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-10 text-center text-sm text-slate-400">Tidak ada cuti pada filter ini.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-3 border-t border-slate-200 flex flex-wrap items-center justify-between gap-2">
                <p class="text-xs text-slate-400">Total {{ number_format($daftar->total(), 0, ',', '.') }} catatan cuti.</p>
                {{ $daftar->links() }}
            </div>
        </div>
    </div>
@endsection
