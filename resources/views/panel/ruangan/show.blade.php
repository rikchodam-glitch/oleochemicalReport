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
            <p class="text-3xl font-bold mt-1" style="color: {{ $warnaKondisi }};">
                {{ $terakhir?->suhu !== null ? number_format((float) $terakhir->suhu, 1) : '-' }}
                <span class="text-sm font-semibold text-slate-400">C</span>
            </p>
            <p class="text-xs mt-2" style="color: {{ $warnaKondisi }};">
                Kondisi: {{ strtoupper($kondisiSuhu) }}
                (ambang {{ number_format((float) $ruangan->ambang_suhu_bawah, 0) }} s/d
                {{ number_format((float) $ruangan->ambang_suhu_atas, 0) }} C)
            </p>
            <p class="text-xs text-slate-500 mt-2">
                Kelembapan:
                {{ $terakhir?->kelembapan !== null ? number_format((float) $terakhir->kelembapan, 1) . ' %' : 'belum ada' }}
            </p>
            <p class="text-xs text-slate-400 mt-1">
                Pembacaan: {{ $terakhir?->waktu?->format('d/m/Y H:i:s') ?? 'belum ada data' }}
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
                    <dt class="text-slate-500">Status terakhir</dt>
                    <dd class="font-medium">{{ $ruangan->status_terakhir ?: 'belum ditarik' }}</dd>
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
            <p class="text-xs text-slate-400 uppercase tracking-wide font-medium">Lokasi dan baris aset</p>
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
                    <dt class="text-slate-500">Jenis alat</dt>
                    <dd class="font-medium text-right">
                        {{ $asetCermin?->equipment_type ?? ($ruangan->equipment_type ?: 'Panel Monitoring') }}
                    </dd>
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
<script>
(function () {
    var URL_UJI   = '{{ route('panel.ruangan.uji-koneksi', $ruangan) }}';
    var URL_TARIK = '{{ route('panel.ruangan.tarik', $ruangan) }}';
    var elPesan   = document.getElementById('pesanAksi');

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

    var tombolUji   = document.getElementById('tombolUji');
    var tombolTarik = document.getElementById('tombolTarik');

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
})();
</script>
@endpush
