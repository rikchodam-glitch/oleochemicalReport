<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Dashboard') — Oleochemical Pro</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @vite('resources/css/app.css')
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script defer>
        /**
         * Komponen Alpine.js untuk widget jam server real-time di header.
         * Selisih (offset) antara waktu server dan waktu browser dihitung
         * sekali saat halaman dimuat, lalu jam berjalan mengikuti clock
         * browser + offset tersebut agar tetap akurat tanpa polling ulang
         * ke server setiap detik. Dipakai admin untuk mengecek apakah
         * waktu server sudah sinkron dengan waktu sebenarnya.
         *
         * @param  string serverIso  Waktu server dalam format ISO 8601
         * @return object            Data dan method komponen Alpine
         */
        function serverClock(serverIso) {
            return {
                offset: 0,
                display: '',
                startClock() {
                    const serverTime = new Date(serverIso);
                    this.offset = serverTime.getTime() - Date.now();
                    this.tick();
                    setInterval(() => this.tick(), 1000);
                },
                tick() {
                    const now = new Date(Date.now() + this.offset);
                    const hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
                    const bulan = [
                        'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
                        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
                    ];
                    const pad = (value) => String(value).padStart(2, '0');

                    this.display = hari[now.getDay()] + ', ' + pad(now.getDate()) + ' '
                        + bulan[now.getMonth()] + ' ' + now.getFullYear() + ' - '
                        + pad(now.getHours()) + ':' + pad(now.getMinutes()) + ':' + pad(now.getSeconds());
                },
            };
        }
    </script>
    <script defer>
        /**
         * Registrasi store global Alpine.js untuk menyimpan status buka/tutup
         * (collapse) setiap node pada tree Functional Location. State disimpan
         * di localStorage browser agar tetap konsisten walau halaman di-refresh.
         * Node yang belum pernah tersimpan statusnya dianggap tertutup secara
         * default, termasuk saat halaman pertama kali diakses.
         */
        document.addEventListener('alpine:init', () => {
            Alpine.store('funcLocTree', {
                storageKey: 'funcloc-tree-state',
                state: {},

                init() {
                    this.state = this.readFromStorage();
                },

                /**
                 * Membaca state collapse tersimpan dari localStorage.
                 *
                 * @return object Peta nodeId => boolean (true berarti terbuka)
                 */
                readFromStorage() {
                    try {
                        const raw = localStorage.getItem(this.storageKey);
                        return raw ? JSON.parse(raw) : {};
                    } catch (e) {
                        return {};
                    }
                },

                /**
                 * Menulis ulang state collapse saat ini ke localStorage.
                 *
                 * @return void
                 */
                persist() {
                    try {
                        localStorage.setItem(this.storageKey, JSON.stringify(this.state));
                    } catch (e) {
                        // localStorage tidak tersedia (misal mode privat), abaikan
                    }
                },

                /**
                 * Mengecek apakah sebuah node dalam kondisi terbuka.
                 *
                 * @param  number nodeId
                 * @return bool
                 */
                isOpen(nodeId) {
                    return this.state[nodeId] === true;
                },

                /**
                 * Membalik status buka/tutup sebuah node lalu menyimpannya.
                 *
                 * @param  number nodeId
                 * @return void
                 */
                toggle(nodeId) {
                    this.state[nodeId] = !this.isOpen(nodeId);
                    this.persist();
                },

                /**
                 * Membuka seluruh node pada daftar id yang diberikan sekaligus.
                 *
                 * @param  number[] nodeIds
                 * @return void
                 */
                expandAll(nodeIds) {
                    nodeIds.forEach((nodeId) => {
                        this.state[nodeId] = true;
                    });
                    this.persist();
                },

                /**
                 * Menutup seluruh node pada daftar id yang diberikan sekaligus.
                 *
                 * @param  number[] nodeIds
                 * @return void
                 */
                collapseAll(nodeIds) {
                    nodeIds.forEach((nodeId) => {
                        this.state[nodeId] = false;
                    });
                    this.persist();
                },
            });
        });
    </script>

    <style>
        body { font-family: 'Inter', system-ui, -apple-system, sans-serif; }
        .sidebar-scroll { scrollbar-width: thin; scrollbar-color: #475569 transparent; }
        .sidebar-scroll::-webkit-scrollbar { width: 4px; }
        .sidebar-scroll::-webkit-scrollbar-thumb { background: #475569; border-radius: 4px; }
        [x-cloak] { display: none !important; }

        /* Kerangka sidebar: logo dan blok user tetap, daftar menu yang di-scroll. */
        #appSidebar { display: flex; flex-direction: column; width: 16rem; transition: width .2s ease; }
        .sidebar-brand { flex: 0 0 auto; display: flex; flex-direction: column; }
        .sidebar-nav { flex: 1 1 auto; overflow-y: auto; padding-bottom: 1rem; scrollbar-width: thin; scrollbar-color: #475569 transparent; }
        .sidebar-user {
            flex: 0 0 auto;
            background: #1e293b;
            border-top: 1px solid #334155;
            padding: 1rem;
        }

        /* Panel sidebar bisa diciutkan jadi rel tipis; konten utama ikut melebar. */
        #appMain { transition: margin-left .2s ease; }
        body.sidebar-hidden #appSidebar { width: 4.5rem; }
        body.sidebar-hidden #appSidebar .sidebar-brand { align-items: center; padding-left: 0; padding-right: 0; }
        body.sidebar-hidden #appSidebar .sidebar-brand-text,
        body.sidebar-hidden #appSidebar .sidebar-nav,
        body.sidebar-hidden #appSidebar .sidebar-user { display: none; }
        body.sidebar-hidden #appSidebar .sidebar-logo-mark { display: inline-flex; }


        body.sidebar-hidden #appMain { margin-left: 4.5rem; }

        /* Tombol buka/tutup panel yang ada di header. */
        .sidebar-toggle-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
            width: 2.25rem;
            height: 2.25rem;
            margin-right: .625rem;
            border: 0;
            border-radius: .5rem;
            background: transparent;
            color: #64748b;
            cursor: pointer;
        }
        .sidebar-toggle-btn:hover { background: #f1f5f9; color: #0f172a; }
        /* Logo ringkas yang tampil saat panel diciutkan. */
        .sidebar-logo-mark {
            display: none;
            align-items: center;
            justify-content: center;
            width: 2.5rem;
            height: 2.5rem;
            border: 0;
            border-radius: .75rem;
            background: #2563eb;
            color: #ffffff;
            font-weight: 700;
            font-size: .875rem;
            letter-spacing: .02em;
            cursor: pointer;
        }
        .sidebar-logo-mark:hover { background: #1d4ed8; }



        /* Grup menu yang bisa diciutkan (accordion). */
        .sidebar-group-toggle {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            gap: .5rem;
            border: 0;
            background: transparent;
            cursor: pointer;
            text-align: left;
        }
        .sidebar-caret { width: .875rem; height: .875rem; transition: transform .15s ease; }
        .sidebar-group.is-collapsed .sidebar-caret { transform: rotate(-90deg); }
        .sidebar-group.is-collapsed .sidebar-group-items { display: none; }
    </style>
</head>
<body class="bg-slate-50 text-slate-900 antialiased">

    <!-- Sidebar -->
    <aside id="appSidebar" class="fixed top-0 left-0 z-40 h-screen bg-slate-800 sidebar-scroll">
        <!-- Logo -->
        <div class="px-4 pt-6 pb-4 border-b border-slate-700 sidebar-brand">
            <button type="button" id="sidebarLogoBtn" class="sidebar-logo-mark" title="Perluas menu" aria-label="Perluas menu">OP</button>
            <div class="sidebar-brand-text">

            <h2 class="text-white font-bold text-lg tracking-tight">Oleochemical Pro</h2>
            <p class="text-slate-400 text-xs mt-0.5">Maintenance System</p>
            </div>

        </div>

        <!-- Menu -->
        <nav class="mt-4 space-y-1 px-3 sidebar-nav">
            {{-- Grup: Menu Utama --}}
            <div class="sidebar-group" data-sidebar-group="utama">
                <button type="button" data-sidebar-group-toggle="utama" aria-expanded="true"
                        class="sidebar-group-toggle text-slate-500 text-xs uppercase tracking-wider px-2 pt-4 pb-1">
                    Menu Utama
                    <svg class="sidebar-caret" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>
                <div class="sidebar-group-items space-y-1">
                    <a href="{{ route('dashboard') }}"
                       class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm transition-colors
                              {{ request()->routeIs('dashboard') ? 'bg-blue-600 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
                        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                        </svg>
                        Dashboard
                    </a>

                    <a href="{{ route('assets.index') }}"
                       class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm transition-colors
                              {{ request()->routeIs('assets.*') ? 'bg-blue-600 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
                        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                        </svg>
                        Asset Management
                    </a>

                    <a href="{{ route('func-locs.index') }}"
                       class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm transition-colors
                              {{ request()->routeIs('func-locs.*') ? 'bg-blue-600 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
                        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a2 2 0 01-2.828 0l-4.243-4.243a8 8 0 1111.314 0z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        Functional Location
                    </a>

                    <a href="{{ route('technicians.index') }}"
                       class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm transition-colors
                              {{ request()->routeIs('technicians.*') ? 'bg-blue-600 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
                        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                        </svg>
                        Teknisi
                    </a>

                    <a href="{{ route('reports.index') }}"
                       class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm transition-colors
                              {{ request()->routeIs('reports.*') ? 'bg-blue-600 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
                        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        Laporan
                    </a>
                </div>
            </div>


            {{-- Grup: IT & Jaringan (dipisah dari menu utama) --}}
            <div class="sidebar-group" data-sidebar-group="it">
                <button type="button" data-sidebar-group-toggle="it" aria-expanded="true"
                        class="sidebar-group-toggle text-slate-500 text-xs uppercase tracking-wider px-2 pt-4 pb-1">
                    IT & Jaringan
                    <svg class="sidebar-caret" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>
                <div class="sidebar-group-items space-y-1">
                    <a href="{{ route('it-assets.index') }}"
                       class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm transition-colors
                              {{ request()->routeIs('it-assets.*') && !request()->routeIs('it-assets.layouts.*') && !request()->routeIs('it-assets.ip-addresses.*') ? 'bg-blue-600 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
                        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                        </svg>
                        IT Assets
                    </a>

                    <a href="{{ route('it-assets.layouts.index') }}"
                       class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm transition-colors
                              {{ request()->routeIs('it-assets.layouts.*') ? 'bg-blue-600 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
                        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/>
                        </svg>
                        Denah IT
                    </a>

                    <a href="{{ route('it-assets.ip-addresses.index') }}"
                       class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm transition-colors
                              {{ request()->routeIs('it-assets.ip-addresses.*') ? 'bg-blue-600 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
                        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 6h6M9 12h6M9 18h6M5 6h.01M5 12h.01M5 18h.01M19 6h.01M19 12h.01M19 18h.01"/>
                        </svg>
                        Daftar IP
                    </a>
                </div>
            </div>


            {{-- Grup: Reliability & Maintenance --}}
            <div class="sidebar-group" data-sidebar-group="reliability">
                <button type="button" data-sidebar-group-toggle="reliability" aria-expanded="true"
                        class="sidebar-group-toggle text-slate-500 text-xs uppercase tracking-wider px-2 pt-4 pb-1">
                    Reliability & Maintenance
                    <svg class="sidebar-caret" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>
                <div class="sidebar-group-items space-y-1">
                    <a href="{{ route('cm.overview') }}"
                       class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm transition-colors
                              {{ request()->routeIs('cm.overview') || request()->routeIs('cm.findings') || request()->routeIs('cm.monitoring') || request()->routeIs('cm.equipment-status') || request()->routeIs('cm.report-analysis') || request()->routeIs('cm.equipment-show') || request()->routeIs('cm.import.show') ? 'bg-blue-600 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
                        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                        </svg>
                        Condition Monitoring
                    </a>

                    <a href="{{ route('reliability.index') }}"
                       class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm transition-colors
                              {{ request()->routeIs('reliability.*') ? 'bg-blue-600 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
                        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 8v8m-4-5v5m-4-2v2m-2 4h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                        Reliability Dashboard
                    </a>
                </div>
            </div>


            {{-- Grup: Pengaturan --}}
            <div class="sidebar-group" data-sidebar-group="pengaturan">
                <button type="button" data-sidebar-group-toggle="pengaturan" aria-expanded="true"
                        class="sidebar-group-toggle text-slate-500 text-xs uppercase tracking-wider px-2 pt-4 pb-1">
                    Pengaturan
                    <svg class="sidebar-caret" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>
                <div class="sidebar-group-items space-y-1">
                    <a href="{{ route('ai-providers.index') }}"
                       class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm transition-colors
                              {{ request()->routeIs('ai-providers.*') ? 'bg-blue-600 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
                        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
                        </svg>
                        AI Providers
                    </a>

                    <a href="{{ route('bot.index') }}"
                       class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm transition-colors
                              {{ request()->routeIs('bot.*') ? 'bg-blue-600 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
                        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/>
                        </svg>
                        Bot Telegram
                    </a>
                </div>
            </div>
        </nav>

        <!-- User Info Bottom -->
        <div class="sidebar-user">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-full bg-blue-600 flex items-center justify-center text-white text-xs font-bold shrink-0">
                    {{ substr(Auth::user()->name, 0, 1) }}
                </div>
                <div class="min-w-0">
                    <p class="text-white text-sm font-medium truncate">{{ Auth::user()->name }}</p>
                    <p class="text-slate-400 text-xs capitalize">{{ Auth::user()->role }}</p>
                </div>
                <form method="POST" action="{{ route('logout') }}" class="ml-auto">
                    @csrf
                    <button type="submit" class="text-slate-400 hover:text-red-400 transition-colors" title="Logout">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                        </svg>
                    </button>
                </form>
            </div>
        </div>
    </aside>


    <!-- Main Content -->
    <div id="appMain" class="ml-64 min-h-screen">
        <!-- Header -->
        <header class="bg-white border-b border-slate-200 px-6 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center">
                    <button type="button" id="sidebarToggle" class="sidebar-toggle-btn"
                            title="Sembunyikan / tampilkan menu" aria-label="Sembunyikan atau tampilkan menu" aria-expanded="true">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                        </svg>
                    </button>
                    <div>
                        <nav class="flex items-center gap-1 text-sm text-slate-500">
                            @yield('breadcrumb')
                        </nav>
                        <h1 class="text-xl font-semibold text-slate-900 mt-1">@yield('title')</h1>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    @yield('header-actions')
                </div>
            </div>
        </header>

        <!-- Flash Messages -->
        @if (session('success'))
            <div class="mx-6 mt-4 p-4 bg-green-50 border border-green-200 rounded-lg text-sm text-green-800 flex items-start gap-3">
                <svg class="w-5 h-5 text-green-500 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <div>{{ session('success') }}</div>
            </div>
        @endif

        @if (session('error'))
            <div class="mx-6 mt-4 p-4 bg-red-50 border border-red-200 rounded-lg text-sm text-red-800 flex items-start gap-3">
                <svg class="w-5 h-5 text-red-500 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <div>{{ session('error') }}</div>
            </div>
        @endif

        @if (session('warning'))
            <div class="mx-6 mt-4 p-4 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-800 flex items-start gap-3">
                <svg class="w-5 h-5 text-amber-500 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                </svg>
                <div>{{ session('warning') }}</div>
            </div>
        @endif

        <!-- Content -->
        <main class="p-6">
            @yield('content')
        </main>
    </div>


    <!-- Pengaturan panel sidebar: sembunyikan panel + ciutkan grup menu -->
    <script>
        (function () {
            'use strict';

            var HIDDEN_KEY = 'app-sidebar-hidden';
            var GROUPS_KEY = 'app-sidebar-groups';

            /**
             * Membaca status ciut grup menu dari localStorage.
             *
             * @return {object} Peta kunci grup => boolean (true berarti diciutkan)
             */
            function readGroups() {
                try {
                    var raw = localStorage.getItem(GROUPS_KEY);
                    return raw ? JSON.parse(raw) : {};
                } catch (e) {
                    return {};
                }
            }

            /**
             * Menyimpan status ciut grup menu ke localStorage.
             *
             * @param  {object} state
             * @return {void}
             */
            function saveGroups(state) {
                try {
                    localStorage.setItem(GROUPS_KEY, JSON.stringify(state));
                } catch (e) {
                    // localStorage tidak tersedia (misal mode privat), abaikan.
                }
            }

            /**
             * Membaca status tampil/sembunyi panel sidebar dari localStorage.
             *
             * @return {string|null} '1' disembunyikan, '0' ditampilkan, null belum pernah disimpan
             */
            function readHidden() {
                try {
                    return localStorage.getItem(HIDDEN_KEY);
                } catch (e) {
                    return null;
                }
            }

            /**
             * Menyimpan status tampil/sembunyi panel sidebar.
             *
             * @param  {boolean} hidden
             * @return {void}
             */
            function saveHidden(hidden) {
                try {
                    localStorage.setItem(HIDDEN_KEY, hidden ? '1' : '0');
                } catch (e) {
                    // abaikan
                }
            }

            /**
             * Menerapkan status tampil/sembunyi panel sidebar.
             *
             * @param  {boolean} hidden
             * @return {void}
             */
            function applyHidden(hidden) {
                document.body.classList.toggle('sidebar-hidden', hidden);
                var btn = document.getElementById('sidebarToggle');
                if (btn) {
                    btn.setAttribute('aria-expanded', hidden ? 'false' : 'true');
                }
            }

            /**
             * Menerapkan status ciut untuk setiap grup menu.
             *
             * @param  {object} state
             * @return {void}
             */
            function applyGroups(state) {
                var nodes = document.querySelectorAll('[data-sidebar-group]');
                Array.prototype.forEach.call(nodes, function (group) {
                    var key = group.getAttribute('data-sidebar-group');
                    var collapsed = state[key] === true;
                    group.classList.toggle('is-collapsed', collapsed);

                    var toggle = group.querySelector('[data-sidebar-group-toggle]');
                    if (toggle) {
                        toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                    }
                });
            }

            // Default: panel terbuka di layar lebar, tertutup di layar kecil.
            var stored = readHidden();
            var hidden = stored === null ? window.innerWidth < 1024 : stored === '1';
            applyHidden(hidden);

            var groupState = readGroups();
            applyGroups(groupState);

            var toggleBtn = document.getElementById('sidebarToggle');
            if (toggleBtn) {
                toggleBtn.addEventListener('click', function () {
                    hidden = !document.body.classList.contains('sidebar-hidden');
                    applyHidden(hidden);
                    saveHidden(hidden);
                });
            }

            // Klik logo saat panel diciutkan akan membuka panel kembali.
            var logoBtn = document.getElementById('sidebarLogoBtn');
            if (logoBtn) {
                logoBtn.addEventListener('click', function () {
                    applyHidden(false);
                    saveHidden(false);
                });
            }


            var groupToggles = document.querySelectorAll('[data-sidebar-group-toggle]');
            Array.prototype.forEach.call(groupToggles, function (btn) {
                btn.addEventListener('click', function () {
                    var key = btn.getAttribute('data-sidebar-group-toggle');
                    groupState[key] = groupState[key] !== true;
                    applyGroups(groupState);
                    saveGroups(groupState);
                });
            });
        })();
    </script>

    @stack('modals')
    @stack('scripts')
</body>
</html>

