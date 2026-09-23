{{--
    Partial: informasi lokasi Functional Location, Tipe Equipment, dan status
    sinkronisasi perangkat IT ke tabel assets (modul Asset industri).

    Dipakai show.blade.php lewat satu baris @include.

    Variabel yang dipakai:
    - $itAsset : instance ItAsset yang sedang ditampilkan
--}}

@php
    // Baris cermin di tabel assets: sumber pencarian wizard laporan Telegram.
    $mirror = $itAsset->assetMirror;
@endphp

<div class="md:col-span-3">
    <dt class="text-xs font-medium text-slate-500 uppercase tracking-wide">Lokasi Functional Location</dt>
    <dd class="mt-1 text-sm text-slate-900">
        @if($itAsset->functionalLocation)
            <span class="font-mono">{{ $itAsset->functionalLocation->code }}</span>
            <span class="text-slate-500">- {{ $itAsset->functionalLocation->name }}</span>
            <span class="text-xs text-slate-400 block mt-0.5">
                {{ $itAsset->functionalLocation->getLevelLabelAttribute() }} pada
                {{ $itAsset->functionalLocation->parent?->code ?? 'PT' }}
            </span>
        @else
            <span class="text-amber-600">Belum punya lokasi Functional Location.</span>
            <span class="text-xs text-slate-500 block mt-0.5">
                Perangkat ini belum bisa dipilih dari pencarian lokasi maupun muncul di
                hierarki wizard laporan. Lengkapi lewat tombol Edit Perangkat.
            </span>
        @endif
    </dd>
</div>

<div>
    <dt class="text-xs font-medium text-slate-500 uppercase tracking-wide">Tipe Equipment</dt>
    <dd class="mt-1 text-sm text-slate-900">{{ $itAsset->equipmentTypeLabel() }}</dd>
</div>

<div>
    <dt class="text-xs font-medium text-slate-500 uppercase tracking-wide">Sinkron ke Asset</dt>
    <dd class="mt-1 text-sm text-slate-900">
        @if($mirror)
            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-50 text-emerald-700">
                Terhubung
            </span>
            <a href="{{ route('assets.show', $mirror) }}" class="text-xs text-blue-600 hover:underline block mt-1">
                Lihat di Asset industri: {{ $mirror->tech_ident_no }}
            </a>
        @else
            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-50 text-amber-700">
                Belum tersinkron
            </span>
            <span class="text-xs text-slate-500 block mt-1">
                Jalankan perintah php artisan it:sync-assets atau simpan ulang perangkat
                ini untuk menyinkronkan.
            </span>
        @endif
    </dd>
</div>
