{{-- Menu Kolaborasi dipindah ke grup Menu Utama (ikon dibedakan dari menu Teknisi). --}}
<a href="{{ route('collaboration.index') }}"
   class="menu-pindahan flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm transition-colors
          {{ request()->routeIs('collaboration.*') ? 'bg-blue-600 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
    <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/>
    </svg>
    <span class="sidebar-link-text">Kolaborasi</span>
</a>
