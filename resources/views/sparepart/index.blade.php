@extends('layouts.app')

@section('title', 'Sparepart — Integrasi PR/PO')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Sparepart</span>
@endsection

@php
    // Format angka tanpa nol desimal yang tidak perlu; nilai kosong jadi tanda hubung.
    $angkaRapi = function ($nilai): string {
        if ($nilai === null || $nilai === '') {
            return '-';
        }

        $teks = number_format((float) $nilai, 3, ',', '.');

        return rtrim(rtrim($teks, '0'), ',') ?: '0';
    };

    $warnaAksi = [
        \App\Models\SpMaterial::AKSI_KEJAR   => 'bg-rose-50 text-rose-700 ring-1 ring-rose-200',
        \App\Models\SpMaterial::AKSI_BUAT_PO => 'bg-amber-50 text-amber-700 ring-1 ring-amber-200',
        \App\Models\SpMaterial::AKSI_CEK     => 'bg-sky-50 text-sky-700 ring-1 ring-sky-200',
        \App\Models\SpMaterial::AKSI_BUAT_PR => 'bg-slate-100 text-slate-600 ring-1 ring-slate-200',
    ];

    $adaMrp = (int) ($ringkasan['punya_mrp'] ?? 0) > 0;
@endphp

@section('content')
    <div class="space-y-6" x-data="{ buka: null }">
        {{-- Kepala halaman --}}
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-slate-900">Sparepart — Integrasi PR/PO</h1>
                <p class="text-sm text-slate-500 mt-1 max-w-3xl">
                    Satu baris per kode material. Data stok gudang G001, PR, PO, dan MRP selalu
                    mutakhir mengikuti import terakhir. Semua nomor PR/PO yang terbaca ditampilkan,
                    bukan hanya dokumen terpilih.
                </p>
                @if($terakhir !== null)
                    <p class="text-xs text-slate-400 mt-1">
                        Import terakhir: #{{ $terakhir->id }}
                        {{ $terakhir->selesai_at?->format('d/m/Y H:i') ?? $terakhir->created_at?->format('d/m/Y H:i') }}
                        oleh {{ $terakhir->pengguna?->name ?? 'Sistem' }}
                    </p>
                @endif
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('sparepart.pantau') }}"
                   class="px-4 py-2 rounded-lg text-sm font-medium border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">
                    Daftar Pantau ({{ number_format($ringkasan['total'], 0, ',', '.') }})
                </a>
                <a href="{{ route('sparepart.riwayat') }}"
                   class="px-4 py-2 rounded-lg text-sm font-medium border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">
                    Riwayat Import
                </a>
                <a href="{{ route('sparepart.impor') }}"
                   class="px-4 py-2 rounded-lg text-sm font-medium text-white"
                   style="background-color:#0E9E8E;">
                    Import Berkas
                </a>
                <a href="{{ route('sparepart.export', $saringan) }}"
                   class="px-4 py-2 rounded-lg text-sm font-medium border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">
                    Export Excel
                </a>
            </div>
        </div>

        @if($terakhir === null)
            <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 text-sm text-amber-800">
                Belum ada import berkas. Buka
                <a href="{{ route('sparepart.impor') }}" class="font-semibold underline">Import Berkas</a>
                untuk mengunggah laporan stok gudang, EXPORT PR, EXPORT PO, dan EXPORT MRP.
            </div>
        @endif
        @include('sparepart.partials._peringatan-cakupan', ['impor' => $terakhir])

        {{-- Kartu ringkasan keadaan --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <a href="{{ route('sparepart.index') }}"
               class="bg-white rounded-xl border {{ $saringan['status'] === '' ? 'border-teal-400' : 'border-slate-200' }} p-4 hover:border-teal-400 transition-colors">
                <p class="text-xs text-slate-500">Material dipantau</p>
                <p class="text-2xl font-bold text-slate-900 mt-1" data-kartu="total">{{ number_format($ringkasan['total'], 0, ',', '.') }}</p>
            </a>
            <a href="{{ route('sparepart.index', ['status' => 'reorder']) }}"
               class="bg-white rounded-xl border {{ $saringan['status'] === 'reorder' ? 'border-teal-400' : 'border-slate-200' }} p-4 hover:border-teal-400 transition-colors">
                <p class="text-xs text-slate-500">Perlu reorder (REORDER)</p>
                <p class="text-2xl font-bold text-rose-600 mt-1" data-kartu="reorder">{{ number_format($ringkasan['reorder'], 0, ',', '.') }}</p>
            </a>
            <a href="{{ route('sparepart.index', ['status' => 'aman']) }}"
               class="bg-white rounded-xl border {{ $saringan['status'] === 'aman' ? 'border-teal-400' : 'border-slate-200' }} p-4 hover:border-teal-400 transition-colors">
                <p class="text-xs text-slate-500">Stok aman</p>
                <p class="text-2xl font-bold text-teal-700 mt-1" data-kartu="aman">{{ number_format($ringkasan['aman'], 0, ',', '.') }}</p>
            </a>
            <a href="{{ route('sparepart.index', ['status' => 'belum']) }}"
               class="bg-white rounded-xl border {{ $saringan['status'] === 'belum' ? 'border-teal-400' : 'border-slate-200' }} p-4 hover:border-teal-400 transition-colors">
                <p class="text-xs text-slate-500">Tanpa stok minimum</p>
                <p class="text-2xl font-bold text-amber-600 mt-1" data-kartu="belum">{{ number_format($ringkasan['belum'], 0, ',', '.') }}</p>
            </a>
        </div>

        {{-- Keterangan lingkup kartu aksi --}}
        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
            <p class="text-sm font-semibold text-slate-700">
                Rincian tindakan {{ number_format($ringkasan['reorder'], 0, ',', '.') }} item REORDER (fokus tindakan)
            </p>
            <p class="text-xs text-slate-400">
                Sama seperti sheet "Daftar Aksi" dashboard lama; material yang stoknya masih aman tidak dihitung.
            </p>
        </div>

        {{-- Kartu tindak lanjut --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            @foreach($kartuAksi as $kartu)
                <a href="{{ route('sparepart.index', ['aksi' => $kartu['nilai'], 'status' => 'reorder']) }}"
                   class="bg-white rounded-xl border {{ $saringan['aksi'] === $kartu['nilai'] ? 'border-teal-400' : 'border-slate-200' }} p-4 hover:border-teal-400 transition-colors"
                   data-kartu-aksi="{{ $kartu['label'] }}">
                    <p class="text-xs text-slate-500">{{ $kartu['label'] }}</p>
                    <p class="text-xl font-semibold text-slate-900 mt-1">{{ number_format($kartu['jumlah'], 0, ',', '.') }}</p>
                    <p class="text-xs text-slate-400 mt-1 leading-snug">{{ $kartu['keterangan'] }}</p>
                </a>
            @endforeach
        </div>

        {{-- Ringkasan kepemilikan data --}}
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <a href="{{ route('sparepart.index', ['punya' => 'pr']) }}"
               class="bg-white rounded-xl border {{ $saringan['punya'] === 'pr' ? 'border-teal-400' : 'border-slate-200' }} px-4 py-3 flex items-center justify-between hover:border-teal-400 transition-colors">
                <span class="text-sm text-slate-600">Material punya data PR</span>
                <span class="text-sm font-semibold text-slate-900">{{ number_format($ringkasan['punya_pr'], 0, ',', '.') }}</span>
            </a>
            <a href="{{ route('sparepart.index', ['punya' => 'po']) }}"
               class="bg-white rounded-xl border {{ $saringan['punya'] === 'po' ? 'border-teal-400' : 'border-slate-200' }} px-4 py-3 flex items-center justify-between hover:border-teal-400 transition-colors">
                <span class="text-sm text-slate-600">Material punya data PO</span>
                <span class="text-sm font-semibold text-slate-900">{{ number_format($ringkasan['punya_po'], 0, ',', '.') }}</span>
            </a>
            <a href="{{ route('sparepart.index', ['punya' => 'mrp']) }}"
               class="bg-white rounded-xl border {{ $saringan['punya'] === 'mrp' ? 'border-teal-400' : 'border-slate-200' }} px-4 py-3 flex items-center justify-between hover:border-teal-400 transition-colors">
                <span class="text-sm text-slate-600">Material punya data MRP</span>
                <span class="text-sm font-semibold text-slate-900">{{ number_format($ringkasan['punya_mrp'], 0, ',', '.') }}</span>
            </a>
        </div>

        {{-- Saringan tabel --}}
        <form method="GET" action="{{ route('sparepart.index') }}"
              class="bg-white rounded-xl border border-slate-200 p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3 items-end">
            <div class="sm:col-span-2">
                <label for="q" class="block text-xs font-medium text-slate-600 mb-1">Cari (kode, deskripsi, semua no PR/PO, vendor, tipe MRP)</label>
                <input type="text" name="q" id="q" value="{{ $saringan['q'] }}"
                       class="w-full rounded-lg border-slate-200 text-sm focus:border-teal-500 focus:ring-teal-500">
            </div>
            <div>
                <label for="status" class="block text-xs font-medium text-slate-600 mb-1">Status stok</label>
                <select name="status" id="status" class="w-full rounded-lg border-slate-200 text-sm focus:border-teal-500 focus:ring-teal-500">
                    <option value="">Semua</option>
                    @foreach(\App\Http\Controllers\SparepartController::STATUS_SARING as $nilai => $label)
                        <option value="{{ $nilai }}" @selected($saringan['status'] === $nilai)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="aksi" class="block text-xs font-medium text-slate-600 mb-1">Aksi</label>
                <select name="aksi" id="aksi" class="w-full rounded-lg border-slate-200 text-sm focus:border-teal-500 focus:ring-teal-500">
                    <option value="">Semua</option>
                    @foreach(\App\Models\SpMaterial::AKSI as $nilai)
                        <option value="{{ $nilai }}" @selected($saringan['aksi'] === $nilai)>{{ \App\Models\SpMaterial::labelAksi($nilai) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="punya" class="block text-xs font-medium text-slate-600 mb-1">Kepemilikan data</label>
                <select name="punya" id="punya" class="w-full rounded-lg border-slate-200 text-sm focus:border-teal-500 focus:ring-teal-500">
                    <option value="">Semua</option>
                    @foreach(\App\Http\Controllers\SparepartController::PUNYA_SARING as $nilai => $label)
                        <option value="{{ $nilai }}" @selected($saringan['punya'] === $nilai)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="urut" class="block text-xs font-medium text-slate-600 mb-1">Urutan</label>
                <select name="urut" id="urut" class="w-full rounded-lg border-slate-200 text-sm focus:border-teal-500 focus:ring-teal-500">
                    <option value="reorder" @selected($saringan['urut'] === 'reorder')>Perlu tindakan dulu</option>
                    <option value="kode" @selected($saringan['urut'] === 'kode')>Kode material</option>
                    <option value="stok" @selected($saringan['urut'] === 'stok')>Stok tersedia terkecil</option>
                    <option value="aksi" @selected($saringan['urut'] === 'aksi')>Aksi</option>
                    <option value="terakhir" @selected($saringan['urut'] === 'terakhir')>Terakhir diperbarui</option>
                </select>
            </div>
            <div class="flex items-center gap-3 lg:col-span-6">
                <button type="submit" class="px-4 py-2 rounded-lg text-sm font-medium text-white" style="background-color:#0E9E8E;">
                    Terapkan saringan
                </button>
                <a href="{{ route('sparepart.index') }}" class="text-sm text-slate-500 hover:text-slate-700">Bersihkan</a>
                <span class="ml-auto text-xs text-slate-400">
                    Menampilkan {{ number_format($baris->count(), 0, ',', '.') }} dari
                    {{ number_format($baris->total(), 0, ',', '.') }} material
                </span>
            </div>
        </form>

        {{-- Tabel keadaan --}}
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-[1500px] w-full text-sm">
                    <thead class="bg-slate-50 text-[11px] uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="sticky top-0 z-10 bg-slate-50 px-3 py-3 text-left font-semibold">Kode / Deskripsi</th>
                            <th class="sticky top-0 z-10 bg-slate-50 px-3 py-3 text-right font-semibold">Stok Min</th>
                            <th class="sticky top-0 z-10 bg-slate-50 px-3 py-3 text-right font-semibold">Stok Tersedia</th>
                            <th class="sticky top-0 z-10 bg-slate-50 px-3 py-3 text-left font-semibold">Status</th>
                            <th class="sticky top-0 z-10 bg-slate-50 px-3 py-3 text-left font-semibold">Aksi</th>
                            <th class="sticky top-0 z-10 bg-slate-50 px-3 py-3 text-left font-semibold">PR</th>
                            <th class="sticky top-0 z-10 bg-slate-50 px-3 py-3 text-left font-semibold">PO</th>
                            <th class="sticky top-0 z-10 bg-slate-50 px-3 py-3 text-left font-semibold">Purchaser / Vendor</th>
                            <th class="sticky top-0 z-10 bg-slate-50 px-3 py-3 text-left font-semibold">MRP</th>
                            <th class="sticky top-0 z-10 bg-slate-50 px-3 py-3 text-left font-semibold">Diperbarui</th>
                            <th class="sticky top-0 z-10 bg-slate-50 px-3 py-3 text-right font-semibold">Rincian</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($baris as $satu)
                            @php $kunciBaris = (string) $satu->kode_material; @endphp
                            <tr data-kode-material="{{ $kunciBaris }}" class="odd:bg-white even:bg-slate-50/40 hover:bg-teal-50/30 transition-colors">
                                <td class="px-3 py-3 align-top">
                                    <p class="font-semibold text-slate-800">{{ $kunciBaris }}</p>
                                    <p class="text-slate-600">{{ $satu->m_deskripsi ?? $satu->deskripsi_pantau ?? '-' }}</p>
                                    @if(collect([$satu->plant, $satu->model, $satu->tag_no])->filter()->isNotEmpty())
                                        <p class="text-xs text-slate-400">
                                            {{ collect([$satu->plant, $satu->model, $satu->tag_no])->filter()->implode(' / ') }}
                                        </p>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-right tabular-nums text-slate-600">{{ $angkaRapi($satu->m_stok_min ?? $satu->stok_min_pantau) }}</td>
                                <td class="px-3 py-3 text-right tabular-nums font-medium text-slate-800">{{ $angkaRapi($satu->m_stok_tersedia) }}</td>
                                <td class="px-3 py-3 align-top">
                                    @if($satu->m_stok_min === null)
                                        <span class="inline-flex px-2 py-0.5 rounded-full bg-amber-50 text-amber-700 text-xs font-medium ring-1 ring-amber-200">Tanpa stok min</span>
                                    @elseif($satu->m_reorder)
                                        <span class="inline-flex px-2 py-0.5 rounded-full bg-rose-50 text-rose-700 text-xs font-medium ring-1 ring-rose-200">REORDER</span>
                                    @else
                                        <span class="inline-flex px-2 py-0.5 rounded-full bg-teal-50 text-teal-700 text-xs font-medium ring-1 ring-teal-200">Aman</span>
                                    @endif
                                    @if(($satu->m_penanda ?? '') !== '')
                                        <p class="text-xs text-slate-400 mt-1">{{ $satu->m_penanda }}</p>
                                    @endif
                                </td>
                                <td class="px-3 py-3 align-top">
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium {{ $warnaAksi[$satu->m_aksi] ?? 'bg-slate-100 text-slate-600 ring-1 ring-slate-200' }}">
                                        {{ \App\Models\SpMaterial::labelAksi($satu->m_aksi) }}
                                    </span>
                                </td>
                                <td class="px-3 py-3 align-top">
                                    @if((int) ($satu->m_pr_line ?? 0) > 0)
                                        <p class="font-medium text-slate-800">{{ $satu->m_pr_no ?? '-' }}</p>
                                        <p class="text-xs text-slate-500">{{ $satu->m_pr_status_aksi ?? '-' }}</p>
                                        <p class="text-xs text-slate-400 mt-1">
                                            {{ (int) $satu->m_pr_line }} baris &middot;
                                            <span class="{{ (int) $satu->m_pr_terbuka > 0 ? 'text-amber-700 font-medium' : '' }}">{{ (int) $satu->m_pr_terbuka }} terbuka</span>
                                        </p>
                                        @if(($satu->m_pr_open_nums ?? '') !== '')
                                            <div class="mt-1 max-h-12 overflow-y-auto text-[11px] text-amber-700 break-words">
                                                {{ $satu->m_pr_open_nums }}
                                            </div>
                                        @endif
                                    @else
                                        <span class="text-slate-400">Belum ada PR</span>
                                    @endif
                                </td>
                                <td class="px-3 py-3 align-top">
                                    @if((int) ($satu->m_po_uniq ?? 0) > 0)
                                        <p class="font-medium text-slate-800">{{ $satu->m_po_no ?? '-' }}</p>
                                        <p class="text-xs text-slate-500">Sisa: {{ $angkaRapi($satu->m_po_sisa) }}</p>
                                        <p class="text-xs text-slate-400 mt-1">
                                            {{ (int) $satu->m_po_uniq }} PO &middot;
                                            <span class="{{ (int) $satu->m_po_open > 0 ? 'text-amber-700 font-medium' : '' }}">{{ (int) $satu->m_po_open }} terbuka</span>
                                        </p>
                                        @if(($satu->m_po_open_nums ?? '') !== '')
                                            <div class="mt-1 max-h-12 overflow-y-auto text-[11px] text-amber-700 break-words">
                                                {{ $satu->m_po_open_nums }}
                                            </div>
                                        @endif
                                    @else
                                        <span class="text-slate-400">Belum ada PO</span>
                                    @endif
                                </td>
                                <td class="px-3 py-3 align-top">
                                    <p class="font-medium text-slate-800">{{ $satu->m_purch ?? '-' }}</p>
                                    <p class="text-xs text-slate-500 break-words">{{ $satu->m_vendor ?? '-' }}</p>
                                    @if(($satu->m_po_prev ?? '') !== '')
                                        <p class="text-[11px] text-slate-400 mt-1">Sebelumnya: {{ $satu->m_po_prev }}</p>
                                    @endif
                                </td>
                                <td class="px-3 py-3 align-top">
                                    @if($adaMrp)
                                        @if($satu->m_mrp_reorder !== null || $satu->m_mrp_stock !== null)
                                            <p class="text-xs text-slate-500">Stock by SAP</p>
                                            <p class="font-medium text-slate-800 tabular-nums">{{ $angkaRapi($satu->m_mrp_stock) }}</p>
                                            <p class="text-[11px] text-slate-500 mt-1">
                                                Safety {{ $angkaRapi($satu->m_mrp_safety) }} / Reorder {{ $angkaRapi($satu->m_mrp_reorder) }}
                                            </p>
                                            <span class="inline-flex mt-1 px-2 py-0.5 rounded-full text-[11px] font-medium {{ $satu->m_mrp_cmp === 'Beda' ? 'bg-amber-50 text-amber-700 ring-1 ring-amber-200' : 'bg-teal-50 text-teal-700 ring-1 ring-teal-200' }}">
                                                Min {{ $satu->m_mrp_cmp ?? '-' }}
                                            </span>
                                        @else
                                            <span class="text-slate-400">Tidak ada di MRP</span>
                                        @endif
                                    @else
                                        <span class="text-slate-400">Belum diimpor</span>
                                    @endif
                                </td>
                                <td class="px-3 py-3 align-top text-xs text-slate-500 whitespace-nowrap">
                                    {{ $satu->m_diubah_at !== null ? \Illuminate\Support\Carbon::parse($satu->m_diubah_at)->format('d/m/Y H:i') : '-' }}
                                </td>
                                <td class="px-3 py-3 text-right align-top">
                                    <button type="button"
                                            class="inline-flex items-center px-3 py-1.5 rounded-lg border border-slate-200 text-xs font-medium text-teal-700 hover:bg-teal-50"
                                            @click="buka = buka === '{{ $kunciBaris }}' ? null : '{{ $kunciBaris }}'">
                                        <span x-text="buka === '{{ $kunciBaris }}' ? 'Tutup' : 'Buka'">Buka</span>
                                    </button>
                                </td>
                            </tr>

                            <template x-if="buka === '{{ $kunciBaris }}'">
                                <tr class="bg-slate-50/80">
                                    <td colspan="11" class="px-4 py-4">
                                        <div class="grid grid-cols-1 xl:grid-cols-4 gap-4 text-xs text-slate-600">
                                            <div class="bg-white rounded-lg border border-slate-200 p-3">
                                                <p class="font-semibold text-slate-800 mb-2">Permintaan pembelian (PR)</p>
                                                <p class="mb-1">
                                                    Terpilih: <span class="font-medium text-slate-800">{{ $satu->m_pr_no ?? '-' }}</span>
                                                    ({{ $satu->m_pr_status_aksi ?? '-' }})
                                                </p>
                                                <p class="mb-1">Status seluruh PR: {{ $satu->m_pr_status ?? '-' }}</p>
                                                <p class="mb-1">
                                                    Qty PR: {{ $angkaRapi($satu->m_pr_qty) }} &middot;
                                                    Baris: {{ (int) ($satu->m_pr_line ?? 0) }} &middot;
                                                    Terbuka: <span class="font-medium text-amber-700">{{ (int) ($satu->m_pr_terbuka ?? 0) }}</span>
                                                </p>
                                                <p class="mb-1">Pemohon: {{ $satu->m_req_list ?? '-' }}</p>
                                                <p class="font-medium text-slate-700 mt-2">Semua nomor PR</p>
                                                <p class="max-h-40 overflow-y-auto break-all text-slate-600">{{ $satu->m_pr_nums ?? '-' }}</p>
                                                @if(($satu->m_pr_open_nums ?? '') !== '')
                                                    <p class="font-medium text-amber-700 mt-2">PR masih terbuka</p>
                                                    <p class="break-all">{{ $satu->m_pr_open_nums }}</p>
                                                @endif
                                                <p class="text-slate-400 mt-2">
                                                    Diperbarui: {{ $satu->m_pr_diupdate_at !== null ? \Illuminate\Support\Carbon::parse($satu->m_pr_diupdate_at)->format('d/m/Y H:i') : '-' }}
                                                </p>
                                            </div>
                                            <div class="bg-white rounded-lg border border-slate-200 p-3">
                                                <p class="font-semibold text-slate-800 mb-2">Pesanan pembelian (PO)</p>
                                                <p class="mb-1">Terpilih: <span class="font-medium text-slate-800">{{ $satu->m_po_no ?? '-' }}</span> &middot; Sisa: {{ $angkaRapi($satu->m_po_sisa) }}</p>
                                                <p class="mb-1">
                                                    Baris: {{ (int) ($satu->m_po_line ?? 0) }} &middot;
                                                    PO unik: {{ (int) ($satu->m_po_uniq ?? 0) }} &middot;
                                                    Terbuka: <span class="font-medium text-amber-700">{{ (int) ($satu->m_po_open ?? 0) }}</span> &middot;
                                                    Tertutup: {{ (int) ($satu->m_po_closed ?? 0) }}
                                                </p>
                                                <p class="mb-1">Sisa seluruh PO: {{ $angkaRapi($satu->m_po_sisa_total) }} &middot; Tanggal PO: {{ $satu->m_po_tanggal_teks ?? '-' }}</p>
                                                <p class="mb-1">Purchaser: {{ $satu->m_purch ?? '-' }} &middot; Vendor: {{ $satu->m_vendor ?? '-' }}</p>
                                                <p class="mb-1">PO sebelumnya: {{ $satu->m_po_prev ?? '-' }} ({{ $satu->m_vendor_prev ?? '-' }})</p>
                                                <p class="mb-1">Purchaser/Vendor terakhir: {{ $satu->m_purch_last ?? '-' }} / {{ $satu->m_vendor_last ?? '-' }}</p>
                                                <p class="font-medium text-slate-700 mt-2">Semua nomor PO</p>
                                                <p class="max-h-40 overflow-y-auto break-all text-slate-600">{{ $satu->m_po_nums ?? '-' }}</p>
                                                @if(($satu->m_po_open_nums ?? '') !== '')
                                                    <p class="font-medium text-amber-700 mt-2">PO masih terbuka</p>
                                                    <p class="break-all">{{ $satu->m_po_open_nums }}</p>
                                                @endif
                                                <p class="text-slate-400 mt-2">
                                                    Diperbarui: {{ $satu->m_po_diupdate_at !== null ? \Illuminate\Support\Carbon::parse($satu->m_po_diupdate_at)->format('d/m/Y H:i') : '-' }}
                                                </p>
                                            </div>
                                            <div class="bg-white rounded-lg border border-slate-200 p-3">
                                                <p class="font-semibold text-slate-800 mb-2">Stok gudang G001</p>
                                                <p class="mb-1">Stok awal: {{ $angkaRapi($satu->m_g_awal) }}</p>
                                                <p class="mb-1">Masuk: {{ $angkaRapi($satu->m_g_masuk) }} &middot; Keluar: {{ $angkaRapi($satu->m_g_keluar) }}</p>
                                                <p class="mb-1">Sisa: <span class="font-medium text-slate-800">{{ $angkaRapi($satu->m_g_sisa) }}</span> {{ $satu->m_g_satuan ?? '' }}</p>
                                                <p class="mb-1">Baris S.Loc: {{ (int) ($satu->m_g_baris ?? 0) }}</p>
                                                <p class="mb-1">Lokasi: {{ $satu->m_g_lokasi ?? '-' }}</p>
                                                <p class="mb-1">Keterangan: {{ $satu->m_ket ?? '-' }}</p>
                                                <p class="mb-1">Daftar pantau: {{ $satu->sumber === 'manual' ? 'Manual' : 'Master Data' }}</p>
                                                <p class="text-slate-400 mt-2">
                                                    Diperbarui: {{ $satu->m_stok_diupdate_at !== null ? \Illuminate\Support\Carbon::parse($satu->m_stok_diupdate_at)->format('d/m/Y H:i') : '-' }}
                                                </p>
                                            </div>
                                            <div class="bg-white rounded-lg border border-slate-200 p-3">
                                                <p class="font-semibold text-slate-800 mb-2">MRP (MD07)</p>
                                                @if($adaMrp)
                                                    <p class="mb-1">Stock by SAP: <span class="font-medium text-slate-800">{{ $angkaRapi($satu->m_mrp_stock) }}</span></p>
                                                    <p class="mb-1">Safety Stk: {{ $angkaRapi($satu->m_mrp_safety) }} &middot; Reorder Pt: {{ $angkaRapi($satu->m_mrp_reorder) }}</p>
                                                    <p class="mb-1">Satuan: {{ $satu->m_mrp_unit ?? '-' }} &middot; Tipe MRP: {{ $satu->m_mrp_type ?? '-' }}</p>
                                                    <p class="mb-1">Min Monitoring vs MRP: <span class="font-medium {{ $satu->m_mrp_cmp === 'Beda' ? 'text-amber-700' : 'text-teal-700' }}">{{ $satu->m_mrp_cmp ?? '-' }}</span></p>
                                                    <p class="text-slate-400 mt-2">
                                                        Diperbarui: {{ $satu->m_mrp_diupdate_at !== null ? \Illuminate\Support\Carbon::parse($satu->m_mrp_diupdate_at)->format('d/m/Y H:i') : '-' }}
                                                    </p>
                                                @else
                                                    <p class="text-slate-400">Berkas EXPORT MRP belum diimpor.</p>
                                                @endif
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        @empty
                            <tr>
                                <td colspan="11" class="px-4 py-8 text-center text-slate-400">
                                    Tidak ada material yang cocok. Bila daftar pantau masih kosong, unggah sheet
                                    MASTER DATA lewat halaman Daftar Pantau.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="px-4 py-3 border-t border-slate-200 flex flex-wrap items-center justify-between gap-2">
                <p class="text-xs text-slate-400">
                    Total {{ number_format($baris->total(), 0, ',', '.') }} material pada saringan ini.
                </p>
                {{ $baris->links() }}
            </div>
        </div>
    </div>
@endsection
