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
        \App\Models\SpMaterial::AKSI_KEJAR   => 'bg-rose-50 text-rose-700',
        \App\Models\SpMaterial::AKSI_BUAT_PO => 'bg-amber-50 text-amber-700',
        \App\Models\SpMaterial::AKSI_CEK     => 'bg-sky-50 text-sky-700',
        \App\Models\SpMaterial::AKSI_BUAT_PR => 'bg-slate-100 text-slate-600',
    ];
@endphp

@section('content')
    <div class="space-y-6" x-data="{ buka: null }">
        {{-- Kepala halaman --}}
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-slate-900">Sparepart — Integrasi PR/PO</h1>
                <p class="text-sm text-slate-500 mt-1 max-w-3xl">
                    Satu baris per kode material, selalu mutakhir mengikuti import terakhir
                    (stok gudang G001, permintaan pembelian, dan pesanan pembelian).
                    Stok minimum diambil dari daftar pantau.
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
                untuk mengunggah laporan stok gudang, EXPORT PR, dan EXPORT PO.
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

        {{-- Petunjuk lingkup kartu aksi: fokus item REORDER seperti dashboard lama --}}
        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
            <p class="text-sm font-semibold text-slate-700">
                Rincian tindakan {{ number_format($ringkasan['reorder'], 0, ',', '.') }} item REORDER (fokus tindakan)
            </p>
            <p class="text-xs text-slate-400">
                Sama seperti sheet "Daftar Aksi" dashboard lama; material yang stoknya masih aman tidak dihitung.
            </p>
        </div>

        {{-- Kartu tindak lanjut per nilai kolom aksi (lingkup: item REORDER) --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            @foreach($kartuAksi as $kartu)
                <a href="{{ route('sparepart.index', ['aksi' => $kartu['nilai'], 'status' => 'reorder']) }}"
                   class="bg-white rounded-xl border {{ $saringan['aksi'] === $kartu['nilai'] ? 'border-teal-400' : 'border-slate-200' }} p-4 hover:border-teal-400 transition-colors"
                   data-kartu-aksi="{{ $kartu['label'] }}">
                    <p class="text-xs text-slate-500">{{ $kartu['label'] }}</p>
                    <p class="text-xl font-semibold text-slate-900 mt-1">{{ number_format($kartu['jumlah'], 0, ',', '.') }}</p>
                    <p class="text-xs text-slate-400 mt-1">{{ $kartu['keterangan'] }}</p>
                </a>
            @endforeach
        </div>

        {{-- Saringan tabel --}}
        <form method="GET" action="{{ route('sparepart.index') }}"
              class="bg-white rounded-xl border border-slate-200 p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 items-end">
            <div class="lg:col-span-2">
                <label for="q" class="block text-xs font-medium text-slate-600 mb-1">Cari (kode, deskripsi, no PR/PO, vendor)</label>
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
                <label for="urut" class="block text-xs font-medium text-slate-600 mb-1">Urutan</label>
                <select name="urut" id="urut" class="w-full rounded-lg border-slate-200 text-sm focus:border-teal-500 focus:ring-teal-500">
                    <option value="reorder" @selected($saringan['urut'] === 'reorder')>Perlu tindakan dulu</option>
                    <option value="kode" @selected($saringan['urut'] === 'kode')>Kode material</option>
                    <option value="stok" @selected($saringan['urut'] === 'stok')>Stok tersedia terkecil</option>
                    <option value="aksi" @selected($saringan['urut'] === 'aksi')>Aksi</option>
                    <option value="terakhir" @selected($saringan['urut'] === 'terakhir')>Terakhir diperbarui</option>
                </select>
            </div>
            <div class="flex items-center gap-3 lg:col-span-5">
                <button type="submit" class="px-4 py-2 rounded-lg text-sm font-medium text-white" style="background-color:#0E9E8E;">
                    Terapkan saringan
                </button>
                <a href="{{ route('sparepart.index') }}" class="text-sm text-slate-500 hover:text-slate-700">Bersihkan</a>
            </div>
        </form>

        {{-- Tabel keadaan: satu baris per kode material, rinciannya dapat dibuka --}}
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="px-3 py-3 text-left font-medium">Kode / Deskripsi</th>
                            <th class="px-3 py-3 text-right font-medium">Stok Min</th>
                            <th class="px-3 py-3 text-right font-medium">Stok Tersedia</th>
                            <th class="px-3 py-3 text-left font-medium">Status</th>
                            <th class="px-3 py-3 text-left font-medium">Aksi</th>
                            <th class="px-3 py-3 text-left font-medium">No PR / Status</th>
                            <th class="px-3 py-3 text-left font-medium">No PO / Sisa</th>
                            <th class="px-3 py-3 text-left font-medium">Vendor PO</th>
                            <th class="px-3 py-3 text-left font-medium">Lokasi G001</th>
                            <th class="px-3 py-3 text-left font-medium">Diperbarui</th>
                            <th class="px-3 py-3 text-right font-medium">Rincian</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($baris as $satu)
                            @php $kunciBaris = (string) $satu->kode_material; @endphp
                            <tr data-kode-material="{{ $kunciBaris }}" class="hover:bg-slate-50">
                                <td class="px-3 py-2">
                                    <p class="font-medium text-slate-800">{{ $kunciBaris }}</p>
                                    <p class="text-slate-600">{{ $satu->m_deskripsi ?? $satu->deskripsi_pantau ?? '-' }}</p>
                                    @if(collect([$satu->plant, $satu->model, $satu->tag_no])->filter()->isNotEmpty())
                                        <p class="text-xs text-slate-400">
                                            {{ collect([$satu->plant, $satu->model, $satu->tag_no])->filter()->implode(' / ') }}
                                        </p>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right text-slate-600">{{ $angkaRapi($satu->m_stok_min ?? $satu->stok_min_pantau) }}</td>
                                <td class="px-3 py-2 text-right font-medium text-slate-800">{{ $angkaRapi($satu->m_stok_tersedia) }}</td>
                                <td class="px-3 py-2">
                                    @if($satu->m_stok_min === null)
                                        <span class="px-2 py-0.5 rounded-full bg-amber-50 text-amber-700 text-xs font-medium">Tanpa stok min</span>
                                    @elseif($satu->m_reorder)
                                        <span class="px-2 py-0.5 rounded-full bg-rose-50 text-rose-700 text-xs font-medium">REORDER</span>
                                    @else
                                        <span class="px-2 py-0.5 rounded-full bg-teal-50 text-teal-700 text-xs font-medium">Aman</span>
                                    @endif
                                    @if(($satu->m_penanda ?? '') !== '')
                                        <p class="text-xs text-slate-400 mt-0.5">{{ $satu->m_penanda }}</p>
                                    @endif
                                </td>
                                <td class="px-3 py-2">
                                    <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $warnaAksi[$satu->m_aksi] ?? 'bg-slate-100 text-slate-600' }}">
                                        {{ \App\Models\SpMaterial::labelAksi($satu->m_aksi) }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-slate-600">
                                    <p>{{ $satu->m_pr_no ?? '-' }}</p>
                                    <p class="text-xs text-slate-400">{{ $satu->m_pr_status_aksi ?? '-' }}</p>
                                </td>
                                <td class="px-3 py-2 text-slate-600">
                                    <p>{{ $satu->m_po_no ?? '-' }}</p>
                                    <p class="text-xs text-slate-400">
                                        Sisa: {{ $angkaRapi($satu->m_po_sisa) }}@if((int) ($satu->m_po_open ?? 0) > 0) (PO terbuka)@endif
                                    </p>
                                </td>
                                <td class="px-3 py-2 text-slate-600">{{ $satu->m_vendor ?? '-' }}</td>
                                <td class="px-3 py-2 text-slate-600">
                                    <p>{{ $satu->m_g_lokasi ?? '-' }}</p>
                                    <p class="text-xs text-slate-400">{{ $satu->m_g_satuan ?? $satu->uom ?? '' }}</p>
                                </td>
                                <td class="px-3 py-2 text-xs text-slate-500">
                                    {{ $satu->m_diubah_at !== null ? \Illuminate\Support\Carbon::parse($satu->m_diubah_at)->format('d/m/Y H:i') : '-' }}
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <button type="button"
                                            class="text-teal-700 hover:text-teal-800 text-xs font-medium"
                                            @click="buka = buka === '{{ $kunciBaris }}' ? null : '{{ $kunciBaris }}'">
                                        <span x-text="buka === '{{ $kunciBaris }}' ? 'Tutup' : 'Buka'">Buka</span>
                                    </button>
                                </td>
                            </tr>

                            <template x-if="buka === '{{ $kunciBaris }}'">
                                <tr class="bg-slate-50">
                                    <td colspan="11" class="px-3 py-4">
                                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 text-xs text-slate-600">
                                            <div>
                                                <p class="font-semibold text-slate-700 mb-1">Permintaan pembelian (PR)</p>
                                                <p>Nomor terpilih: {{ $satu->m_pr_no ?? '-' }} ({{ $satu->m_pr_status_aksi ?? '-' }})</p>
                                                <p>Status seluruh PR: {{ $satu->m_pr_status ?? '-' }}</p>
                                                <p>Qty PR: {{ $angkaRapi($satu->m_pr_qty) }} | Jumlah baris: {{ (int) ($satu->m_pr_line ?? 0) }} | PR terbuka: {{ (int) ($satu->m_pr_terbuka ?? 0) }}</p>
                                                <p>Pemohon: {{ $satu->m_req_list ?? '-' }}</p>
                                                <p class="mt-1">Semua nomor PR: {{ $satu->m_pr_nums ?? '-' }}</p>
                                                <p class="text-slate-400 mt-1">
                                                    Diperbarui: {{ $satu->m_pr_diupdate_at !== null ? \Illuminate\Support\Carbon::parse($satu->m_pr_diupdate_at)->format('d/m/Y H:i') : '-' }}
                                                </p>
                                            </div>
                                            <div>
                                                <p class="font-semibold text-slate-700 mb-1">Pesanan pembelian (PO)</p>
                                                <p>Nomor terpilih: {{ $satu->m_po_no ?? '-' }} | Sisa kirim: {{ $angkaRapi($satu->m_po_sisa) }}</p>
                                                <p>Baris PO: {{ (int) ($satu->m_po_line ?? 0) }} | PO unik: {{ (int) ($satu->m_po_uniq ?? 0) }} | Terbuka: {{ (int) ($satu->m_po_open ?? 0) }} | Tertutup: {{ (int) ($satu->m_po_closed ?? 0) }}</p>
                                                <p>Sisa seluruh PO: {{ $angkaRapi($satu->m_po_sisa_total) }} | Tanggal PO: {{ $satu->m_po_tanggal_teks ?? '-' }}</p>
                                                <p>Purchaser: {{ $satu->m_purch ?? '-' }}</p>
                                                <p>PO sebelumnya: {{ $satu->m_po_prev ?? '-' }} ({{ $satu->m_vendor_prev ?? '-' }})</p>
                                                <p class="mt-1">Semua nomor PO: {{ $satu->m_po_nums ?? '-' }}</p>
                                                <p class="text-slate-400 mt-1">
                                                    Diperbarui: {{ $satu->m_po_diupdate_at !== null ? \Illuminate\Support\Carbon::parse($satu->m_po_diupdate_at)->format('d/m/Y H:i') : '-' }}
                                                </p>
                                            </div>
                                            <div>
                                                <p class="font-semibold text-slate-700 mb-1">Stok gudang G001</p>
                                                <p>Stok awal: {{ $angkaRapi($satu->m_g_awal) }} | Masuk: {{ $angkaRapi($satu->m_g_masuk) }} | Keluar: {{ $angkaRapi($satu->m_g_keluar) }}</p>
                                                <p>Sisa: {{ $angkaRapi($satu->m_g_sisa) }} {{ $satu->m_g_satuan ?? '' }} | Baris S.Loc: {{ (int) ($satu->m_g_baris ?? 0) }}</p>
                                                <p>Lokasi: {{ $satu->m_g_lokasi ?? '-' }}</p>
                                                <p class="mt-1">Keterangan: {{ $satu->m_ket ?? '-' }}</p>
                                                <p>Daftar pantau: {{ $satu->sumber === 'manual' ? 'Manual' : 'Master Data' }}</p>
                                                <p class="text-slate-400 mt-1">
                                                    Diperbarui: {{ $satu->m_stok_diupdate_at !== null ? \Illuminate\Support\Carbon::parse($satu->m_stok_diupdate_at)->format('d/m/Y H:i') : '-' }}
                                                </p>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        @empty
                            <tr>
                                <td colspan="11" class="px-4 py-6 text-center text-slate-400">
                                    Tidak ada material yang cocok. Bila daftar pantau masih kosong, unggah sheet
                                    MASTER DATA lewat halaman Daftar Pantau.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="px-4 py-3 border-t border-slate-200">
                {{ $baris->links() }}
            </div>
        </div>
    </div>
@endsection
