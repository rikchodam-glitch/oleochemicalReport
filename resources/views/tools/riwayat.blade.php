@extends('layouts.app')

@section('title', 'Riwayat Peminjaman Alat')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('tools.index') }}" class="hover:text-slate-700">Management Tools</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Riwayat Peminjaman</span>
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

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold text-slate-900">Riwayat Peminjaman</h2>
            <p class="text-xs text-slate-500">Semua transaksi peminjaman alat, termasuk pengembalian.</p>
        </div>
    </div>

    <form method="GET" action="{{ route('tools.loans.index') }}"
          class="bg-white rounded-xl border border-slate-200 p-4 flex flex-wrap items-end gap-3">
        <div class="flex-1 min-w-[220px]">
            <label class="block text-xs font-medium text-slate-600 mb-1">Pencarian</label>
            <input type="search" name="q" value="{{ $cari }}" placeholder="Kode, alat, peminjam, NIK..."
                   class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
        </div>
        <div>
            <label class="block text-xs font-medium text-slate-600 mb-1">Status</label>
            <select name="status" class="rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                <option value="aktif" @selected($status === 'aktif')>Masih dipinjam</option>
                <option value="selesai" @selected($status === 'selesai')>Sudah dikembalikan</option>
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium text-slate-600 mb-1">Lokasi</label>
            <select name="lokasi" class="rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                <option value="">Semua lokasi</option>
                @foreach($daftarLokasi as $lokasi)
                    <option value="{{ $lokasi->id }}" @selected((string) request('lokasi') === (string) $lokasi->id)>{{ $lokasi->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium text-slate-600 mb-1">Rak</label>
            <select name="rak" class="rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                <option value="">Semua rak</option>
                @foreach($daftarRak as $rak)
                    <option value="{{ $rak->id }}" @selected((string) request('rak') === (string) $rak->id)>{{ $rak->name }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="px-4 py-2 rounded-lg bg-teal-600 text-white text-sm font-semibold hover:bg-teal-700">
            Saring
        </button>
        <a href="{{ route('tools.loans.index') }}" class="px-3 py-2 rounded-lg border border-slate-300 text-sm text-slate-600 hover:bg-slate-50">
            Reset
        </a>
    </form>

    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-slate-600">
                    <tr>
                        <th class="text-left px-4 py-2 font-medium">Kode</th>
                        <th class="text-left px-4 py-2 font-medium">Alat</th>
                        <th class="text-left px-4 py-2 font-medium">Peminjam</th>
                        <th class="text-left px-4 py-2 font-medium">Lokasi / Rak</th>
                        <th class="text-left px-4 py-2 font-medium">Dipinjam</th>
                        <th class="text-left px-4 py-2 font-medium">Dikembalikan</th>
                        <th class="text-left px-4 py-2 font-medium">Status</th>
                        <th class="text-right px-4 py-2 font-medium">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($daftar as $pinjaman)
                        @php
                            $lokasiAlat = $pinjaman->tool?->rack?->location?->name;
                            $rakAlat = $pinjaman->tool?->rack?->name;
                        @endphp
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-2.5 font-mono text-xs text-slate-600">{{ $pinjaman->kode }}</td>
                            <td class="px-4 py-2.5">
                                <p class="font-medium text-slate-800">{{ $pinjaman->tool_name }}</p>
                                <p class="text-xs text-slate-400">{{ $pinjaman->tool_code }}</p>
                            </td>
                            <td class="px-4 py-2.5">
                                <p class="text-slate-800">{{ $pinjaman->peminjamLabel() }}</p>
                                <p class="text-[11px] text-slate-400">
                                    {{ \App\Models\ToolLoan::BORROWER[$pinjaman->borrower_type] ?? $pinjaman->borrower_type }}
                                </p>
                            </td>
                            <td class="px-4 py-2.5 text-xs text-slate-600">
                                {{ $lokasiAlat ?? '-' }} / {{ $rakAlat ?? '-' }}
                            </td>
                            <td class="px-4 py-2.5 text-xs text-slate-600">{{ $pinjaman->borrowed_at?->format('d/m/Y H:i') }}</td>
                            <td class="px-4 py-2.5 text-xs text-slate-600">
                                {{ $pinjaman->returned_at?->format('d/m/Y H:i') ?? '-' }}
                            </td>
                            <td class="px-4 py-2.5">
                                @if($pinjaman->returned_at !== null)
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold bg-green-100 text-green-700">Dikembalikan</span>
                                @elseif($pinjaman->menungguPenerimaan())
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold bg-amber-100 text-amber-700">Menunggu diterima</span>
                                @else
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold bg-blue-100 text-blue-700">Dipinjam</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    @if($pinjaman->tool_id)
                                        <a href="{{ route('alat.show', $pinjaman->tool_id) }}"
                                           class="text-xs font-semibold text-slate-600 hover:text-slate-800">
                                            Log Alat
                                        </a>
                                    @endif

                                    @if($bolehTulis && $pinjaman->masihDipinjam())
                                        <form method="POST" action="{{ route('tools.loans.kembalikan', $pinjaman->id) }}"
                                              onsubmit="return confirm('Tandai alat {{ $pinjaman->tool_name }} ({{ $pinjaman->tool_code }}) sudah dikembalikan?')">
                                            @csrf
                                            <button type="submit" class="text-xs font-semibold text-teal-700 hover:text-teal-800">
                                                Kembalikan
                                            </button>
                                        </form>
                                    @endif

                                    @if(! $pinjaman->tool_id && ! ($bolehTulis && $pinjaman->masihDipinjam()))
                                        <span class="text-xs text-slate-300">-</span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-8 text-center text-sm text-slate-500">
                                Belum ada riwayat peminjaman pada filter ini.
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
</div>
@endsection
