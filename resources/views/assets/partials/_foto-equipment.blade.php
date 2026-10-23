{{--
    Bagian foto equipment yang dipakai bersama oleh detail Asset Management
    dan halaman CM Equipment. Sumber foto hanya assets.photos; halaman CM
    membaca foto dari asset yang bertaut, jadi tidak ada data foto terpisah.

    Parameter:
    - $asset      : Asset|null yang fotonya ditampilkan
    - $bisaKelola : true bila halaman ini boleh menautkan ke Edit Asset
    - $konteks    : teks kecil penjelas konteks (Informasi Asset / Ringkasan Equipment)
    - $tanpaGaris : true untuk menghilangkan garis pemisah atas (dipakai di kartu CM)
--}}
@php
    $asset      = $asset ?? null;
    $bisaKelola = (bool) ($bisaKelola ?? false);
    $konteks    = (string) ($konteks ?? 'Foto Equipment');
    $tanpaGaris = (bool) ($tanpaGaris ?? false);
    $fotoUrls   = $asset?->photo_urls ?? [];
    $judulFoto  = $asset?->tech_ident_no ?? $asset?->description ?? 'Foto Equipment';

    // JSON_HEX_QUOT/APOS supaya hasil json_encode aman dipakai di atribut HTML
    // x-data yang memakai tanda kutip ganda.
    $fotoJson  = json_encode($fotoUrls, JSON_HEX_QUOT | JSON_HEX_APOS | JSON_UNESCAPED_SLASHES) ?: '[]';
    $judulJson = json_encode($judulFoto, JSON_HEX_QUOT | JSON_HEX_APOS | JSON_UNESCAPED_SLASHES) ?: '""';
@endphp

<div x-data="galeriFotoAset({{ $fotoJson }}, {{ $judulJson }})"
     class="{{ $tanpaGaris ? '' : 'mt-5 border-t border-slate-100 pt-5' }}">
    <div class="mb-3 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h3 class="text-sm font-semibold text-slate-900">Foto Equipment</h3>
            <p class="mt-0.5 text-xs text-slate-400">
                {{ $konteks }} &middot; {{ count($fotoUrls) }} foto
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            @if(count($fotoUrls) > 0)
                <button type="button" @click="galeriBuka(0)"
                        class="text-xs font-semibold text-teal-700 hover:text-teal-800">
                    Lihat semua
                </button>
            @endif

            @if($asset !== null)
                @if($bisaKelola)
                    <a href="{{ route('assets.edit', $asset) }}"
                       class="text-xs font-semibold text-teal-700 hover:text-teal-800">
                        Kelola foto
                    </a>
                @else
                    <a href="{{ route('assets.show', $asset) }}"
                       class="text-xs font-semibold text-teal-700 hover:text-teal-800">
                        Buka Asset Management
                    </a>
                @endif
            @endif
        </div>
    </div>

    @if($asset === null)
        <p class="text-sm text-slate-400">Belum ada asset terkait untuk menampilkan foto equipment.</p>
    @elseif(count($fotoUrls) > 0)
        <div class="grid grid-cols-3 gap-3 sm:grid-cols-5 xl:grid-cols-6">
            @foreach($fotoUrls as $index => $url)
                <button type="button" @click="galeriBuka({{ $index }})"
                        class="group aspect-square overflow-hidden rounded-lg border border-slate-200 bg-slate-50 transition-opacity hover:opacity-80"
                        title="Buka foto {{ $index + 1 }} dari {{ count($fotoUrls) }}">
                    <img src="{{ $url }}"
                         alt="Foto {{ $judulFoto }} {{ $index + 1 }}"
                         class="h-full w-full object-cover">
                </button>
            @endforeach
        </div>
    @else
        <div class="rounded-lg border border-dashed border-slate-300 bg-slate-50/60 p-4 text-sm text-slate-400">
            Belum ada foto equipment.
            @if($bisaKelola)
                <a href="{{ route('assets.edit', $asset) }}" class="font-medium text-teal-700 hover:underline">Tambahkan foto</a>.
            @else
                <a href="{{ route('assets.edit', $asset) }}" class="font-medium text-teal-700 hover:underline">Tambahkan lewat Edit Asset</a>.
            @endif
        </div>
    @endif

    @include('partials._galeri-foto-aset')
</div>
