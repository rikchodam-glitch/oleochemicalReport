{{-- Kotak pencarian utama untuk halaman Asset Management dan IT Assets.
     Pencarian bersifat GLOBAL: form hanya mengirim kata kunci, sehingga filter
     lokasi/tipe yang sedang aktif tidak ikut menyembunyikan hasil. Filter tetap
     bisa dipakai lagi lewat panel Filter setelah hasil muncul.

     Dropdown saran memakai bentuk item yang sama untuk kedua halaman:
       judul    : teks utama (kode alat/tag/tipe/plant)
       subjudul : keterangan kedua (deskripsi/nama)
       meta     : keterangan tambahan (tipe, lokasi, jumlah)
       url      : tujuan saat item diklik

     Parameter:
       $aksi       : URL form pencarian (route index halaman)
       $saranUrl   : URL endpoint saran (returns JSON)
       $idInput    : id unik input (searchAsset / searchItAsset)
       $placeholder: teks placeholder input
       $kataKunci  : kata kunci aktif
       $total      : jumlah hasil pada halaman
       $satuan     : 'aset' atau 'perangkat'
       $petunjuk   : teks bantuan di bawah input --}}
@php
    $idForm = 'form-pencarian-' . $idInput;

    // Hapus hanya kata kunci; filter lain yang sedang aktif tetap dipertahankan.
    $parameterHapus = collect(request()->query())
        ->except(['search', 'page'])
        ->filter(fn ($nilai) => is_scalar($nilai) && $nilai !== '')
        ->all();

    $tautanHapus = $parameterHapus === []
        ? $aksi
        : $aksi . '?' . http_build_query($parameterHapus);
@endphp

<div class="bg-white rounded-xl border border-slate-200 mb-5">
    <form method="GET" action="{{ $aksi }}" id="{{ $idForm }}" class="p-4 sm:p-5">
        @foreach(($filterTersembunyi ?? []) as $namaFilter => $nilaiFilter)
            @if($nilaiFilter !== null && $nilaiFilter !== '')
                <input type="hidden" name="{{ $namaFilter }}" value="{{ $nilaiFilter }}">
            @endif
        @endforeach

        <div class="relative"
             x-data="pencarianEntitas(@js($saranUrl), @js($kataKunci), @js($idForm))"
             @click.outside="tutup()">
            <div class="flex flex-col sm:flex-row gap-2">
                <div class="relative flex-1">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/>
                        </svg>
                    </span>

                    <label for="{{ $idInput }}" class="sr-only">Cari</label>
                    <input type="search" name="search" id="{{ $idInput }}" x-model="kataKunci"
                           autocomplete="off"
                           placeholder="{{ $placeholder }}"
                           class="w-full border border-slate-300 rounded-lg pl-10 pr-10 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           role="combobox" aria-autocomplete="list" aria-controls="saran-{{ $idInput }}"
                           :aria-expanded="terbuka ? 'true' : 'false'"
                           @input.debounce.300ms="cari()"
                           @focus="if (kataKunci.trim().length >= 2) cari()"
                           @keydown.arrow-down.prevent="pindah(1)"
                           @keydown.arrow-up.prevent="pindah(-1)"
                           @keydown.enter.prevent="pilihAtauCari()"
                           @keydown.escape="tutup()">

                    <div class="absolute right-2 top-1/2 -translate-y-1/2 flex items-center gap-1">
                        <span x-show="memuat" x-cloak class="text-blue-600" title="Memuat saran">
                            <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                            </svg>
                        </span>

                        @if($kataKunci !== '')
                            <a href="{{ $tautanHapus }}" title="Hapus pencarian"
                               class="p-1.5 rounded-md text-slate-400 hover:text-slate-600 hover:bg-slate-100">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </a>
                        @else
                            <button type="button" x-show="kataKunci !== ''" x-cloak @click="bersihkan()"
                                    title="Kosongkan pencarian"
                                    class="p-1.5 rounded-md text-slate-400 hover:text-slate-600 hover:bg-slate-100">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        @endif
                    </div>
                </div>

                <button type="submit"
                        class="shrink-0 px-5 py-3 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
                    Cari
                </button>
            </div>

            <p class="text-xs text-slate-400 mt-2">
                {{ $petunjuk }} Ketik minimal 2 huruf; gunakan panah atas/bawah lalu Enter untuk memilih.
            </p>

            {{-- Dropdown saran. Semua teks dirender sebagai teks, kecuali
                 sorotan yang sudah di-escape oleh sorot(). --}}
            <div x-show="terbuka" x-cloak x-transition.opacity.duration.150ms
                 id="saran-{{ $idInput }}" role="listbox"
                 class="absolute left-0 right-0 z-30 mt-2 rounded-xl border border-slate-200 bg-white shadow-xl overflow-hidden">
                <div class="max-h-[70vh] overflow-y-auto divide-y divide-slate-100">
                    <template x-for="kelompok in saran" :key="kelompok.tipe">
                        <div class="py-1.5">
                            <div class="px-4 py-1.5 flex items-center justify-between gap-3">
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400" x-text="kelompok.label"></p>
                                <span class="text-[11px] text-slate-400"
                                      x-text="kelompok.jumlah + ' ' + kelompok.label.toLowerCase()"></span>
                            </div>

                            <template x-for="item in kelompok.items" :key="item.kunci">
                                <button type="button" role="option"
                                        @click="pilih(item)"
                                        @mouseenter="aktif = item.kunci"
                                        :aria-selected="aktif === item.kunci ? 'true' : 'false'"
                                        :class="aktif === item.kunci ? 'bg-blue-50' : 'hover:bg-slate-50'"
                                        class="w-full text-left px-4 py-2.5 flex items-start gap-3 transition-colors">
                                    <span class="mt-0.5 shrink-0 text-slate-400">
                                        <template x-if="kelompok.tipe === 'aset' || kelompok.tipe === 'perangkat'">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                                            </svg>
                                        </template>
                                        <template x-if="kelompok.tipe === 'tipe'">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5a2 2 0 011.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A2 2 0 013 12V7a4 4 0 014-4z"/>
                                            </svg>
                                        </template>
                                        <template x-if="kelompok.tipe === 'lokasi'">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a2 2 0 01-2.828 0l-4.243-4.243a8 8 0 1111.314 0z"/>
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                                            </svg>
                                        </template>
                                        <template x-if="kelompok.tipe === 'plant'">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0H5m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5"/>
                                            </svg>
                                        </template>
                                        <template x-if="kelompok.tipe === 'vlan'">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 9l3 3-3 3m5 0l3-3-3-3M5 5h14a2 2 0 012 2v10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2z"/>
                                            </svg>
                                        </template>
                                    </span>

                                    <span class="min-w-0 flex-1">
                                        <span class="block text-sm font-medium text-slate-800"
                                              :class="['aset', 'perangkat', 'lokasi'].includes(kelompok.tipe) ? 'font-mono' : ''"
                                              x-html="sorot(item.judul)"></span>
                                        <span class="block text-xs text-slate-500"
                                              x-html="sorot(item.subjudul)"></span>
                                        <span class="block text-xs text-slate-400 mt-0.5"
                                              x-show="item.meta" x-text="item.meta"></span>
                                    </span>

                                    <span class="mt-0.5 shrink-0 text-[11px] text-slate-300">↵</span>
                                </button>
                            </template>
                        </div>
                    </template>

                    <div x-show="!memuat && galat === '' && saran.length === 0" x-cloak
                         class="px-4 py-8 text-center">
                        <p class="text-sm text-slate-500">Tidak ada saran untuk
                            <span class="font-semibold text-slate-700" x-text="'&quot;' + kataKunci.trim() + '&quot;'"></span>.
                        </p>
                        <p class="text-xs text-slate-400 mt-1">Tekan Enter untuk mencari.</p>
                    </div>

                    <div x-show="galat !== ''" x-cloak class="px-4 py-6 text-center text-sm text-rose-600" x-text="galat"></div>
                </div>

                <div class="p-2 border-t border-slate-100 bg-white">
                    <button type="submit"
                            class="w-full px-4 py-2 rounded-lg bg-slate-50 hover:bg-slate-100 text-sm font-medium text-slate-700 transition-colors">
                        Lihat semua hasil
                        <span x-show="total > 0" x-text="'(' + total + ')'"></span>
                    </button>
                </div>
            </div>
        </div>
    </form>

    @if($kataKunci !== '')
        <div class="px-4 sm:px-5 py-3 border-t border-slate-100 flex flex-wrap items-center gap-x-3 gap-y-2">
            <p class="text-xs text-blue-800">
                Hasil pencarian <span class="font-semibold">"{{ $kataKunci }}"</span> di seluruh data.
            </p>
            <span class="px-2 py-0.5 rounded-md bg-blue-50 border border-blue-100 text-xs font-semibold text-blue-700">
                {{ number_format($total) }} {{ $satuan }}
            </span>
            <a href="{{ $tautanHapus }}" class="ml-auto text-xs font-medium text-blue-700 hover:underline">
                Hapus pencarian
            </a>
        </div>
    @endif
</div>

@push('scripts')
<script>
    /**
     * Komponen Alpine kotak pencarian Asset Management / IT Assets.
     *
     * Mengambil saran dari endpoint setelah pengguna berhenti mengetik
     * (debounce 300 ms). Semua teks dari server dirender sebagai teks biasa;
     * hanya fungsi sorot() yang menghasilkan markup dan teksnya sudah
     * di-escape lebih dulu.
     *
     * @param  {string} urlCari        URL endpoint saran
     * @param  {string} kataKunciAwal  Kata kunci yang sedang aktif
     * @param  {string} idForm         ID form pencarian halaman
     * @return {object}                Data dan method komponen Alpine
     */
    function pencarianEntitas(urlCari, kataKunciAwal, idForm) {
        return {
            kataKunci: kataKunciAwal || '',
            terbuka: false,
            memuat: false,
            saran: [],
            total: 0,
            galat: '',
            aktif: '',
            jeda: null,
            pengendali: null,

            /**
             * Ratakan seluruh item saran untuk navigasi panah lintas kelompok.
             *
             * @return {Array} Daftar item saran
             */
            daftarItem() {
                const hasil = [];

                this.saran.forEach(function (kelompok) {
                    (kelompok.items || []).forEach(function (item) {
                        hasil.push(item);
                    });
                });

                return hasil;
            },

            /**
             * Jadwalkan pengambilan saran setelah jeda mengetik.
             *
             * @return {void}
             */
            cari() {
                const kunci = this.kataKunci.trim();

                clearTimeout(this.jeda);

                if (kunci.length < 2) {
                    this.saran = [];
                    this.total = 0;
                    this.memuat = false;
                    this.terbuka = false;

                    return;
                }

                this.jeda = setTimeout(() => this.ambilSaran(kunci), 300);
            },

            /**
             * Ambil saran dari server; jawaban usang diabaikan.
             *
             * @param  {string} kunci  Kata kunci yang diminta
             * @return {Promise<void>}
             */
            async ambilSaran(kunci) {
                if (this.pengendali) {
                    this.pengendali.abort();
                }

                this.pengendali = new AbortController();
                this.memuat = true;
                this.galat = '';

                try {
                    const balasan = await fetch(urlCari + '?q=' + encodeURIComponent(kunci), {
                        headers: { 'Accept': 'application/json' },
                        signal: this.pengendali.signal,
                    });

                    if (!balasan.ok) {
                        throw new Error('HTTP ' + balasan.status);
                    }

                    const data = await balasan.json();

                    if (this.kataKunci.trim() !== kunci) {
                        return;
                    }

                    this.saran = data.kelompok || [];
                    this.total = data.total || 0;
                    this.aktif = '';
                    this.terbuka = true;
                } catch (galat) {
                    if (galat.name === 'AbortError') {
                        return;
                    }

                    this.saran = [];
                    this.total = 0;
                    this.terbuka = true;
                    this.galat = 'Saran pencarian gagal dimuat. Tekan Enter untuk mencari.';
                } finally {
                    this.memuat = false;
                }
            },

            /**
             * Pindahkan pilihan aktif ke item berikutnya/sebelumnya.
             *
             * @param  {number} arah  1 = bawah, -1 = atas
             * @return {void}
             */
            pindah(arah) {
                const daftar = this.daftarItem();

                if (daftar.length === 0) {
                    return;
                }

                const posisi = daftar.findIndex((item) => item.kunci === this.aktif);
                let baru = posisi + arah;

                if (baru < 0) {
                    baru = daftar.length - 1;
                }

                if (baru >= daftar.length) {
                    baru = 0;
                }

                this.aktif = daftar[baru].kunci;
                this.terbuka = true;
            },

            /**
             * Buka item saran yang dipilih.
             *
             * @param  {object} item  Item saran berisi url
             * @return {void}
             */
            pilih(item) {
                if (item && item.url) {
                    window.location.href = item.url;
                }
            },

            /**
             * Enter: buka item aktif, atau kirim form bila tidak ada pilihan.
             *
             * @return {void}
             */
            pilihAtauCari() {
                const item = this.daftarItem().find((baris) => baris.kunci === this.aktif);

                if (item && item.url) {
                    window.location.href = item.url;

                    return;
                }

                if (this.kataKunci.trim().length >= 2) {
                    const form = document.getElementById(idForm);

                    if (form && typeof form.requestSubmit === 'function') {
                        form.requestSubmit();
                    } else if (form) {
                        form.submit();
                    }
                }
            },

            /**
             * Tutup dropdown tanpa mengubah kata kunci.
             *
             * @return {void}
             */
            tutup() {
                this.terbuka = false;
                this.aktif = '';
            },

            /**
             * Kosongkan kata kunci (hanya saat belum ada hasil server).
             *
             * @return {void}
             */
            bersihkan() {
                this.kataKunci = '';
                this.saran = [];
                this.total = 0;
                this.galat = '';
                this.tutup();
            },

            /**
             * Sorot kata kunci pada teks saran. Teks di-escape lebih dulu agar
             * data dari database tidak bisa menyisipkan HTML.
             *
             * @param  {string} teks  Teks yang akan disorot
             * @return {string}       HTML aman dengan <mark>
             */
            sorot(teks) {
                const aman = this.escapeHtml(teks == null ? '' : String(teks));
                const token = this.kataKunci.trim().split(/\s+/).filter((kata) => kata.length >= 2);

                if (token.length === 0) {
                    return aman;
                }

                return aman.split(/(<[^>]+>)/g).map((bagian) => {
                    if (bagian.startsWith('<')) {
                        return bagian;
                    }

                    let hasil = bagian;

                    token.forEach((kata) => {
                        const pola = new RegExp('(' + this.escapeRegex(this.escapeHtml(kata)) + ')', 'gi');
                        hasil = hasil.replace(pola, '<mark class="bg-amber-100 text-amber-900 rounded px-0.5">$1</mark>');
                    });

                    return hasil;
                }).join('');
            },

            /**
             * Escape karakter HTML.
             *
             * @param  {string} teks  Teks mentah
             * @return {string}
             */
            escapeHtml(teks) {
                return String(teks)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            },

            /**
             * Escape karakter khusus regular expression.
             *
             * @param  {string} teks  Teks mentah
             * @return {string}
             */
            escapeRegex(teks) {
                return String(teks).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            },
        };
    }
</script>
@endpush
