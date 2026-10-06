@extends('layouts.app')

@section('title', 'Detail Ruangan Panel')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('panel.index') }}" class="hover:text-slate-700">Monitoring Ruang Panel</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">{{ $ruangan->nama_ruangan }}</span>
@endsection

@section('content')
    @php
        // Ringkasan keadaan ruangan dipakai beberapa kali di halaman ini.
        $terakhir = $ruangan->pembacaanTerakhir;
        $kondisiSuhu = $ruangan->kondisiSuhu($terakhir?->suhu);
        $warnaKondisi = match ($kondisiSuhu) {
            'tinggi' => '#b91c1c',
            'rendah' => '#b45309',
            default  => '#0b7f72',
        };
        $labelKondisi = match ($kondisiSuhu) {
            'tinggi' => 'TERLALU PANAS',
            'rendah' => 'TERLALU DINGIN',
            'normal' => 'SUHU NORMAL',
            default  => 'BELUM ADA DATA',
        };
        $online = $ruangan->apakahOnline();
    @endphp

    @if (session('success'))
        <div class="mb-4 rounded-xl border px-4 py-3 text-sm"
             style="background-color:#e6f6f3; border-color:#bfe7e1; color:#0b7f72;">
            {{ session('success') }}
        </div>
    @endif

    @if (session('hasil_sinkron'))
        <div class="mb-4 rounded-xl border px-4 py-3 text-sm"
             style="background-color:#eef2ff; border-color:#c7d2fe; color:#3730a3;">
            {{ session('hasil_sinkron') }}
        </div>
    @endif

    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-xl font-semibold text-slate-900">{{ $ruangan->nama_ruangan }}</h1>
                @if ($online)
                    <span class="px-2 py-1 rounded-full text-xs font-semibold"
                          style="background-color:#e6f6f3;color:#0b7f72;">ONLINE</span>
                @else
                    <span class="px-2 py-1 rounded-full text-xs font-semibold"
                          style="background-color:#fdecec;color:#b91c1c;">OFFLINE</span>
                @endif
                @if (! $ruangan->aktif)
                    <span class="px-2 py-1 rounded-full text-xs font-semibold bg-slate-100 text-slate-600">
                        TIDAK DITARIK
                    </span>
                @endif
            </div>
            <p class="text-sm text-slate-500 mt-1">
                {{ $ruangan->labelLokasi() }} &middot; Sumber data: {{ $ruangan->labelProtokol() }}
            </p>
            @if ($ruangan->keterangan)
                <p class="text-xs text-slate-400 mt-1">{{ $ruangan->keterangan }}</p>
            @endif
        </div>

        <div class="flex flex-wrap gap-2">
            <a href="{{ route('panel.ruangan.edit', $ruangan) }}"
               class="px-4 py-2 text-white text-sm font-medium rounded-lg"
               style="background-color:#0E9E8E;">Ubah Pengaturan</a>
            <button type="button" id="tombolUji"
                    class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg">
                Uji Koneksi
            </button>
            <button type="button" id="tombolTarik"
                    class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg">
                Tarik Sekarang
            </button>
            <button type="button" id="tombolPengingat"
                    class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg">
                Kirim Pengingat
            </button>
            <form method="POST" action="{{ route('panel.ruangan.sinkron-aset', $ruangan) }}">
                @csrf
                <button type="submit"
                        class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg">
                    Sinkronkan Aset
                </button>
            </form>
            <a href="{{ route('reports.create') }}"
               class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg">
                Buat Laporan
            </a>
            @if ($asetCermin)
                <a href="{{ route('assets.show', $asetCermin) }}"
                   class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg">
                    Buka Aset
                </a>
            @endif
            <form method="POST" action="{{ route('panel.ruangan.destroy', $ruangan) }}"
                  onsubmit="return confirm('Hapus ruangan panel ini beserta riwayat pembacaannya?');">
                @csrf
                @method('DELETE')
                <button type="submit"
                        class="px-4 py-2 border text-sm font-medium rounded-lg"
                        style="border-color:#f6c9c9; color:#b91c1c; background-color:#fdecec;">
                    Hapus
                </button>
            </form>
        </div>
    </div>

    <div id="pesanAksi" class="hidden mb-6 rounded-xl border px-4 py-3 text-sm"></div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-xl border border-slate-200 p-5">
            <p class="text-xs text-slate-400 uppercase tracking-wide font-medium">Suhu terkini</p>
            <p class="text-3xl font-bold mt-1" id="kartuSuhu" style="color: {{ $warnaKondisi }};">
                {{ $terakhir?->suhu !== null ? number_format((float) $terakhir->suhu, 1) : '-' }}
                <span class="text-sm font-semibold text-slate-400">C</span>
            </p>
            <p class="mt-2">
                <span class="inline-block px-3 py-1 rounded-full text-xs font-semibold" id="lencanaKondisi"
                      style="background-color: {{ $kondisiSuhu === 'tinggi' ? '#fdecec' : ($kondisiSuhu === 'rendah' ? '#fef6e7' : ($kondisiSuhu === 'normal' ? '#e6f6f3' : '#f1f5f9')) }};
                             color: {{ $kondisiSuhu === 'tinggi' ? '#b91c1c' : ($kondisiSuhu === 'rendah' ? '#b45309' : ($kondisiSuhu === 'normal' ? '#0b7f72' : '#475569')) }};">
                    {{ $labelKondisi }}
                </span>
            </p>
            <p class="text-xs text-slate-400 mt-2">
                Ambang {{ number_format((float) $ruangan->ambang_suhu_bawah, 0) }} s/d
                {{ number_format((float) $ruangan->ambang_suhu_atas, 0) }} C
            </p>
            <p class="text-xs text-slate-500 mt-2">
                Kelembapan: <span id="kartuKelembapan">{{ $terakhir?->kelembapan !== null ? number_format((float) $terakhir->kelembapan, 1) . ' %' : 'belum ada' }}</span>
            </p>
            <p class="text-xs text-slate-400 mt-1">
                Pembacaan: <span id="kartuWaktu">{{ $terakhir?->waktu?->format('d/m/Y H:i:s') ?? 'belum ada data' }}</span>
            </p>
        </div>

        <div class="bg-white rounded-xl border border-slate-200 p-5">
            <p class="text-xs text-slate-400 uppercase tracking-wide font-medium">Perangkat</p>
            <dl class="mt-2 space-y-1.5 text-sm text-slate-700">
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">Alamat IP</dt>
                    <dd class="font-medium">{{ $ruangan->ip_address ?: '-' }}:{{ $ruangan->port ?: '-' }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">Port JSON TCP</dt>
                    <dd class="font-medium">{{ $portTcpEfektif }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">Cara baca</dt>
                    <dd class="font-medium text-right">{{ $ruangan->labelProtokol() }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">Alamat MAC</dt>
                    <dd class="font-medium">{{ $ruangan->mac_address ?: '-' }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">Status perangkat</dt>
                    <dd class="font-medium">{{ $online ? 'online' : 'offline' }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">Ditarik terakhir</dt>
                    <dd class="font-medium">{{ $ruangan->terakhir_ditarik?->format('d/m/Y H:i:s') ?? '-' }}</dd>
                </div>
            </dl>
            @if ($ruangan->catatan)
                <p class="text-xs mt-3" style="color:#b45309;">{{ $ruangan->catatan }}</p>
            @endif
        </div>

        <div class="bg-white rounded-xl border border-slate-200 p-5">
            <p class="text-xs text-slate-400 uppercase tracking-wide font-medium">Lokasi, aset, dan penanggung jawab</p>
            <dl class="mt-2 space-y-1.5 text-sm text-slate-700">
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">Functional Location</dt>
                    <dd class="font-medium text-right">{{ $ruangan->funcloc?->code ?? 'belum dipilih' }}</dd>
                </div>
                @if ($ruangan->funcloc)
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500">Nama lokasi</dt>
                        <dd class="font-medium text-right">{{ $ruangan->funcloc->name }}</dd>
                    </div>
                @endif
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">Kode alat</dt>
                    <dd class="font-medium">{{ $ruangan->kode_alat ?: 'belum diisi' }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">Nomor equipment</dt>
                    <dd class="font-medium">{{ $asetCermin?->equipment_no ?? 'belum dibuat' }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-slate-500">Penanggung jawab</dt>
                    <dd class="font-medium text-right">{{ $ruangan->namaPenanggungJawab() }}</dd>
                </div>
            </dl>

            @if ($asetCermin)
                <p class="text-xs mt-3 text-slate-500">
                    Baris aset ini dipakai laporan sebagai equipment
                    "{{ $asetCermin->description }}".
                </p>
            @elseif ($alasanLewat)
                <p class="text-xs mt-3" style="color:#8a5a10;">{{ $alasanLewat }}</p>
                <a href="{{ route('panel.ruangan.edit', $ruangan) }}"
                   class="text-xs underline mt-1 inline-block" style="color:#0b7f72;">
                    Lengkapi pengaturan ruangan
                </a>
            @endif
        </div>
    </div>


    {{-- Grafik realtime: diberi data berkala dari endpoint panel.ruangan.data --}}
    <div class="bg-white rounded-xl border border-slate-200 p-5 mb-6">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
            <div>
                <h3 class="text-sm font-semibold text-slate-900">Grafik realtime</h3>
                <p class="text-xs text-slate-500 mt-1">
                    {{ $titikGrafik }} pembacaan terakhir, diperbarui otomatis tiap
                    {{ $intervalPolling }} detik (sumber: {{ $ruangan->labelProtokol() }}).
                </p>
            </div>
            <p class="text-xs text-slate-400" id="statusGrafik">Menunggu data pertama...</p>
        </div>
        <div style="position: relative; height: 320px;">
            <canvas id="grafikRuangan"></canvas>
        </div>
    </div>

    {{-- Rekap per hari, bisa diunduh sebagai berkas Excel --}}
    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden mb-6">
        <div class="px-6 py-4 border-b border-slate-100 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h3 class="text-sm font-semibold text-slate-900">Riwayat harian</h3>
                <p class="text-xs text-slate-500 mt-1">
                    Periode {{ $labelPeriode }} &middot;
                    {{ number_format((int) $ringkasanHarian['jumlah_hari']) }} hari berisi data,
                    {{ number_format((int) $ringkasanHarian['jumlah_pembacaan']) }} pembacaan
                    ({{ number_format((int) $ringkasanHarian['jumlah_panas']) }} terlalu panas,
                    {{ number_format((int) $ringkasanHarian['jumlah_dingin']) }} terlalu dingin).
                </p>
            </div>
            <div class="flex flex-wrap items-end gap-2">
                <form method="GET" action="{{ route('panel.ruangan.show', $ruangan) }}" class="flex items-end gap-2">
                    <div>
                        <label class="block text-[11px] text-slate-400 mb-1">Bulan</label>
                        <input type="month" name="bulan" value="{{ $bulanTerpilih }}"
                               class="border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-teal-500">
                    </div>
                    <button type="submit"
                            class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg">
                        Tampilkan
                    </button>
                </form>
                <a href="{{ route('panel.ruangan.export-harian', ['panelRuangan' => $ruangan, 'bulan' => $bulanTerpilih]) }}"
                   class="px-4 py-2 text-white text-sm font-medium rounded-lg"
                   style="background-color:#0E9E8E;">
                    Ekspor Excel
                </a>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-slate-500 uppercase tracking-wide border-b border-slate-200">
                        <th class="py-2 pl-6 pr-4">Tanggal</th>
                        <th class="py-2 pr-4">Pembacaan</th>
                        <th class="py-2 pr-4">Suhu min</th>
                        <th class="py-2 pr-4">Suhu max</th>
                        <th class="py-2 pr-4">Suhu rata</th>
                        <th class="py-2 pr-4">Kelembapan min</th>
                        <th class="py-2 pr-4">Kelembapan max</th>
                        <th class="py-2 pr-4">Kelembapan rata</th>
                        <th class="py-2 pr-4">Terlalu panas</th>
                        <th class="py-2 pr-4">Terlalu dingin</th>
                        <th class="py-2 pr-6">Keterangan</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($harian as $rekap)
                        <tr class="border-b border-slate-100">
                            <td class="py-2 pl-6 pr-4 text-slate-700">
                                {{ \Illuminate\Support\Carbon::parse($rekap['tanggal'])->translatedFormat('d/m/Y (D)') }}
                            </td>
                            <td class="py-2 pr-4">{{ number_format((int) $rekap['jumlah']) }}</td>
                            <td class="py-2 pr-4">{{ $rekap['suhu_min'] !== null ? number_format((float) $rekap['suhu_min'], 2) : '-' }}</td>
                            <td class="py-2 pr-4">{{ $rekap['suhu_max'] !== null ? number_format((float) $rekap['suhu_max'], 2) : '-' }}</td>
                            <td class="py-2 pr-4">{{ $rekap['suhu_rata'] !== null ? number_format((float) $rekap['suhu_rata'], 2) : '-' }}</td>
                            <td class="py-2 pr-4">{{ $rekap['kelembapan_min'] !== null ? number_format((float) $rekap['kelembapan_min'], 2) : '-' }}</td>
                            <td class="py-2 pr-4">{{ $rekap['kelembapan_max'] !== null ? number_format((float) $rekap['kelembapan_max'], 2) : '-' }}</td>
                            <td class="py-2 pr-4">{{ $rekap['kelembapan_rata'] !== null ? number_format((float) $rekap['kelembapan_rata'], 2) : '-' }}</td>
                            <td class="py-2 pr-4">{{ number_format((int) $rekap['jumlah_panas']) }}</td>
                            <td class="py-2 pr-4">{{ number_format((int) $rekap['jumlah_dingin']) }}</td>
                            <td class="py-2 pr-6 {{ ((int) $rekap['jumlah_panas'] > 0 || (int) $rekap['jumlah_dingin'] > 0) ? '' : 'text-slate-500' }}"
                                @if ((int) $rekap['jumlah_panas'] > 0 || (int) $rekap['jumlah_dingin'] > 0) style="color:#b45309;" @endif>
                                {{ $rekap['keterangan'] }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="11" class="py-3 pl-6 pr-6 text-slate-400">
                                Belum ada pembacaan tersimpan pada periode ini.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 flex flex-wrap items-center justify-between gap-2">
            <h3 class="text-sm font-semibold text-slate-900">
                Riwayat pembacaan terakhir ({{ $riwayat->count() }} baris)
            </h3>
            <a href="{{ route('panel.index') }}" class="text-xs underline" style="color:#0b7f72;">
                Kembali ke dashboard panel
            </a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-slate-500 uppercase tracking-wide border-b border-slate-200">
                        <th class="py-2 pl-6 pr-4">Waktu pengukuran</th>
                        <th class="py-2 pr-4">Suhu (C)</th>
                        <th class="py-2 pr-4">Kelembapan (%)</th>
                        <th class="py-2 pr-4">Sumber</th>
                        <th class="py-2 pr-6">Valid</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($riwayat as $baris)
                        <tr class="border-b border-slate-100">
                            <td class="py-2 pl-6 pr-4 text-slate-700">
                                {{ $baris->waktu?->format('d/m/Y H:i:s') ?? '-' }}
                            </td>
                            <td class="py-2 pr-4">
                                {{ $baris->suhu !== null ? number_format((float) $baris->suhu, 2) : '-' }}
                            </td>
                            <td class="py-2 pr-4">
                                {{ $baris->kelembapan !== null ? number_format((float) $baris->kelembapan, 2) : '-' }}
                            </td>
                            <td class="py-2 pr-4 text-slate-500">{{ $baris->sumber ?: '-' }}</td>
                            <td class="py-2 pr-6">{{ $baris->valid ? 'ya' : 'tidak' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-3 pl-6 pr-6 text-slate-400">
                                Belum ada pembacaan tersimpan untuk ruangan ini.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection


@push('scripts')
<script src="{{ asset('vendor/chart.umd.min.js') }}"></script>
<script>
(function () {
    'use strict';

    var URL_UJI    = '{{ route('panel.ruangan.uji-koneksi', $ruangan) }}';
    var URL_TARIK  = '{{ route('panel.ruangan.tarik', $ruangan) }}';
    var URL_PENGINGAT = '{{ route('panel.ruangan.kirim-pengingat', $ruangan) }}';
    var URL_DATA   = '{{ route('panel.ruangan.data', $ruangan) }}';
    var INTERVAL_MS = {{ $intervalPolling }} * 1000;

    var elPesan        = document.getElementById('pesanAksi');
    var elStatusGrafik = document.getElementById('statusGrafik');
    var grafik         = null;

    /**
     * Tampilkan pesan hasil aksi (uji koneksi atau tarik data).
     *
     * @param  {string}  pesan
     * @param  {boolean} berhasil
     * @return {void}
     */
    function tampilkanPesan(pesan, berhasil) {
        if (elPesan === null) {
            return;
        }

        elPesan.textContent = pesan;
        elPesan.classList.remove('hidden');
        elPesan.setAttribute('style', berhasil
            ? 'background-color:#e6f6f3; border-color:#bfe7e1; color:#0b7f72;'
            : 'background-color:#fdecec; border-color:#f6c9c9; color:#b91c1c;');
    }

    /**
     * Kirim aksi POST ke server lalu tampilkan balasannya di halaman.
     *
     * @param  {string} url
     * @return {void}
     */
    function kirimAksi(url) {
        fetch(url, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json'
            }
        })
            .then(function (balasan) {
                return balasan.json();
            })
            .then(function (hasil) {
                tampilkanPesan(hasil.pesan || 'Selesai.', (hasil.status || '') === 'ok');
            })
            .catch(function (galat) {
                tampilkanPesan('Gagal menghubungi server: ' + galat.message, false);
            });
    }

    /**
     * Ubah angka menjadi teks dengan jumlah desimal tertentu.
     *
     * @param  {number|null} nilai
     * @param  {number} desimal
     * @return {string}
     */
    function formatAngka(nilai, desimal) {
        if (nilai === null || nilai === undefined) {
            return '-';
        }

        return Number(nilai).toFixed(desimal);
    }

    /**
     * Warna teks untuk tiap kondisi suhu.
     *
     * @param  {string} kondisi
     * @return {string}
     */
    function warnaKondisi(kondisi) {
        if (kondisi === 'tinggi') {
            return '#b91c1c';
        }
        if (kondisi === 'rendah') {
            return '#b45309';
        }
        if (kondisi === 'normal') {
            return '#0E9E8E';
        }

        return '#64748b';
    }

    /**
     * Teks lencana indikator suhu.
     *
     * @param  {string} kondisi
     * @return {string}
     */
    function labelKondisi(kondisi) {
        if (kondisi === 'tinggi') {
            return 'TERLALU PANAS';
        }
        if (kondisi === 'rendah') {
            return 'TERLALU DINGIN';
        }
        if (kondisi === 'normal') {
            return 'SUHU NORMAL';
        }

        return 'BELUM ADA DATA';
    }

    /**
     * Gaya lencana indikator suhu (latar dan warna tulisan).
     *
     * @param  {string} kondisi
     * @return {string}
     */
    function gayaLencana(kondisi) {
        var dasar = 'display:inline-block;padding:4px 12px;border-radius:9999px;font-size:11px;font-weight:600;';

        if (kondisi === 'tinggi') {
            return dasar + 'background-color:#fdecec;color:#b91c1c;';
        }
        if (kondisi === 'rendah') {
            return dasar + 'background-color:#fef6e7;color:#b45309;';
        }
        if (kondisi === 'normal') {
            return dasar + 'background-color:#e6f6f3;color:#0b7f72;';
        }

        return dasar + 'background-color:#f1f5f9;color:#475569;';
    }


    /**
     * Siapkan objek grafik Chart.js untuk suhu dan kelembapan.
     *
     * @return {void}
     */
    function siapkanGrafik() {
        var kanvas = document.getElementById('grafikRuangan');

        if (kanvas === null || typeof window.Chart !== 'function') {
            return;
        }

        grafik = new window.Chart(kanvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: [],
                datasets: [
                    {
                        label: 'Suhu (C)',
                        data: [],
                        borderColor: '#e07a5f',
                        backgroundColor: 'rgba(224, 122, 95, 0.12)',
                        borderWidth: 2,
                        pointRadius: 0,
                        tension: 0.3,
                        fill: true,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Kelembapan (%)',
                        data: [],
                        borderColor: '#0E9E8E',
                        backgroundColor: 'rgba(14, 158, 142, 0.12)',
                        borderWidth: 2,
                        pointRadius: 0,
                        tension: 0.3,
                        fill: true,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { position: 'bottom' } },
                scales: {
                    x: { ticks: { maxTicksLimit: 8, autoSkip: true } },
                    y: {
                        position: 'left',
                        title: { display: true, text: 'Suhu (C)' },
                        grid: { color: '#eef2f7' }
                    },
                    y1: {
                        position: 'right',
                        title: { display: true, text: 'Kelembapan (%)' },
                        grid: { drawOnChartArea: false }
                    }
                }
            }
        });
    }

    /**
     * Perbarui isi grafik dengan titik data terbaru ruangan ini.
     *
     * @param  {Array} titik
     * @return {void}
     */
    function perbaruiGrafik(titik) {
        if (grafik === null) {
            return;
        }

        var label = [];
        var suhu = [];
        var kelembapan = [];

        for (var i = 0; i < titik.length; i++) {
            label.push(titik[i].waktu);
            suhu.push(titik[i].suhu);
            kelembapan.push(titik[i].kelembapan);
        }

        grafik.data.labels = label;
        grafik.data.datasets[0].data = suhu;
        grafik.data.datasets[1].data = kelembapan;
        grafik.update('none');
    }

    /**
     * Perbarui kartu ringkasan dan grafik dari data satu ruangan.
     *
     * @param  {object} ruangan
     * @return {void}
     */
    function perbaruiTampilan(ruangan) {
        var warna = warnaKondisi(ruangan.kondisi);
        var kartuSuhu = document.getElementById('kartuSuhu');
        var kartuKelembapan = document.getElementById('kartuKelembapan');
        var kartuWaktu = document.getElementById('kartuWaktu');
        var lencana = document.getElementById('lencanaKondisi');

        if (kartuSuhu !== null) {
            kartuSuhu.innerHTML = formatAngka(ruangan.suhu, 1)
                + '<span class="text-sm font-semibold text-slate-400"> C</span>';
            kartuSuhu.style.color = warna;
        }

        if (kartuKelembapan !== null) {
            kartuKelembapan.textContent = (ruangan.kelembapan === null || ruangan.kelembapan === undefined)
                ? 'belum ada'
                : formatAngka(ruangan.kelembapan, 1) + ' %';
        }

        if (kartuWaktu !== null) {
            kartuWaktu.textContent = ruangan.waktu_pembacaan || 'belum ada data';
        }

        if (lencana !== null) {
            lencana.textContent = labelKondisi(ruangan.kondisi);
            lencana.setAttribute('style', gayaLencana(ruangan.kondisi));
        }

        if (grafik === null) {
            siapkanGrafik();
        }

        perbaruiGrafik(ruangan.grafik || []);
    }

    /**
     * Ambil data terbaru ruangan ini dari server lalu perbarui tampilan.
     *
     * @return {void}
     */
    function ambilData() {
        fetch(URL_DATA, { headers: { 'Accept': 'application/json' } })
            .then(function (balasan) {
                if (!balasan.ok) {
                    throw new Error('HTTP ' + balasan.status);
                }

                return balasan.json();
            })
            .then(function (hasil) {
                perbaruiTampilan(hasil.ruangan || {});

                if (elStatusGrafik !== null) {
                    elStatusGrafik.textContent = 'Diperbarui '
                        + new Date().toLocaleTimeString('id-ID')
                        + ' (waktu server ' + (hasil.waktu_server || '-') + ')';
                }
            })
            .catch(function (galat) {
                if (elStatusGrafik !== null) {
                    elStatusGrafik.textContent = 'Gagal mengambil data: ' + galat.message
                        + '. Halaman akan mencoba lagi otomatis.';
                }
            });
    }

    var tombolUji   = document.getElementById('tombolUji');
    var tombolTarik = document.getElementById('tombolTarik');
    var tombolPengingat = document.getElementById('tombolPengingat');

    if (tombolUji !== null) {
        tombolUji.addEventListener('click', function () {
            kirimAksi(URL_UJI);
        });
    }

    if (tombolTarik !== null) {
        tombolTarik.addEventListener('click', function () {
            kirimAksi(URL_TARIK);
        });
    }

    if (tombolPengingat !== null) {
        tombolPengingat.addEventListener('click', function () {
            kirimAksi(URL_PENGINGAT);
        });
    }

    ambilData();
    setInterval(ambilData, INTERVAL_MS);
})();
</script>
@endpush

