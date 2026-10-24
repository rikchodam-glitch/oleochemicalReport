{{--
    Tab tingkat atas halaman Sparepart: tabel stok/PR/PO dan halaman request.
    Tab Request hanya tampil bila pengguna punya modul sparepart_request.
--}}
@php
    $tabAktif = $tabAktif ?? 'stok';
    $bolehTabRequest = auth()->user()?->punyaAkses('sparepart_request') ?? false;
@endphp

<div class="flex flex-wrap items-center gap-1 border-b border-slate-200">
    <a href="{{ route('sparepart.index') }}"
       class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 transition-colors
              {{ $tabAktif === 'stok'
                    ? 'border-teal-500 text-teal-700'
                    : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300' }}">
        Stok &amp; PR/PO
    </a>

    @if($bolehTabRequest)
        <a href="{{ route('sparepart.request.index') }}"
           class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 transition-colors
                  {{ $tabAktif === 'request'
                        ? 'border-teal-500 text-teal-700'
                        : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300' }}">
            Request Sparepart
        </a>
    @endif
</div>
