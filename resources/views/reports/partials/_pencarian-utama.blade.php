{{-- Kotak pencarian utama halaman Laporan.
     Mencari SELURUH tanggal dan kedua tab (Reguler + Shift) dengan saran
     langsung: potongan laporan, teknisi, area, alat, dan lokasi.

     Parameter:
       $cariAktif    : true bila kata kunci sedang dipakai
       $cakupanSemua : true bila mode semua tanggal aktif
       $kataKunci    : kata kunci aktif
       $totalLaporan : jumlah hasil pada filter yang sedang aktif --}}
@php
    // Filter selain tanggal/tab yang dipertahankan saat kata kunci dikirim,
    // sehingga pencarian bisa dipersempit dengan facet yang sudah dipilih.
    $parameterTersimpan = collect(request()->query())
        ->except(['page', 'tanggal', 'tampilan', 'grub', 'date_from', 'date_to', 'search', 'cakupan'])
        ->filter(fn ($nilai) => is_scalar($nilai) && $nilai !== '')
        ->all();

    // Tautan keluar dari mode pencarian: kembali ke tampilan tanggal hari ini
    // dengan facet lain yang sedang aktif.
    $tautanKembali = route('reports.index', array_filter(array_merge(
        collect(request()->query())
            ->except(['page', 'tanggal', 'tampilan', 'grub', 'date_from', 'date_to', 'search', 'cakupan'])
            ->all(),
        ['tanggal' => now()->toDateString()]
    ), fn ($nilai) => is_scalar($nilai) && $nilai !== ''));

    // Hapus hanya filter lokasi dari klik saran; kata kunci & facet lain tetap.
    $tautanHapusFuncloc = route('reports.index', array_filter(
        collect(request()->query())->except(['funcloc_id', 'page'])->all(),
        fn ($nilai) => is_scalar($nilai) && $nilai !== ''
    ));
@endphp

<div class="bg-white rounded-xl border border-slate-200 mb-5">
    <form method="GET" action="{{ route('reports.index') }}" id="form-pencarian-laporan" class="p-4 sm:p-5">
        @foreach($parameterTersimpan as $nama => $nilai)
            <input type="hidden" name="{{ $nama }}" value="{{ $nilai }}">
        @endforeach
        {{-- Pencarian selalu memakai cakupan seluruh tanggal; tab Reguler dan
             Shift ditampilkan sekaligus oleh controller. --}}
        <input type="hidden" name="cakupan" value="semua">

        <div class="relative"
             x-data="pencarianLaporan(@js(route('reports.cari')), @js($kataKunci))"
             @click.outside="tutup()">
            <div class="flex flex-col sm:flex-row gap-2">
                <div class="relative flex-1">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/>
                        </svg>
                    </span>

                    <label for="cari-laporan" class="sr-only">Cari laporan</label>
                    <input type="search" name="search" id="cari-laporan" x-model="kataKunci"
                           autocomplete="off"
                           placeholder="Cari pekerjaan, kode laporan, teknisi, area, lokasi, atau alat..."
                           class="w-full border border-slate-300 rounded-lg pl-10 pr-10 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500"
                           role="combobox" aria-autocomplete="list" aria-controls="saran-pencarian"
                           :aria-expanded="terbuka ? 'true' : 'false'"
                           @input.debounce.300ms="cari()"
                           @focus="if (kataKunci.trim().length >= 2) cari()"
                           @keydown.arrow-down.prevent="pindah(1)"
                           @keydown.arrow-up.prevent="pindah(-1)"
                           @keydown.enter.prevent="pilihAtauCari()"
                           @keydown.escape="tutup()">

                    <div class="absolute right-2 top-1/2 -translate-y-1/2 flex items-center gap-1">
                        <span x-show="memuat" x-cloak class="text-teal-600" title="Memuat saran">
                            <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                            </svg>
                        </span>

                        @if($cariAktif || $cakupanSemua)
                            <a href="{{ $tautanKembali }}" title="Tutup pencarian dan kembali ke tampilan tanggal"
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
                        class="shrink-0 px-5 py-3 bg-teal-600 hover:bg-teal-700 text-white text-sm font-medium rounded-lg transition-colors">
                    Cari
                </button>
            </div>

            <p class="text-xs text-slate-400 mt-2">
                Mencari di seluruh tanggal dan kedua tab (Reguler + Shift). Ketik minimal 2 huruf untuk melihat saran;
                gunakan panah atas/bawah lalu Enter untuk memilih.
            </p>

            {{-- Dropdown saran. Semua teks dirender sebagai teks (bukan HTML
                 mentah) kecuali sorotan yang sudah di-escape oleh sorot(). --}}
            <div x-show="terbuka" x-cloak x-transition.opacity.duration.150ms
                 id="saran-pencarian" role="listbox"
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
                                        :class="aktif === item.kunci ? 'bg-teal-50' : 'hover:bg-slate-50'"
                                        class="w-full text-left px-4 py-2.5 flex items-start gap-3 transition-colors">
                                    {{-- Ikon sesuai kelompok --}}
                                    <span class="mt-0.5 shrink-0 text-slate-400">
                                        <template x-if="kelompok.tipe === 'laporan'">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6M7 4h7l5 5v11a1 1 0 01-1 1H7a1 1 0 01-1-1V5a1 1 0 011-1z"/>
                                            </svg>
                                        </template>
                                        <template x-if="kelompok.tipe === 'teknisi'">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                            </svg>
                                        </template>
                                        <template x-if="kelompok.tipe === 'area'">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h10"/>
                                            </svg>
                                        </template>
                                        <template x-if="kelompok.tipe === 'alat'">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M14.7 6.3a4 4 0 105.4 5.4l-2.1-2.1 1.4-1.4-2.6-2.6-1.4 1.4-2.1-2.1a4 4 0 002.4 2.4zM3 21l6.6-6.6"/>
                                            </svg>
                                        </template>
                                        <template x-if="kelompok.tipe === 'lokasi'">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a2 2 0 01-2.828 0l-4.243-4.243a8 8 0 1111.314 0z"/>
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                                            </svg>
                                        </template>
                                    </span>

                                    <span class="min-w-0 flex-1">
                                        {{-- Laporan: deskripsi + kode + meta --}}
                                        <template x-if="kelompok.tipe === 'laporan'">
                                            <span class="block">
                                                <span class="block text-sm text-slate-800 leading-snug"
                                                      x-html="sorot(item.deskripsi)"></span>
                                                <span class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-slate-400">
                                                    <span class="font-mono text-slate-500" x-text="item.kode"></span>
                                                    <span x-text="item.tanggal"></span>
                                                    <span x-text="item.teknisi"></span>
                                                    <span x-show="item.status_label" class="px-1.5 py-0.5 rounded bg-slate-100 text-slate-500" x-text="item.status_label"></span>
                                                </span>
                                                <span class="mt-0.5 block text-xs text-slate-400">
                                                    <span x-text="item.lokasi"></span>
                                                    <span x-show="item.alat && item.alat !== '-'" class="font-mono"> · <span x-text="item.alat"></span></span>
                                                </span>
                                            </span>
                                        </template>

                                        {{-- Teknisi: nama + NIK + grup --}}
                                        <template x-if="kelompok.tipe === 'teknisi'">
                                            <span class="block">
                                                <span class="block text-sm font-medium text-slate-800" x-text="item.nama"></span>
                                                <span class="block text-xs text-slate-400"
                                                      x-text="[item.nik, item.grup].filter(Boolean).join(' · ')"></span>
                                            </span>
                                        </template>

                                        {{-- Area/Lokasi: kode + nama --}}
                                        <template x-if="kelompok.tipe === 'area' || kelompok.tipe === 'lokasi'">
                                            <span class="block">
                                                <span class="block text-sm font-mono text-slate-800" x-text="item.kode"></span>
                                                <span class="block text-xs text-slate-400" x-text="item.nama"></span>
                                            </span>
                                        </template>

                                        {{-- Alat: kode + deskripsi + lokasi --}}
                                        <template x-if="kelompok.tipe === 'alat'">
                                            <span class="block">
                                                <span class="block text-sm font-mono text-slate-800" x-text="item.kode"></span>
                                                <span class="block text-xs text-slate-500" x-text="item.nama"></span>
                                                <span class="block text-xs text-slate-400" x-show="item.lokasi" x-text="item.lokasi"></span>
                                            </span>
                                        </template>
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
                        <p class="text-xs text-slate-400 mt-1">Tekan Enter untuk mencari di seluruh tanggal.</p>
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

    @if($cariAktif || $cakupanSemua)
        <div class="px-4 sm:px-5 py-3 border-t border-slate-100 flex flex-wrap items-center gap-x-3 gap-y-2">
            <p class="text-xs text-teal-800">
                @if($cariAktif)
                    Hasil pencarian <span class="font-semibold">"{{ $kataKunci }}"</span> dari seluruh tanggal
                @else
                    Menampilkan seluruh tanggal
                @endif
                @if(request()->filled('technician_id'))
                    (tab mengikuti grup teknisi yang dipilih).
                @else
                    dan kedua tab (Reguler + Shift).
                @endif
            </p>
            <span class="px-2 py-0.5 rounded-md bg-teal-50 border border-teal-100 text-xs font-semibold text-teal-700">
                {{ number_format($totalLaporan) }} pekerjaan
            </span>
            @if($funclocAktif)
                <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md bg-white border border-teal-100 text-xs text-teal-800">
                    Lokasi:
                    <span class="font-mono">{{ $funclocAktif->code }}</span>
                    — {{ $funclocAktif->name }}
                    <a href="{{ $tautanHapusFuncloc }}" title="Hapus filter lokasi"
                       class="text-teal-500 hover:text-teal-900 leading-none">×</a>
                </span>
            @endif
            <a href="{{ $tautanKembali }}" class="ml-auto text-xs font-medium text-teal-700 hover:underline">
                Kembali ke tampilan tanggal
            </a>
        </div>
    @endif
</div>

@push('scripts')
<script>
    /**
     * Komponen Alpine kotak pencarian laporan.
     *
     * Mengambil saran dari endpoint reports.cari setelah pengguna berhenti
     * mengetik (debounce 300 ms). Semua teks dari server dirender sebagai teks
     * biasa; hanya fungsi sorot() yang menghasilkan markup dan teksnya sudah
     * di-escape lebih dulu.
     *
     * @param  {string} urlCari        URL endpoint saran
     * @param  {string} kataKunciAwal  Kata kunci yang sedang aktif di halaman
     * @return {object}                Data dan method komponen Alpine
     */
    function pencarianLaporan(urlCari, kataKunciAwal) {
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
             * Ratakan seluruh item saran supaya navigasi panah bekerja lintas
             * kelompok.
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
             * Ambil saran dari server. Jawaban usang (kata kunci sudah berubah)
             * diabaikan supaya dropdown tidak menampilkan hasil lama.
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
                    const form = document.getElementById('form-pencarian-laporan');

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
             * Kosongkan kata kunci pada mode normal (belum ada hasil server).
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
