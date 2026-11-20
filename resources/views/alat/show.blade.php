@extends('layouts.app')

@section('title', 'Log Alat - ' . $alat->name)
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('alat.index') }}" class="hover:text-slate-700">Peminjaman Alat</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">{{ $alat->name }}</span>
@endsection

@section('content')
@php
    $warna = [
        'tersedia' => 'bg-green-100 text-green-700',
        'dipakai'  => 'bg-amber-100 text-amber-700',
        'rusak'    => 'bg-red-100 text-red-700',
    ][$alat->status] ?? 'bg-slate-100 text-slate-600';
@endphp

<div class="space-y-4">
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

    <div class="flex items-center justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold text-slate-900">{{ $alat->name }} ({{ $alat->code }})</h2>
            <p class="text-xs text-slate-500">{{ $alat->rack?->location?->name ?? '-' }} / Rak {{ $alat->rack?->name ?? '-' }}</p>
        </div>
        <a href="{{ route('alat.index') }}" class="text-sm font-semibold text-teal-700 hover:text-teal-800">
            Kembali
        </a>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-4">
        <div class="flex flex-col sm:flex-row gap-4">
            <div class="w-full sm:w-48 aspect-square rounded-xl bg-slate-100 overflow-hidden shrink-0">
                @if($alat->foto_url)
                    <img src="{{ $alat->foto_url }}" alt="{{ $alat->name }}" class="w-full h-full object-cover">
                @else
                    <div class="w-full h-full flex items-center justify-center text-slate-300">
                        <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                        </svg>
                    </div>
                @endif
            </div>

            <div class="flex-1 space-y-3">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-semibold {{ $warna }}">
                        {{ $alat->statusLabel() }}
                    </span>
                    <span class="text-xs text-slate-500">Kode: {{ $alat->code }}</span>
                </div>

                @if($alat->note)
                    <p class="text-sm text-slate-600">{{ $alat->note }}</p>
                @endif

                @if($aktif)
                    <div class="rounded-lg bg-amber-50 border border-amber-200 px-3 py-2 text-sm text-amber-900">
                        Dipakai oleh <span class="font-semibold">{{ $aktif->peminjamLabel() }}</span>
                        sejak {{ $aktif->borrowed_at?->format('d/m/Y H:i') }}
                        ({{ $aktif->durasiTeks() }}).
                    </div>
                @else
                    <p class="text-sm text-slate-600">
                        @if($alat->pinjamanTerakhir)
                            Peminjam terakhir: <span class="font-medium">{{ $alat->pinjamanTerakhir->peminjamLabel() }}</span>
                            ({{ $alat->pinjamanTerakhir->durasiTeks() }}).
                        @else
                            Belum pernah dipinjam.
                        @endif
                    </p>
                @endif

                <div class="flex flex-wrap items-center gap-2 pt-2 border-t border-slate-100">
                    @if($bolehPinjam)
                        <form method="POST" action="{{ route('alat.pinjam', $alat->id) }}"
                              onsubmit="return confirm('Pinjam {{ $alat->name }} ({{ $alat->code }}) untuk diri sendiri?')">
                            @csrf
                            <button type="submit" class="px-3 py-2 rounded-lg bg-teal-600 text-white text-sm font-semibold hover:bg-teal-700">
                                Pinjam Alat Ini
                            </button>
                        </form>
                    @endif

                    @if($bolehMeminjamkan && $alat->status === \App\Models\Tool::STATUS_TERSEDIA)
                        <button type="button"
                                class="tombol-pinjamkan px-3 py-2 rounded-lg border border-teal-300 text-teal-700 text-sm font-semibold hover:bg-teal-50"
                                data-id="{{ $alat->id }}"
                                data-nama="{{ $alat->name }} ({{ $alat->code }})">
                            Pinjamkan
                        </button>
                    @endif

                    @if($bolehKembalikan && $aktif)
                        <form method="POST" action="{{ route('alat.kembali', $aktif->id) }}"
                              onsubmit="return confirm('Kembalikan {{ $alat->name }} ({{ $alat->code }})?')">
                            @csrf
                            <button type="submit" class="px-3 py-2 rounded-lg bg-amber-500 text-white text-sm font-semibold hover:bg-amber-600">
                                Kembalikan
                            </button>
                        </form>
                    @endif

                    @if(! $bolehPinjam && ! $bolehMeminjamkan && ! $bolehKembalikan)
                        <span class="text-xs text-slate-400">Tidak ada aksi yang tersedia untuk akun ini.</span>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-200 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="text-sm font-semibold text-slate-900">Log Peminjaman</h3>
                <p class="text-xs text-slate-500">Riwayat lengkap alat ini beserta lama peminjaman.</p>
            </div>
            <span class="text-xs text-slate-500">{{ $log->total() }} transaksi</span>
        </div>

        <form method="GET" action="{{ route('alat.show', $alat->id) }}"
              class="px-4 py-3 border-b border-slate-100 flex flex-wrap items-end gap-3">
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Dari tanggal</label>
                <input type="date" name="dari" value="{{ $dari }}"
                       class="rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Sampai tanggal</label>
                <input type="date" name="sampai" value="{{ $sampai }}"
                       class="rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
            </div>
            <button type="submit" class="px-3 py-2 rounded-lg bg-teal-600 text-white text-sm font-semibold hover:bg-teal-700">
                Saring
            </button>
            <a href="{{ route('alat.show', $alat->id) }}" class="px-3 py-2 rounded-lg border border-slate-300 text-sm text-slate-600 hover:bg-slate-50">
                Reset
            </a>
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-slate-600">
                    <tr>
                        <th class="text-left px-4 py-2 font-medium">Kode</th>
                        <th class="text-left px-4 py-2 font-medium">Peminjam</th>
                        <th class="text-left px-4 py-2 font-medium">Jenis</th>
                        <th class="text-left px-4 py-2 font-medium">Dipinjam</th>
                        <th class="text-left px-4 py-2 font-medium">Dikembalikan</th>
                        <th class="text-left px-4 py-2 font-medium">Durasi</th>
                        <th class="text-left px-4 py-2 font-medium">Petugas</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($log as $pinjaman)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-2.5 font-mono text-xs text-slate-600">{{ $pinjaman->kode }}</td>
                            <td class="px-4 py-2.5">
                                <p class="text-slate-800">{{ $pinjaman->peminjamLabel() }}</p>
                                <p class="text-[11px] text-slate-400">
                                    {{ \App\Models\ToolLoan::BORROWER[$pinjaman->borrower_type] ?? $pinjaman->borrower_type }}
                                </p>
                            </td>
                            <td class="px-4 py-2.5 text-xs text-slate-600">
                                {{ \App\Models\ToolLoan::HANDLED[$pinjaman->handled_by_type] ?? $pinjaman->handled_by_type }}
                            </td>
                            <td class="px-4 py-2.5 text-xs text-slate-600">{{ $pinjaman->borrowed_at?->format('d/m/Y H:i') }}</td>
                            <td class="px-4 py-2.5 text-xs text-slate-600">
                                {{ $pinjaman->returned_at?->format('d/m/Y H:i') ?? '-' }}
                            </td>
                            <td class="px-4 py-2.5 text-xs text-slate-600">{{ $pinjaman->durasiTeks() }}</td>
                            <td class="px-4 py-2.5 text-xs text-slate-600">
                                {{ $pinjaman->handlerTechnician?->name ?? $pinjaman->handlerUser?->name ?? '-' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-sm text-slate-500">
                                Belum ada log peminjaman pada filter ini.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($log->hasPages())
            <div class="px-4 py-3 border-t border-slate-200">
                {{ $log->links() }}
            </div>
        @endif
    </div>
</div>

@if($bolehMeminjamkan)
    @include('tools.partials._modal-pinjam', [
        'daftarTeknisi'    => $daftarTeknisi,
        'daftarDepartemen' => $daftarDepartemen,
        'aksiPinjamkan'    => route('alat.pinjamkan'),
    ])
@endif
@endsection
