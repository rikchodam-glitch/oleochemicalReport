@extends('layouts.app')

@section('title', 'Laporan')

@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Laporan</span>
@endsection

@section('header-actions')
    <a href="{{ route('reports.export-csv', request()->query()) }}"
       class="inline-flex items-center gap-2 px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
        </svg>
        Export CSV
    </a>
@endsection

@section('content')

@php
    // Label tanggal Bahasa Indonesia (locale aplikasi masih 'en', jadi tidak
    // memakai translatedFormat agar nama hari/bulan tetap Bahasa Indonesia).
    $hariId = [
        'Monday' => 'Senin', 'Tuesday' => 'Selasa', 'Wednesday' => 'Rabu',
        'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu', 'Sunday' => 'Minggu',
    ];
    $bulanId = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    $tanggalAktifStr = $tanggalAktif->toDateString();
    $labelTanggal = ($hariId[$tanggalAktif->format('l')] ?? $tanggalAktif->format('l'))
        . ', ' . $tanggalAktif->format('d') . ' ' . $bulanId[(int) $tanggalAktif->format('n')]
        . ' ' . $tanggalAktif->format('Y');

    // Parameter dasar untuk semua tautan tanggal/tampilan. Tanggal & tampilan
    // diatur ulang oleh tautannya sendiri, jadi dikeluarkan dari daftar dasar.
    $dasarTautan = request()->except(['page', 'tanggal', 'tampilan', 'grub', 'date_from', 'date_to']);

    $tautanTanggal = function (?string $tanggal) use ($dasarTautan, $tampilan, $grubAktif) {
        return route('reports.index', array_filter(array_merge($dasarTautan, [
            'tampilan' => $tampilan,
            'grub'     => $grubAktif,
            'tanggal'  => $tanggal,
        ]), fn ($nilai) => $nilai !== null && $nilai !== ''));
    };

    $tautanTampilan = function (string $mode, ?string $grub = null) use ($dasarTautan) {
        return route('reports.index', array_filter(array_merge($dasarTautan, [
            'tampilan' => $mode,
            'grub'     => $grub,
        ]), fn ($nilai) => $nilai !== null && $nilai !== ''));
    };

    // Tautan filter cepat (kartu jenis pekerjaan & kartu status): SELURUH filter
    // yang sedang aktif dipertahankan, hanya kunci yang diberikan yang ditimpa.
    // Nilai null/'' membuang kunci itu (filter dimatikan).
    $tautanFilter = function (array $ganti) {
        $parameter = array_filter(
            array_merge(request()->query(), $ganti),
            fn ($nilai) => $nilai !== null && $nilai !== ''
        );
        unset($parameter['page']);

        return route('reports.index', $parameter);
    };

    // Klik ulang kartu yang sedang aktif = filter dimatikan (kembali ke semua).
    $tautanJenis = function (string $jenis) use ($tautanFilter) {
        return $tautanFilter([
            'jenis_pekerjaan' => request('jenis_pekerjaan') === $jenis ? null : $jenis,
        ]);
    };

    $tautanStatus = function (string $status) use ($tautanFilter) {
        return $tautanFilter([
            'status' => request('status') === $status ? null : $status,
        ]);
    };

    $jenisColors = [
        'CM'        => 'bg-red-100 text-red-700 border-red-200',
        'dCM'       => 'bg-amber-100 text-amber-700 border-amber-200',
        'PM'        => 'bg-blue-100 text-blue-700 border-blue-200',
        'EI'        => 'bg-cyan-100 text-cyan-700 border-cyan-200',
        'Mekanik'   => 'bg-slate-100 text-slate-700 border-slate-200',
        'CM/PM'     => 'bg-emerald-100 text-emerald-700 border-emerald-200',
        'Fabrikasi' => 'bg-violet-100 text-violet-700 border-violet-200',
        'Sipil'     => 'bg-orange-100 text-orange-700 border-orange-200',
        'IT'        => 'bg-indigo-100 text-indigo-700 border-indigo-200',
        'Project'   => 'bg-rose-100 text-rose-700 border-rose-200',
    ];

    $zpmLabels = [
        'zpm1' => 'ZPM 1', 'zpm3' => 'ZPM 3', 'zpm6' => 'ZPM 6',
        'zpm5' => 'ZPM 5', 'zpm7' => 'ZPM 7', 'zpm8' => 'ZPM 8',
    ];

    // Kartu ringkasan per jenis pekerjaan (bisa diklik = filter cepat).
    // Angkanya dihitung server dengan seluruh filter yang aktif kecuali filter
    // jenis_pekerjaan itu sendiri, supaya angka kartu lain tidak jadi nol saat
    // salah satu kartu dipakai sebagai filter.
    $kartuJenis = [
        ['jenis' => 'EI',        'nilai' => $countEI,        'teks' => 'text-cyan-600',    'border' => 'border-cyan-200'],
        ['jenis' => 'Mekanik',   'nilai' => $countMekanik,   'teks' => 'text-slate-700',   'border' => 'border-slate-200'],
        ['jenis' => 'CM/PM',     'nilai' => $countCmPm,      'teks' => 'text-emerald-600', 'border' => 'border-emerald-200'],
        ['jenis' => 'Fabrikasi', 'nilai' => $countFabrikasi, 'teks' => 'text-violet-600',  'border' => 'border-violet-200'],
        ['jenis' => 'Sipil',     'nilai' => $countSipil,     'teks' => 'text-orange-600',  'border' => 'border-orange-200'],
        ['jenis' => 'IT',        'nilai' => $countIT,        'teks' => 'text-indigo-600',  'border' => 'border-indigo-200'],
        ['jenis' => 'Project',   'nilai' => $countProject,   'teks' => 'text-rose-600',    'border' => 'border-rose-200'],
    ];

    $jenisAktif  = (string) request('jenis_pekerjaan', '');
    $statusAktif = (string) request('status', '');

    // Filter lanjutan dianggap aktif bila ada filter di dalam panelnya. Jenis
    // pekerjaan & status tidak dihitung karena sudah dipegang kartu ringkasan.
    $adaFilterLanjutan = collect([
        request('search'), request('report_code'), request('asset_code'),
        request('area_id'), request('technician_id'), request('report_type'),
        request('work_classification'),
    ])->filter()->isNotEmpty();
@endphp

{{-- ============================================================
     Panel utama: pilih tampilan (Reguler / Shift), status, dan tanggal kerja
============================================================ --}}
<div class="bg-white rounded-xl border border-slate-200 mb-5">
    <div class="p-5 space-y-4">

        {{-- Tab tampilan, pemilih grub, dan kartu status (Continue / Done) --}}
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-3">
                <div class="inline-flex rounded-lg border border-slate-200 overflow-hidden">
                    <a href="{{ $tautanTampilan('reguler') }}"
                       class="px-4 py-2 text-sm font-medium transition-colors {{ $tampilan === 'reguler' ? 'bg-teal-600 text-white' : 'bg-white text-slate-600 hover:bg-slate-50' }}">
                        Reguler
                    </a>
                    <a href="{{ $tautanTampilan('shift', $tampilan === 'shift' ? $grubAktif : null) }}"
                       class="px-4 py-2 text-sm font-medium border-l border-slate-200 transition-colors {{ $tampilan === 'shift' ? 'bg-teal-600 text-white' : 'bg-white text-slate-600 hover:bg-slate-50' }}">
                        Shift
                    </a>
                </div>

                @if($tampilan === 'shift')
                    <form method="GET" action="{{ route('reports.index') }}" class="flex items-center gap-2">
                        @foreach($dasarTautan as $nama => $nilai)
                            @if(is_scalar($nilai))
                                <input type="hidden" name="{{ $nama }}" value="{{ $nilai }}">
                            @endif
                        @endforeach
                        <input type="hidden" name="tampilan" value="shift">
                        <input type="hidden" name="tanggal" value="{{ $tanggalAktifStr }}">

                        <label for="pilih-grub" class="text-xs text-slate-500">Grub</label>
                        <select name="grub" id="pilih-grub"
                                class="border border-slate-300 rounded-lg px-3 py-1.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-teal-500">
                            <option value="">Semua Grub</option>
                            @foreach($grubOptions as $kunci => $label)
                                <option value="{{ $kunci }}" {{ $grubAktif === $kunci ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                        <button type="submit"
                                class="px-3 py-1.5 bg-teal-600 hover:bg-teal-700 text-white text-sm font-medium rounded-lg transition-colors">
                            Tampilkan
                        </button>
                    </form>
                @endif
            </div>

            {{-- Kartu status pekerjaan: klik = filter cepat, klik ulang = semua status --}}
            <div class="flex items-center gap-2">
                <a href="{{ $tautanStatus('continue') }}"
                   title="Tampilkan pekerjaan yang belum selesai"
                   class="flex items-baseline gap-2 px-3.5 py-2 rounded-lg border-2 transition-colors
                          {{ $statusAktif === 'continue' ? 'border-amber-400 bg-amber-50' : 'border-amber-200 bg-white hover:bg-amber-50' }}">
                    <span class="text-lg font-bold text-amber-600" data-kartu="status-continue">{{ number_format($countContinue) }}</span>
                    <span class="text-xs font-medium text-slate-600">Continue</span>
                </a>
                <a href="{{ $tautanStatus('completed') }}"
                   title="Tampilkan pekerjaan yang sudah selesai"
                   class="flex items-baseline gap-2 px-3.5 py-2 rounded-lg border-2 transition-colors
                          {{ $statusAktif === 'completed' ? 'border-teal-500 bg-teal-50' : 'border-teal-200 bg-white hover:bg-teal-50' }}">
                    <span class="text-lg font-bold text-teal-600" data-kartu="status-completed">{{ number_format($countDone) }}</span>
                    <span class="text-xs font-medium text-slate-600">Done</span>
                </a>
            </div>
        </div>

        {{-- Navigasi tanggal: hari sebelumnya / hari berikutnya --}}
        <div class="flex flex-wrap items-center gap-2 pt-1">
            @if($tanggalSekitar['sebelumnya'])
                <a href="{{ $tautanTanggal($tanggalSekitar['sebelumnya']) }}"
                   class="inline-flex items-center gap-1.5 px-3 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm rounded-lg transition-colors">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                    </svg>
                    {{ \Carbon\Carbon::parse($tanggalSekitar['sebelumnya'])->format('d/m/Y') }}
                </a>
            @else
                <span class="inline-flex items-center gap-1.5 px-3 py-2 border border-slate-100 text-slate-300 text-sm rounded-lg">
                    Tidak ada tanggal sebelumnya
                </span>
            @endif

            <div class="px-3 py-2 rounded-lg bg-teal-50 border border-teal-100">
                <p class="text-sm font-semibold text-teal-800">{{ $labelTanggal }}</p>
            </div>

            @if($tanggalSekitar['berikutnya'])
                <a href="{{ $tautanTanggal($tanggalSekitar['berikutnya']) }}"
                   class="inline-flex items-center gap-1.5 px-3 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm rounded-lg transition-colors">
                    {{ \Carbon\Carbon::parse($tanggalSekitar['berikutnya'])->format('d/m/Y') }}
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                    </svg>
                </a>
            @else
                <span class="inline-flex items-center gap-1.5 px-3 py-2 border border-slate-100 text-slate-300 text-sm rounded-lg">
                    Tidak ada tanggal berikutnya
                </span>
            @endif
        </div>


        {{-- Pilih tanggal lain + tombol hari ini --}}
        <div class="flex flex-wrap items-center gap-2 pt-1">
            <form method="GET" action="{{ route('reports.index') }}" class="flex items-center gap-2">
                @foreach($dasarTautan as $nama => $nilai)
                    @if(is_scalar($nilai))
                        <input type="hidden" name="{{ $nama }}" value="{{ $nilai }}">
                    @endif
                @endforeach
                <input type="hidden" name="tampilan" value="{{ $tampilan }}">
                @if($grubAktif)
                    <input type="hidden" name="grub" value="{{ $grubAktif }}">
                @endif

                <label for="pilih-tanggal" class="text-xs text-slate-500">Tanggal lain</label>
                <input type="date" name="tanggal" id="pilih-tanggal" value="{{ $tanggalAktifStr }}"
                       class="border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                <button type="submit"
                        class="px-3 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                    Buka
                </button>
            </form>

            <a href="{{ $tautanTanggal(now()->toDateString()) }}"
               class="px-3 py-2 border border-teal-200 bg-teal-50 hover:bg-teal-100 text-teal-700 text-sm font-medium rounded-lg transition-colors">
                Hari Ini
            </a>

            @if($pakaiRentang)
                <span class="px-3 py-2 rounded-lg bg-amber-50 border border-amber-200 text-xs text-amber-700">
                    Sedang memakai rentang tanggal dari filter lanjutan
                </span>
            @endif
        </div>

        {{-- Tanggal-tanggal terakhir yang punya laporan. Angka di dalam tanda
             kurung memakai definisi yang sama dengan tabel: satu laporan = satu
             baris (salinan kolaborasi & multi alat tidak dihitung). --}}
        @if($tanggalSekitar['daftar']->isNotEmpty())
            <div class="flex flex-wrap items-center gap-2 pt-3 border-t border-slate-100">
                <span class="text-xs text-slate-400">Tanggal terakhir:</span>
                @foreach($tanggalSekitar['daftar'] as $barisTanggal)
                    <a href="{{ $tautanTanggal($barisTanggal['tanggal']) }}"
                       data-chip-tanggal="{{ $barisTanggal['tanggal'] }}"
                       data-chip-jumlah="{{ $barisTanggal['jumlah'] }}"
                       class="px-2.5 py-1 rounded-lg text-xs font-medium border transition-colors
                              {{ $barisTanggal['tanggal'] === $tanggalAktifStr
                                    ? 'bg-teal-600 border-teal-600 text-white'
                                    : 'bg-white border-slate-200 text-slate-600 hover:bg-slate-50' }}">
                        {{ \Carbon\Carbon::parse($barisTanggal['tanggal'])->format('d/m') }}
                        <span class="{{ $barisTanggal['tanggal'] === $tanggalAktifStr ? 'text-teal-100' : 'text-slate-400' }}">
                            ({{ $barisTanggal['jumlah'] }})
                        </span>
                    </a>
                @endforeach
            </div>
        @endif

    </div>
</div>

{{-- ── Kolom ringkasan jenis pekerjaan (klik = filter cepat) ─────────── --}}
<div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-3 mb-4">
    @foreach($kartuJenis as $kartu)
        <a href="{{ $tautanJenis($kartu['jenis']) }}"
           title="{{ $jenisAktif === $kartu['jenis'] ? 'Tampilkan semua jenis pekerjaan' : 'Tampilkan laporan ' . $kartu['jenis'] . ' saja' }}"
           class="bg-white rounded-xl border-2 px-4 py-3 transition-colors
                  {{ $jenisAktif === $kartu['jenis']
                        ? 'border-teal-400 ring-2 ring-teal-200 bg-teal-50'
                        : $kartu['border'] . ' hover:border-teal-300' }}">
            <p class="text-xl font-bold {{ $kartu['teks'] }}" data-kartu="jenis-{{ $kartu['jenis'] }}">{{ number_format($kartu['nilai']) }}</p>
            <p class="text-xs text-slate-500 mt-0.5">{{ $kartu['jenis'] }}</p>
        </a>
    @endforeach
    <div class="bg-white rounded-xl border-2 border-teal-200 px-4 py-3">
        <p class="text-xl font-bold text-teal-600" data-kartu="total">{{ number_format($totalLaporan) }}</p>
        <p class="text-xs text-slate-500 mt-0.5">Total Pekerjaan</p>
    </div>
</div>

{{-- ============================================================
     Filter lanjutan (opsional) — diletakkan tepat sebelum tabel
============================================================ --}}
<details class="mb-4 bg-white rounded-xl border border-slate-200" {{ $adaFilterLanjutan ? 'open' : '' }}>
    <summary class="px-5 py-4 cursor-pointer text-sm font-medium text-slate-700 flex items-center gap-2">
        <svg class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2a1 1 0 01-.293.707L13 13.414V19a1 1 0 01-.553.894l-4 2A1 1 0 017 21v-7.586L3.293 6.707A1 1 0 013 6V4z"/>
        </svg>
        Filter Lanjutan
        @if($adaFilterLanjutan)
            <span class="px-1.5 py-0.5 rounded-full text-xs font-medium bg-blue-600 text-white leading-none">aktif</span>
        @endif
    </summary>

    <form method="GET" action="{{ route('reports.index') }}" class="px-5 pb-5 space-y-4 border-t border-slate-100 pt-5">
        <input type="hidden" name="tampilan" value="{{ $tampilan }}">
        @if($grubAktif)
            <input type="hidden" name="grub" value="{{ $grubAktif }}">
        @endif
        {{-- Tanggal aktif dipertahankan supaya menerapkan filter tidak melompat
             kembali ke hari ini (rentang tanggal tetap menang bila diisi). --}}
        <input type="hidden" name="tanggal" value="{{ $tanggalAktifStr }}">

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div>
                <label class="block text-xs text-slate-500 mb-1">Pekerjaan / Lokasi / Alat</label>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Cari pekerjaan, kode lokasi, atau alat..."
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Kode Laporan</label>
                <input type="text" name="report_code" value="{{ request('report_code') }}" placeholder="Cari kode laporan..."
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Kode Alat</label>
                <input type="text" name="asset_code" value="{{ request('asset_code') }}" placeholder="Cari kode alat..."
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
            </div>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
            <div>
                <label class="block text-xs text-slate-500 mb-1">Tanggal Dari</label>
                <input type="date" name="date_from" value="{{ request('date_from') }}"
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Tanggal Sampai</label>
                <input type="date" name="date_to" value="{{ request('date_to') }}"
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Area</label>
                <select name="area_id"
                        class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-teal-500">
                    <option value="">Semua Area</option>
                    @foreach($areas as $area)
                        <option value="{{ $area->id }}" {{ (string) request('area_id') === (string) $area->id ? 'selected' : '' }}>
                            {{ $area->code }} - {{ $area->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Teknisi</label>
                <select name="technician_id"
                        class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-teal-500">
                    <option value="">Semua Teknisi</option>
                    @foreach($technicians as $technician)
                        <option value="{{ $technician->id }}" {{ (string) request('technician_id') === (string) $technician->id ? 'selected' : '' }}>
                            {{ $technician->name }}{{ $technician->nik ? ' (' . $technician->nik . ')' : '' }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
            <div>
                <label class="block text-xs text-slate-500 mb-1">Jenis Pekerjaan</label>
                <select name="jenis_pekerjaan"
                        class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-teal-500">
                    <option value="">Semua Jenis</option>
                    @foreach(['EI', 'Mekanik', 'CM/PM', 'Fabrikasi', 'Sipil', 'IT', 'Project'] as $jenisOpsi)
                        <option value="{{ $jenisOpsi }}" {{ request('jenis_pekerjaan') === $jenisOpsi ? 'selected' : '' }}>{{ $jenisOpsi }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Klasifikasi (Wizard)</label>
                <select name="work_classification"
                        class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-teal-500">
                    <option value="">Semua Klasifikasi</option>
                    <option value="zpm1" {{ request('work_classification') === 'zpm1' ? 'selected' : '' }}>ZPM 1 - Corrective</option>
                    <option value="zpm3" {{ request('work_classification') === 'zpm3' ? 'selected' : '' }}>ZPM 3 - Preventive</option>
                    <option value="zpm6" {{ request('work_classification') === 'zpm6' ? 'selected' : '' }}>ZPM 6 - Planned</option>
                    <option value="zpm5" {{ request('work_classification') === 'zpm5' ? 'selected' : '' }}>ZPM 5 - Fabrikasi</option>
                    <option value="zpm7" {{ request('work_classification') === 'zpm7' ? 'selected' : '' }}>ZPM 7 - Sipil</option>
                    <option value="zpm8" {{ request('work_classification') === 'zpm8' ? 'selected' : '' }}>ZPM 8 - Project</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Tipe Laporan</label>
                <select name="report_type"
                        class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-teal-500">
                    <option value="">Semua Tipe</option>
                    <option value="equipment_repair" {{ request('report_type') === 'equipment_repair' ? 'selected' : '' }}>Perbaikan Equipment</option>
                    <option value="area_work" {{ request('report_type') === 'area_work' ? 'selected' : '' }}>Pekerjaan Area</option>
                    <option value="general" {{ request('report_type') === 'general' ? 'selected' : '' }}>Umum</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Status Pekerjaan</label>
                <select name="status"
                        class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-teal-500">
                    <option value="">Semua Status</option>
                    <option value="continue" {{ request('status') === 'continue' ? 'selected' : '' }}>Continue (belum selesai)</option>
                    <option value="completed" {{ request('status') === 'completed' ? 'selected' : '' }}>Done (selesai)</option>
                    <option value="needs_review" {{ request('status') === 'needs_review' ? 'selected' : '' }}>Perlu Diperiksa</option>
                </select>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <button type="submit"
                    class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white text-sm font-medium rounded-lg transition-colors">
                Terapkan Filter
            </button>
            <a href="{{ route('reports.index', ['tampilan' => $tampilan, 'tanggal' => $tanggalAktifStr]) }}"
               class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                Reset Filter
            </a>
        </div>
    </form>
</details>

{{-- ============================================================
     Tabel fokus pekerjaan: pekerjaan di depan, teknisi di sampingnya
============================================================ --}}
<div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-slate-50 border-b border-slate-100">
                    <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Pekerjaan</th>
                    <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3 whitespace-nowrap">Teknisi</th>
                    <th class="text-center text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3 whitespace-nowrap">Jenis</th>
                    <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3 whitespace-nowrap">Lokasi / Alat</th>
                    <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3 whitespace-nowrap">Waktu & Foto</th>
                    @if($pakaiRentang)
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3 whitespace-nowrap">Tanggal</th>
                    @endif
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                @php $kolaborasiPerLaporan = []; @endphp
                @forelse($reports as $report)
                    @php
                        $jColor = $jenisColors[$report->jenis_pekerjaan] ?? 'bg-slate-100 text-slate-400 border-slate-200';
                        $kolaborator = $report->collaboratorReports
                            ->map(fn ($baris) => $baris->technician?->name)
                            ->filter()
                            ->values();

                        // Data alat/lokasi kolaborasi (multi equipment) untuk daftar singkat
                        // dan popup "Lihat": cukup keterangan + tautan ke detail lokasi/alat,
                        // bukan halaman laporan terpisah karena pekerjaan ini satu kesatuan.
                        $kolaborasiPerLaporan[$report->id] = $report->multiItems
                            ->map(fn ($item) => [
                                'tipe' => $item->asset ? 'Alat' : 'Lokasi',
                                'kode' => $item->asset?->tech_ident_no
                                    ?? $item->functionalLocation?->code
                                    ?? ('#' . $item->id),
                                'nama' => $item->asset?->description
                                    ?? $item->functionalLocation?->name
                                    ?? '-',
                                'url'  => $item->asset
                                    ? route('assets.show', $item->asset)
                                    : ($item->functionalLocation
                                        ? route('locations.show', $item->functionalLocation)
                                        : route('reports.show', $report)),
                            ])
                            ->values()
                            ->all();
                    @endphp
                    <tr class="hover:bg-slate-50 transition-colors cursor-pointer"
                        onclick="window.location='{{ route('reports.show', $report) }}'">

                        {{-- Pekerjaan: kolom utama --}}
                        <td class="px-5 py-3.5 align-top">
                            <p class="text-slate-800 text-sm font-medium leading-snug whitespace-normal break-words">
                                {{ $report->work_description ?: '(tanpa deskripsi pekerjaan)' }}
                            </p>
                            <p class="text-xs text-slate-400 mt-1">
                                {{ $report->report_code ?? '-' }}
                                @if($report->status === 'continue')
                                    <span class="inline-flex ml-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-amber-100 text-amber-700 border border-amber-200">
                                        CONTINUE
                                    </span>
                                @endif
                                @if($report->continued_from_id)
                                    <span class="inline-flex ml-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-teal-50 text-teal-700 border border-teal-100">
                                        LANJUTAN
                                    </span>
                                @endif
                                @if($report->root_cause)
                                    <span class="text-slate-200 mx-1">|</span>
                                    Penyebab: {{ \Illuminate\Support\Str::limit($report->root_cause, 80) }}
                                @endif
                            </p>
                        </td>

                        {{-- Teknisi + daftar kolaborator --}}
                        <td class="px-5 py-3.5 align-top">
                            <p class="text-sm font-semibold text-slate-700 leading-snug">
                                {{ $report->technician?->name ?? '-' }}
                            </p>

                            @if($tampilan === 'shift' && $report->technician?->group)
                                <span class="inline-flex mt-1 px-2 py-0.5 rounded-md text-xs font-medium bg-teal-50 text-teal-700 border border-teal-100">
                                    {{ \App\Models\Technician::GROUPS[$report->technician->group] ?? $report->technician->group }}
                                </span>
                            @endif

                            @if($kolaborator->isNotEmpty())
                                <p class="text-xs text-slate-500 mt-1 leading-snug">
                                    Berkolaborasi dengan:
                                    <span class="text-slate-700">{{ $kolaborator->implode(', ') }}</span>
                                </p>
                            @endif
                        </td>


                        {{-- Jenis pekerjaan --}}
                        <td class="px-4 py-3.5 align-top text-center">
                            @if($report->jenis_pekerjaan)
                                <span class="inline-flex px-2 py-0.5 rounded-md text-xs font-medium border {{ $jColor }}">
                                    {{ $report->jenis_pekerjaan }}
                                </span>
                            @else
                                <span class="text-xs text-slate-300">-</span>
                            @endif

                            @if($report->work_classification)
                                <p class="text-xs text-slate-400 mt-1">
                                    {{ $zpmLabels[$report->work_classification] ?? $report->work_classification }}
                                </p>
                            @endif
                        </td>

                        {{-- Lokasi / alat --}}
                        <td class="px-4 py-3.5 align-top whitespace-nowrap">
                            @if($report->functionalLocation)
                                <a href="{{ route('locations.show', $report->functionalLocation) }}"
                                   onclick="event.stopPropagation()"
                                   title="Detail lokasi {{ $report->functionalLocation->name ?? $report->functionalLocation->code }}"
                                   class="text-xs font-medium font-mono text-teal-700 hover:underline">
                                    {{ $report->functionalLocation->code }}
                                </a>
                            @elseif($report->area)
                                <a href="{{ route('assets.index', ['area_id' => $report->area->id]) }}"
                                   onclick="event.stopPropagation()"
                                   title="Lihat alat di area {{ $report->area->code }}"
                                   class="text-xs font-medium text-slate-700 hover:text-teal-700 hover:underline">
                                    {{ $report->area->code }}
                                </a>
                            @else
                                <span class="text-xs font-medium text-slate-300">Tanpa lokasi</span>
                            @endif
                            <p class="text-xs text-slate-400 mt-0.5 font-mono">
                                @if($report->asset)
                                    <a href="{{ route('assets.show', $report->asset) }}"
                                       onclick="event.stopPropagation()"
                                       title="Detail alat {{ $report->asset->description ?? $report->asset->tech_ident_no }}"
                                       class="text-teal-700 hover:underline">
                                        {{ $report->asset->tech_ident_no ?? $report->asset->equipment_no ?? ('#' . $report->asset->id) }}
                                    </a>
                                @else
                                    {{ $report->equipment_tag ?: '-' }}
                                @endif
                            </p>

                            {{-- Alat/lokasi yang dikolaborasikan (pekerjaan multi equipment):
                                 cukup penanda jumlahnya, diklik untuk membuka popup daftarnya. --}}
                            @if($report->multiItems->isNotEmpty())
                                <div class="mt-1.5 pt-1.5 border-t border-slate-100">
                                    <button type="button"
                                            onclick="event.stopPropagation(); tampilkanKolaborasi({{ $report->id }})"
                                            title="Klik untuk melihat daftar alat/lokasi kolaborasi"
                                            class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-semibold bg-teal-50 text-teal-700 border border-teal-100 hover:bg-teal-100 transition-colors">
                                        {{ $report->multiItems->count() }} KOLABORASI
                                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                                        </svg>
                                    </button>
                                </div>
                            @endif

                            @if($report->linkedFinding)
                                <span class="inline-flex mt-1 px-2 py-0.5 rounded-md text-xs font-medium bg-rose-50 text-rose-600 border border-rose-100">
                                    CM Finding
                                </span>
                            @endif
                        </td>

                        {{-- Waktu & foto --}}
                        <td class="px-4 py-3.5 align-top whitespace-nowrap">
                            @if($report->time_range)
                                <p class="text-xs text-slate-600">{{ $report->time_range }}</p>
                            @endif
                            <p class="text-xs text-slate-600 {{ $report->time_range ? 'mt-0.5' : '' }}">
                                {{ $report->duration_label ?? '-' }}
                            </p>
                            <p class="text-xs text-slate-400 mt-0.5">
                                Dok : {{ $report->photo_doc_count }}
                                <span class="text-slate-200 mx-0.5">|</span>
                                HC : {{ $report->photo_hyg_count }}
                            </p>
                        </td>

                        @if($pakaiRentang)
                            <td class="px-4 py-3.5 align-top whitespace-nowrap text-xs text-slate-600">
                                {{ $report->report_date?->format('d/m/Y') ?? '-' }}
                            </td>
                        @endif
                    </tr>

                @empty
                    <tr>
                        <td colspan="{{ $pakaiRentang ? 6 : 5 }}" class="px-5 py-16 text-center">
                            <div class="flex flex-col items-center gap-3">
                                <svg class="w-12 h-12 text-slate-200" fill="none" viewBox="0 0 48 48">
                                    <rect x="8" y="6" width="32" height="36" rx="4" fill="currentColor" opacity=".4"/>
                                    <rect x="8" y="6" width="32" height="36" rx="4" stroke="#94a3b8" stroke-width="1.5" fill="none"/>
                                    <line x1="16" y1="18" x2="32" y2="18" stroke="#94a3b8" stroke-width="1.5" stroke-linecap="round"/>
                                    <line x1="16" y1="24" x2="32" y2="24" stroke="#94a3b8" stroke-width="1.5" stroke-linecap="round"/>
                                    <line x1="16" y1="30" x2="24" y2="30" stroke="#94a3b8" stroke-width="1.5" stroke-linecap="round"/>
                                </svg>
                                <div>
                                    <p class="text-sm font-medium text-slate-500">
                                        Belum ada pekerjaan pada {{ $labelTanggal }}
                                        @if($tampilan === 'shift')
                                            ({{ $grubAktif ? (\App\Models\Technician::GROUPS[$grubAktif] ?? $grubAktif) : 'semua grub' }})
                                        @else
                                            (reguler)
                                        @endif
                                    </p>
                                    <p class="text-xs text-slate-400 mt-1">
                                        Pilih tanggal lain lewat tombol tanggal di atas, atau tombol Hari Ini.
                                    </p>
                                </div>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>


    {{-- Pagination + info jumlah --}}
    @if($reports->hasPages() || $reports->total() > 0)
        <div class="px-5 py-3.5 border-t border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-3">
            <p class="text-xs text-slate-500 shrink-0">
                Menampilkan
                <span class="font-medium text-slate-700">{{ $reports->firstItem() }}–{{ $reports->lastItem() }}</span>
                dari
                <span class="font-medium text-slate-700">{{ number_format($reports->total()) }}</span>
                pekerjaan
                @if(! $pakaiRentang)
                    pada {{ $labelTanggal }}
                @endif
            </p>

            @if($reports->hasPages())
                <div class="shrink-0">
                    {{ $reports->links() }}
                </div>
            @endif
        </div>
    @endif
</div>

{{-- Modal daftar alat/lokasi kolaborasi (dipakai tombol "Lihat" pada kolom Lokasi / Alat) --}}
@push('modals')
    <div id="modal-kolaborasi" class="hidden fixed inset-0 z-50 items-center justify-center bg-slate-900/50 p-4">
        <div class="bg-white rounded-xl shadow-xl w-full max-w-lg max-h-[80vh] flex flex-col overflow-hidden">
            <div class="flex items-start justify-between gap-4 px-5 py-3.5 border-b border-slate-100">
                <div>
                    <h3 class="text-sm font-semibold text-slate-800">Alat / Lokasi Kolaborasi</h3>
                    <p id="modal-kolaborasi-sub" class="text-xs text-slate-400 mt-0.5"></p>
                </div>
                <button type="button" onclick="tutupKolaborasi()" title="Tutup"
                        class="text-slate-400 hover:text-slate-600 shrink-0">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <div id="modal-kolaborasi-isi" class="px-5 py-4 space-y-2 overflow-y-auto"></div>
            <div class="px-5 py-3 border-t border-slate-100 text-right">
                <button type="button" onclick="tutupKolaborasi()"
                        class="px-4 py-1.5 rounded-lg border border-slate-200 text-sm font-medium text-slate-600 hover:bg-slate-50">
                    Tutup
                </button>
            </div>
        </div>
    </div>
@endpush

@push('scripts')
    <script>
        // Data alat/lokasi kolaborasi per laporan, dipakai tombol "Lihat" pada kolom Lokasi / Alat.
        var dataKolaborasi = {!! json_encode($kolaborasiPerLaporan ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!};

        /**
         * Tampilkan popup daftar alat/lokasi kolaborasi untuk satu laporan.
         * @param {number} idLaporan ID laporan induk
         */
        function tampilkanKolaborasi(idLaporan) {
            var daftar = dataKolaborasi[idLaporan] || [];
            var modal = document.getElementById('modal-kolaborasi');
            var isi = document.getElementById('modal-kolaborasi-isi');
            var sub = document.getElementById('modal-kolaborasi-sub');

            if (!modal || !isi || !sub) {
                return;
            }

            sub.textContent = daftar.length + ' alat/lokasi dikerjakan bersama pada laporan ini';
            isi.innerHTML = '';

            daftar.forEach(function (item) {
                var baris = document.createElement('div');
                baris.className = 'flex items-center justify-between gap-3 rounded-lg border border-slate-100 px-3 py-2';

                var kiri = document.createElement('div');
                kiri.className = 'min-w-0';

                var tipe = document.createElement('p');
                tipe.className = 'text-[10px] font-semibold uppercase tracking-wide text-slate-400';
                tipe.textContent = item.tipe;

                var kode = document.createElement('a');
                kode.href = item.url;
                kode.className = 'block text-sm font-mono text-teal-700 hover:underline truncate';
                kode.textContent = item.kode;

                var nama = document.createElement('p');
                nama.className = 'text-xs text-slate-400 truncate';
                nama.textContent = item.nama || '-';

                kiri.appendChild(tipe);
                kiri.appendChild(kode);
                kiri.appendChild(nama);

                var aksi = document.createElement('div');
                aksi.className = 'flex items-center gap-2 shrink-0';

                // Hanya tautan ke detail lokasi/equipment: kolaborasi ini satu kesatuan
                // pekerjaan, tidak dipecah jadi laporan terpisah yang bisa dibuka.
                var tautanDetail = document.createElement('a');
                tautanDetail.href = item.url;
                tautanDetail.className = 'px-3 py-1.5 rounded-lg bg-teal-600 text-white text-xs font-semibold hover:bg-teal-700';
                tautanDetail.textContent = item.tipe === 'Alat' ? 'Detail Equipment' : 'Detail Lokasi';

                aksi.appendChild(tautanDetail);

                baris.appendChild(kiri);
                baris.appendChild(aksi);
                isi.appendChild(baris);
            });

            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        /**
         * Tutup popup daftar alat/lokasi kolaborasi.
         */
        function tutupKolaborasi() {
            var modal = document.getElementById('modal-kolaborasi');

            if (!modal) {
                return;
            }

            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        document.addEventListener('keydown', function (peristiwa) {
            if (peristiwa.key === 'Escape') {
                tutupKolaborasi();
            }
        });
    </script>
@endpush

@endsection
