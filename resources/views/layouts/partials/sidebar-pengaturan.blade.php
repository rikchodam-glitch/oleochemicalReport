{{-- Menu grup Pengaturan: Manajemen User & Hak Akses (khusus administrator)
     serta Storage yang dipindah dari grup "Sistem". Tautan posisi lama
     dihapus oleh skrip di partial sidebar-akses. --}}
@if (auth()->user()?->bolehKelolaUser())
    <a href="{{ route('users.index') }}"
       class="menu-pindahan flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm transition-colors
              {{ request()->routeIs('users.index') || request()->routeIs('users.create') || request()->routeIs('users.edit') ? 'bg-blue-600 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
        </svg>
        <span class="sidebar-link-text">Manajemen User</span>
    </a>

    <a href="{{ route('akses.index') }}"
       class="menu-pindahan flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm transition-colors
              {{ request()->routeIs('akses.*') ? 'bg-blue-600 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
        </svg>
        <span class="sidebar-link-text">Hak Akses Bagian</span>
    </a>
@endif

<a href="{{ route('storage.index') }}"
   class="menu-pindahan flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm transition-colors
          {{ request()->routeIs('storage.*') ? 'bg-blue-600 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
    <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"/>
    </svg>
    <span class="sidebar-link-text">Storage</span>
</a>
