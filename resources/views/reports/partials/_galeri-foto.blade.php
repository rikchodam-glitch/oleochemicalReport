{{-- Popup galeri foto laporan (halaman detail maupun mode edit).

     Foto yang diklik pada grid kecil (dokumentasi / hygiene) dibuka BESAR di
     sini, lengkap dengan tombol sebelumnya/berikutnya, tombol panah kiri-kanan
     pada keyboard, geser (swipe) pada layar sentuh, penghitung posisi, dan
     strip thumbnail untuk melompat langsung ke foto tertentu.

     Markup ini hanya boleh di-include SEKALI per halaman (dipanggil dari
     reports/show.blade.php) supaya popupnya tidak tampil berganda. State dan
     method-nya ada di komponen Alpine reportDetail() pada show.blade.php.

     Parameter:
     - $bolehHapusFoto : bool, true bila laporan yang dibuka adalah laporan
                         INDUK (baris salinan memakai berkas foto yang sama). --}}
<template x-teleport="body">
    <div x-show="galeri.buka" x-cloak
         @keydown.escape.window="galeriTutup()"
         @keydown.arrow-left.window="galeriSebelum()"
         @keydown.arrow-right.window="galeriBerikut()"
         class="fixed inset-0 z-50 bg-black/90 flex flex-col">

        {{-- Kepala: pilih galeri, penghitung posisi, tombol tutup --}}
        <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 border-b border-white/10">
            <div class="flex items-center gap-2">
                <button type="button"
                        @click="galeriJenisBuka('documentation')"
                        :class="galeri.jenis === 'documentation'
                            ? 'bg-white text-slate-900'
                            : 'bg-white/10 text-white hover:bg-white/20'"
                        class="px-3 py-1.5 text-xs font-medium rounded-lg transition-colors">
                    Foto Dokumentasi (<span x-text="photoGroups.documentation.length"></span>)
                </button>

                <template x-if="photoGroups.hygiene.length > 0">
                    <button type="button"
                            @click="galeriJenisBuka('hygiene')"
                            :class="galeri.jenis === 'hygiene'
                                ? 'bg-white text-slate-900'
                                : 'bg-white/10 text-white hover:bg-white/20'"
                            class="px-3 py-1.5 text-xs font-medium rounded-lg transition-colors">
                        Foto Hygiene (<span x-text="photoGroups.hygiene.length"></span>)
                    </button>
                </template>
            </div>

            <div class="flex items-center gap-3">
                <span class="text-xs text-white/70" x-text="galeriPosisi()"></span>

                @if($bolehHapusFoto)
                    <button type="button" x-show="editMode" x-cloak
                            @click="galeriHapus()"
                            :disabled="deleting[galeri.jenis + '_' + galeri.indeks]"
                            class="px-3 py-1.5 bg-red-600 hover:bg-red-700 disabled:opacity-50 text-white text-xs font-medium rounded-lg transition-colors">
                        Hapus foto ini
                    </button>
                @endif

                <button type="button" @click="galeriTutup()"
                        class="px-3 py-1.5 bg-white/10 hover:bg-white/20 text-white text-xs font-medium rounded-lg transition-colors">
                    Tutup
                </button>
            </div>
        </div>

        {{-- Isi: tombol sebelumnya, foto besar, tombol berikutnya --}}
        <div class="flex-1 flex items-center justify-center gap-2 sm:gap-4 px-2 sm:px-4 py-3 min-h-0"
             @touchstart="galeriGeserMulai($event)"
             @touchend="galeriGeserSelesai($event)">
            <button type="button" @click="galeriSebelum()" title="Foto sebelumnya (panah kiri)"
                    class="shrink-0 w-10 h-10 sm:w-12 sm:h-12 flex items-center justify-center rounded-full bg-white/10 hover:bg-white/20 text-white transition-colors">
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                </svg>
            </button>

            <img :src="galeriFoto()" alt="Foto laporan"
                 class="max-h-full max-w-full object-contain rounded-lg select-none">

            <button type="button" @click="galeriBerikut()" title="Foto berikutnya (panah kanan)"
                    class="shrink-0 w-10 h-10 sm:w-12 sm:h-12 flex items-center justify-center rounded-full bg-white/10 hover:bg-white/20 text-white transition-colors">
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                </svg>
            </button>
        </div>

        {{-- Kaki: strip thumbnail --}}
        <div class="px-4 py-3 border-t border-white/10">
            <div class="flex items-center gap-2 overflow-x-auto pb-1">
                <template x-for="(url, idx) in (photoGroups[galeri.jenis] || [])" :key="galeri.jenis + '-' + idx">
                    <button type="button" @click="galeriKe(idx)"
                            :class="idx === galeri.indeks ? 'ring-2 ring-teal-400' : 'opacity-60 hover:opacity-100'"
                            class="shrink-0 w-16 h-16 rounded-lg overflow-hidden transition-all">
                        <img :src="url" alt="Thumbnail foto" class="w-full h-full object-cover">
                    </button>
                </template>
            </div>

            <p class="text-xs text-white/50 mt-2">
                Geser foto atau tekan tombol panah kiri/kanan pada keyboard untuk berpindah foto.
            </p>
        </div>
    </div>
</template>
