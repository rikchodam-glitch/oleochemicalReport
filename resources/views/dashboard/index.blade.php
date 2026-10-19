@extends('layouts.app')

@section('title', 'Dashboard')
@section('breadcrumb')
    <span class="text-slate-900 font-medium">Dashboard</span>
@endsection

@php
    $tone = [
        'blue'    => ['bg' => 'bg-blue-50',    'text' => 'text-blue-700',    'ring' => 'ring-blue-100'],
        'emerald' => ['bg' => 'bg-emerald-50', 'text' => 'text-emerald-700', 'ring' => 'ring-emerald-100'],
        'violet'  => ['bg' => 'bg-violet-50',  'text' => 'text-violet-700',  'ring' => 'ring-violet-100'],
        'amber'   => ['bg' => 'bg-amber-50',   'text' => 'text-amber-700',   'ring' => 'ring-amber-100'],
        'rose'    => ['bg' => 'bg-rose-50',    'text' => 'text-rose-700',    'ring' => 'ring-rose-100'],
        'orange'  => ['bg' => 'bg-orange-50',  'text' => 'text-orange-700',  'ring' => 'ring-orange-100'],
        'cyan'    => ['bg' => 'bg-cyan-50',    'text' => 'text-cyan-700',    'ring' => 'ring-cyan-100'],
        'teal'    => ['bg' => 'bg-teal-50',    'text' => 'text-teal-700',    'ring' => 'ring-teal-100'],
        'slate'   => ['bg' => 'bg-slate-100',  'text' => 'text-slate-700',   'ring' => 'ring-slate-200'],
    ];

    $ikon = [
        'asset'     => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
        'users'     => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z',
        'report'    => 'M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
        'review'    => 'M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        'alert'     => 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4.77c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z',
        'sparepart' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
        'network'   => 'M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0',
        'panel'     => 'M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 7h10a2 2 0 012 2v6a2 2 0 01-2 2H7a2 2 0 01-2-2V9a2 2 0 012-2z',
        'workorder' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4',
    ];

    $trenLaporan = $laporan['tren'] ?? ['labels' => [], 'selesai' => [], 'review' => [], 'lanjut' => [], 'lainnya' => []];
    $statusLaporan = $laporan['status'] ?? ['labels' => [], 'data' => []];
    $jenisLaporan = $laporan['jenis'] ?? [];
    $topTeknisi = $laporan['top_teknisi'] ?? [];
    $asetDepartemen = $aset['per_departemen'] ?? [];
    $cmKondisi = [
        $cm['good'] ?? 0,
        $cm['alarm'] ?? 0,
        $cm['danger'] ?? 0,
        $cm['visual_bad'] ?? 0,
    ];
    $sparepartAksi = $sparepart['aksi'] ?? [];
    $itStatus = [
        $it['online'] ?? 0,
        $it['offline'] ?? 0,
        $it['unknown'] ?? 0,
    ];
    $panelSeries = $panel['series'] ?? [];
    $workOrderJenis = $workOrder['jenis'] ?? [];
@endphp

@section('content')
    <div class="space-y-6">
        {{-- Kepala halaman + periode --}}
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-[11px] uppercase tracking-[0.18em] text-slate-400 font-semibold">Ringkasan operasional</p>
                <h2 class="text-2xl font-bold text-slate-900 mt-1">Dashboard Maintenance</h2>
                <p class="text-sm text-slate-500 mt-1">
                    Indikator utama lintas modul untuk {{ $labelPeriode }}.
                    Diperbarui {{ $diperbarui->format('d/m/Y H:i') }}.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @foreach([7 => '7 hari', 30 => '30 hari', 90 => '90 hari'] as $nilai => $teks)
                    <a href="{{ route('dashboard', ['periode' => $nilai]) }}"
                       class="px-3 py-2 rounded-lg text-xs font-semibold border transition-colors {{ $periode === $nilai ? 'bg-slate-900 text-white border-slate-900' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50' }}">
                        {{ $teks }}
                    </a>
                @endforeach
                <a href="{{ route('dashboard', ['periode' => $periode]) }}"
                   class="px-3 py-2 rounded-lg text-xs font-semibold border border-slate-200 bg-white text-slate-600 hover:bg-slate-50">
                    Muat ulang
                </a>
            </div>
        </div>

        {{-- KPI --}}
        @if($kpi !== [])
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4" data-section="kpi">
                @foreach($kpi as $kartu)
                    <a href="{{ $kartu['href'] }}"
                       class="group bg-white rounded-xl border border-slate-200 p-4 shadow-sm hover:shadow-md hover:border-slate-300 transition-all">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-[11px] uppercase tracking-wider font-semibold text-slate-400">{{ $kartu['label'] }}</p>
                                <p class="text-2xl font-bold text-slate-900 mt-1 leading-none">{{ $kartu['nilai'] }}</p>
                                <p class="text-xs text-slate-500 mt-2 leading-snug">{{ $kartu['sub'] }}</p>
                            </div>
                            <span class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0 ring-1 {{ $tone[$kartu['tone']]['bg'] ?? 'bg-slate-100' }} {{ $tone[$kartu['tone']]['text'] ?? 'text-slate-700' }} {{ $tone[$kartu['tone']]['ring'] ?? 'ring-slate-200' }}">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="{{ $ikon[$kartu['ikon']] ?? $ikon['report'] }}"/>
                                </svg>
                            </span>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif

        {{-- Laporan: tren besar + komposisi --}}
        @if($akses['reports'] && $laporan !== null)
            <div class="grid grid-cols-1 xl:grid-cols-3 gap-5">
                <div class="xl:col-span-2 bg-white rounded-xl border border-slate-200 shadow-sm flex flex-col" data-chart="laporan-tren">
                    <div class="px-5 py-4 border-b border-slate-100 flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <h3 class="font-semibold text-slate-900">Tren laporan</h3>
                            <p class="text-xs text-slate-500 mt-0.5">{{ $labelPeriode }} · {{ number_format($laporan['total'], 0, ',', '.') }} laporan · {{ number_format($laporan['jam'], 1, ',', '.') }} jam kerja</p>
                        </div>
                        <a href="{{ route('reports.index') }}" class="text-xs font-semibold text-teal-700 hover:text-teal-800">Lihat laporan</a>
                    </div>
                    <div class="p-4 flex-1 min-h-[280px]">
                        <canvas id="chartLaporanTren" aria-label="Tren laporan per hari"></canvas>
                    </div>
                </div>

                <div class="bg-white rounded-xl border border-slate-200 shadow-sm flex flex-col" data-chart="status-laporan">
                    <div class="px-5 py-4 border-b border-slate-100">
                        <h3 class="font-semibold text-slate-900">Status laporan</h3>
                        <p class="text-xs text-slate-500 mt-0.5">Komposisi {{ $labelPeriode }}</p>
                    </div>
                    <div class="p-4 flex-1 min-h-[190px]">
                        <canvas id="chartStatusLaporan" aria-label="Komposisi status laporan"></canvas>
                    </div>
                    <div class="px-5 pb-4 grid grid-cols-2 gap-2 text-xs">
                        <div class="rounded-lg bg-slate-50 px-3 py-2">
                            <p class="text-slate-400">Selesai</p>
                            <p class="font-semibold text-slate-800">{{ number_format($laporan['selesai'], 0, ',', '.') }}</p>
                        </div>
                        <div class="rounded-lg bg-amber-50 px-3 py-2">
                            <p class="text-amber-600">Perlu review</p>
                            <p class="font-semibold text-amber-800">{{ number_format($laporan['review'], 0, ',', '.') }}</p>
                        </div>
                        <div class="rounded-lg bg-sky-50 px-3 py-2">
                            <p class="text-sky-600">Dilanjutkan</p>
                            <p class="font-semibold text-sky-800">{{ number_format($laporan['lanjut'], 0, ',', '.') }}</p>
                        </div>
                        <div class="rounded-lg bg-slate-50 px-3 py-2">
                            <p class="text-slate-400">Hari ini</p>
                            <p class="font-semibold text-slate-800">{{ number_format($laporan['hari_ini'], 0, ',', '.') }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-5">
                <div class="bg-white rounded-xl border border-slate-200 shadow-sm">
                    <div class="px-5 py-4 border-b border-slate-100">
                        <h3 class="font-semibold text-slate-900">Jenis pekerjaan</h3>
                        <p class="text-xs text-slate-500 mt-0.5">Sebaran laporan per jenis</p>
                    </div>
                    <div class="p-4 h-64">
                        <canvas id="chartJenisPekerjaan" aria-label="Sebaran jenis pekerjaan"></canvas>
                    </div>
                </div>

                <div class="bg-white rounded-xl border border-slate-200 shadow-sm">
                    <div class="px-5 py-4 border-b border-slate-100">
                        <h3 class="font-semibold text-slate-900">Teknisi paling aktif</h3>
                        <p class="text-xs text-slate-500 mt-0.5">Jumlah laporan {{ $labelPeriode }}</p>
                    </div>
                    <div class="p-4 h-64">
                        <canvas id="chartTeknisi" aria-label="Teknisi paling aktif"></canvas>
                    </div>
                </div>

                @if($akses['assets'] && $aset !== null)
                    <div class="bg-white rounded-xl border border-slate-200 shadow-sm">
                        <div class="px-5 py-4 border-b border-slate-100">
                            <h3 class="font-semibold text-slate-900">Aset per departemen</h3>
                            <p class="text-xs text-slate-500 mt-0.5">{{ number_format($aset['total'], 0, ',', '.') }} aset · {{ number_format($aset['aktif'], 0, ',', '.') }} aktif</p>
                        </div>
                        <div class="p-4 h-64">
                            <canvas id="chartAsetDepartemen" aria-label="Aset per departemen"></canvas>
                        </div>
                    </div>
                @endif
            </div>
        @endif

        {{-- Reliability & aset --}}
        <div class="grid grid-cols-1 xl:grid-cols-3 gap-5">
            @if($akses['cm'] && $cm !== null)
                <div class="bg-white rounded-xl border border-slate-200 shadow-sm" data-chart="cm-kondisi">
                    <div class="px-5 py-4 border-b border-slate-100 flex items-start justify-between gap-2">
                        <div>
                            <h3 class="font-semibold text-slate-900">Condition Monitoring</h3>
                            <p class="text-xs text-slate-500 mt-0.5">{{ number_format($cm['total_baca'], 0, ',', '.') }} pembacaan · {{ number_format($cm['equipment'], 0, ',', '.') }} equipment</p>
                        </div>
                        <a href="{{ route('cm.overview') }}" class="text-xs font-semibold text-teal-700 hover:text-teal-800">Buka CM</a>
                    </div>
                    <div class="p-4 grid grid-cols-2 gap-3 items-center">
                        <div class="h-40">
                            <canvas id="chartCmKondisi" aria-label="Kondisi pembacaan CM"></canvas>
                        </div>
                        <div class="space-y-1.5 text-xs">
                            <div class="flex justify-between"><span class="text-emerald-700">Good</span><span class="font-semibold text-slate-800">{{ number_format($cm['good'], 0, ',', '.') }}</span></div>
                            <div class="flex justify-between"><span class="text-amber-700">Alarm</span><span class="font-semibold text-slate-800">{{ number_format($cm['alarm'], 0, ',', '.') }}</span></div>
                            <div class="flex justify-between"><span class="text-rose-700">Danger</span><span class="font-semibold text-slate-800">{{ number_format($cm['danger'], 0, ',', '.') }}</span></div>
                            <div class="flex justify-between"><span class="text-slate-600">Visual bad</span><span class="font-semibold text-slate-800">{{ number_format($cm['visual_bad'], 0, ',', '.') }}</span></div>
                        </div>
                    </div>
                    <div class="px-5 pb-4">
                        <div class="flex items-center justify-between text-xs mb-2">
                            <span class="text-slate-500">Temuan terbuka</span>
                            <span class="font-semibold text-rose-700">{{ number_format($cm['temuan_terbuka'], 0, ',', '.') }} / {{ number_format($cm['temuan_terbuka'] + $cm['temuan_tutup'], 0, ',', '.') }}</span>
                        </div>
                        <div class="h-1.5 rounded-full bg-slate-100 overflow-hidden">
                            @php $persenTemuan = ($cm['temuan_terbuka'] + $cm['temuan_tutup']) > 0 ? round($cm['temuan_terbuka'] / ($cm['temuan_terbuka'] + $cm['temuan_tutup']) * 100, 1) : 0; @endphp
                            <div class="h-1.5 rounded-full bg-rose-500" style="width: {{ $persenTemuan }}%"></div>
                        </div>
                        @if($cm['equipment_danger'] !== [])
                            <div class="mt-3 space-y-1.5">
                                @foreach(array_slice($cm['equipment_danger'], 0, 3) as $danger)
                                    <div class="flex items-center justify-between text-[11px] gap-2">
                                        <span class="font-medium text-slate-700 truncate">{{ $danger['tag'] }}</span>
                                        <span class="text-slate-400 whitespace-nowrap">{{ $danger['tanggal'] }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                        @if($cm['kategori'] !== [])
                            <p class="text-[10px] text-slate-400 mt-2">
                                Kategori terbanyak: {{ $cm['kategori'][0]['label'] }} ({{ number_format($cm['kategori'][0]['total'], 0, ',', '.') }})
                            </p>
                        @endif
                        @if($cm['severity'] !== [])
                            <p class="text-[10px] text-slate-400">
                                Severity terbuka:
                                @foreach($cm['severity'] as $nama => $jumlah)
                                    {{ $nama }} {{ number_format($jumlah, 0, ',', '.') }}@if(! $loop->last) · @endif
                                @endforeach
                            </p>
                        @endif
                    </div>
                </div>
            @endif

            @if($akses['sparepart'] && $sparepart !== null)
                <div class="bg-white rounded-xl border border-slate-200 shadow-sm" data-chart="sparepart-aksi">
                    <div class="px-5 py-4 border-b border-slate-100 flex items-start justify-between gap-2">
                        <div>
                            <h3 class="font-semibold text-slate-900">Sparepart</h3>
                            <p class="text-xs text-slate-500 mt-0.5">{{ number_format($sparepart['total'], 0, ',', '.') }} material dipantau</p>
                        </div>
                        <a href="{{ route('sparepart.index') }}" class="text-xs font-semibold text-teal-700 hover:text-teal-800">Buka</a>
                    </div>
                    <div class="p-4 grid grid-cols-3 gap-2 text-center">
                        <div class="rounded-lg bg-rose-50 px-2 py-2">
                            <p class="text-[10px] uppercase tracking-wide text-rose-600">REORDER</p>
                            <p class="text-lg font-bold text-rose-700">{{ number_format($sparepart['reorder'], 0, ',', '.') }}</p>
                        </div>
                        <div class="rounded-lg bg-amber-50 px-2 py-2">
                            <p class="text-[10px] uppercase tracking-wide text-amber-600">PR terbuka</p>
                            <p class="text-lg font-bold text-amber-700">{{ number_format($sparepart['pr_terbuka'], 0, ',', '.') }}</p>
                        </div>
                        <div class="rounded-lg bg-sky-50 px-2 py-2">
                            <p class="text-[10px] uppercase tracking-wide text-sky-600">PO terbuka</p>
                            <p class="text-lg font-bold text-sky-700">{{ number_format($sparepart['po_terbuka'], 0, ',', '.') }}</p>
                        </div>
                    </div>
                    <div class="px-4 h-44">
                        <canvas id="chartSparepartAksi" aria-label="Sebaran aksi sparepart"></canvas>
                    </div>
                    <div class="px-5 pb-4 text-[11px] text-slate-400">
                        @if($sparepart['terakhir'] !== null)
                            Import terakhir #{{ $sparepart['terakhir']->id }}
                            {{ $sparepart['terakhir']->selesai_at?->format('d/m/Y H:i') ?? $sparepart['terakhir']->created_at?->format('d/m/Y H:i') }}
                        @else
                            Belum ada import sparepart.
                        @endif
                    </div>
                </div>
            @endif

            @if($akses['it_assets'] && $it !== null)
                <div class="bg-white rounded-xl border border-slate-200 shadow-sm" data-chart="it-status">
                    <div class="px-5 py-4 border-b border-slate-100 flex items-start justify-between gap-2">
                        <div>
                            <h3 class="font-semibold text-slate-900">Perangkat IT</h3>
                            <p class="text-xs text-slate-500 mt-0.5">{{ number_format($it['total'], 0, ',', '.') }} perangkat · {{ number_format($it['notifikasi_baru'], 0, ',', '.') }} notifikasi baru</p>
                        </div>
                        <a href="{{ route('it-assets.index') }}" class="text-xs font-semibold text-teal-700 hover:text-teal-800">Buka</a>
                    </div>
                    <div class="p-4 grid grid-cols-2 gap-3 items-center">
                        <div class="h-40">
                            <canvas id="chartItStatus" aria-label="Status perangkat IT"></canvas>
                        </div>
                        <div class="space-y-1.5 text-xs">
                            <div class="flex justify-between"><span class="text-emerald-700">Online</span><span class="font-semibold text-slate-800">{{ number_format($it['online'], 0, ',', '.') }}</span></div>
                            <div class="flex justify-between"><span class="text-rose-700">Offline</span><span class="font-semibold text-slate-800">{{ number_format($it['offline'], 0, ',', '.') }}</span></div>
                            <div class="flex justify-between"><span class="text-slate-600">Belum diping</span><span class="font-semibold text-slate-800">{{ number_format($it['unknown'], 0, ',', '.') }}</span></div>
                        </div>
                    </div>
                    <div class="px-5 pb-4">
                        <div class="flex items-center justify-between text-xs mb-2">
                            <span class="text-slate-500">Pemakaian IP</span>
                            <span class="font-semibold text-slate-800">{{ number_format($it['ip_terpakai'], 0, ',', '.') }} / {{ number_format($it['ip_total'], 0, ',', '.') }} ({{ number_format($it['ip_persen'], 1, ',', '.') }}%)</span>
                        </div>
                        <div class="space-y-1.5">
                            @foreach($it['per_jaringan'] as $jaringan)
                                <div>
                                    <div class="flex items-center justify-between text-[11px] text-slate-500">
                                        <span>VLAN {{ $jaringan['vlan'] }} · {{ $jaringan['network'] }}</span>
                                        <span>{{ number_format($jaringan['persen'], 1, ',', '.') }}%</span>
                                    </div>
                                    <div class="h-1.5 rounded-full bg-slate-100 overflow-hidden">
                                        <div class="h-1.5 rounded-full bg-cyan-500" style="width: {{ $jaringan['persen'] }}%"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif
        </div>

        {{-- Panel + work order + AI/bot --}}
        <div class="grid grid-cols-1 xl:grid-cols-4 gap-5">
            @if($akses['panel'] && $panel !== null)
                <div class="{{ $akses['reliability'] && $akses['work_orders'] ? 'xl:col-span-2' : (($akses['reliability'] || $akses['work_orders']) ? 'xl:col-span-3' : 'xl:col-span-4') }} bg-white rounded-xl border border-slate-200 shadow-sm" data-chart="panel-suhu">
                    <div class="px-5 py-4 border-b border-slate-100 flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <h3 class="font-semibold text-slate-900">Monitoring ruang panel</h3>
                            <p class="text-xs text-slate-500 mt-0.5">
                                {{ $panel['total'] }} ruang · {{ $panel['alert'] }} perlu perhatian
                                @if($panel['utama'] !== null) · {{ $panel['utama'] }} @endif
                            </p>
                        </div>
                        <a href="{{ route('panel.index') }}" class="text-xs font-semibold text-teal-700 hover:text-teal-800">Buka panel</a>
                    </div>
                    @if($panelSeries !== [])
                        <div class="p-4 h-64">
                            <canvas id="chartPanel" aria-label="Suhu dan kelembapan ruang panel"></canvas>
                        </div>
                    @else
                        <div class="p-6 h-64 flex flex-col items-center justify-center text-center">
                            <p class="text-sm font-medium text-slate-600">Belum ada pembacaan sensor</p>
                            <p class="text-xs text-slate-400 mt-1">Data akan tampil setelah ruang panel terhubung.</p>
                        </div>
                    @endif
                    <div class="px-5 pb-4 grid grid-cols-1 sm:grid-cols-2 gap-2">
                        @foreach(array_slice($panel['daftar'], 0, 4) as $ruang)
                            <div class="rounded-lg border border-slate-100 px-3 py-2 text-xs flex items-center justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="font-medium text-slate-700 truncate">{{ $ruang['nama'] }}</p>
                                    <p class="text-slate-400 truncate">{{ $ruang['lokasi'] }}</p>
                                </div>
                                <div class="text-right shrink-0">
                                    <p class="font-semibold {{ $ruang['kondisi'] === 'normal' && $ruang['online'] ? 'text-emerald-700' : 'text-rose-700' }}">
                                        {{ $ruang['suhu'] !== null ? number_format($ruang['suhu'], 1, ',', '.') . ' °C' : '-' }}
                                    </p>
                                    <p class="text-[10px] text-slate-400">{{ $ruang['online'] ? 'online' : 'offline' }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            @if($akses['reliability'] && $reliability !== null)
                <div class="bg-white rounded-xl border border-slate-200 shadow-sm" data-section="reliability">
                    <div class="px-5 py-4 border-b border-slate-100 flex items-start justify-between gap-2">
                        <div>
                            <h3 class="font-semibold text-slate-900">Reliability</h3>
                            <p class="text-xs text-slate-500 mt-0.5">{{ $labelPeriode }} · {{ number_format($reliability['failure'], 0, ',', '.') }} failure</p>
                        </div>
                        <a href="{{ route('reliability.index') }}" class="text-xs font-semibold text-teal-700 hover:text-teal-800">Buka</a>
                    </div>
                    <div class="p-4 grid grid-cols-2 gap-2">
                        <div class="rounded-lg bg-slate-50 p-3">
                            <p class="text-[10px] uppercase tracking-wide text-slate-400">Fleet MTBF</p>
                            <p class="text-lg font-bold text-slate-900 mt-1">{{ number_format($reliability['mtbf'], 0, ',', '.') }} <span class="text-[10px] font-medium text-slate-400">jam</span></p>
                        </div>
                        <div class="rounded-lg bg-emerald-50 p-3">
                            <p class="text-[10px] uppercase tracking-wide text-emerald-600">Defer success</p>
                            <p class="text-lg font-bold text-emerald-700 mt-1">{{ number_format($reliability['defer_sukses'], 1, ',', '.') }}%</p>
                        </div>
                        <div class="rounded-lg bg-amber-50 p-3">
                            <p class="text-[10px] uppercase tracking-wide text-amber-600">Undetected</p>
                            <p class="text-lg font-bold text-amber-700 mt-1">{{ number_format($reliability['undetected'], 1, ',', '.') }}%</p>
                        </div>
                        <div class="rounded-lg bg-sky-50 p-3">
                            <p class="text-[10px] uppercase tracking-wide text-sky-600">CM : dCM : PM</p>
                            <p class="text-lg font-bold text-sky-700 mt-1">{{ $reliability['cm'] }} : {{ $reliability['dcm'] }} : {{ $reliability['pm'] }}</p>
                        </div>
                    </div>
                    <div class="px-5 pb-4 text-[11px] text-slate-400">
                        @if($reliability['failure'] === 0)
                            Belum ada laporan berklasifikasi CM/dCM/PM pada periode ini.
                        @else
                            MTBF memakai total jam kalender seluruh equipment. Defer success dari dCM sesuai rencana; undetected dari CM tanpa temuan tertaut.
                        @endif
                    </div>
                </div>
            @endif

            @if($akses['work_orders'] && $workOrder !== null)
                <div class="bg-white rounded-xl border border-slate-200 shadow-sm self-start">
                    <div class="px-5 py-4 border-b border-slate-100 flex items-start justify-between gap-2">
                        <div>
                            <h3 class="font-semibold text-slate-900">Work Order</h3>
                            <p class="text-xs text-slate-500 mt-0.5">{{ number_format($workOrder['total'], 0, ',', '.') }} work order</p>
                        </div>
                        <a href="{{ route('work-orders.index') }}" class="text-xs font-semibold text-teal-700 hover:text-teal-800">Buka</a>
                    </div>
                    @if($workOrder['total'] === 0)
                        <div class="p-6 h-56 flex flex-col items-center justify-center text-center">
                            <p class="text-sm font-medium text-slate-600">Belum ada work order</p>
                            <p class="text-xs text-slate-400 mt-1 max-w-xs">Modul sudah tersedia. Work order akan muncul di sini setelah dibuat dari temuan CM atau laporan.</p>
                        </div>
                    @else
                        <div class="p-4 grid grid-cols-2 gap-2 text-center">
                            <div class="rounded-lg bg-amber-50 px-2 py-2">
                                <p class="text-[10px] uppercase tracking-wide text-amber-600">Terbuka</p>
                                <p class="text-lg font-bold text-amber-700">{{ number_format($workOrder['open'], 0, ',', '.') }}</p>
                            </div>
                            <div class="rounded-lg bg-emerald-50 px-2 py-2">
                                <p class="text-[10px] uppercase tracking-wide text-emerald-600">Selesai</p>
                                <p class="text-lg font-bold text-emerald-700">{{ number_format($workOrder['selesai'], 0, ',', '.') }}</p>
                            </div>
                        </div>
                        <div class="px-4 h-44">
                            <canvas id="chartWorkOrder" aria-label="Jenis work order"></canvas>
                        </div>
                    @endif
                </div>
            @endif
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-3 gap-5">
            @if($akses['ai_providers'] && $ai !== null)
                <div class="bg-white rounded-xl border border-slate-200 shadow-sm">
                    <div class="px-5 py-4 border-b border-slate-100 flex items-start justify-between gap-2">
                        <div>
                            <h3 class="font-semibold text-slate-900">AI Provider</h3>
                            <p class="text-xs text-slate-500 mt-0.5">{{ $ai['sehat'] }} / {{ $ai['total'] }} sehat · {{ number_format($ai['token_bulan'], 0, ',', '.') }} token bulan ini</p>
                        </div>
                        <a href="{{ route('ai-providers.index') }}" class="text-xs font-semibold text-teal-700 hover:text-teal-800">Buka</a>
                    </div>
                    <div class="p-4 space-y-3">
                        @foreach($ai['provider'] as $provider)
                            <div>
                                <div class="flex items-center justify-between text-xs">
                                    <span class="font-medium text-slate-700">{{ $provider['nama'] }}</span>
                                    <span class="{{ $provider['status'] === 'healthy' ? 'text-emerald-600' : 'text-rose-600' }}">{{ $provider['status'] }}</span>
                                </div>
                                <div class="h-1.5 rounded-full bg-slate-100 overflow-hidden mt-1">
                                    <div class="h-1.5 rounded-full {{ $provider['persen'] >= 90 ? 'bg-rose-500' : 'bg-violet-500' }}" style="width: {{ $provider['persen'] }}%"></div>
                                </div>
                                <p class="text-[10px] text-slate-400 mt-1">{{ number_format($provider['bulan_ini'], 0, ',', '.') }} / {{ number_format($provider['limit'], 0, ',', '.') }} token · {{ $provider['request'] }} request 24 jam</p>
                            </div>
                        @endforeach
                        <div class="flex items-center justify-between text-xs pt-2 border-t border-slate-100">
                            <span class="text-slate-500">Keberhasilan log {{ $labelPeriode }}</span>
                            <span class="font-semibold {{ $ai['rate'] >= 80 ? 'text-emerald-700' : 'text-rose-700' }}">{{ number_format($ai['rate'], 1, ',', '.') }}%</span>
                        </div>
                    </div>
                </div>
            @endif

            @if($akses['bot'] && $bot !== null)
                <div class="bg-white rounded-xl border border-slate-200 shadow-sm self-start">
                    <div class="px-5 py-4 border-b border-slate-100 flex items-start justify-between gap-2">
                        <div>
                            <h3 class="font-semibold text-slate-900">Bot Telegram</h3>
                            <p class="text-xs text-slate-500 mt-0.5">Pendaftaran & aset tak dikenal</p>
                        </div>
                        <a href="{{ route('bot.index') }}" class="text-xs font-semibold text-teal-700 hover:text-teal-800">Buka</a>
                    </div>
                    <div class="p-4 grid grid-cols-3 gap-2 text-center">
                        <div class="rounded-lg bg-amber-50 px-2 py-3">
                            <p class="text-[10px] uppercase tracking-wide text-amber-600">Pending</p>
                            <p class="text-xl font-bold text-amber-700">{{ number_format($bot['pending'], 0, ',', '.') }}</p>
                        </div>
                        <div class="rounded-lg bg-emerald-50 px-2 py-3">
                            <p class="text-[10px] uppercase tracking-wide text-emerald-600">Disetujui</p>
                            <p class="text-xl font-bold text-emerald-700">{{ number_format($bot['approved'], 0, ',', '.') }}</p>
                        </div>
                        <div class="rounded-lg bg-slate-100 px-2 py-3">
                            <p class="text-[10px] uppercase tracking-wide text-slate-500">Unknown</p>
                            <p class="text-xl font-bold text-slate-700">{{ number_format($bot['unknown'], 0, ',', '.') }}</p>
                        </div>
                    </div>
                </div>
            @endif

            <div class="bg-white rounded-xl border border-slate-200 shadow-sm">
                <div class="px-5 py-4 border-b border-slate-100">
                    <h3 class="font-semibold text-slate-900">Perlu perhatian</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Indikator yang melewati batas</p>
                </div>
                <div class="p-4 space-y-2">
                    @forelse($perhatian as $item)
                        <a href="{{ $item['href'] }}" class="flex items-start justify-between gap-3 rounded-lg border border-slate-100 px-3 py-2.5 hover:bg-slate-50 transition-colors">
                            <div class="min-w-0">
                                <p class="text-xs font-semibold text-slate-700">{{ $item['judul'] }}</p>
                                <p class="text-[10px] text-slate-400 mt-0.5">{{ $item['detail'] }}</p>
                            </div>
                            <span class="shrink-0 px-2 py-0.5 rounded-full text-[10px] font-bold {{ $tone[$item['tone']]['bg'] ?? 'bg-slate-100' }} {{ $tone[$item['tone']]['text'] ?? 'text-slate-700' }}">
                                {{ $item['nilai'] }}
                            </span>
                        </a>
                    @empty
                        <div class="rounded-lg bg-emerald-50 border border-emerald-100 px-3 py-4 text-center">
                            <p class="text-xs font-semibold text-emerald-700">Semua indikator aman</p>
                            <p class="text-[10px] text-emerald-600 mt-1">Tidak ada indikator yang melewati batas pada periode ini.</p>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- Aktivitas terbaru --}}
        @if($akses['reports'] && $recentReports->isNotEmpty())
            <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
                    <div>
                        <h3 class="font-semibold text-slate-900">Laporan terbaru</h3>
                        <p class="text-xs text-slate-500 mt-0.5">8 laporan terakhir</p>
                    </div>
                    <a href="{{ route('reports.index') }}" class="text-xs font-semibold text-teal-700 hover:text-teal-800">Lihat semua</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-[10px] uppercase tracking-wider text-slate-500">
                            <tr>
                                <th class="px-5 py-2.5 text-left font-semibold">Tanggal</th>
                                <th class="px-5 py-2.5 text-left font-semibold">Teknisi</th>
                                <th class="px-5 py-2.5 text-left font-semibold">Pekerjaan</th>
                                <th class="px-5 py-2.5 text-left font-semibold">Area</th>
                                <th class="px-5 py-2.5 text-left font-semibold">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($recentReports as $report)
                                <tr class="hover:bg-slate-50/70">
                                    <td class="px-5 py-2.5 text-xs text-slate-500 whitespace-nowrap">{{ $report->report_date?->format('d/m/Y') ?? '-' }}</td>
                                    <td class="px-5 py-2.5 text-xs font-medium text-slate-700">{{ $report->technician?->name ?? '-' }}</td>
                                    <td class="px-5 py-2.5 text-xs text-slate-600">
                                        <a href="{{ route('reports.show', $report) }}" class="hover:text-teal-700">
                                            {{ \Illuminate\Support\Str::limit($report->work_description, 70) }}
                                        </a>
                                    </td>
                                    <td class="px-5 py-2.5 text-xs text-slate-500">{{ $report->area?->name ?? '-' }}</td>
                                    <td class="px-5 py-2.5">
                                        @php
                                            $labelStatus = [
                                                'completed' => 'Selesai',
                                                'needs_review' => 'Perlu review',
                                                'continue' => 'Dilanjutkan',
                                            ][$report->status] ?? ucfirst((string) $report->status);
                                        @endphp
                                        <x-status-badge :status="$report->status" :label="$labelStatus" />
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            if (typeof Chart === 'undefined') {
                return;
            }

            Chart.defaults.font.family = "'Inter', system-ui, -apple-system, sans-serif";
            Chart.defaults.color = '#64748b';
            Chart.defaults.borderColor = '#e2e8f0';

            const warna = {
                teal: '#0E9E8E',
                emerald: '#059669',
                blue: '#2563EB',
                violet: '#7C3AED',
                amber: '#D97706',
                rose: '#E11D48',
                cyan: '#0891B2',
                slate: '#64748B',
                sky: '#0284C7',
            };

            const legenda = {
                position: 'bottom',
                labels: {
                    usePointStyle: true,
                    boxWidth: 8,
                    boxHeight: 8,
                    padding: 14,
                    font: { size: 10 },
                },
            };

            const grid = { color: '#eef2f7', drawTicks: false };

            const buat = function (id, konfigurasi) {
                const elemen = document.getElementById(id);

                if (elemen !== null) {
                    new Chart(elemen, konfigurasi);
                }
            };

            const tren = @json($trenLaporan);
            const status = @json($statusLaporan);
            const jenis = @json($jenisLaporan);
            const teknisi = @json($topTeknisi);
            const aset = @json($asetDepartemen);
            const cmKondisi = @json($cmKondisi);
            const sparepart = @json($sparepartAksi);
            const itStatus = @json($itStatus);
            const panel = @json($panelSeries);
            const workOrder = @json($workOrderJenis);

            buat('chartLaporanTren', {
                type: 'bar',
                data: {
                    labels: tren.labels || [],
                    datasets: [
                        { label: 'Selesai', data: tren.selesai || [], backgroundColor: warna.teal, borderRadius: 4, stack: 'laporan' },
                        { label: 'Perlu review', data: tren.review || [], backgroundColor: warna.amber, borderRadius: 4, stack: 'laporan' },
                        { label: 'Dilanjutkan', data: tren.lanjut || [], backgroundColor: warna.sky, borderRadius: 4, stack: 'laporan' },
                        { label: 'Lainnya', data: tren.lainnya || [], backgroundColor: warna.slate, borderRadius: 4, stack: 'laporan' },
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: { legend: legenda, tooltip: { padding: 10 } },
                    scales: {
                        x: { stacked: true, grid: { display: false }, ticks: { maxTicksLimit: 12, font: { size: 10 } } },
                        y: { stacked: true, beginAtZero: true, grid: grid, ticks: { precision: 0 } },
                    },
                },
            });

            buat('chartStatusLaporan', {
                type: 'doughnut',
                data: {
                    labels: status.labels || [],
                    datasets: [{
                        data: status.data || [],
                        backgroundColor: [warna.teal, warna.amber, warna.sky, warna.slate],
                        borderWidth: 0,
                        hoverOffset: 4,
                    }],
                },
                options: { responsive: true, maintainAspectRatio: false, cutout: '68%', plugins: { legend: legenda } },
            });

            buat('chartJenisPekerjaan', {
                type: 'bar',
                data: {
                    labels: jenis.map(function (item) { return item.label; }),
                    datasets: [{ data: jenis.map(function (item) { return item.total; }), backgroundColor: warna.blue, borderRadius: 4 }],
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { beginAtZero: true, grid: grid, ticks: { precision: 0 } },
                        y: { grid: { display: false }, ticks: { font: { size: 10 } } },
                    },
                },
            });

            buat('chartTeknisi', {
                type: 'bar',
                data: {
                    labels: teknisi.map(function (item) { return item.nama; }),
                    datasets: [{ data: teknisi.map(function (item) { return item.laporan; }), backgroundColor: warna.violet, borderRadius: 4 }],
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function (konteks) {
                                    const item = teknisi[konteks.dataIndex] || { jam: 0 };

                                    return konteks.raw + ' laporan · ' + item.jam + ' jam';
                                },
                            },
                        },
                    },
                    scales: {
                        x: { beginAtZero: true, grid: grid, ticks: { precision: 0 } },
                        y: { grid: { display: false }, ticks: { font: { size: 10 } } },
                    },
                },
            });

            buat('chartAsetDepartemen', {
                type: 'bar',
                data: {
                    labels: aset.map(function (item) { return item.label; }),
                    datasets: [{ data: aset.map(function (item) { return item.total; }), backgroundColor: warna.cyan, borderRadius: 4 }],
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { beginAtZero: true, grid: grid, ticks: { precision: 0 } },
                        y: { grid: { display: false }, ticks: { font: { size: 10 } } },
                    },
                },
            });

            buat('chartCmKondisi', {
                type: 'doughnut',
                data: {
                    labels: ['Good', 'Alarm', 'Danger', 'Visual bad'],
                    datasets: [{
                        data: cmKondisi,
                        backgroundColor: [warna.emerald, warna.amber, warna.rose, warna.slate],
                        borderWidth: 0,
                        hoverOffset: 4,
                    }],
                },
                options: { responsive: true, maintainAspectRatio: false, cutout: '66%', plugins: { legend: { display: false } } },
            });

            buat('chartSparepartAksi', {
                type: 'bar',
                data: {
                    labels: sparepart.map(function (item) { return item.label; }),
                    datasets: [{
                        data: sparepart.map(function (item) { return item.total; }),
                        backgroundColor: sparepart.map(function (item) {
                            if (item.kode === 'KEJAR KEDATANGAN (PO OPEN)') return warna.rose;
                            if (item.kode === 'BUAT PO') return warna.amber;
                            if (item.kode === 'CEK / BUAT PR BARU') return warna.sky;

                            return warna.slate;
                        }),
                        borderRadius: 4,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { grid: { display: false }, ticks: { font: { size: 9 }, maxRotation: 25 } },
                        y: { beginAtZero: true, grid: grid, ticks: { precision: 0 } },
                    },
                },
            });

            buat('chartItStatus', {
                type: 'doughnut',
                data: {
                    labels: ['Online', 'Offline', 'Belum diping'],
                    datasets: [{
                        data: itStatus,
                        backgroundColor: [warna.emerald, warna.rose, warna.slate],
                        borderWidth: 0,
                        hoverOffset: 4,
                    }],
                },
                options: { responsive: true, maintainAspectRatio: false, cutout: '66%', plugins: { legend: { display: false } } },
            });

            buat('chartPanel', {
                type: 'line',
                data: {
                    labels: panel.map(function (item) { return item.label; }),
                    datasets: [
                        {
                            label: 'Suhu (°C)',
                            data: panel.map(function (item) { return item.suhu; }),
                            borderColor: warna.rose,
                            backgroundColor: 'rgba(225, 29, 72, 0.08)',
                            fill: true,
                            tension: 0.35,
                            pointRadius: 2,
                            yAxisID: 'y',
                        },
                        {
                            label: 'Kelembapan (%)',
                            data: panel.map(function (item) { return item.kelembapan; }),
                            borderColor: warna.cyan,
                            backgroundColor: 'transparent',
                            borderDash: [4, 4],
                            tension: 0.35,
                            pointRadius: 2,
                            yAxisID: 'y1',
                        },
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: { legend: legenda },
                    scales: {
                        x: { grid: { display: false }, ticks: { maxTicksLimit: 8, font: { size: 9 } } },
                        y: { position: 'left', beginAtZero: true, suggestedMin: 15, suggestedMax: 35, grid: grid, title: { display: true, text: '°C' } },
                        y1: { position: 'right', beginAtZero: true, suggestedMin: 50, suggestedMax: 90, grid: { drawOnChartArea: false }, title: { display: true, text: '%' } },
                    },
                },
            });

            buat('chartWorkOrder', {
                type: 'bar',
                data: {
                    labels: workOrder.map(function (item) { return item.label; }),
                    datasets: [{ data: workOrder.map(function (item) { return item.total; }), backgroundColor: warna.amber, borderRadius: 4 }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { grid: { display: false }, ticks: { font: { size: 10 } } },
                        y: { beginAtZero: true, grid: grid, ticks: { precision: 0 } },
                    },
                },
            });
        });
    </script>
@endpush
