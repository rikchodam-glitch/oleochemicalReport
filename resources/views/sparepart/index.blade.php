@extends('layouts.app')

@section('title', 'Integrasi PR/PO Spare Part')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Integrasi PR/PO Spare Part</span>
@endsection

@section('content')
    {{-- Kepala halaman --}}
    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Integrasi PR/PO Spare Part</h1>
            <p class="text-sm text-slate-500 mt-1 max-w-3xl">
                Hasil penggabungan monitoring stok, permintaan pembelian (PR), pesanan
                pembelian (PO), stok gudang G001, dan nilai MRP MD07 untuk setiap kode
                material. Halaman ini hanya membaca hasil import.
            </p>
        </div>

        @if($terbaru !== null)
            <a href="{{ route('sparepart.show', $terbaru) }}"
               class="px-4 py-2 rounded-lg text-sm font-medium text-white transition-colors"
               style="background-color:#0E9E8E;">
                Buka rincian periode terbaru
            </a>
        @endif
    </div>

    @if($terbaru === null)
        {{-- Belum ada data sama sekali --}}
        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <p class="text-sm font-medium text-slate-700">Belum ada data integrasi.</p>
            <p class="text-sm text-slate-500 mt-2">
                Data dibuat lewat perintah berikut, dijalankan dari terminal pada folder
                periode yang memuat lima berkas sumber:
            </p>
            <code class="inline-block mt-3 px-3 py-2 rounded-lg bg-slate-100 text-slate-700 text-xs">php artisan sparepart:import "J:\Spare Part\Okt 26"</code>
        </div>
    @endif

    @if($terbaru !== null)
        {{-- Ringkasan periode terbaru --}}
        <div class="bg-white rounded-xl border border-slate-200 p-4 mb-6">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
                <div>
                    <p class="text-xs uppercase tracking-wider text-slate-400">Periode terbaru</p>
                    <p class="text-lg font-semibold text-slate-900" data-periode-terbaru>{{ $terbaru->periode }}</p>
                </div>
                <p class="text-xs text-slate-400">
                    Diimpor {{ $terbaru->selesai_at?->format('d/m/Y H:i') ?? $terbaru->created_at?->format('d/m/Y H:i') }}
                    oleh {{ $terbaru->pengguna?->name ?? 'Sistem' }}
                </p>
            </div>

            {{-- Kartu tindak lanjut per nilai kolom aksi --}}
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                @foreach($kartuAksi as $kartu)
                    <a href="{{ route('sparepart.show', ['batch' => $terbaru->id, 'aksi' => $kartu['nilai']]) }}"
                       class="rounded-xl border border-slate-200 p-4 hover:border-teal-400 transition-colors"
                       data-kartu-aksi="{{ $kartu['label'] }}">
                        <p class="text-xs text-slate-500">{{ $kartu['label'] }}</p>
                        <p class="text-2xl font-bold text-slate-900 mt-1">{{ number_format($kartu['jumlah'], 0, ',', '.') }}</p>
                    </a>
                @endforeach
            </div>

            {{-- Angka pendukung periode terbaru --}}
            @php
                $stokTerbaru    = (array) ($ringkasan['stok'] ?? []);
                $jumlahBaris    = (array) ($ringkasan['jumlah'] ?? []);
                $angkaPendukung = [
                    ['label' => 'Material terintegrasi', 'nilai' => $jumlahBaris['hasil'] ?? $terbaru->jumlah_hasil],
                    ['label' => 'Perlu reorder', 'nilai' => $stokTerbaru['reorder'] ?? 0],
                    ['label' => 'Stok aman', 'nilai' => $stokTerbaru['aman'] ?? 0],
                    ['label' => 'Baris G001', 'nilai' => $terbaru->jumlah_g001],
                    ['label' => 'Baris PR', 'nilai' => $terbaru->jumlah_pr],
                    ['label' => 'Baris PO', 'nilai' => $terbaru->jumlah_po],
                ];
            @endphp

            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mt-3">
                @foreach($angkaPendukung as $angka)
                    <div class="rounded-lg bg-slate-50 border border-slate-200 p-3">
                        <p class="text-lg font-semibold text-slate-800">{{ number_format((int) $angka['nilai'], 0, ',', '.') }}</p>
                        <p class="text-xs text-slate-500 mt-0.5">{{ $angka['label'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    @endif


    {{-- Daftar periode import. Urutan memakai id menurun karena kolom periode
         berisi teks bebas yang tidak bisa diurutkan secara kronologis. --}}
    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-200 flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-sm font-semibold text-slate-800">Daftar periode import</h2>
            <p class="text-xs text-slate-400">Terbaru di atas</p>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium">Periode</th>
                        <th class="px-4 py-3 text-left font-medium">Waktu import</th>
                        <th class="px-4 py-3 text-left font-medium">Oleh</th>
                        <th class="px-4 py-3 text-right font-medium">Material</th>
                        <th class="px-4 py-3 text-right font-medium">REORDER</th>
                        <th class="px-4 py-3 text-left font-medium">Status</th>
                        <th class="px-4 py-3 text-right font-medium">Rincian</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($batches as $batch)
                        @php $stokBatch = (array) ($batch->ringkasan['stok'] ?? []); @endphp
                        <tr data-batch-id="{{ $batch->id }}">
                            <td class="px-4 py-3 font-medium text-slate-800">{{ $batch->periode }}</td>
                            <td class="px-4 py-3 text-slate-500">
                                {{ $batch->selesai_at?->format('d/m/Y H:i') ?? $batch->created_at?->format('d/m/Y H:i') ?? '-' }}
                            </td>
                            <td class="px-4 py-3 text-slate-500">{{ $batch->pengguna?->name ?? 'Sistem' }}</td>
                            <td class="px-4 py-3 text-right text-slate-700">{{ number_format((int) $batch->jumlah_hasil, 0, ',', '.') }}</td>
                            <td class="px-4 py-3 text-right text-slate-700">{{ number_format((int) ($stokBatch['reorder'] ?? 0), 0, ',', '.') }}</td>
                            <td class="px-4 py-3">
                                @if($batch->status === 'selesai')
                                    <span class="px-2 py-1 rounded-full bg-teal-50 text-teal-700 text-xs font-medium">{{ $batch->label_status }}</span>
                                @elseif($batch->status === 'gagal')
                                    <span class="px-2 py-1 rounded-full bg-rose-50 text-rose-700 text-xs font-medium">{{ $batch->label_status }}</span>
                                @else
                                    <span class="px-2 py-1 rounded-full bg-amber-50 text-amber-700 text-xs font-medium">{{ $batch->label_status }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('sparepart.show', $batch) }}" class="text-teal-700 hover:text-teal-800 font-medium">Buka</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-6 text-center text-slate-400">Belum ada periode yang diimport.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="px-4 py-3 border-t border-slate-200">
            {{ $batches->links() }}
        </div>
    </div>
@endsection

