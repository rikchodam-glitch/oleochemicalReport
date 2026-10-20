@extends('layouts.app')

@section('title', 'Cuti Teknisi')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Cuti Teknisi</span>
@endsection

@php
    $warnaStatus = [
        \App\Models\CutiTeknisi::STATUS_PENDING   => 'bg-amber-50 text-amber-700 ring-amber-200',
        \App\Models\CutiTeknisi::STATUS_DISETUJUI => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        \App\Models\CutiTeknisi::STATUS_DITOLAK   => 'bg-rose-50 text-rose-700 ring-rose-200',
    ];
@endphp

@section('content')
    <div class="space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-2xl font-bold text-slate-900">Cuti Teknisi</h2>
                <p class="text-sm text-slate-500 mt-1">
                    Pengajuan dari form Telegram. Cuti yang disetujui mengurangi target man hour teknisi.
                </p>
            </div>
            <a href="{{ route('dashboard') }}" class="text-xs font-semibold text-teal-700 hover:text-teal-800">Kembali ke dashboard</a>
        </div>

        @if(session('status'))
            <div class="rounded-xl bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
        @endif
        @if(session('error'))
            <div class="rounded-xl bg-rose-50 border border-rose-200 px-4 py-3 text-sm text-rose-800">{{ session('error') }}</div>
        @endif

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <a href="{{ route('cuti.admin.index', ['status' => 'pending']) }}"
               class="bg-white rounded-xl border {{ $status === 'pending' ? 'border-teal-400' : 'border-slate-200' }} p-4 hover:border-teal-400 transition-colors">
                <p class="text-xs text-slate-500">Menunggu persetujuan</p>
                <p class="text-2xl font-bold text-amber-700 mt-1">{{ number_format($ringkasan['pending'], 0, ',', '.') }}</p>
            </a>
            <a href="{{ route('cuti.admin.index', ['status' => 'disetujui']) }}"
               class="bg-white rounded-xl border {{ $status === 'disetujui' ? 'border-teal-400' : 'border-slate-200' }} p-4 hover:border-teal-400 transition-colors">
                <p class="text-xs text-slate-500">Disetujui</p>
                <p class="text-2xl font-bold text-emerald-700 mt-1">{{ number_format($ringkasan['disetujui'], 0, ',', '.') }}</p>
            </a>
            <a href="{{ route('cuti.admin.index', ['status' => 'ditolak']) }}"
               class="bg-white rounded-xl border {{ $status === 'ditolak' ? 'border-teal-400' : 'border-slate-200' }} p-4 hover:border-teal-400 transition-colors">
                <p class="text-xs text-slate-500">Ditolak</p>
                <p class="text-2xl font-bold text-rose-700 mt-1">{{ number_format($ringkasan['ditolak'], 0, ',', '.') }}</p>
            </a>
        </div>

        <form method="GET" action="{{ route('cuti.admin.index') }}"
              class="bg-white rounded-xl border border-slate-200 p-4 flex flex-wrap items-end gap-3">
            <div class="min-w-[200px] flex-1">
                <label for="q" class="block text-xs font-medium text-slate-600 mb-1">Cari teknisi / NIK</label>
                <input type="text" name="q" id="q" value="{{ $cari }}"
                       class="w-full rounded-lg border-slate-200 text-sm focus:border-teal-500 focus:ring-teal-500">
            </div>
            <div class="w-44">
                <label for="status" class="block text-xs font-medium text-slate-600 mb-1">Status</label>
                <select name="status" id="status" class="w-full rounded-lg border-slate-200 text-sm focus:border-teal-500 focus:ring-teal-500">
                    <option value="pending" @selected($status === 'pending')>Menunggu</option>
                    <option value="disetujui" @selected($status === 'disetujui')>Disetujui</option>
                    <option value="ditolak" @selected($status === 'ditolak')>Ditolak</option>
                    <option value="semua" @selected($status === 'semua')>Semua</option>
                </select>
            </div>
            <button type="submit" class="px-4 py-2 rounded-lg text-sm font-medium text-white" style="background-color:#0E9E8E;">Terapkan</button>
            <a href="{{ route('cuti.admin.index') }}" class="text-sm text-slate-500 hover:text-slate-700">Bersihkan</a>
        </form>

        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-[10px] uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="px-4 py-3 text-left font-semibold">Teknisi</th>
                            <th class="px-4 py-3 text-left font-semibold">Periode</th>
                            <th class="px-4 py-3 text-left font-semibold">Jenis / Alasan</th>
                            <th class="px-4 py-3 text-left font-semibold">Status</th>
                            <th class="px-4 py-3 text-left font-semibold">Diajukan</th>
                            <th class="px-4 py-3 text-right font-semibold">Tindakan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($daftar as $cuti)
                            <tr class="hover:bg-slate-50/70 align-top">
                                <td class="px-4 py-3">
                                    <p class="font-medium text-slate-800">{{ $cuti->technician?->name ?? '-' }}</p>
                                    <p class="text-xs text-slate-500">
                                        NIK {{ $cuti->technician?->nik ?? '-' }} ·
                                        {{ \App\Models\Technician::GROUPS[$cuti->technician?->group] ?? ($cuti->technician?->group ?? '-') }}
                                    </p>
                                </td>
                                <td class="px-4 py-3 text-xs text-slate-600 whitespace-nowrap">
                                    {{ $cuti->tanggal_mulai->format('d/m/Y') }} – {{ $cuti->tanggal_selesai->format('d/m/Y') }}
                                    <p class="text-slate-400">{{ $cuti->jumlahHari() }} hari</p>
                                </td>
                                <td class="px-4 py-3 text-xs">
                                    <p class="font-medium text-slate-700">{{ $cuti->labelJenis() }}</p>
                                    <p class="text-slate-500 max-w-xs">{{ $cuti->alasan ?: '-' }}</p>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold ring-1 {{ $warnaStatus[$cuti->status] ?? 'bg-slate-100 text-slate-600 ring-slate-200' }}">
                                        {{ $cuti->labelStatus() }}
                                    </span>
                                    @if($cuti->penyetuju)
                                        <p class="text-[10px] text-slate-400 mt-1">oleh {{ $cuti->penyetuju->name }}</p>
                                    @endif
                                    @if($cuti->catatan_approver)
                                        <p class="text-[10px] text-slate-400 mt-1 max-w-[180px]">{{ $cuti->catatan_approver }}</p>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-xs text-slate-500 whitespace-nowrap">
                                    {{ $cuti->created_at?->format('d/m/Y H:i') ?? '-' }}
                                    <p class="text-slate-400">{{ $cuti->diajukan_via === 'telegram' ? 'Telegram' : 'Web' }}</p>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    @if($cuti->status === \App\Models\CutiTeknisi::STATUS_PENDING)
                                        <form method="POST" action="{{ route('cuti.admin.setujui', $cuti) }}" class="space-y-1.5">
                                            @csrf
                                            <input type="text" name="catatan" maxlength="500" placeholder="Catatan (opsional)"
                                                   class="w-40 rounded-lg border-slate-200 text-xs focus:border-teal-500 focus:ring-teal-500">
                                            <div class="flex items-center justify-end gap-2">
                                                <button type="submit" class="px-2.5 py-1 rounded-md bg-emerald-600 text-white text-[11px] font-semibold hover:bg-emerald-700">Setujui</button>
                                                <button type="submit" formaction="{{ route('cuti.admin.tolak', $cuti) }}"
                                                        onclick="return confirm('Tolak pengajuan cuti ini?')"
                                                        class="px-2.5 py-1 rounded-md bg-rose-50 text-rose-700 border border-rose-200 text-[11px] font-semibold hover:bg-rose-100">Tolak</button>
                                            </div>
                                        </form>
                                    @else
                                        <span class="text-[11px] text-slate-400">Selesai</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-10 text-center text-sm text-slate-400">
                                    Tidak ada pengajuan cuti pada filter ini.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-3 border-t border-slate-200 flex flex-wrap items-center justify-between gap-2">
                <p class="text-xs text-slate-400">Total {{ number_format($daftar->total(), 0, ',', '.') }} pengajuan.</p>
                {{ $daftar->links() }}
            </div>
        </div>
    </div>
@endsection
