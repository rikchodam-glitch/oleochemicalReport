{{--
    Popup filter pengelompokan: pengguna memilih satu kelompok, langsung
    diterapkan lewat tautan. Filter lanjutan tetap tersedia lewat tombol.
--}}
@php
    $kolom = (string) ($kolom ?? '');
    $petunjuk = (string) ($petunjuk ?? '');
    $opsi = (array) ($opsi ?? []);
    $aktif = (string) ($saringan[$kolom] ?? '');
@endphp

<div x-show="filterAktif === '{{ $kolom }}' && filterMode === 'grup'" class="space-y-3">
    @if($petunjuk !== '')
        <p class="text-xs text-slate-500">{{ $petunjuk }}</p>
    @endif

    <div class="space-y-1">
        <a href="{{ $tautanFilter([$kolom]) }}"
           class="flex items-center justify-between gap-2 rounded-lg border px-3 py-2 text-sm transition-colors {{ $aktif === '' ? 'border-teal-300 bg-teal-50 text-teal-800' : 'border-slate-200 text-slate-700 hover:bg-slate-50' }}">
            <span>Semua kelompok</span>
            @if($aktif === '')
                <span class="text-teal-600">&#10003;</span>
            @endif
        </a>

        @foreach($opsi as $nilai => $label)
            <a href="{{ $tautanFilter([$kolom], [$kolom => $nilai]) }}"
               class="flex items-center justify-between gap-2 rounded-lg border px-3 py-2 text-sm transition-colors {{ $aktif === (string) $nilai ? 'border-teal-300 bg-teal-50 text-teal-800' : 'border-slate-200 text-slate-700 hover:bg-slate-50' }}">
                <span>{{ $label }}</span>
                @if($aktif === (string) $nilai)
                    <span class="text-teal-600">&#10003;</span>
                @endif
            </a>
        @endforeach
    </div>

    <div class="flex items-center justify-between gap-2 border-t border-slate-100 pt-3">
        <button type="button" class="text-xs font-medium text-teal-700 hover:text-teal-800" @click="filterMode = 'lanjut'">
            Filter lanjutan
        </button>
        <button type="button" class="text-xs text-slate-500 hover:text-slate-700" @click="filterAktif = null">
            Tutup
        </button>
    </div>
</div>
