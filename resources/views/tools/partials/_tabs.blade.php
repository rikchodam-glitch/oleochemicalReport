@php
    $tabs = [
        ['route' => 'tools.index', 'label' => 'Dashboard Tools', 'aktif' => 'tools.index'],
        ['route' => 'tools.locations.index', 'label' => 'Lokasi & Rak', 'aktif' => 'tools.locations.*'],
        ['route' => 'tools.loans.index', 'label' => 'Riwayat Peminjaman', 'aktif' => 'tools.loans.*'],
    ];

    if (auth()->user()?->punyaAkses('tools')) {
        $tabs[] = ['route' => 'tools.keepers.index', 'label' => 'Tool Keeper', 'aktif' => 'tools.keepers.*'];
    }
@endphp

<div class="flex flex-wrap items-center gap-2 border-b border-slate-200 pb-2">
    @foreach($tabs as $tab)
        <a href="{{ route($tab['route']) }}"
           class="px-3 py-2 rounded-lg text-sm font-medium transition-colors
                  {{ request()->routeIs($tab['aktif']) ? 'bg-teal-600 text-white' : 'text-slate-600 hover:bg-slate-100' }}">
            {{ $tab['label'] }}
        </a>
    @endforeach
</div>
