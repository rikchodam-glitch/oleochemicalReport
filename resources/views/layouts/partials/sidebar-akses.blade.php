{{-- Menu tambahan khusus administrator dan penyembunyi menu tanpa hak akses. --}}
@php
    $penggunaSekarang = auth()->user();
    $menuTerlarang    = ($penggunaSekarang !== null && ! $penggunaSekarang->aksesPenuh())
        ? $penggunaSekarang->menuTerlarangUrls()
        : [];
@endphp

@if ($menuTerlarang !== [])
    <script>
        /**
         * Sembunyikan menu sidebar yang tidak diizinkan untuk Bagian pengguna.
         *
         * Penegakan izin sebenarnya dilakukan AksesMiddleware di sisi server;
         * penyembunyian ini hanya supaya tampilan menu tidak menyesatkan.
         */
        (function () {
            var menuTerlarang = @json($menuTerlarang);

            document.querySelectorAll('#appSidebar .sidebar-nav a').forEach(function (tautan) {
                if (menuTerlarang.indexOf(tautan.getAttribute('href')) !== -1) {
                    tautan.remove();
                }
            });
        })();
    </script>
@endif

@if ($penggunaSekarang?->bolehKelolaUser())
    <p class="text-slate-500 text-xs uppercase tracking-wider px-2 pt-4 pb-1">Manajemen Akses</p>

    <a href="{{ route('users.index') }}"
       class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm transition-colors
              {{ request()->routeIs('users.index') || request()->routeIs('users.create') || request()->routeIs('users.edit') ? 'bg-blue-600 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
        </svg>
        <span class="sidebar-link-text">Manajemen User</span>
    </a>

    <a href="{{ route('akses.index') }}"
       class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm transition-colors
              {{ request()->routeIs('akses.*') ? 'bg-blue-600 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
        </svg>
        <span class="sidebar-link-text">Hak Akses Bagian</span>
    </a>
@endif
