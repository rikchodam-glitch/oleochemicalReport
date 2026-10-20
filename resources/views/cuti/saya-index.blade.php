@extends('layouts.app')

@section('title', 'Cuti Saya')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Cuti Saya</span>
@endsection

@php
    $warnaStatus = [
        \App\Models\CutiTeknisi::STATUS_DISETUJUI => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        \App\Models\CutiTeknisi::STATUS_PENDING   => 'bg-amber-50 text-amber-700 ring-amber-200',
        \App\Models\CutiTeknisi::STATUS_DITOLAK   => 'bg-rose-50 text-rose-700 ring-rose-200',
    ];
@endphp

@section('content')
    <div class="space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-2xl font-bold text-slate-900">Cuti Saya</h2>
                <p class="text-sm text-slate-500 mt-1">
                    {{ $teknisi->name }} · NIK {{ $teknisi->nik ?? '-' }} ·
                    {{ \App\Models\Technician::GROUPS[$teknisi->group] ?? ($teknisi->group ?? '-') }}
                </p>
                <p class="text-xs text-slate-400 mt-1">
                    Cuti langsung berlaku tanpa persetujuan admin. Perubahan dan penghapusan langsung memengaruhi target man hour.
                </p>
            </div>
            <a href="{{ route('dashboard') }}" class="text-xs font-semibold text-teal-700 hover:text-teal-800">Kembali ke dashboard</a>
        </div>

        @if(session('status'))
            <div class="rounded-xl bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
        @endif

        <div class="rounded-xl bg-blue-50 border border-blue-100 px-4 py-3 text-xs text-blue-800">
            Pengajuan cuti baru dilakukan lewat bot Telegram: kirim
            <span class="font-semibold">/cuti</span> atau <span class="font-semibold">/cuti alasan</span>.
            Daftar di bawah adalah cuti yang sudah tercatat.
        </div>

        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-[10px] uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="px-4 py-3 text-left font-semibold">Periode</th>
                            <th class="px-4 py-3 text-left font-semibold">Jenis</th>
                            <th class="px-4 py-3 text-left font-semibold">Alasan</th>
                            <th class="px-4 py-3 text-left font-semibold">Status</th>
                            <th class="px-4 py-3 text-left font-semibold">Diperbarui</th>
                            <th class="px-4 py-3 text-right font-semibold">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($daftar as $cuti)
                            <tr class="hover:bg-slate-50/70">
                                <td class="px-4 py-3 text-xs text-slate-600 whitespace-nowrap">
                                    {{ $cuti->tanggal_mulai->format('d/m/Y') }} – {{ $cuti->tanggal_selesai->format('d/m/Y') }}
                                    <p class="text-slate-400">{{ $cuti->jumlahHari() }} hari</p>
                                </td>
                                <td class="px-4 py-3 text-xs font-medium text-slate-700">{{ $cuti->labelJenis() }}</td>
                                <td class="px-4 py-3 text-xs text-slate-500 max-w-sm">{{ $cuti->alasan ?: '-' }}</td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold ring-1 {{ $warnaStatus[$cuti->status] ?? 'bg-slate-100 text-slate-600 ring-slate-200' }}">
                                        {{ $cuti->labelStatus() }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-xs text-slate-500 whitespace-nowrap">{{ $cuti->updated_at?->format('d/m/Y H:i') ?? '-' }}</td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    <a href="{{ route('cuti.saya.edit', $cuti) }}"
                                       class="inline-flex px-2.5 py-1 rounded-md border border-slate-200 text-[11px] font-semibold text-slate-700 hover:bg-slate-50">
                                        Edit
                                    </a>
                                    <form method="POST" action="{{ route('cuti.saya.destroy', $cuti) }}" class="inline"
                                          onsubmit="return confirm('Hapus cuti {{ $cuti->tanggal_mulai->format('d/m/Y') }} - {{ $cuti->tanggal_selesai->format('d/m/Y') }}? Target man hour akan berubah.')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="px-2.5 py-1 rounded-md bg-rose-50 border border-rose-200 text-[11px] font-semibold text-rose-700 hover:bg-rose-100">
                                            Hapus
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-10 text-center text-sm text-slate-400">
                                    Belum ada cuti tercatat. Kirim <span class="font-semibold">/cuti</span> di Telegram untuk mengajukan.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-3 border-t border-slate-200 flex flex-wrap items-center justify-between gap-2">
                <p class="text-xs text-slate-400">Total {{ number_format($daftar->total(), 0, ',', '.') }} cuti.</p>
                {{ $daftar->links() }}
            </div>
        </div>
    </div>
@endsection
