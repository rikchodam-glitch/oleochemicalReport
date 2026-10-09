@extends('layouts.app')

@section('title', 'Rincian Integrasi PR/PO — ' . $batch->periode)
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('sparepart.index') }}" class="hover:text-slate-700">Integrasi PR/PO Spare Part</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">{{ $batch->periode }}</span>
@endsection

@section('content')
    {{-- Kepala halaman dan tombol ekspor --}}
    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Rincian periode {{ $batch->periode }}</h1>
            <p class="text-sm text-slate-500 mt-1">
                {{ number_format($batch->jumlah_hasil, 0, ',', '.') }} material hasil integrasi.
                Status batch: {{ $batch->label_status }}@if($batch->selesai_at !== null) pada {{ $batch->selesai_at->format('d/m/Y H:i') }}@endif.
            </p>
            <p class="text-xs text-slate-400 mt-1 break-all">Folder sumber: {{ $batch->folder ?? '-' }}</p>
        </div>

        <a href="{{ route('sparepart.export', array_merge(['batch' => $batch->id], $saringan)) }}"
           class="px-4 py-2 rounded-lg text-sm font-medium text-white transition-colors"
           style="background-color:#0E9E8E;">
            Export Excel
        </a>
    </div>

    {{-- Ringkasan periode: jumlah material per nilai kolom aksi --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-3">
        @foreach($rekapAksi as $nilai => $jumlah)
            <a href="{{ route('sparepart.show', ['batch' => $batch->id, 'aksi' => $nilai]) }}"
               class="bg-white rounded-xl border {{ $saringan['aksi'] === $nilai ? 'border-teal-400' : 'border-slate-200' }} p-4 transition-colors"
               data-kartu-aksi="{{ \App\Models\SpIntegrasi::labelAksi($nilai) }}">
                <p class="text-xs text-slate-500">{{ \App\Models\SpIntegrasi::labelAksi($nilai) }}</p>
                <p class="text-2xl font-bold text-slate-900 mt-1">{{ number_format($jumlah, 0, ',', '.') }}</p>
            </a>
        @endforeach
    </div>

    @php $stokBatch = (array) ($batch->ringkasan['stok'] ?? []); @endphp

    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
        <div class="rounded-lg bg-white border border-rose-200 p-3">
            <p class="text-lg font-semibold text-rose-600">{{ number_format($jumlahReorder, 0, ',', '.') }}</p>
            <p class="text-xs text-slate-500 mt-0.5">Material perlu reorder</p>
        </div>
        <div class="rounded-lg bg-white border border-slate-200 p-3">
            <p class="text-lg font-semibold text-slate-800">{{ number_format((int) ($stokBatch['aman'] ?? 0), 0, ',', '.') }}</p>
            <p class="text-xs text-slate-500 mt-0.5">Stok aman</p>
        </div>
        <div class="rounded-lg bg-white border border-slate-200 p-3">
            <p class="text-lg font-semibold text-slate-800">{{ number_format($batch->jumlah_g001 + $batch->jumlah_pr + $batch->jumlah_po, 0, ',', '.') }}</p>
            <p class="text-xs text-slate-500 mt-0.5">Baris G001 + PR + PO</p>
        </div>
        <div class="rounded-lg bg-white border border-slate-200 p-3">
            <p class="text-lg font-semibold text-slate-800">{{ number_format($batch->jumlah_mrp, 0, ',', '.') }}</p>
            <p class="text-xs text-slate-500 mt-0.5">Baris MRP MD07</p>
        </div>
    </div>

    {{-- Saringan rincian: pencarian, aksi, dan penanda reorder --}}
    <div class="bg-white rounded-xl border border-slate-200 p-4 mb-6">
        <form method="GET" action="{{ route('sparepart.show', $batch) }}" class="flex flex-wrap gap-4 items-end">
            <div class="space-y-1 flex-1 min-w-[220px]">
                <label class="text-xs font-medium text-slate-500" for="sp_cari">Cari material</label>
                <input type="text" id="sp_cari" name="search" value="{{ $saringan['search'] }}"
                       placeholder="Kode material, deskripsi, no PR/PO, atau vendor..."
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
            </div>
            <div class="space-y-1">
                <label class="text-xs font-medium text-slate-500" for="sp_aksi">Aksi</label>
                <select id="sp_aksi" name="aksi" class="border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-teal-500">
                    <option value="">Semua aksi</option>
                    @foreach(\App\Models\SpIntegrasi::AKSI as $nilai)
                        <option value="{{ $nilai }}" {{ $saringan['aksi'] === $nilai ? 'selected' : '' }}>{{ $nilai }}</option>
                    @endforeach
                </select>
            </div>
            <div class="space-y-1">
                <label class="text-xs font-medium text-slate-500" for="sp_reorder">Reorder</label>
                <select id="sp_reorder" name="reorder" class="border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-teal-500">
                    <option value="">Semua material</option>
                    <option value="ya" {{ $saringan['reorder'] === 'ya' ? 'selected' : '' }}>Hanya REORDER</option>
                    <option value="tidak" {{ $saringan['reorder'] === 'tidak' ? 'selected' : '' }}>Hanya stok aman</option>
                </select>
            </div>
            <button type="submit" class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white text-sm font-medium rounded-lg transition-colors">Terapkan</button>
            <a href="{{ route('sparepart.show', $batch) }}" class="text-sm text-slate-500 hover:text-slate-700 py-2">Reset</a>
        </form>
    </div>


    {{-- Rincian tiap material pada periode ini --}}
    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-200 flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-sm font-semibold text-slate-800">
                Rincian material ({{ number_format($rincian->total(), 0, ',', '.') }} baris sesuai saringan)
            </h2>
            <p class="text-xs text-slate-400">Satu baris mewakili satu kode material</p>
        </div>

        @php
            // Rapikan angka: nol di belakang koma dibuang, nilai kosong jadi tanda hubung.
            $angkaRapi = function ($nilai) {
                if ($nilai === null) {
                    return '-';
                }

                return rtrim(rtrim(number_format((float) $nilai, 2, ',', '.'), '0'), ',');
            };
        @endphp

        <div class="overflow-x-auto">
            <table class="min-w-[1500px] w-full text-xs">
                <thead class="bg-slate-50 uppercase tracking-wider text-slate-500">
                    <tr>
                        <th class="px-3 py-3 text-left font-medium">Kode Material</th>
                        <th class="px-3 py-3 text-left font-medium">Deskripsi</th>
                        <th class="px-3 py-3 text-center font-medium">Reorder</th>
                        <th class="px-3 py-3 text-right font-medium">Stok Min</th>
                        <th class="px-3 py-3 text-right font-medium">Stok Tersedia</th>
                        <th class="px-3 py-3 text-center font-medium">Min vs MRP</th>
                        <th class="px-3 py-3 text-left font-medium">Aksi</th>
                        <th class="px-3 py-3 text-left font-medium">No PR</th>
                        <th class="px-3 py-3 text-left font-medium">Status PR</th>
                        <th class="px-3 py-3 text-right font-medium">Qty PR</th>
                        <th class="px-3 py-3 text-left font-medium">No PO</th>
                        <th class="px-3 py-3 text-right font-medium">Sisa PO</th>
                        <th class="px-3 py-3 text-left font-medium">Tanggal PO</th>
                        <th class="px-3 py-3 text-left font-medium">Vendor</th>
                        <th class="px-3 py-3 text-right font-medium">G001 Sisa</th>
                        <th class="px-3 py-3 text-right font-medium">MRP Reorder</th>
                        <th class="px-3 py-3 text-left font-medium">Keterangan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($rincian as $baris)
                        <tr data-kode-material="{{ $baris->kode_material }}">
                            <td class="px-3 py-2 font-medium text-slate-800">{{ $baris->kode_material }}</td>
                            <td class="px-3 py-2 text-slate-600">{{ $baris->deskripsi ?? '-' }}</td>
                            <td class="px-3 py-2 text-center">
                                @if($baris->reorder)
                                    <span class="px-2 py-0.5 rounded-full bg-rose-50 text-rose-700 font-medium">REORDER</span>
                                @else
                                    <span class="text-slate-400">Aman</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-right text-slate-600">{{ $angkaRapi($baris->stok_min) }}</td>
                            <td class="px-3 py-2 text-right text-slate-600">{{ $angkaRapi($baris->stok_tersedia) }}</td>
                            <td class="px-3 py-2 text-center text-slate-600">{{ $baris->min_cmp ?? '-' }}</td>
                            <td class="px-3 py-2 font-medium text-slate-700">{{ $baris->label_aksi }}</td>
                            <td class="px-3 py-2 text-slate-600">{{ $baris->pr_no ?? '-' }}</td>
                            <td class="px-3 py-2 text-slate-600">{{ $baris->pr_status ?? '-' }}</td>
                            <td class="px-3 py-2 text-right text-slate-600">{{ $angkaRapi($baris->pr_qty) }}</td>
                            <td class="px-3 py-2 text-slate-600">{{ $baris->po_no ?? '-' }}</td>
                            <td class="px-3 py-2 text-right text-slate-600">{{ $angkaRapi($baris->po_sisa) }}</td>
                            <td class="px-3 py-2 text-slate-600">{{ $baris->po_tanggal_teks ?? '-' }}</td>
                            <td class="px-3 py-2 text-slate-600">{{ $baris->vendor ?? '-' }}</td>
                            <td class="px-3 py-2 text-right text-slate-600">{{ $angkaRapi($baris->g_sisa) }}</td>
                            <td class="px-3 py-2 text-right text-slate-600">{{ $angkaRapi($baris->mrp_reorder) }}</td>
                            <td class="px-3 py-2 text-slate-500">{{ $baris->ket ?? '-' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="17" class="px-4 py-6 text-center text-slate-400">Tidak ada material yang cocok dengan saringan.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="px-4 py-3 border-t border-slate-200">
            {{ $rincian->links() }}
        </div>
    </div>
@endsection

