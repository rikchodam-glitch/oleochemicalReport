@extends('layouts.app')

@section('title', 'Hak Akses Bagian')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Hak Akses Bagian</span>
@endsection

@section('content')
    <div class="bg-white rounded-xl border border-slate-200 p-4 mb-6">
        <p class="text-sm text-slate-600">
            Halaman yang boleh dibuka diatur per Bagian, dan bisa dipersempit lagi per Group
            (Reguler, Reguler Staff, Grub A sampai Grub D, dan Staff Grub A sampai Staff Grub D). Jadi akses Bagian Mekanik Group Reguler
            bisa berbeda dari Mekanik Group Reguler Staff. Akun dengan role
            <span class="font-medium text-slate-800">Administrator</span> dan
            <span class="font-medium text-slate-800">Supervisor</span> selalu bisa membuka semua halaman
            ({{ number_format($jumlahAkunAksesPenuh) }} akun), jadi tidak diatur di sini.
        </p>
        <p class="text-xs text-slate-400 mt-2">
            Tab "Bawaan Bagian" berlaku untuk semua Group yang belum diatur sendiri dan untuk akun yang
            tidak terhubung data teknisi. Bawaannya seluruh halaman diizinkan, centang/hapus centang untuk
            mengubahnya. Penyembunyian menu di panel kiri mengikuti centang ini juga.
        </p>
    </div>

    <div class="flex flex-wrap gap-2 mb-4">
        @foreach($daftarBagian as $data)
            <a href="{{ route('akses.index', ['bagian' => $data['kode']]) }}"
               class="px-3 py-1.5 rounded-lg text-sm font-medium border transition-colors
                      {{ $bagianTerbuka === $data['kode'] ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50' }}">
                {{ $data['label'] }}
                <span class="text-xs {{ $bagianTerbuka === $data['kode'] ? 'text-white/80' : 'text-slate-400' }}">
                    ({{ $data['jumlah_akun'] }} akun)
                </span>
            </a>
        @endforeach
    </div>

    @php
        $bagianAktif = collect($daftarBagian)->firstWhere('kode', $bagianTerbuka) ?? ($daftarBagian[0] ?? null);
        $grupAktif   = collect($daftarGrup)->firstWhere('aktif', true) ?? ($daftarGrup[0] ?? null);
    @endphp

    @if($bagianAktif !== null && $grupAktif !== null)
        <form method="POST" action="{{ route('akses.update', $bagianAktif['kode']) }}" class="space-y-4">
            @csrf
            <input type="hidden" name="grup" value="{{ $grupAktif['kode'] ?? '' }}">

            <div class="bg-white rounded-xl border border-slate-200 p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h3 class="text-sm font-semibold text-slate-900">
                            Hak Akses Bagian {{ $bagianAktif['label'] }}
                        </h3>
                        <p class="text-xs text-slate-400 mt-1">
                            {{ $bagianAktif['jumlah_akun'] }} akun terdaftar,
                            {{ $bagianAktif['jumlah_teknisi'] }} teknisi terdaftar di Bagian ini.
                            @if(! $bagianAktif['tersimpan'])
                                Hak akses bawaan Bagian ini belum pernah diatur, untuk sementara seluruh
                                halaman diizinkan.
                            @endif
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" onclick="pilihSemuaHakAkses(true)"
                                class="px-3 py-1.5 rounded-lg border border-slate-200 text-xs font-medium text-slate-600 hover:bg-slate-50">
                            Pilih Semua
                        </button>
                        <button type="button" onclick="pilihSemuaHakAkses(false)"
                                class="px-3 py-1.5 rounded-lg border border-slate-200 text-xs font-medium text-slate-600 hover:bg-slate-50">
                            Kosongkan
                        </button>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2 mt-4">
                    @foreach($daftarGrup as $data)
                        <a href="{{ route('akses.index', ['bagian' => $bagianTerbuka, 'grup' => $data['kode']]) }}"
                           class="px-3 py-1.5 rounded-lg text-xs font-medium border transition-colors
                                  {{ $grupAktif['kode'] === $data['kode'] ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50' }}">
                            {{ $data['label'] }}
                            <span class="{{ $grupAktif['kode'] === $data['kode'] ? 'text-white/80' : 'text-slate-400' }}">
                                ({{ $data['jumlah_akun'] }} akun)
                            </span>
                        </a>
                    @endforeach
                </div>

                <p class="text-xs text-slate-500 mt-4">
                    @if($grupAktif['kode'] === null)
                        Pengaturan bawaan Bagian ini berlaku untuk semua Group yang belum diatur sendiri
                        dan untuk akun yang tidak terhubung data teknisi.
                    @elseif($grupAktif['mengikuti'])
                        Group ini belum diatur sendiri, yang berlaku sekarang adalah pengaturan bawaan
                        Bagian. Menyimpan akan membuat pengaturan khusus Group ini
                        ({{ $grupAktif['jumlah_akun'] }} akun, {{ $grupAktif['jumlah_teknisi'] }} teknisi).
                    @else
                        Pengaturan khusus Group ini sudah tersimpan
                        ({{ $grupAktif['jumlah_akun'] }} akun, {{ $grupAktif['jumlah_teknisi'] }} teknisi).
                    @endif
                </p>
            </div>

            @foreach($modulPerGrup as $namaGrupModul => $modulGrup)
                <div class="bg-white rounded-xl border border-slate-200 p-5">
                    <h4 class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-3">{{ $namaGrupModul }}</h4>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        @foreach($modulGrup as $kunci => $info)
                            @php
                                $khususAdmin = (bool) ($info['khusus_admin'] ?? false);
                                $dicentang   = ! $khususAdmin && in_array($kunci, $grupAktif['permissions'], true);
                            @endphp
                            <label class="flex items-center gap-2 text-sm {{ $khususAdmin ? 'text-slate-400' : 'text-slate-700' }}">
                                <input type="checkbox" name="permissions[]" value="{{ $kunci }}"
                                       class="hak-akses rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                                       {{ $dicentang ? 'checked' : '' }}
                                       {{ $khususAdmin ? 'disabled' : '' }}>
                                <span>
                                    {{ $info['label'] ?? $kunci }}
                                    @if($khususAdmin)
                                        <span class="text-xs">(khusus administrator)</span>
                                    @endif
                                </span>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endforeach

            <div class="flex items-center gap-2">
                <button type="submit"
                        class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
                    Simpan Hak Akses
                </button>
                <a href="{{ route('users.index') }}"
                   class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                    Buka Manajemen User
                </a>
            </div>
        </form>
    @endif
@endsection

@push('scripts')
    <script>
        /**
         * Centang atau kosongkan semua modul yang bisa diatur pada kombinasi Bagian
         * dan Group yang sedang dibuka. Modul khusus administrator (disabled) tidak
         * ikut diubah.
         *
         * @param  {boolean} nilai True untuk mencentang semua
         * @return {void}
         */
        function pilihSemuaHakAkses(nilai) {
            document.querySelectorAll('.hak-akses').forEach(function (kotak) {
                if (! kotak.disabled) {
                    kotak.checked = nilai;
                }
            });
        }
    </script>
@endpush
