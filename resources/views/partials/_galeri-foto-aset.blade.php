{{--
    Popup galeri foto asset. Dipakai halaman detail Asset Management dan
    halaman CM Equipment. Galeri bersifat lihat-saja; pengelolaan foto tetap
    di halaman Edit Asset.

    Markup ini di-include SEKALI per halaman oleh partial _foto-equipment.
--}}
<template x-teleport="body">
    <div x-show="galeri.buka" x-cloak
         @keydown.escape.window="galeriTutup()"
         @keydown.arrow-left.window="galeriSebelum()"
         @keydown.arrow-right.window="galeriBerikut()"
         class="fixed inset-0 z-50 flex flex-col bg-black/90">

        {{-- Kepala: judul, posisi foto, tombol tutup --}}
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-white/10 px-4 py-3">
            <div class="min-w-0">
                <p class="truncate text-sm font-medium text-white" x-text="judul"></p>
                <p class="text-xs text-white/50">Foto equipment dari Asset Management</p>
            </div>

            <div class="flex items-center gap-3">
                <span class="text-xs text-white/70" x-text="galeriPosisi()"></span>
                <button type="button" @click="galeriTutup()"
                        class="rounded-lg bg-white/10 px-3 py-1.5 text-xs font-medium text-white transition-colors hover:bg-white/20">
                    Tutup
                </button>
            </div>
        </div>

        {{-- Isi: tombol sebelumnya, foto besar, tombol berikutnya --}}
        <div class="flex min-h-0 flex-1 items-center justify-center gap-2 px-2 py-3 sm:gap-4 sm:px-4"
             @touchstart="galeriGeserMulai($event)"
             @touchend="galeriGeserSelesai($event)">
            <button type="button" @click="galeriSebelum()" title="Foto sebelumnya (panah kiri)"
                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-white/10 text-white transition-colors hover:bg-white/20 sm:h-12 sm:w-12">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                </svg>
            </button>

            <img :src="galeriFoto()" alt="Foto equipment"
                 class="max-h-full max-w-full select-none rounded-lg object-contain">

            <button type="button" @click="galeriBerikut()" title="Foto berikutnya (panah kanan)"
                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-white/10 text-white transition-colors hover:bg-white/20 sm:h-12 sm:w-12">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                </svg>
            </button>
        </div>

        {{-- Kaki: strip thumbnail --}}
        <div class="border-t border-white/10 px-4 py-3">
            <div class="flex items-center gap-2 overflow-x-auto pb-1">
                <template x-for="(url, idx) in foto" :key="idx">
                    <button type="button" @click="galeriKe(idx)"
                            :class="idx === galeri.indeks ? 'ring-2 ring-teal-400' : 'opacity-60 hover:opacity-100'"
                            class="h-16 w-16 shrink-0 overflow-hidden rounded-lg transition-all">
                        <img :src="url" alt="Thumbnail foto" class="h-full w-full object-cover">
                    </button>
                </template>
            </div>

            <p class="mt-2 text-xs text-white/50">
                Geser foto atau tekan tombol panah kiri/kanan pada keyboard untuk berpindah foto.
            </p>
        </div>
    </div>
</template>

@once
    @push('scripts')
    <script>
    /**
     * Komponen Alpine galeri foto asset.
     *
     * @param {Array<string>} urls  URL foto yang akan ditampilkan
     * @param {string} judul        Judul di kepala galeri
     * @return {object}             State dan method galeri
     */
    function galeriFotoAset(urls, judul) {
        return {
            foto: Array.isArray(urls) ? urls : [],
            judul: judul || 'Foto Equipment',
            galeri: {
                buka: false,
                indeks: 0,
                geserDari: null,
            },

            galeriBuka(indeks) {
                var jumlah = this.foto.length;

                if (!jumlah) {
                    return;
                }

                this.galeri.indeks = Math.min(Math.max(indeks || 0, 0), jumlah - 1);
                this.galeri.buka = true;
            },

            galeriTutup() {
                this.galeri.buka = false;
            },

            galeriFoto() {
                return this.foto[this.galeri.indeks] || '';
            },

            galeriPosisi() {
                var jumlah = this.foto.length;

                return jumlah ? (this.galeri.indeks + 1) + ' / ' + jumlah : '0 / 0';
            },

            galeriSebelum() {
                var jumlah = this.foto.length;

                if (!this.galeri.buka || !jumlah) {
                    return;
                }

                this.galeri.indeks = (this.galeri.indeks - 1 + jumlah) % jumlah;
            },

            galeriBerikut() {
                var jumlah = this.foto.length;

                if (!this.galeri.buka || !jumlah) {
                    return;
                }

                this.galeri.indeks = (this.galeri.indeks + 1) % jumlah;
            },

            galeriKe(indeks) {
                if (indeks >= 0 && indeks < this.foto.length) {
                    this.galeri.indeks = indeks;
                }
            },

            galeriGeserMulai(kejadian) {
                this.galeri.geserDari = kejadian.touches && kejadian.touches.length
                    ? kejadian.touches[0].clientX
                    : null;
            },

            galeriGeserSelesai(kejadian) {
                if (this.galeri.geserDari === null || !kejadian.changedTouches.length) {
                    this.galeri.geserDari = null;
                    return;
                }

                var jarak = kejadian.changedTouches[0].clientX - this.galeri.geserDari;
                this.galeri.geserDari = null;

                if (jarak > 50) {
                    this.galeriSebelum();
                } else if (jarak < -50) {
                    this.galeriBerikut();
                }
            },
        };
    }
    </script>
    @endpush
@endonce
