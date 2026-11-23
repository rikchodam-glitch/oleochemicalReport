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
        @if($bolehMeminjamkan)
            <a href="{{ route('alat.returns.index') }}"
               class="px-3 py-2 rounded-lg border border-amber-300 bg-amber-50 text-amber-800 text-sm font-semibold hover:bg-amber-100">
                Barang Kembali ({{ $jumlahKembali }})
            </a>
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
                                {{ $pinjaman->tool?->group?->name ?? $pinjaman->tool_name }} ({{ $pinjaman->tool_code }})
                            </a>
                            <p class="text-[11px] text-slate-500">
                                Dipinjam {{ $pinjaman->borrowed_at?->format('d/m/Y H:i') }} &middot;
                                {{ $pinjaman->durasiTeks() }}
                            </p>
                        </div>
                        @if($pinjaman->menungguPenerimaan())
                            <span class="px-2 py-1 rounded-md bg-amber-100 text-amber-700 text-[11px] font-semibold">
                                Menunggu diterima keeper
                            </span>
                        @else
                            <form method="POST" action="{{ route('alat.kembali', $pinjaman->id) }}"
                                  onsubmit="return confirm('Ajukan pengembalian {{ $pinjaman->tool_name }} ({{ $pinjaman->tool_code }})?')">
                                @csrf
                                <button type="submit" class="px-3 py-1.5 rounded-lg bg-amber-500 text-white text-xs font-semibold hover:bg-amber-600">
                                    Kembalikan
                                </button>
                            </form>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    @if($bolehMeminjamkan && $pinjamanAktif->isNotEmpty())
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-200 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-slate-900">Pinjaman Aktif (Semua)</h3>
                <a href="{{ route('alat.returns.index') }}" class="text-xs font-semibold text-teal-700 hover:text-teal-800">
                    Buka Barang Kembali
                </a>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-slate-600">
                        <tr>
                            <th class="text-left px-4 py-2 font-medium">Alat</th>
                            <th class="text-left px-4 py-2 font-medium">Peminjam</th>
                            <th class="text-left px-4 py-2 font-medium">Dipinjam</th>
                            <th class="text-left px-4 py-2 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($pinjamanAktif as $pinjaman)
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-2.5">
                                    <a href="{{ $pinjaman->tool_id ? route('alat.show', $pinjaman->tool_id) : '#' }}"
                                       class="font-medium text-slate-800 hover:text-teal-700">
                                        {{ $pinjaman->tool?->group?->name ?? $pinjaman->tool_name }} ({{ $pinjaman->tool_code }})
                                    </a>
                                </td>
                                <td class="px-4 py-2.5 text-xs text-slate-600">{{ $pinjaman->peminjamLabel() }}</td>
                                <td class="px-4 py-2.5 text-xs text-slate-600">{{ $pinjaman->borrowed_at?->format('d/m/Y H:i') }}</td>
                                <td class="px-4 py-2.5">
                                    @if($pinjaman->menungguPenerimaan())
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-100 text-amber-700">Menunggu diterima</span>
                                    @else
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-blue-100 text-blue-700">Dipinjam</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @include('partials._pencarian-entitas', [
        'aksi'              => route('alat.index'),
        'saranUrl'          => route('alat.cari'),
        'idInput'           => 'alatSearch',
        'placeholder'       => 'Cari kelompok, kode unit, jenis, lokasi, atau rak...',
        'kataKunci'         => $kataKunci,
        'total'             => $daftar->total(),
        'satuan'            => 'alat',
        'petunjuk'          => 'Pencarian mencakup kelompok, kode, jenis, lokasi, dan rak.',
        'filterTersembunyi' => array_filter([
            'lokasi' => $filter['lokasi'],
            'rak'    => $filter['rak'],
            'jenis'  => $filter['jenis'],
            'grup'   => $filter['grup'],
            'status' => $filter['status'],
        ]),
    ])

    <form method="GET" action="{{ route('alat.index') }}" class="bg-white rounded-xl border border-slate-200 p-3 flex flex-wrap items-end gap-2">
        @if($kataKunci !== '')
            <input type="hidden" name="search" value="{{ $kataKunci }}">
        @endif
        <div>
            <label class="block text-[11px] font-medium text-slate-600 mb-1">Lokasi</label>
            <select name="lokasi" onchange="this.form.requestSubmit()" class="rounded-lg border-slate-300 text-xs focus:border-teal-500 focus:ring-teal-500">
                <option value="">Semua lokasi</option>
                @foreach($daftarLokasi as $lokasi)
                    <option value="{{ $lokasi->id }}" @selected((string) $filter['lokasi'] === (string) $lokasi->id)>{{ $lokasi->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-[11px] font-medium text-slate-600 mb-1">Rak</label>
            <select name="rak" onchange="this.form.requestSubmit()" class="rounded-lg border-slate-300 text-xs focus:border-teal-500 focus:ring-teal-500">
                <option value="">Semua rak</option>
                @foreach($daftarRak->groupBy(fn($rak) => $rak->location?->name ?? 'Tanpa Lokasi') as $namaLokasi => $rakLokasi)
                    <optgroup label="{{ $namaLokasi }}">
                        @foreach($rakLokasi as $rak)
                            <option value="{{ $rak->id }}" @selected((string) $filter['rak'] === (string) $rak->id)>{{ $rak->name }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-[11px] font-medium text-slate-600 mb-1">Jenis</label>
            <select name="jenis" onchange="this.form.requestSubmit()" class="rounded-lg border-slate-300 text-xs focus:border-teal-500 focus:ring-teal-500">
                <option value="">Semua jenis</option>
                @foreach($daftarJenis as $jenis)
                    <option value="{{ $jenis->id }}" @selected((string) $filter['jenis'] === (string) $jenis->id)>{{ $jenis->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-[11px] font-medium text-slate-600 mb-1">Kelompok</label>
            <select name="grup" onchange="this.form.requestSubmit()" class="rounded-lg border-slate-300 text-xs focus:border-teal-500 focus:ring-teal-500">
                <option value="">Semua kelompok</option>
                @foreach($daftarKelompok as $kelompok)
                    <option value="{{ $kelompok->id }}" @selected((string) $filter['grup'] === (string) $kelompok->id)>{{ $kelompok->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-[11px] font-medium text-slate-600 mb-1">Status</label>
            <select name="status" onchange="this.form.requestSubmit()" class="rounded-lg border-slate-300 text-xs focus:border-teal-500 focus:ring-teal-500">
                <option value="">Semua status</option>
                <option value="tersedia" @selected($filter['status'] === 'tersedia')>Tersedia</option>
                <option value="dipakai" @selected($filter['status'] === 'dipakai')>Dipakai</option>
                <option value="rusak" @selected($filter['status'] === 'rusak')>Rusak</option>
            </select>
        </div>
        <a href="{{ route('alat.index', $kataKunci !== '' ? ['search' => $kataKunci] : []) }}"
           class="px-3 py-2 rounded-lg border border-slate-300 text-xs text-slate-600 hover:bg-slate-50">Reset filter</a>
        <span class="ml-auto text-[11px] text-slate-400">{{ $daftar->total() }} alat</span>
    </form>

    <div class="flex flex-wrap items-center justify-end gap-2">
        <button type="button" data-collapse-all="buka" class="px-3 py-1.5 rounded-lg border border-slate-300 text-xs font-semibold text-slate-600 hover:bg-slate-50">Buka semua</button>
        <button type="button" data-collapse-all="tutup" class="px-3 py-1.5 rounded-lg border border-slate-300 text-xs font-semibold text-slate-600 hover:bg-slate-50">Tutup semua</button>
    </div>

    <div class="space-y-3">
        @forelse($grup as $lokasi)
            <section class="bg-white rounded-xl border border-slate-200 overflow-hidden" data-grup-lokasi="{{ $lokasi['id'] }}">
                <button type="button" class="w-full px-3 py-2.5 bg-slate-50 flex items-center justify-between text-left hover:bg-slate-100"
                        data-collapse-toggle aria-expanded="true" aria-controls="akun-lokasi-{{ $lokasi['id'] }}">
                    <span class="flex items-center gap-2 text-sm font-semibold text-slate-800">
                        <svg data-collapse-chevron class="w-4 h-4 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                        </svg>
                        {{ $lokasi['name'] }}
                    </span>
                    <span class="text-xs text-slate-500">{{ collect($lokasi['rak'])->sum(fn($rak) => count($rak['tools'])) }} alat</span>
                </button>

                <div id="akun-lokasi-{{ $lokasi['id'] }}" class="px-3 py-2.5 space-y-3">
                    @foreach($lokasi['rak'] as $rak)
                        <div data-grup-rak="{{ $rak['id'] }}" class="space-y-1.5">
                            <button type="button" class="w-full flex items-center justify-between gap-2 text-left"
                                    data-collapse-toggle aria-expanded="true" aria-controls="akun-rak-{{ $rak['id'] }}">
                                <span class="flex items-center gap-2">
                                    <svg data-collapse-chevron class="w-3.5 h-3.5 text-slate-400 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                    </svg>
                                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Rak: {{ $rak['name'] }}</span>
                                </span>
                                <span class="text-[11px] text-slate-400">{{ count($rak['tools']) }} alat</span>
                            </button>

                            <div id="akun-rak-{{ $rak['id'] }}" class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-2">
                                @foreach($rak['tools'] as $alat)
                                    @include('tools.partials._kartu-ringkas', [
                                        'alat' => $alat,
                                        'mode' => 'alat',
                                        'bolehTulis' => false,
                                        'lokasiName' => $lokasi['name'],
                                        'rakName' => $rak['name'],
                                        'loopLokasiId' => $lokasi['id'],
                                        'loopRakId' => $rak['id'],
                                    ])
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @empty
            <div class="bg-white rounded-xl border border-slate-200 p-8 text-center text-sm text-slate-500">
                Tidak ada alat yang cocok dengan pencarian/filter.
            </div>
        @endforelse
    </div>

    @if($daftar->hasPages())
        <div class="bg-white rounded-xl border border-slate-200 px-4 py-3">{{ $daftar->links() }}</div>
    @endif
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
@endpush
