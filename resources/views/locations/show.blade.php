@extends('layouts.app')

@section('title', $location->name ?? $location->code)

@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('assets.index') }}" class="hover:text-slate-700">Asset Management</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">{{ $location->code }}</span>
@endsection

@section('header-actions')
    <a href="{{ route('assets.index', ['search' => $location->code]) }}"
       class="inline-flex items-center gap-2 px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
        Buka di Asset Management
    </a>
@endsection

@section('content')

    @php
        // Label status laporan agar konsisten dengan halaman laporan.
        $labelStatus = [
            'needs_review' => ['Perlu Diperiksa', 'bg-amber-50 text-amber-700 border-amber-100'],
            'completed'    => ['Selesai', 'bg-teal-50 text-teal-700 border-teal-100'],
            'continue'     => ['Continue', 'bg-amber-100 text-amber-700 border-amber-200'],
        ];

        // Nama hari & bulan Bahasa Indonesia (locale aplikasi masih 'en').
        $hariId = [
            'Monday' => 'Senin', 'Tuesday' => 'Selasa', 'Wednesday' => 'Rabu',
            'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu', 'Sunday' => 'Minggu',
        ];
        $bulanId = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
            7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ];
    @endphp

    {{-- Navigasi jalur lokasi: PT > Departemen > Area > Section --}}
    <div class="flex flex-wrap items-center gap-1.5 text-xs">
        @foreach($jalur as $node)
            @if(!$loop->first)
                <span class="text-slate-300">/</span>
            @endif
            @if($node->id === $location->id)
                <span class="px-2 py-1 rounded-md bg-teal-50 text-teal-700 border border-teal-100 font-mono font-medium">
                    {{ $node->code }}
                </span>
            @else
                <a href="{{ route('locations.show', $node) }}"
                   class="px-2 py-1 rounded-md bg-white text-slate-600 border border-slate-200 hover:border-teal-300 hover:text-teal-700 font-mono transition-colors">
                    {{ $node->code }}
                </a>
            @endif
        @endforeach
    </div>

    {{-- Statistik lokasi --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl border border-slate-200 px-4 py-3">
            <p class="text-xl font-bold text-slate-800">{{ number_format($statistik['alat']) }}</p>
            <p class="text-xs text-slate-500 mt-0.5">Alat Terdaftar</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 px-4 py-3">
            <p class="text-xl font-bold text-slate-800">{{ number_format($statistik['laporan']) }}</p>
            <p class="text-xs text-slate-500 mt-0.5">Total Laporan</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 px-4 py-3">
            <p class="text-xl font-bold text-teal-600">{{ number_format($statistik['laporan_baru']) }}</p>
            <p class="text-xs text-slate-500 mt-0.5">Laporan 30 Hari</p>
        </div>
        <div class="bg-white rounded-xl border border-teal-200 px-4 py-3">
            <p class="text-xl font-bold text-amber-600">{{ number_format($statistik['belum_selesai']) }}</p>
            <p class="text-xs text-slate-500 mt-0.5">Belum Selesai</p>
        </div>
    </div>

    {{-- Filter: tanggal per-hari / rentang, teknisi, dan pencarian alat --}}
    <div class="bg-white rounded-xl border border-slate-200 p-4">
        <form method="GET" action="{{ route('locations.show', $location) }}"
              class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 items-end">
            <div>
                <label class="block text-xs text-slate-500 mb-1">Tanggal</label>
                <input type="date" name="tanggal" value="{{ $filterAktif['tanggal'] }}"
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Rentang - Dari</label>
                <input type="date" name="date_from" value="{{ $filterAktif['date_from'] }}"
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Rentang - Sampai</label>
                <input type="date" name="date_to" value="{{ $filterAktif['date_to'] }}"
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Teknisi</label>
                <select name="teknisi"
                        class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-teal-500">
                    <option value="">Semua Teknisi</option>
                    @foreach($teknisiOpsi as $opsiTeknisi)
                        <option value="{{ $opsiTeknisi->id }}"
                            {{ (int) $filterAktif['teknisi'] === $opsiTeknisi->id ? 'selected' : '' }}>
                            {{ $opsiTeknisi->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Cari Alat di Lokasi Ini</label>
                <input type="text" name="alat" value="{{ $filterAktif['alat'] }}" placeholder="Kode alat, EQ, atau deskripsi..."
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
            </div>
            <div class="flex flex-wrap items-center gap-2 sm:col-span-2 lg:col-span-5">
                <button type="submit"
                        class="px-4 py-2 bg-teal-600 hover:bg-teal-700 text-white text-sm font-medium rounded-lg transition-colors">
                    Terapkan Filter
                </button>
                <a href="{{ route('locations.show', $location) }}"
                   class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                    Reset
                </a>
                <p class="text-xs text-slate-400">
                    Menampilkan {{ number_format($filterAktif['jumlah_tampil']) }} laporan
                    @if($filterAktif['tanggal'])
                        pada {{ \Illuminate\Support\Carbon::parse($filterAktif['tanggal'])->format('d/m/Y') }}
                    @elseif($filterAktif['date_from'] || $filterAktif['date_to'])
                        pada rentang {{ $filterAktif['date_from'] ?: '-' }} s/d {{ $filterAktif['date_to'] ?: '-' }}
                    @else
                        (seluruh riwayat)
                    @endif
                    serta {{ number_format($statistik['alat']) }} alat pada lokasi ini dan turunannya.
                </p>
            </div>
        </form>
    </div>

    {{-- Informasi lokasi --}}
    <div class="bg-white rounded-xl border border-slate-200 p-6">
        <div class="flex items-start justify-between gap-4 mb-4">
            <div>
                <h3 class="text-base font-semibold text-slate-900">{{ $location->name ?? '(tanpa nama)' }}</h3>
                <p class="text-xs font-mono text-slate-400 mt-0.5">{{ $location->code }}</p>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <span class="inline-flex px-2 py-0.5 rounded-md text-xs font-medium bg-slate-100 text-slate-600 border border-slate-200">
                    {{ $location->level_label }}
                </span>
                @if($location->is_active)
                    <span class="inline-flex px-2 py-0.5 rounded-md text-xs font-medium bg-teal-50 text-teal-700 border border-teal-100">
                        Aktif
                    </span>
                @else
                    <span class="inline-flex px-2 py-0.5 rounded-md text-xs font-medium bg-rose-50 text-rose-600 border border-rose-100">
                        Tidak Aktif
                    </span>
                @endif
            </div>
        </div>

        <dl class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-3 text-sm">
            <div>
                <dt class="text-xs text-slate-400">PT / Site</dt>
                <dd class="text-slate-700">{{ $location->company?->name ?? '-' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-slate-400">Departemen</dt>
                <dd class="text-slate-700">{{ $location->department?->name ?? '-' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-slate-400">Area</dt>
                <dd class="text-slate-700">
                    @if($location->area)
                        {{ $location->area->code }} - {{ $location->area->name }}
                    @else
                        -
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-xs text-slate-400">Sub Area</dt>
                <dd class="text-slate-700">{{ $location->subArea?->name ?? '-' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-slate-400">Lokasi Induk</dt>
                <dd class="text-slate-700">
                    @if($location->parent)
                        <a href="{{ route('locations.show', $location->parent) }}"
                           class="font-mono text-teal-700 hover:underline">{{ $location->parent->code }}</a>
                    @else
                        -
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-xs text-slate-400">Sub Lokasi</dt>
                <dd class="text-slate-700">{{ number_format($statistik['sub_lokasi']) }} node turunan</dd>
            </div>
        </dl>
    </div>

    {{-- Grafik analitik lokasi: tren per bulan + komposisi jenis pekerjaan --}}
    @include('partials._grafik-perbaikan', ['konteks' => 'lokasi ini'])

    {{-- Daftar laporan pada lokasi ini --}}
    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 flex flex-wrap items-center justify-between gap-2">
            <div>
                <h3 class="text-sm font-semibold text-slate-900">Laporan Lokasi Ini</h3>
                <p class="text-xs text-slate-400 mt-0.5">
                    Termasuk laporan perbaikan alat yang berlokasi di sini
                    ({{ number_format($asalLaporan['lokasi']) }} laporan ber-lokasi langsung,
                    {{ number_format($asalLaporan['per_alat']) }} laporan lewat data alat).
                    @if($filterAktif['teknisi'] || $filterAktif['tanggal'] || $filterAktif['date_from'] || $filterAktif['date_to'])
                        &middot; sesuai filter:
                        <span class="font-medium text-teal-700">{{ number_format($filterAktif['jumlah_tampil']) }} laporan</span>
                    @endif
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ $tautanTanggal['sebelumnya'] }}"
                   class="px-2.5 py-1.5 rounded-lg border border-slate-300 hover:bg-slate-50 text-xs font-medium text-slate-600 transition-colors">
                    Hari Sebelumnya
                </a>
                <a href="{{ $tautanTanggal['ini'] }}"
                   class="px-2.5 py-1.5 rounded-lg border border-teal-200 bg-teal-50 hover:bg-teal-100 text-xs font-medium text-teal-700 transition-colors">
                    Hari Ini
                </a>
                <a href="{{ $tautanTanggal['berikutnya'] }}"
                   class="px-2.5 py-1.5 rounded-lg border border-slate-300 hover:bg-slate-50 text-xs font-medium text-slate-600 transition-colors">
                    Hari Berikutnya
                </a>
                @if($tautanTanggal['aktif'])
                    <a href="{{ $tautanTanggal['semua'] }}"
                       class="px-2.5 py-1.5 rounded-lg border border-slate-300 hover:bg-slate-50 text-xs font-medium text-slate-600 transition-colors">
                        Semua Tanggal
                    </a>
                @endif
                <a href="{{ route('reports.index', array_filter([
                       'search'    => $location->code,
                       'date_from' => $rentangLaporan['dari'],
                       'date_to'   => $rentangLaporan['sampai'],
                   ])) }}"
                   class="text-xs font-medium text-teal-700 hover:underline">Lihat semua di halaman Laporan</a>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-100">
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3 whitespace-nowrap">Tanggal</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">Pekerjaan</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3 whitespace-nowrap">Alat</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3 whitespace-nowrap">Teknisi</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3 whitespace-nowrap">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($laporanPerHari as $tanggalHari => $laporanHari)
                        @php
                            $tanggalObj   = $tanggalHari === 'tanpa-tanggal' ? null : \Illuminate\Support\Carbon::parse($tanggalHari);
                            $labelTanggal = $tanggalObj
                                ? ($hariId[$tanggalObj->format('l')] ?? '') . ', ' . $tanggalObj->format('d') . ' '
                                    . ($bulanId[(int) $tanggalObj->format('n')] ?? '') . ' ' . $tanggalObj->format('Y')
                                : 'Tanpa Tanggal';
                            $tautanHari   = route('locations.show', array_filter(array_merge(request()->query(), [
                                'location'     => $location->id,
                                'tanggal'      => $tanggalObj?->toDateString(),
                                'page_laporan' => null,
                            ])));
                        @endphp
                        {{-- Judul hari: pemisah daftar laporan per hari, seperti halaman Laporan --}}
                        <tr class="bg-slate-50/80 border-y border-slate-100">
                            <td colspan="5" class="px-5 py-2">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p class="text-xs font-semibold text-slate-600 uppercase tracking-wide">
                                        {{ $labelTanggal }}
                                    </p>
                                    <span class="flex items-center gap-2 text-xs text-slate-500">
                                        {{ $laporanHari->count() }} laporan
                                        @if($tanggalObj)
                                            <a href="{{ $tautanHari }}" onclick="event.stopPropagation()"
                                               class="text-teal-700 hover:underline">Fokus hari ini</a>
                                        @endif
                                    </span>
                                </div>
                            </td>
                        </tr>
                        @foreach($laporanHari as $report)
                            @php
                                $status = $labelStatus[$report->status] ?? [ucfirst((string) $report->status), 'bg-slate-100 text-slate-500 border-slate-200'];
                            @endphp
                            <tr class="hover:bg-slate-50 transition-colors cursor-pointer"
                                onclick="window.location='{{ route('reports.show', $report) }}'">
                                <td class="px-5 py-3.5 align-top whitespace-nowrap">
                                    <p class="text-xs text-slate-600">{{ $report->report_date?->format('d M Y') ?? '-' }}</p>
                                    @if($report->start_time)
                                        <p class="text-xs text-slate-400 mt-0.5">{{ $report->start_time }}</p>
                                    @endif
                                </td>
                                <td class="px-4 py-3.5 align-top">
                                    <p class="text-slate-800 leading-snug whitespace-normal break-words">
                                        {{ $report->work_description ?: '(tanpa deskripsi pekerjaan)' }}
                                    </p>
                                </td>
                                <td class="px-4 py-3.5 align-top whitespace-nowrap">
                                    @if($report->asset)
                                        <a href="{{ route('assets.show', $report->asset) }}"
                                           onclick="event.stopPropagation()"
                                           class="text-xs font-mono text-teal-700 hover:underline">
                                            {{ $report->asset->tech_ident_no ?? $report->asset->equipment_no ?? ('#' . $report->asset->id) }}
                                        </a>
                                    @elseif($report->functionalLocation)
                                        <span class="text-xs font-mono text-slate-400">{{ $report->functionalLocation->code }}</span>
                                    @else
                                        <span class="text-xs text-slate-300">-</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3.5 align-top whitespace-nowrap">
                                    <p class="text-xs text-slate-700">{{ $report->technician?->name ?? '-' }}</p>
                                    @if($report->collaboratorReports->isNotEmpty())
                                        <button type="button"
                                                onclick="tampilkanKolaborator({{ $report->id }})"
                                                title="Klik untuk melihat laporan para kolaborator"
                                                class="mt-1 inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-semibold bg-teal-50 text-teal-700 border border-teal-100 hover:bg-teal-100 transition-colors">
                                            {{ $report->collaboratorReports->count() }} KOLABORATOR
                                            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                                            </svg>
                                        </button>
                                    @endif
                                </td>
                                <td class="px-4 py-3.5 align-top whitespace-nowrap">
                                    <span class="inline-flex px-2 py-0.5 rounded-md text-xs font-medium border {{ $status[1] }}">
                                        {{ $status[0] }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-10 text-center text-sm text-slate-400">
                                Belum ada laporan pada lokasi ini untuk filter yang dipilih.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($laporan->hasPages())
            <div class="px-5 py-4 border-t border-slate-100">
                {{ $laporan->links() }}
            </div>
        @endif
    </div>

    {{-- Daftar alat pada lokasi ini --}}
    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100">
            <h3 class="text-sm font-semibold text-slate-900">Alat di Lokasi Ini</h3>
            <p class="text-xs text-slate-400 mt-0.5">
                Klik kode alat untuk membuka detail dan riwayat laporannya.
                Menampilkan {{ number_format($statistik['alat']) }} alat
                @if($filterAktif['alat'] !== '')
                    yang cocok dengan "{{ $filterAktif['alat'] }}"
                @endif
                &mdash; pakai kotak "Cari Alat di Lokasi Ini" di atas untuk menyaring daftar ini.
            </p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-100">
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3 whitespace-nowrap">Tech Ident No</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3 whitespace-nowrap">Equipment No</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3">Deskripsi</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3 whitespace-nowrap">Tipe</th>
                        <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-4 py-3 whitespace-nowrap">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    @forelse($alat as $item)
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-5 py-3.5 align-top whitespace-nowrap">
                                <a href="{{ route('assets.show', $item) }}"
                                   class="text-xs font-mono font-medium text-teal-700 hover:underline">
                                    {{ $item->tech_ident_no ?? '(tanpa Tech Ident No)' }}
                                </a>
                            </td>
                            <td class="px-4 py-3.5 align-top whitespace-nowrap font-mono text-xs text-slate-500">
                                {{ $item->equipment_no ?? '-' }}
                            </td>
                            <td class="px-4 py-3.5 align-top">
                                <p class="text-slate-800 leading-snug whitespace-normal break-words">
                                    {{ $item->description ?? '-' }}
                                </p>
                            </td>
                            <td class="px-4 py-3.5 align-top whitespace-nowrap">
                                @if($item->equipment_type)
                                    <span class="text-xs text-slate-600">{{ $item->equipment_type }}</span>
                                @else
                                    <span class="text-xs text-slate-300">TANPA TIPE</span>
                                @endif
                            </td>
                            <td class="px-4 py-3.5 align-top whitespace-nowrap">
                                <span class="text-xs text-slate-600">{{ $item->status ?? '-' }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-10 text-center text-sm text-slate-400">
                                Belum ada alat terdaftar pada lokasi ini.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($alat->hasPages())
            <div class="px-5 py-4 border-t border-slate-100">
                {{ $alat->links() }}
            </div>
        @endif
    </div>

{{-- Modal daftar laporan kolaborator (dibuka tombol "N KOLABORATOR" di kolom Teknisi) --}}
@push('modals')
    <div id="modal-kolaborator" class="hidden fixed inset-0 z-50 items-center justify-center bg-slate-900/50 p-4">
        <div class="bg-white rounded-xl shadow-xl w-full max-w-lg max-h-[80vh] flex flex-col overflow-hidden">
            <div class="flex items-start justify-between gap-4 px-5 py-3.5 border-b border-slate-100">
                <div>
                    <h3 class="text-sm font-semibold text-slate-800">Laporan Kolaborator</h3>
                    <p id="modal-kolaborator-sub" class="text-xs text-slate-400 mt-0.5"></p>
                </div>
                <button type="button" onclick="tutupKolaborator()" title="Tutup"
                        class="text-slate-400 hover:text-slate-600 shrink-0">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <div id="modal-kolaborator-isi" class="px-5 py-4 space-y-2 overflow-y-auto"></div>
            <div class="px-5 py-3 border-t border-slate-100 text-right">
                <button type="button" onclick="tutupKolaborator()"
                        class="px-4 py-1.5 rounded-lg border border-slate-200 text-sm font-medium text-slate-600 hover:bg-slate-50">
                    Tutup
                </button>
            </div>
        </div>
    </div>
@endpush

@push('scripts')
    @include('partials._grafik-perbaikan-script')

    <script>
        // Data laporan kolaborator per laporan induk, dipakai tombol "N KOLABORATOR".
        var dataKolaborator = {!! json_encode($kolaboratorPerLaporan ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!};

        /**
         * Tampilkan popup daftar laporan kolaborator untuk satu laporan.
         * @param {number} idLaporan ID laporan induk
         */
        function tampilkanKolaborator(idLaporan) {
            var daftar = dataKolaborator[idLaporan] || [];
            var modal = document.getElementById('modal-kolaborator');
            var isi = document.getElementById('modal-kolaborator-isi');
            var sub = document.getElementById('modal-kolaborator-sub');

            if (!modal || !isi || !sub) {
                return;
            }

            sub.textContent = daftar.length + ' laporan kolaborator pada pekerjaan ini';
            isi.innerHTML = '';

            daftar.forEach(function (item) {
                var baris = document.createElement('div');
                baris.className = 'flex items-center justify-between gap-3 rounded-lg border border-slate-100 px-3 py-2';

                var kiri = document.createElement('div');
                kiri.className = 'min-w-0';

                var nama = document.createElement('p');
                nama.className = 'text-sm font-semibold text-slate-700 truncate';
                nama.textContent = item.nama;

                var keterangan = document.createElement('p');
                keterangan.className = 'text-xs text-slate-400 truncate';
                keterangan.textContent = item.kode + ' - ' + item.tanggal;

                kiri.appendChild(nama);
                kiri.appendChild(keterangan);

                var tautanDetail = document.createElement('a');
                tautanDetail.href = item.url;
                tautanDetail.className = 'px-3 py-1.5 rounded-lg bg-teal-600 text-white text-xs font-semibold hover:bg-teal-700 shrink-0';
                tautanDetail.textContent = 'Detail';

                baris.appendChild(kiri);
                baris.appendChild(tautanDetail);
                isi.appendChild(baris);
            });

            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        /**
         * Tutup popup daftar laporan kolaborator.
         */
        function tutupKolaborator() {
            var modal = document.getElementById('modal-kolaborator');

            if (!modal) {
                return;
            }

            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        document.addEventListener('keydown', function (peristiwa) {
            if (peristiwa.key === 'Escape') {
                tutupKolaborator();
            }
        });
    </script>
@endpush


@endsection
