@extends('layouts.app')

@section('title', 'Request Spare Part')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('sparepart.index') }}" class="hover:text-slate-700">Sparepart</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Request Spare Part</span>
@endsection

@php
    $badgeStatus = [
        \App\Models\SpRequest::STATUS_MENUNGGU       => 'bg-amber-50 text-amber-700 ring-amber-200',
        \App\Models\SpRequest::STATUS_DISETUJUI      => 'bg-sky-50 text-sky-700 ring-sky-200',
        \App\Models\SpRequest::STATUS_DITOLAK        => 'bg-rose-50 text-rose-700 ring-rose-200',
        \App\Models\SpRequest::STATUS_KEJAR_PO       => 'bg-orange-50 text-orange-700 ring-orange-200',
        \App\Models\SpRequest::STATUS_KEJAR_DELIVERY => 'bg-teal-50 text-teal-700 ring-teal-200',
        \App\Models\SpRequest::STATUS_SELESAI        => 'bg-green-50 text-green-700 ring-green-200',
    ];

    $angka = function ($nilai): string {
        $teks = number_format((float) $nilai, 2, ',', '.');

        return rtrim(rtrim($teks, '0'), ',') ?: '0';
    };

    $tautanTab = function (string $tab) use ($cari, $dari, $sampai, $bagian): string {
        return route('sparepart.request.index', array_filter([
            'tab'    => $tab,
            'q'      => $cari,
            'dari'   => $dari,
            'sampai' => $sampai,
            'bagian' => $bagian,
        ], static fn ($nilai): bool => $nilai !== '' && $nilai !== null));
    };

    $tautanStatus = function (string $status) use ($cari, $dari, $sampai, $bagian): string {
        return route('sparepart.request.index', array_filter([
            'tab'    => 'pengajuan',
            'status' => $status,
            'q'      => $cari,
            'dari'   => $dari,
            'sampai' => $sampai,
            'bagian' => $bagian,
        ], static fn ($nilai): bool => $nilai !== '' && $nilai !== null));
    };

    $labelTab = [
        'pengajuan'      => 'Pengajuan',
        'kejar-po'       => 'Kejar PO',
        'kejar-delivery' => 'Kejar Delivery',
        'selesai'        => 'Selesai',
    ];
@endphp

@section('content')
    @include('sparepart.partials._tabs', ['tabAktif' => 'request'])

    <div class="mt-6 space-y-5" x-data="{ tolak: null, foto: null }">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-slate-900">Request Spare Part</h1>
                <p class="text-sm text-slate-500 mt-1 max-w-3xl">
                    Request dari teknisi lewat bot Telegram. Staff CM/PM menyetujui, mengisi PR,
                    mengisi PO, lalu menandai barang sudah diterima.
                </p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-right">
                <p class="text-[11px] uppercase tracking-wide text-slate-400">Total request</p>
                <p class="text-lg font-bold text-slate-900">{{ number_format(array_sum($jumlahStatus), 0, ',', '.') }}</p>
            </div>
        </div>

        @if($errors->any())
            <div class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                <p class="font-medium">Periksa kembali isian berikut:</p>
                <ul class="mt-1 list-inside list-disc">
                    @foreach($errors->all() as $pesan)
                        <li>{{ $pesan }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if(! $bolehKelola)
            <div class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                Anda dapat melihat request, tetapi hanya Staff CM/PM yang boleh menyetujui,
                mengisi PR/PO, dan menandai barang diterima.
            </div>
        @endif

        <div class="flex flex-wrap items-center gap-1 border-b border-slate-200">
            @foreach($labelTab as $kunci => $label)
                <a href="{{ $tautanTab($kunci) }}"
                   class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 transition-colors
                          {{ $tab === $kunci
                                ? 'border-teal-500 text-teal-700'
                                : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300' }}">
                    {{ $label }}
                    <span class="inline-flex min-w-[1.4rem] justify-center rounded-full px-1.5 py-0.5 text-[10px] font-semibold
                                 {{ $tab === $kunci ? 'bg-teal-100 text-teal-700' : 'bg-slate-100 text-slate-500' }}">
                        {{ number_format($jumlahTab[$kunci] ?? 0, 0, ',', '.') }}
                    </span>
                </a>
            @endforeach
        </div>

        <form method="GET" action="{{ route('sparepart.request.index') }}"
              class="rounded-xl border border-slate-200 bg-white p-4">
            <input type="hidden" name="tab" value="{{ $tab }}">
            @if($tab === 'pengajuan')
                <input type="hidden" name="status" value="{{ $statusPengajuan }}">
            @endif
            <div class="grid grid-cols-1 gap-3 md:grid-cols-5">
                <div class="md:col-span-2">
                    <label for="cariRequest" class="mb-1 block text-xs font-medium text-slate-500">Pencarian</label>
                    <input id="cariRequest" name="q" value="{{ $cari }}" type="search"
                           placeholder="Kode, keterangan, teknisi, nomor PR, atau PO"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-400">
                </div>
                <div>
                    <label for="bagianRequest" class="mb-1 block text-xs font-medium text-slate-500">Bagian</label>
                    <select id="bagianRequest" name="bagian"
                            class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-400">
                        <option value="">Semua bagian</option>
                        @foreach(\App\Models\Technician::SECTIONS as $kodeBagian => $labelBagian)
                            <option value="{{ $kodeBagian }}" {{ $bagian === $kodeBagian ? 'selected' : '' }}>
                                {{ $labelBagian }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="dariRequest" class="mb-1 block text-xs font-medium text-slate-500">Dari tanggal</label>
                    <input id="dariRequest" name="dari" value="{{ $dari }}" type="date"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-400">
                </div>
                <div>
                    <label for="sampaiRequest" class="mb-1 block text-xs font-medium text-slate-500">Sampai tanggal</label>
                    <input id="sampaiRequest" name="sampai" value="{{ $sampai }}" type="date"
                           class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-400">
                </div>
            </div>
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <button type="submit"
                        class="rounded-lg px-4 py-2 text-sm font-medium text-white"
                        style="background-color:#0E9E8E;">Terapkan</button>
                <a href="{{ route('sparepart.request.index', ['tab' => $tab]) }}"
                   class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50">
                    Bersihkan
                </a>
            </div>
        </form>

        @if($tab === 'pengajuan')
            @include('sparepart.request.partials._pengajuan')
        @elseif($tab === 'kejar-po')
            @include('sparepart.request.partials._kejar-po')
        @elseif($tab === 'kejar-delivery')
            @include('sparepart.request.partials._kejar-delivery')
        @else
            @include('sparepart.request.partials._selesai')
        @endif

        @if($bolehKelola)
            <template x-teleport="body">
                <div x-show="tolak !== null" x-cloak
                     class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <div class="w-full max-w-lg rounded-xl bg-white shadow-xl" @@click.away="tolak = null">
                        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                            <h3 class="text-sm font-semibold text-slate-900">Tolak Request</h3>
                            <button type="button" @click="tolak = null" class="text-slate-400 hover:text-slate-600">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>
                        <form method="POST" :action="'{{ url('/sparepart/request') }}/' + tolak + '/tolak'" class="p-5">
                            @csrf
                            <label for="alasanTolak" class="mb-1 block text-xs font-medium text-slate-500">
                                Alasan penolakan <span class="text-rose-500">*</span>
                            </label>
                            <textarea id="alasanTolak" name="alasan" rows="3" required
                                      class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-rose-400"
                                      placeholder="Contoh: stok masih ada, request tidak sesuai kebutuhan"></textarea>
                            <div class="mt-4 flex justify-end gap-2">
                                <button type="button" @click="tolak = null"
                                        class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                                    Batal
                                </button>
                                <button type="submit"
                                        class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-medium text-white hover:bg-rose-700">
                                    Tolak Request
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </template>
        @endif

        <template x-teleport="body">
            <div x-show="foto" x-cloak @click="foto = null"
                 class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4">
                <img :src="foto" alt="Foto request" class="max-h-[90vh] max-w-full rounded-lg object-contain">
            </div>
        </template>
    </div>
@endsection
