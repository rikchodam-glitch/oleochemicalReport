@extends('layouts.app')

@section('title', 'Riwayat Import Sparepart')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('sparepart.index') }}" class="hover:text-slate-700">Sparepart</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Riwayat Import</span>
@endsection

@php
    $labelSumber = [
        'stok' => 'Laporan stok gudang',
        'pr'   => 'EXPORT PR',
        'po'   => 'EXPORT PO',
        'mrp'  => 'EXPORT MRP',
    ];
@endphp

@section('content')
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-slate-900">Riwayat Import Sparepart</h1>
                <p class="text-sm text-slate-500 mt-1 max-w-3xl">
                    Setiap import meninggalkan catatan berkas yang dipakai, jumlah baris yang dibaca,
                    dan analisa hasilnya: material baru, yang berubah, material di luar daftar pantau,
                    serta perubahan status reorder dan kolom aksi.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('sparepart.index') }}"
                   class="px-4 py-2 rounded-lg text-sm font-medium border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">
                    Daftar material
                </a>
                <a href="{{ route('sparepart.impor') }}"
                   class="px-4 py-2 rounded-lg text-sm font-medium text-white" style="background-color:#0E9E8E;">
                    Import Berkas
                </a>
            </div>
        </div>

        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="px-3 py-3 text-left font-medium">#</th>
                            <th class="px-3 py-3 text-left font-medium">Waktu</th>
                            <th class="px-3 py-3 text-left font-medium">Oleh</th>
                            <th class="px-3 py-3 text-left font-medium">Catatan</th>
                            <th class="px-3 py-3 text-left font-medium">Berkas dipakai</th>
                            <th class="px-3 py-3 text-right font-medium">Baris stok/PR/PO/MRP</th>
                            <th class="px-3 py-3 text-right font-medium">Material berubah</th>
                            <th class="px-3 py-3 text-left font-medium">Status</th>
                            <th class="px-3 py-3 text-right font-medium">Analisa</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($daftar as $impor)
                            @php
                                $sumber  = (array) ($impor->berkas ?? []);
                                $dipakai = [];

                                foreach ($labelSumber as $kunci => $label) {
                                    if (! empty($sumber[$kunci]['dipakai'])) {
                                        $dipakai[] = $label;
                                    }
                                }
                            @endphp
                            <tr data-impor-id="{{ $impor->id }}" class="{{ $dibuka !== null && $dibuka->id === $impor->id ? 'bg-slate-50' : '' }}">
                                <td class="px-3 py-2 font-medium text-slate-800">{{ $impor->id }}</td>
                                <td class="px-3 py-2 text-slate-500">
                                    {{ $impor->selesai_at?->format('d/m/Y H:i') ?? $impor->created_at?->format('d/m/Y H:i') ?? '-' }}
                                </td>
                                <td class="px-3 py-2 text-slate-500">{{ $impor->pengguna?->name ?? 'Sistem' }}</td>
                                <td class="px-3 py-2 text-slate-600">{{ $impor->label ?? '-' }}@if($impor->peringatanCakupan() !== [])<span class="ml-1 px-2 py-0.5 rounded-full bg-amber-50 text-amber-700 text-xs font-medium">CAKUPAN SEMPIT</span>@endif</td>
                                <td class="px-3 py-2 text-slate-600">{{ $dipakai === [] ? '-' : implode(', ', $dipakai) }}</td>
                                <td class="px-3 py-2 text-right text-slate-600">
                                    {{ number_format($impor->jumlah_stok, 0, ',', '.') }} /
                                    {{ number_format($impor->jumlah_pr, 0, ',', '.') }} /
                                    {{ number_format($impor->jumlah_po, 0, ',', '.') }} /
                                    {{ number_format($impor->jumlah_mrp, 0, ',', '.') }}
                                </td>
                                <td class="px-3 py-2 text-right text-slate-600">{{ number_format($impor->jumlah_keadaan, 0, ',', '.') }}</td>
                                <td class="px-3 py-2">
                                    @if($impor->status === 'selesai')
                                        <span class="px-2 py-0.5 rounded-full bg-teal-50 text-teal-700 text-xs font-medium">{{ $impor->label_status }}</span>
                                    @elseif($impor->status === 'gagal')
                                        <span class="px-2 py-0.5 rounded-full bg-rose-50 text-rose-700 text-xs font-medium">{{ $impor->label_status }}</span>
                                    @else
                                        <span class="px-2 py-0.5 rounded-full bg-amber-50 text-amber-700 text-xs font-medium">{{ $impor->label_status }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <a href="{{ route('sparepart.riwayat', ['impor' => $impor->id]) }}"
                                       class="text-teal-700 hover:text-teal-800 font-medium text-xs">Buka</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-4 py-6 text-center text-slate-400">Belum ada riwayat import.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="px-4 py-3 border-t border-slate-200">
                {{ $daftar->links() }}
            </div>
        </div>

        @if($dibuka !== null)
            @php
                $analisa = (array) ($dibuka->analisa ?? []);
                $keadaan = (array) ($analisa['keadaan'] ?? []);
                $status  = (array) ($analisa['status'] ?? []);
                $pantau  = (array) ($analisa['pantau'] ?? []);
            @endphp

            <div class="bg-white rounded-xl border border-slate-200 p-6 space-y-5">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-900">Analisa import #{{ $dibuka->id }}</h2>
                        <p class="text-xs text-slate-400">
                            {{ $dibuka->selesai_at?->format('d/m/Y H:i') ?? $dibuka->created_at?->format('d/m/Y H:i') }}
                            oleh {{ $dibuka->pengguna?->name ?? 'Sistem' }}
                            {{ $dibuka->label !== null ? ' | ' . $dibuka->label : '' }}
                        </p>
                    </div>
                    <a href="{{ route('sparepart.riwayat') }}" class="text-sm text-slate-500 hover:text-slate-700">Tutup analisa</a>
                </div>

                {{-- Rincian per berkas sumber --}}
                <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-4 gap-4">
                    @foreach($labelSumber as $kunci => $label)
                        @php
                            $satu  = (array) ($analisa['sumber'][$kunci] ?? []);
                            $judul = (array) ($satu['judul'] ?? []);
                            $barisJudul = [];

                            foreach ($judul as $k => $v) {
                                $barisJudul[] = is_array($v)
                                    ? $k . ': ' . implode(' | ', $v)
                                    : (string) $v;
                            }
                        @endphp
                        <div class="rounded-xl border border-slate-200 p-4 text-xs text-slate-600">
                            <p class="text-sm font-semibold text-slate-800">{{ $label }}</p>

                            @if(empty($satu['dipakai']))
                                <p class="text-slate-400 mt-2">
                                    Tidak diunggah pada import ini; nilai terakhir dari import sebelumnya dipakai.
                                </p>
                            @else
                                <p class="mt-2">Berkas: <span class="text-slate-800">{{ $satu['nama'] ?? '-' }}</span></p>
                                <p>Baris dibaca: {{ number_format((int) ($satu['baris'] ?? 0), 0, ',', '.') }}
                                    | diabaikan: {{ number_format((int) ($satu['diabaikan'] ?? 0), 0, ',', '.') }}</p>
                                <p>Material unik: {{ number_format((int) ($satu['material'] ?? 0), 0, ',', '.') }}@if(isset($satu['dokumen'])) | dokumen: {{ number_format((int) $satu['dokumen'], 0, ',', '.') }}@endif</p>
                                <p>Lembar: {{ implode(', ', (array) ($satu['lembar'] ?? [])) }}</p>

                                @if($barisJudul !== [])
                                    <p class="text-slate-400 mt-2 break-words">Judul kolom: {{ implode(' ; ', $barisJudul) }}</p>
                                @endif

                                @foreach((array) ($satu['catatan'] ?? []) as $catatan)
                                    <p class="text-amber-700 mt-1">{{ $catatan }}</p>
                                @endforeach
                            @endif
                        </div>
                    @endforeach
                </div>

                {{-- Ringkasan hasil --}}
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="rounded-xl border border-slate-200 p-4 text-xs text-slate-600">
                        <p class="text-sm font-semibold text-slate-800 mb-1">Perubahan keadaan</p>
                        <p>Material baru: <span class="font-medium text-slate-800">{{ number_format((int) ($keadaan['baru'] ?? 0), 0, ',', '.') }}</span></p>
                        <p>Nilai berubah: <span class="font-medium text-slate-800">{{ number_format((int) ($keadaan['diperbarui'] ?? 0), 0, ',', '.') }}</span></p>
                        <p>Tidak berubah: <span class="font-medium text-slate-800">{{ number_format((int) ($keadaan['tetap'] ?? 0), 0, ',', '.') }}</span></p>
                    </div>
                    <div class="rounded-xl border border-slate-200 p-4 text-xs text-slate-600">
                        <p class="text-sm font-semibold text-slate-800 mb-1">Status stok setelah import</p>
                        <p>Perlu reorder: <span class="font-medium text-rose-600">{{ number_format((int) ($status['reorder'] ?? 0), 0, ',', '.') }}</span></p>
                        <p>Stok aman: <span class="font-medium text-teal-700">{{ number_format((int) ($status['aman'] ?? 0), 0, ',', '.') }}</span></p>
                        <p>Belum dinilai: <span class="font-medium text-amber-600">{{ number_format((int) ($status['belum_dinilai'] ?? 0), 0, ',', '.') }}</span></p>
                    </div>
                    <div class="rounded-xl border border-slate-200 p-4 text-xs text-slate-600">
                        <p class="text-sm font-semibold text-slate-800 mb-1">Daftar pantau</p>
                        <p>Total dipantau: <span class="font-medium text-slate-800">{{ number_format((int) ($pantau['total'] ?? 0), 0, ',', '.') }}</span></p>
                        @if(($pantau['bercatatan_stok'] ?? null) !== null)
                            <p>Punya catatan stok: <span class="font-medium text-slate-800">{{ number_format((int) $pantau['bercatatan_stok'], 0, ',', '.') }}</span></p>
                            <p>Tanpa catatan stok: <span class="font-medium text-amber-600">{{ number_format((int) ($pantau['tanpa_catatan_stok'] ?? 0), 0, ',', '.') }}</span></p>
                        @else
                            <p class="text-slate-400 mt-1">Berkas stok gudang tidak diunggah, jadi jumlah catatan stok tidak dihitung.</p>
                        @endif
                    </div>
                </div>
                {{-- Perubahan status dan sebaran aksi --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs text-slate-600">
                    <div class="rounded-xl border border-slate-200 p-4">
                        <p class="text-sm font-semibold text-slate-800 mb-1">Perubahan status</p>
                        <p>Mulai perlu reorder:
                            @if((array) ($status['naik_reorder'] ?? []) === [])
                                <span class="text-slate-400">tidak ada</span>
                            @else
                                <span class="text-rose-600">{{ implode(', ', (array) $status['naik_reorder']) }}</span>
                            @endif
                        </p>
                        <p class="mt-1">Kembali aman:
                            @if((array) ($status['turun_aman'] ?? []) === [])
                                <span class="text-slate-400">tidak ada</span>
                            @else
                                <span class="text-teal-700">{{ implode(', ', (array) $status['turun_aman']) }}</span>
                            @endif
                        </p>
                        <p class="text-slate-400 mt-2">Daftar kode dibatasi 20 per kategori.</p>
                    </div>
                    <div class="rounded-xl border border-slate-200 p-4">
                        <p class="text-sm font-semibold text-slate-800 mb-1">Sebaran kolom aksi</p>
                        @foreach((array) ($analisa['aksi'] ?? []) as $aksi => $jumlah)
                            <p>{{ \App\Models\SpMaterial::labelAksi((string) $aksi) }}:
                                <span class="font-medium text-slate-800">{{ number_format((int) $jumlah, 0, ',', '.') }}</span>
                            </p>
                        @endforeach
                    </div>
                {{-- Material di luar daftar pantau --}}
                @php $luar = (array) ($analisa['luar_pantau'] ?? []); @endphp
                @if($luar !== [])
                    <div class="rounded-xl border border-slate-200 p-4 text-xs text-slate-600">
                        <p class="text-sm font-semibold text-slate-800 mb-1">Material di luar daftar pantau</p>
                        <p class="text-slate-500">
                            Material ini muncul di berkas tetapi tidak ada di daftar pantau, sehingga tidak
                            ikut dihitung. Tambahkan lewat halaman Daftar Pantau bila memang perlu dipantau.
                        </p>
                        @foreach($labelSumber as $kunci => $label)
                            @php $satuLuar = (array) ($luar[$kunci] ?? []); @endphp
                            @if((int) ($satuLuar['jumlah'] ?? 0) > 0)
                                <p class="mt-2">
                                    {{ $label }}:
                                    <span class="font-medium text-slate-800">{{ number_format((int) $satuLuar['jumlah'], 0, ',', '.') }}</span>
                                    @if((array) ($satuLuar['contoh'] ?? []) !== [])
                                        <span class="text-slate-400">(contoh: {{ implode(', ', (array) $satuLuar['contoh']) }})</span>
                                    @endif
                                </p>
                            @endif
                        @endforeach
                    </div>
                @endif

                @if((array) ($analisa['catatan'] ?? []) !== [])
                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-xs text-amber-800">
                        <p class="text-sm font-semibold mb-1">Catatan</p>
                        <ul class="list-disc list-inside space-y-1">
                            @foreach((array) $analisa['catatan'] as $catatan)
                                <li>{{ $catatan }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        </div>
        @endif
    </div>
@endsection
