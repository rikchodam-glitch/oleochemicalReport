@extends('layouts.app')

@section('title', 'Peminjaman Alat')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Peminjaman Alat</span>
@endsection

@section('content')
<div class="space-y-4">
    @if(session('success'))
        <x-alert type="success">{{ session('success') }}</x-alert>
    @endif

    @if($errors->any())
        <x-alert type="error" title="Periksa kembali isian">
            <ul class="list-disc ml-4 space-y-0.5">
                @foreach($errors->all() as $pesan)
                    <li>{{ $pesan }}</li>
                @endforeach
            </ul>
        </x-alert>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold text-slate-900">Peminjaman Alat</h2>
            <p class="text-xs text-slate-500">
                @if($teknisi)
                    Atas nama {{ $teknisi->name }} (NIK {{ $teknisi->nik ?: '-' }}).
                @elseif(auth()->user()?->isAdmin())
                    Administrator dapat meminjamkan alat dari halaman ini.
                @else
                    Akun ini belum terhubung ke teknisi aktif.
                @endif
                @if($bolehMeminjamkan)
                    Anda dapat meminjamkan alat sebagai tool keeper/administrator.
                @endif
            </p>
        </div>
        @if($teknisi)
            <span class="text-[11px] text-slate-400">Masa pinjam dihitung sejak waktu peminjaman.</span>
        @endif
    </div>

    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-200">
            <h3 class="text-sm font-semibold text-slate-900">Pinjaman Saya</h3>
        </div>

        @if($pinjamanSaya->isEmpty())
            <p class="px-4 py-4 text-sm text-slate-500">
                {{ $teknisi ? 'Tidak ada alat yang sedang Anda pinjam.' : 'Akun ini tidak memiliki pinjaman.' }}
            </p>
        @else
            <div class="divide-y divide-slate-100">
                @foreach($pinjamanSaya as $pinjaman)
                    <div class="px-4 py-3 flex flex-wrap items-center gap-3">
                        <div class="w-12 h-12 rounded-lg bg-slate-100 overflow-hidden shrink-0">
                            @if($pinjaman->tool?->foto_url)
                                <img src="{{ $pinjaman->tool->foto_url }}" alt="{{ $pinjaman->tool_name }}" class="w-full h-full object-cover">
                            @endif
                        </div>
                        <div class="min-w-0 flex-1">
                            <a href="{{ $pinjaman->tool_id ? route('alat.show', $pinjaman->tool_id) : '#' }}"
                               class="text-sm font-semibold text-slate-900 hover:text-teal-700">
                                {{ $pinjaman->tool_name }} ({{ $pinjaman->tool_code }})
                            </a>
                            <p class="text-[11px] text-slate-500">
                                Dipinjam {{ $pinjaman->borrowed_at?->format('d/m/Y H:i') }} &middot;
                                {{ $pinjaman->durasiTeks() }}
                            </p>
                        </div>
                        <form method="POST" action="{{ route('alat.kembali', $pinjaman->id) }}"
                              onsubmit="return confirm('Kembalikan {{ $pinjaman->tool_name }} ({{ $pinjaman->tool_code }})?')">
                            @csrf
                            <button type="submit" class="px-3 py-1.5 rounded-lg bg-teal-600 text-white text-xs font-semibold hover:bg-teal-700">
                                Kembalikan
                            </button>
                        </form>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    @if($bolehMeminjamkan)
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-200 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-slate-900">Pinjaman Aktif (Semua)</h3>
                <span class="text-xs text-slate-500">{{ $pinjamanAktif->count() }} pinjaman</span>
            </div>

            @if($pinjamanAktif->isEmpty())
                <p class="px-4 py-4 text-sm text-slate-500">Tidak ada alat yang sedang dipinjam.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-slate-600">
                            <tr>
                                <th class="text-left px-4 py-2 font-medium">Alat</th>
                                <th class="text-left px-4 py-2 font-medium">Peminjam</th>
                                <th class="text-left px-4 py-2 font-medium">Dipinjam</th>
                                <th class="text-left px-4 py-2 font-medium">Durasi</th>
                                <th class="text-right px-4 py-2 font-medium">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($pinjamanAktif as $pinjaman)
                                <tr class="hover:bg-slate-50">
                                    <td class="px-4 py-2.5">
                                        <a href="{{ $pinjaman->tool_id ? route('alat.show', $pinjaman->tool_id) : '#' }}"
                                           class="font-medium text-slate-800 hover:text-teal-700">
                                            {{ $pinjaman->tool_name }} ({{ $pinjaman->tool_code }})
                                        </a>
                                    </td>
                                    <td class="px-4 py-2.5 text-xs text-slate-600">{{ $pinjaman->peminjamLabel() }}</td>
                                    <td class="px-4 py-2.5 text-xs text-slate-600">{{ $pinjaman->borrowed_at?->format('d/m/Y H:i') }}</td>
                                    <td class="px-4 py-2.5 text-xs text-slate-600">{{ $pinjaman->durasiTeks() }}</td>
                                    <td class="px-4 py-2.5 text-right">
                                        <form method="POST" action="{{ route('alat.kembali', $pinjaman->id) }}"
                                              onsubmit="return confirm('Kembalikan {{ $pinjaman->tool_name }} ({{ $pinjaman->tool_code }})?')">
                                            @csrf
                                            <button type="submit" class="text-xs font-semibold text-teal-700 hover:text-teal-800">
                                                Kembalikan
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif

    <div class="bg-white rounded-xl border border-slate-200 p-4 flex flex-wrap items-end gap-3">
        <div class="flex-1 min-w-[220px]">
            <label for="cari-alat-akun" class="block text-xs font-medium text-slate-600 mb-1">Pencarian</label>
            <input type="search" id="cari-alat-akun" placeholder="Nama atau kode alat..."
                   class="w-full rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
        </div>
        <div>
            <label for="filter-lokasi-akun" class="block text-xs font-medium text-slate-600 mb-1">Lokasi</label>
            <select id="filter-lokasi-akun" class="rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                <option value="">Semua lokasi</option>
                @foreach($daftarLokasi as $lokasi)
                    <option value="{{ $lokasi->id }}">{{ $lokasi->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="filter-rak-akun" class="block text-xs font-medium text-slate-600 mb-1">Rak</label>
            <select id="filter-rak-akun" class="rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                <option value="">Semua rak</option>
                @foreach($daftarRak as $rak)
                    <option value="{{ $rak->id }}" data-lokasi="{{ $rak->tool_location_id }}">{{ $rak->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="filter-status-akun" class="block text-xs font-medium text-slate-600 mb-1">Status</label>
            <select id="filter-status-akun" class="rounded-lg border-slate-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                <option value="">Semua status</option>
                <option value="tersedia">Tersedia</option>
                <option value="dipakai">Dipakai</option>
                <option value="rusak">Rusak</option>
            </select>
        </div>
        <button type="button" id="reset-filter-alat-akun"
                class="px-3 py-2 rounded-lg border border-slate-300 text-sm text-slate-600 hover:bg-slate-50">
            Reset
        </button>
    </div>

    <div class="flex flex-wrap items-center justify-end gap-2">
        <button type="button" data-collapse-all="buka"
                class="px-3 py-1.5 rounded-lg border border-slate-300 text-xs font-semibold text-slate-600 hover:bg-slate-50">
            Buka semua
        </button>
        <button type="button" data-collapse-all="tutup"
                class="px-3 py-1.5 rounded-lg border border-slate-300 text-xs font-semibold text-slate-600 hover:bg-slate-50">
            Tutup semua
        </button>
    </div>

    <div id="wadah-grup-akun" class="space-y-4">
        @forelse($grup as $lokasi)
            <section class="bg-white rounded-xl border border-slate-200 overflow-hidden" data-grup-lokasi="{{ $lokasi['id'] }}">
                <button type="button"
                        class="w-full px-4 py-3 bg-slate-50 flex items-center justify-between text-left hover:bg-slate-100"
                        data-collapse-toggle
                        aria-expanded="true"
                        aria-controls="akun-lokasi-{{ $lokasi['id'] }}">
                    <span class="flex items-center gap-2 text-sm font-semibold text-slate-800">
                        <svg data-collapse-chevron class="w-4 h-4 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                        </svg>
                        {{ $lokasi['name'] }}
                    </span>
                    <span class="text-xs text-slate-500">{{ collect($lokasi['rak'])->sum(fn($rak) => count($rak['tools'])) }} alat</span>
                </button>

                <div id="akun-lokasi-{{ $lokasi['id'] }}" class="px-4 py-3 space-y-4">
                    @foreach($lokasi['rak'] as $rak)
                        <div data-grup-rak="{{ $rak['id'] }}" class="space-y-2">
                            <button type="button"
                                    class="w-full flex items-center justify-between gap-2 text-left"
                                    data-collapse-toggle
                                    aria-expanded="true"
                                    aria-controls="akun-rak-{{ $rak['id'] }}">
                                <span class="flex items-center gap-2">
                                    <svg data-collapse-chevron class="w-3.5 h-3.5 text-slate-400 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                    </svg>
                                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Rak: {{ $rak['name'] }}</span>
                                </span>
                                <span class="text-[11px] text-slate-400">{{ count($rak['tools']) }} alat</span>
                            </button>

                            <div id="akun-rak-{{ $rak['id'] }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
                                @foreach($rak['tools'] as $alat)
                                    @php
                                        $warna = [
                                            'tersedia' => 'bg-green-100 text-green-700',
                                            'dipakai'  => 'bg-amber-100 text-amber-700',
                                            'rusak'    => 'bg-red-100 text-red-700',
                                        ][$alat['status']] ?? 'bg-slate-100 text-slate-600';
                                    @endphp

                                    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden flex flex-col cursor-pointer transition-colors hover:border-teal-300"
                                         data-kartu-tool
                                         data-href-card="{{ route('alat.show', $alat['id']) }}"
                                         tabindex="0"
                                         role="link"
                                         aria-label="Buka detail {{ $alat['name'] }} ({{ $alat['code'] }})"
                                         data-cari="{{ strtolower($alat['name'] . ' ' . $alat['code']) }}"
                                         data-lokasi="{{ $lokasi['id'] }}"
                                         data-rak="{{ $rak['id'] }}"
                                         data-status="{{ $alat['status'] }}">
                                        <div class="relative aspect-square bg-slate-100">
                                            @if($alat['foto_url'])
                                                <img src="{{ $alat['foto_url'] }}" alt="{{ $alat['name'] }}" class="w-full h-full object-cover" loading="lazy">
                                            @else
                                                <div class="w-full h-full flex items-center justify-center text-slate-300">
                                                    <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                                                    </svg>
                                                </div>
                                            @endif
                                            <span class="absolute top-2 left-2 px-2 py-0.5 rounded-full text-[11px] font-semibold {{ $warna }}">
                                                {{ $alat['status_label'] }}
                                            </span>
                                        </div>

                                        <div class="p-3 space-y-2 flex-1 flex flex-col">
                                            <div>
                                                <a href="{{ route('alat.show', $alat['id']) }}"
                                                   class="text-sm font-semibold text-slate-900 hover:text-teal-700">
                                                    {{ $alat['name'] }}
                                                </a>
                                                <p class="text-xs text-slate-500">{{ $alat['code'] }}</p>
                                            </div>

                                            <p class="text-[11px] text-slate-400">{{ $lokasi['name'] }} / {{ $rak['name'] }}</p>

                                            <div class="text-xs text-slate-600 flex-1">
                                                @if($alat['status'] === 'dipakai')
                                                    <p>Dipakai oleh <span class="font-semibold">{{ $alat['peminjam'] ?? '-' }}</span></p>
                                                @elseif($alat['peminjam_terakhir'])
                                                    <p>Peminjam terakhir: <span class="font-medium">{{ $alat['peminjam_terakhir'] }}</span></p>
                                                @else
                                                    <p class="text-slate-400">Belum pernah dipinjam</p>
                                                @endif
                                            </div>

                                            <div class="flex flex-wrap items-center gap-2 pt-2 border-t border-slate-100">
                                                @if($alat['boleh_pinjam'])
                                                    <form method="POST" action="{{ route('alat.pinjam', $alat['id']) }}"
                                                          onsubmit="return confirm('Pinjam {{ $alat['name'] }} ({{ $alat['code'] }}) untuk diri sendiri?')">
                                                        @csrf
                                                        <button type="submit" class="px-2.5 py-1.5 rounded-lg bg-teal-600 text-white text-xs font-semibold hover:bg-teal-700">
                                                            Pinjam
                                                        </button>
                                                    </form>
                                                @endif

                                                @if($alat['boleh_pinjamkan'])
                                                    <button type="button"
                                                            class="tombol-pinjamkan px-2.5 py-1.5 rounded-lg border border-teal-300 text-teal-700 text-xs font-semibold hover:bg-teal-50"
                                                            data-id="{{ $alat['id'] }}"
                                                            data-nama="{{ $alat['name'] }} ({{ $alat['code'] }})">
                                                        Pinjamkan
                                                    </button>
                                                @endif

                                                @if($alat['boleh_kembalikan'])
                                                    <form method="POST" action="{{ route('alat.kembali', $alat['pinjaman_aktif_id']) }}"
                                                          onsubmit="return confirm('Kembalikan {{ $alat['name'] }} ({{ $alat['code'] }})?')">
                                                        @csrf
                                                        <button type="submit" class="px-2.5 py-1.5 rounded-lg bg-amber-500 text-white text-xs font-semibold hover:bg-amber-600">
                                                            Kembalikan
                                                        </button>
                                                    </form>
                                                @endif

                                                <span class="text-[11px] text-slate-400">Klik kartu untuk detail</span>
                                                <svg class="w-4 h-4 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                                </svg>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @empty
            <div class="bg-white rounded-xl border border-slate-200 p-8 text-center text-sm text-slate-500">
                Belum ada alat yang tersimpan.
            </div>
        @endforelse
    </div>

    <p id="pesan-kosong-akun" class="hidden text-center text-sm text-slate-500 py-6">
        Tidak ada alat yang cocok dengan pencarian/filter.
    </p>
</div>

@if($bolehMeminjamkan)
    @include('tools.partials._modal-pinjam', [
        'daftarTeknisi'    => $daftarTeknisi,
        'daftarDepartemen' => $daftarDepartemen,
        'aksiPinjamkan'    => route('alat.pinjamkan'),
    ])
@endif
@endsection

@push('scripts')
@include('tools.partials._collapse-script')
@include('tools.partials._card-klik-script')
<script>
(function () {
    const kartu = Array.from(document.querySelectorAll('[data-kartu-tool]'));
    const grupRak = Array.from(document.querySelectorAll('[data-grup-rak]'));
    const grupLokasi = Array.from(document.querySelectorAll('[data-grup-lokasi]'));
    const cari = document.getElementById('cari-alat-akun');
    const filterLokasi = document.getElementById('filter-lokasi-akun');
    const filterRak = document.getElementById('filter-rak-akun');
    const filterStatus = document.getElementById('filter-status-akun');
    const pesanKosong = document.getElementById('pesan-kosong-akun');

    function saringRak() {
        const lokasi = filterLokasi ? filterLokasi.value : '';
        Array.from(filterRak ? filterRak.options : []).forEach(function (opsi, index) {
            if (index === 0) return;
            const cocok = !lokasi || opsi.dataset.lokasi === lokasi;
            opsi.hidden = !cocok;
            if (!cocok && filterRak.value === opsi.value) filterRak.value = '';
        });
    }

    function terapkan() {
        const q = (cari ? cari.value : '').toLowerCase().trim();
        const lokasi = filterLokasi ? filterLokasi.value : '';
        const rak = filterRak ? filterRak.value : '';
        const status = filterStatus ? filterStatus.value : '';
        let jumlah = 0;

        kartu.forEach(function (el) {
            const cocok = (!q || el.dataset.cari.includes(q))
                && (!lokasi || el.dataset.lokasi === lokasi)
                && (!rak || el.dataset.rak === rak)
                && (!status || el.dataset.status === status);
            el.style.display = cocok ? '' : 'none';
            if (cocok) jumlah++;
        });

        grupRak.forEach(function (el) {
            const ada = Array.from(el.querySelectorAll('[data-kartu-tool]')).some(function (k) {
                return k.style.display !== 'none';
            });
            el.style.display = ada ? '' : 'none';
        });

        grupLokasi.forEach(function (el) {
            const ada = Array.from(el.querySelectorAll('[data-kartu-tool]')).some(function (k) {
                return k.style.display !== 'none';
            });
            el.style.display = ada ? '' : 'none';
        });

        if (pesanKosong) pesanKosong.classList.toggle('hidden', jumlah > 0);

        if ((q || lokasi || rak || status) && typeof window.bukaSemuaCollapse === 'function') {
            window.bukaSemuaCollapse();
        }
    }

    [cari, filterStatus].forEach(function (el) {
        if (el) el.addEventListener('input', terapkan);
    });

    if (filterLokasi) {
        filterLokasi.addEventListener('change', function () {
            saringRak();
            terapkan();
        });
    }

    if (filterRak) filterRak.addEventListener('change', terapkan);

    const reset = document.getElementById('reset-filter-alat-akun');
    if (reset) {
        reset.addEventListener('click', function () {
            if (cari) cari.value = '';
            if (filterLokasi) filterLokasi.value = '';
            if (filterRak) filterRak.value = '';
            if (filterStatus) filterStatus.value = '';
            saringRak();
            terapkan();
        });
    }

    saringRak();
    terapkan();
})();
</script>
@endpush
