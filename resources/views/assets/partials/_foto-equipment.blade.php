{{--
    Kotak foto equipment dengan slideshow otomatis dan geser manual.
    Dipakai halaman detail Asset Management dan halaman CM Equipment.

    Sumber foto hanya assets.photos; halaman CM membaca foto dari asset yang
    bertaut, jadi tidak ada data foto terpisah.

    Parameter:
    - $asset      : Asset|null yang fotonya ditampilkan
    - $bisaKelola : true bila halaman ini boleh menautkan ke Edit Asset
    - $konteks    : teks kecil penjelas konteks (Informasi Asset / Ringkasan Equipment)
--}}
@php
    $asset      = $asset ?? null;
    $bisaKelola = (bool) ($bisaKelola ?? false);
    $konteks    = (string) ($konteks ?? 'Foto Equipment');
    $fotoUrls   = $asset?->photo_urls ?? [];
    $judulFoto  = $asset?->tech_ident_no ?? $asset?->description ?? 'Foto Equipment';

    // JSON_HEX_QUOT/APOS supaya hasil json_encode aman dipakai di atribut HTML
    // x-data yang memakai tanda kutip ganda.
    $fotoJson  = json_encode($fotoUrls, JSON_HEX_QUOT | JSON_HEX_APOS | JSON_UNESCAPED_SLASHES) ?: '[]';
    $judulJson = json_encode($judulFoto, JSON_HEX_QUOT | JSON_HEX_APOS | JSON_UNESCAPED_SLASHES) ?: '""';
@endphp

<div x-data="galeriFotoAset({{ $fotoJson }}, {{ $judulJson }})"
     x-init="mulaiSlide()">
    <div class="mb-3 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h3 class="text-sm font-semibold text-slate-900">Foto Equipment</h3>
            <p class="mt-0.5 text-xs text-slate-400">
                {{ $konteks }} &middot; {{ count($fotoUrls) }} foto
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            @if($asset !== null && $bisaKelola)
                <a href="{{ route('assets.edit', $asset) }}"
                   class="text-xs font-semibold text-teal-700 hover:text-teal-800">
                    Kelola foto
                </a>
            @endif
        </div>
    </div>

    @if($asset === null)
        <div class="rounded-lg border border-dashed border-slate-300 bg-slate-50/60 p-4 text-sm text-slate-400">
            Belum ada asset terkait untuk menampilkan foto equipment.
        </div>
    @elseif(count($fotoUrls) > 0)
        <div class="relative overflow-hidden rounded-xl border border-slate-200 bg-slate-900"
             @mouseenter="hentikanSlide()"
             @mouseleave="mulaiSlide()"
             @touchstart="geserSlideMulai($event)"
             @touchend="geserSlideSelesai($event)">
            <button type="button" @click="masukGaleri()"
                    class="block w-full"
                    title="Klik untuk membuka galeri foto">
                <img :src="slideFoto()" src="{{ $fotoUrls[0] }}"
                     alt="Foto {{ $judulFoto }}"
                     class="aspect-square w-full object-cover">
            </button>

            <button type="button" @click.stop="slideSebelum()"
                    title="Foto sebelumnya"
                    class="absolute left-2 top-1/2 flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-full bg-black/45 text-white transition-colors hover:bg-black/65">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                </svg>
            </button>

            <button type="button" @click.stop="slideBerikut()"
                    title="Foto berikutnya"
                    class="absolute right-2 top-1/2 flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-full bg-black/45 text-white transition-colors hover:bg-black/65">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                </svg>
            </button>

            <span class="absolute bottom-2 left-2 rounded-full bg-black/60 px-2 py-0.5 text-[10px] font-medium text-white"
                  x-text="slidePosisi()"></span>

            <div class="absolute inset-x-0 bottom-2 flex items-center justify-center gap-1.5">
                <template x-for="(url, idx) in foto" :key="idx">
                    <button type="button" @click.stop="slideKe(idx)"
                            :class="idx === indeksSlide ? 'bg-white' : 'bg-white/40 hover:bg-white/70'"
                            class="h-1.5 w-1.5 rounded-full transition-colors"
                            :aria-label="'Foto ' + (idx + 1)"></button>
                </template>
            </div>
        </div>

        <p class="mt-1.5 text-[10px] text-slate-400">
            Berganti otomatis tiap 5 detik. Geser foto atau klik untuk membuka ukuran penuh.
        </p>
    @else
        <div class="rounded-lg border border-dashed border-slate-300 bg-slate-50/60 p-4 text-sm text-slate-400">
            Belum ada foto equipment.
            @if($bisaKelola)
                <a href="{{ route('assets.edit', $asset) }}" class="font-medium text-teal-700 hover:underline">Tambahkan foto</a>.
            @endif
        </div>
    @endif

    @include('partials._galeri-foto-aset')
</div>
