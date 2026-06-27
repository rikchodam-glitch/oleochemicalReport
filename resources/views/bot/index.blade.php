@extends('layouts.app')

@section('title', 'Panel Bot Telegram')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Bot Telegram</span>
@endsection

@section('content')
    <div x-data="{ activeTab: 'settings' }">
        <!-- Header -->
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-3">
                <h2 class="text-lg font-semibold text-slate-900">PANEL BOT TELEGRAM</h2>
                <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium
                    {{ $stats['bot_status'] === 'online' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                    <span class="w-1.5 h-1.5 rounded-full {{ $stats['bot_status'] === 'online' ? 'bg-green-500' : 'bg-red-500' }}"></span>
                    {{ $stats['bot_status'] === 'online' ? 'ONLINE' : 'OFFLINE' }}
                </span>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="grid grid-cols-2 md:grid-cols-6 gap-4 mb-6">
            <div class="bg-white rounded-xl border border-slate-200 p-4">
                <p class="text-2xl font-bold {{ $stats['bot_status'] === 'online' ? 'text-green-600' : 'text-red-600' }}">
                    {{ strtoupper($stats['bot_status']) }}
                </p>
                <p class="text-xs text-slate-500 mt-1">Status Bot</p>
            </div>
            <div class="bg-white rounded-xl border border-slate-200 p-4">
                <p class="text-2xl font-bold text-blue-600">{{ $stats['active_technicians'] }}</p>
                <p class="text-xs text-slate-500 mt-1">Teknisi Aktif</p>
            </div>
            <div class="bg-white rounded-xl border border-slate-200 p-4">
                <p class="text-2xl font-bold text-teal-600">{{ $stats['terhubung_ke_bot'] }}</p>
                <p class="text-xs text-slate-500 mt-1">Terhubung Bot</p>
            </div>
            <div class="bg-white rounded-xl border border-slate-200 p-4">
                <p class="text-2xl font-bold text-violet-600">{{ $stats['reports_via_bot'] }}</p>
                <p class="text-xs text-slate-500 mt-1">Laporan Via Bot</p>
            </div>
            <div class="bg-white rounded-xl border border-slate-200 p-4">
                <p class="text-2xl font-bold text-amber-600">{{ $stats['reports_today'] }}</p>
                <p class="text-xs text-slate-500 mt-1">Hari Ini</p>
            </div>
            <div class="bg-white rounded-xl border border-slate-200 p-4">
                <p class="text-2xl font-bold text-red-600">{{ $stats['unknown_assets'] }}</p>
                <p class="text-xs text-slate-500 mt-1">Unknown Assets</p>
            </div>
        </div>

        <!-- Tab Navigation -->
        <div class="border-b border-slate-200 mb-6">
            <nav class="flex gap-6 -mb-px">
                <button @click="activeTab = 'settings'"
                        class="pb-3 text-sm font-medium border-b-2 transition-colors"
                        :class="activeTab === 'settings' ? 'border-blue-600 text-blue-600' : 'border-transparent text-slate-500 hover:text-slate-700'">
                    Status & Koneksi
                </button>
                <button @click="activeTab = 'registrations'"
                        class="pb-3 text-sm font-medium border-b-2 transition-colors"
                        :class="activeTab === 'registrations' ? 'border-blue-600 text-blue-600' : 'border-transparent text-slate-500 hover:text-slate-700'">
                    Pendaftaran
                    @if($registrations->count() > 0)
                        <span class="ml-1.5 px-1.5 py-0.5 text-xs bg-amber-100 text-amber-600 rounded-full">{{ $registrations->count() }}</span>
                    @endif
                </button>
                <button @click="activeTab = 'teknisi-aktif'"
                        class="pb-3 text-sm font-medium border-b-2 transition-colors"
                        :class="activeTab === 'teknisi-aktif' ? 'border-blue-600 text-blue-600' : 'border-transparent text-slate-500 hover:text-slate-700'">
                    Teknisi Aktif Bot
                    @if($stats['terhubung_ke_bot'] > 0)
                        <span class="ml-1.5 px-1.5 py-0.5 text-xs bg-teal-100 text-teal-600 rounded-full">{{ $stats['terhubung_ke_bot'] }}</span>
                    @endif
                </button>
                <button @click="activeTab = 'unknown-assets'"
                        class="pb-3 text-sm font-medium border-b-2 transition-colors"
                        :class="activeTab === 'unknown-assets' ? 'border-blue-600 text-blue-600' : 'border-transparent text-slate-500 hover:text-slate-700'">
                    Unknown Assets
                    @if($unknownAssets->count() > 0)
                        <span class="ml-1.5 px-1.5 py-0.5 text-xs bg-red-100 text-red-600 rounded-full">{{ $unknownAssets->count() }}</span>
                    @endif
                </button>
            </nav>
        </div>

        <!-- Tab: Settings -->
        <div x-show="activeTab === 'settings'" x-transition>
            <div class="bg-white rounded-xl border border-slate-200 p-6 max-w-2xl">
                <h3 class="font-medium text-slate-900 mb-4">Pengaturan Bot Telegram</h3>
                <form method="POST" action="{{ route('bot.settings.update') }}" class="space-y-4">
                    @csrf

                    <div class="space-y-1.5">
                        <label class="block text-sm font-medium text-slate-700">Token Bot</label>
                        <input type="password" name="token"
                               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                               placeholder="••••••••••••••••••••••">
                        <p class="text-xs text-slate-400">Dari @BotFather di Telegram. Tersembunyi untuk keamanan.</p>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-1.5">
                            <label class="block text-sm font-medium text-slate-700">Status Bot</label>
                            <select name="status"
                                    class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="active">Aktif</option>
                                <option value="inactive">Nonaktif</option>
                            </select>
                        </div>
                        <div class="space-y-1.5">
                            <label class="block text-sm font-medium text-slate-700">Auto Approve</label>
                            <select name="auto_approve"
                                    class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="1">Ya</option>
                                <option value="0">Tidak (Manual)</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-1.5">
                            <label class="block text-sm font-medium text-slate-700">Max Item per Halaman</label>
                            <input type="number" name="max_item" value="5" min="1" max="20"
                                   class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div class="space-y-1.5">
                            <label class="block text-sm font-medium text-slate-700">Channel Notifikasi</label>
                            <input type="text" name="notif_channel"
                                   class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                                   placeholder="@channel_username">
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <label class="block text-sm font-medium text-slate-700">Webhook URL</label>
                        <input type="text" readonly value="{{ route('telegram.webhook') }}"
                               class="w-full border border-slate-200 bg-slate-50 rounded-lg px-3 py-2 text-sm text-slate-500 font-mono">
                        <p class="text-xs text-slate-400">URL ini akan menerima update dari Telegram.</p>
                    </div>

                    <div class="flex gap-3 pt-2">
                        <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
                            Simpan Pengaturan
                        </button>
                        <button type="button" onclick="testBotConnection()"
                                class="px-4 py-2 border border-green-500 hover:bg-green-50 text-green-600 text-sm font-medium rounded-lg transition-colors">
                            Test Koneksi
                        </button>
                        <form method="POST" action="{{ route('bot.set-webhook') }}" class="inline">
                            @csrf
                            <button type="submit" class="px-4 py-2 border border-blue-300 hover:bg-blue-50 text-blue-600 text-sm font-medium rounded-lg transition-colors">
                                Set Webhook
                            </button>
                        </form>
                        <form method="POST" action="{{ route('bot.delete-webhook') }}" class="inline">
                            @csrf
                            <button type="submit" class="px-4 py-2 border border-red-300 hover:bg-red-50 text-red-600 text-sm font-medium rounded-lg transition-colors">
                                Hapus Webhook
                            </button>
                        </form>
                    </div>
                </form>

                <!-- Long Polling (Development Mode) -->
                <div class="border-t border-slate-100 pt-6 mt-6">
                    <h3 class="font-medium text-slate-900 mb-4">Long Polling (Development Mode)</h3>
                    <p class="text-xs text-slate-400 mb-4">
                        Karena aplikasi berjalan di localhost (tanpa domain publik), webhook Telegram tidak bisa mengirim update.
                        Jalankan polling di terminal untuk menerima pesan bot.
                    </p>

                    <div class="flex items-center gap-3 mb-4">
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium
                            {{ $pollingRunning ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                            <span class="w-1.5 h-1.5 rounded-full {{ $pollingRunning ? 'bg-green-500' : 'bg-red-500' }}"></span>
                            {{ $pollingRunning ? 'POLLING AKTIF' : 'POLLING NONAKTIF' }}
                        </span>
                    </div>

                    @if(!$pollingRunning)
                    <div class="bg-slate-900 text-green-400 rounded-lg p-4 mb-4 font-mono text-sm">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs text-slate-500">Jalankan di terminal:</span>
                            <button onclick="copyCommand()" class="text-xs text-blue-400 hover:text-blue-300">📋 Salin</button>
                        </div>
                        <code id="pollCommand">php artisan telegram:poll</code>
                    </div>
                @endif

                    <div class="flex gap-3">
                        @if($pollingRunning)
                        <form method="POST" action="{{ route('bot.polling.stop') }}" class="inline">
                            @csrf
                            <button type="submit" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-lg transition-colors">
                                ■ Hentikan Polling
                            </button>
                        </form>
                        @endif
                        <button onclick="checkPollingStatus()"
                                class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                            🔄 Cek Status
                        </button>
                </div>

                    <div class="mt-4">
                        <div class="flex items-center justify-between mb-2">
                            <label class="text-xs font-medium text-slate-500">Log Terakhir</label>
                            <button onclick="refreshPollingLog()" class="text-xs text-blue-600 hover:text-blue-700">Refresh</button>
                        </div>
                        <pre id="pollingLog" class="bg-slate-900 text-green-400 text-xs p-4 rounded-lg overflow-x-auto max-h-40 font-mono leading-relaxed">Klik "Cek Status" atau mulai polling untuk melihat log.</pre>
                    </div>
                </div>
            </div>
        </div>


        <!-- Tab: Teknisi Aktif Bot -->
        <div x-show="activeTab === 'teknisi-aktif'" x-transition>
            <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
                    <h3 class="font-medium text-slate-900">Teknisi Terhubung ke Bot</h3>
                    <span class="text-xs text-slate-400">{{ $activeBotTechnicians->count() }} teknisi</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-slate-50 border-b border-slate-100">
                                <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Nama</th>
                                <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Telegram</th>
                                <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Departemen</th>
                                <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Status</th>
                                <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Terakhir Aktif</th>
                                <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            @forelse($activeBotTechnicians as $tech)
                                @php
                                    // Tentukan status koneksi berdasarkan last_active_at
                                    $lastActive = $tech->last_active_at;
                                    if ($lastActive && $lastActive->gt(now()->subDays(7))) {
                                        $koneksiKelas = 'bg-green-100 text-green-700';
                                        $koneksiLabel = 'Aktif';
                                    } elseif ($lastActive) {
                                        $koneksiKelas = 'bg-amber-100 text-amber-700';
                                        $koneksiLabel = 'Tidak Aktif';
                                    } else {
                                        $koneksiKelas = 'bg-slate-100 text-slate-500';
                                        $koneksiLabel = 'Belum Pernah Aktif';
                                    }
                                @endphp
                                <tr class="hover:bg-slate-50 transition-colors">
                                    <td class="px-5 py-3 font-medium text-slate-900">
                                        <a href="{{ route('technicians.show', $tech) }}"
                                           class="hover:text-blue-600 transition-colors">
                                            {{ $tech->name }}
                                        </a>
                                    </td>
                                    <td class="px-5 py-3 text-slate-600">
                                        <div>{{ $tech->telegram_username ? '@' . $tech->telegram_username : '-' }}</div>
                                        <div class="text-xs text-slate-400 font-mono">{{ $tech->telegram_id }}</div>
                                    </td>
                                    <td class="px-5 py-3 text-slate-600">{{ $tech->department?->code ?? '-' }}</td>
                                    <td class="px-5 py-3">
                                        <x-status-badge :status="$tech->status" />
                                    </td>
                                    <td class="px-5 py-3">
                                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium {{ $koneksiKelas }}">
                                            {{ $koneksiLabel }}
                                        </span>
                                        @if($lastActive)
                                            <div class="text-xs text-slate-400 mt-0.5">{{ $lastActive->diffForHumans() }}</div>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3">
                                        <div class="flex items-center gap-3">
                                            <a href="{{ route('technicians.show', $tech) }}"
                                               class="text-blue-600 hover:text-blue-700 text-xs font-medium">
                                                Lihat Detail
                                            </a>
                                            @if($tech->status === 'active')
                                                <form method="POST" action="{{ route('technicians.suspend', $tech) }}" class="inline"
                                                      onsubmit="return confirm('Tangguhkan {{ addslashes($tech->name) }}?')">
                                                    @csrf
                                                    <button type="submit"
                                                            class="text-amber-600 hover:text-amber-700 text-xs font-medium">
                                                        Tangguhkan
                                                    </button>
                                                </form>
                                            @elseif($tech->status === 'suspended')
                                                <form method="POST" action="{{ route('technicians.reactivate', $tech) }}" class="inline">
                                                    @csrf
                                                    <button type="submit"
                                                            class="text-green-600 hover:text-green-700 text-xs font-medium">
                                                        Aktifkan
                                                    </button>
                                                </form>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-5 py-8 text-center text-slate-400">
                                        Belum ada teknisi yang terhubung ke bot.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Tab: Unknown Assets -->
        <div x-show="activeTab === 'unknown-assets'" x-transition>
            <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-100">
                    <h3 class="font-medium text-slate-900">Unknown Assets</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-slate-50 border-b border-slate-100">
                                <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Keyword</th>
                                <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Dari Laporan</th>
                                <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Tanggal</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            @forelse($unknownAssets as $ua)
                                <tr class="hover:bg-slate-50 transition-colors">
                                    <td class="px-5 py-3 font-mono text-sm text-slate-900">{{ $ua->keyword_mentioned }}</td>
                                    <td class="px-5 py-3 text-slate-600">{{ $ua->report_id ? '#' . $ua->report_id : '-' }}</td>
                                    <td class="px-5 py-3 text-xs text-slate-500">{{ $ua->created_at->format('d/m/Y') }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="px-5 py-8 text-center text-slate-400">Tidak ada unknown asset.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Tab: Registrations -->
        <div x-show="activeTab === 'registrations'" x-transition>

            {{-- Modal persetujuan dengan form lengkap --}}
            <div x-data="{
                    open: false,
                    regId: null,
                    regName: '',
                    regNik: '',
                    actionUrl: ''
                }"
                @open-approve-modal.window="
                    regId      = $event.detail.id;
                    regName    = $event.detail.name;
                    regNik     = $event.detail.nik;
                    actionUrl  = $event.detail.url;
                    open       = true;
                ">

                {{-- Overlay --}}
                <div x-show="open"
                     x-transition:enter="transition ease-out duration-150"
                     x-transition:enter-start="opacity-0"
                     x-transition:enter-end="opacity-100"
                     x-transition:leave="transition ease-in duration-100"
                     x-transition:leave-start="opacity-100"
                     x-transition:leave-end="opacity-0"
                     class="fixed inset-0 z-40 bg-black/40"
                     @click="open = false">
                </div>

                {{-- Panel modal --}}
                <div x-show="open"
                     x-transition:enter="transition ease-out duration-150"
                     x-transition:enter-start="opacity-0 scale-95"
                     x-transition:enter-end="opacity-100 scale-100"
                     x-transition:leave="transition ease-in duration-100"
                     x-transition:leave-start="opacity-100 scale-100"
                     x-transition:leave-end="opacity-0 scale-95"
                     class="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div class="bg-white rounded-xl shadow-xl w-full max-w-lg" @click.stop>
                        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                            <h3 class="font-semibold text-slate-900">Setujui Pendaftaran</h3>
                            <button @click="open = false" class="text-slate-400 hover:text-slate-600">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>

                        <form method="POST" :action="actionUrl">
                            @csrf
                            <div class="px-6 py-5 space-y-4">

                                <p class="text-sm text-slate-500">
                                    Lengkapi data teknisi sebelum menyimpan. Nama dan NIK sudah diisi dari data pendaftaran.
                                </p>

                                <div class="space-y-1.5">
                                    <label class="block text-sm font-medium text-slate-700">Nama <span class="text-red-500">*</span></label>
                                    <input type="text" name="name" :value="regName" required
                                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>

                                <div class="space-y-1.5">
                                    <label class="block text-sm font-medium text-slate-700">NIK</label>
                                    <input type="text" name="nik" :value="regNik"
                                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                                           placeholder="Nomor Induk Karyawan">
                                </div>

                                <div class="space-y-1.5">
                                    <label class="block text-sm font-medium text-slate-700">Departemen</label>
                                    <select name="department_id"
                                            class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                        <option value="">-- Pilih Departemen --</option>
                                        @foreach($departments as $dept)
                                            <option value="{{ $dept->id }}">{{ $dept->name }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="grid grid-cols-2 gap-4">
                                    <div class="space-y-1.5">
                                        <label class="block text-sm font-medium text-slate-700">Group</label>
                                        <select name="group"
                                                class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                            <option value="">-- Pilih Group --</option>
                                            @foreach(\App\Models\Technician::GROUPS as $key => $label)
                                                <option value="{{ $key }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="space-y-1.5">
                                        <label class="block text-sm font-medium text-slate-700">Section</label>
                                        <select name="section"
                                                class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                            <option value="">-- Pilih Section --</option>
                                            @foreach(\App\Models\Technician::SECTIONS as $key => $label)
                                                <option value="{{ $key }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>

                            </div>

                            <div class="px-6 py-4 border-t border-slate-100 flex justify-end gap-3">
                                <button type="button" @click="open = false"
                                        class="px-4 py-2 text-sm font-medium text-slate-700 border border-slate-300 rounded-lg hover:bg-slate-50 transition-colors">
                                    Batal
                                </button>
                                <button type="submit"
                                        class="px-4 py-2 text-sm font-medium text-white bg-green-600 hover:bg-green-700 rounded-lg transition-colors">
                                    Setujui & Buat Teknisi
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

            </div>{{-- akhir x-data modal --}}

            {{-- Tabel pendaftaran pending --}}
            <div class="bg-white rounded-xl border border-slate-200 overflow-hidden mb-6">
                <div class="px-5 py-4 border-b border-slate-100">
                    <h3 class="font-medium text-slate-900">Pendaftaran Menunggu Persetujuan</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-slate-50 border-b border-slate-100">
                                <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Nama</th>
                                <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">NIK</th>
                                <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Telegram</th>
                                <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Tgl Daftar</th>
                                <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            @forelse($registrations as $reg)
                                <tr class="hover:bg-slate-50 transition-colors">
                                    <td class="px-5 py-3 font-medium text-slate-900">{{ $reg->name }}</td>
                                    <td class="px-5 py-3 font-mono text-slate-600">{{ $reg->nik ?? '-' }}</td>
                                    <td class="px-5 py-3 text-slate-600">{{ $reg->telegram_username ? '@' . $reg->telegram_username : '-' }}</td>
                                    <td class="px-5 py-3 text-xs text-slate-500">{{ $reg->created_at->format('d/m/Y H:i') }}</td>
                                    <td class="px-5 py-3">
                                        <div class="flex gap-3">
                                            {{-- Tombol Setujui: buka modal dengan data prefill --}}
                                            <button type="button"
                                                    @click="$dispatch('open-approve-modal', {
                                                        id:   {{ $reg->id }},
                                                        name: '{{ addslashes($reg->name) }}',
                                                        nik:  '{{ addslashes($reg->nik ?? '') }}',
                                                        url:  '{{ route('bot.registrations.approve-with-details', $reg) }}'
                                                    })"
                                                    class="text-green-600 hover:text-green-700 text-xs font-medium">
                                                Setujui
                                            </button>
                                            <form method="POST" action="{{ route('bot.registrations.reject', $reg) }}" class="inline">
                                                @csrf
                                                <button type="submit"
                                                        onclick="return confirm('Tolak pendaftaran {{ addslashes($reg->name) }}?')"
                                                        class="text-red-600 hover:text-red-700 text-xs font-medium">
                                                    Tolak
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-5 py-8 text-center text-slate-400">Tidak ada pendaftaran yang menunggu persetujuan.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Tabel riwayat pendaftaran yang sudah diproses --}}
            <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-100">
                    <h3 class="font-medium text-slate-900">Riwayat Pendaftaran</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-slate-50 border-b border-slate-100">
                                <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Nama</th>
                                <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">NIK</th>
                                <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Telegram</th>
                                <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Tgl Daftar</th>
                                <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Status</th>
                                <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Diproses</th>
                                <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Teknisi Terbuat</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            @forelse($registrationHistory as $hist)
                                <tr class="hover:bg-slate-50 transition-colors">
                                    <td class="px-5 py-3 font-medium text-slate-900">{{ $hist->name }}</td>
                                    <td class="px-5 py-3 font-mono text-slate-600">{{ $hist->nik ?? '-' }}</td>
                                    <td class="px-5 py-3 text-slate-600">{{ $hist->telegram_username ? '@' . $hist->telegram_username : '-' }}</td>
                                    <td class="px-5 py-3 text-xs text-slate-500">{{ $hist->created_at->format('d/m/Y H:i') }}</td>
                                    <td class="px-5 py-3"><x-status-badge :status="$hist->status" /></td>
                                    <td class="px-5 py-3 text-xs text-slate-500">
                                        @if($hist->processor)
                                            {{ $hist->processor->name }}<br>
                                            {{ $hist->processed_at?->format('d/m/Y H:i') }}
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td class="px-5 py-3">
                                        @if($hist->status === 'approved' && $hist->technician)
                                            <a href="{{ route('technicians.show', $hist->technician) }}"
                                               class="text-blue-600 hover:text-blue-700 text-xs font-medium">
                                                {{ $hist->technician->name }}
                                            </a>
                                        @elseif($hist->status === 'approved')
                                            <span class="text-xs text-slate-400">Data tidak ditemukan</span>
                                        @else
                                            <span class="text-xs text-slate-400">-</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-5 py-8 text-center text-slate-400">Belum ada riwayat pendaftaran.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
function testBotConnection() {
    fetch('{{ route('bot.test-connection') }}', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
    })
    .then(res => res.json())
    .then(data => {
        alert(data.message);
    });
}

function copyCommand() {
    const cmd = document.getElementById('pollCommand');
    navigator.clipboard.writeText(cmd.textContent.trim());
    alert('Command disalin: ' + cmd.textContent.trim());
}

function checkPollingStatus() {
    fetch('{{ route('bot.polling.status') }}')
        .then(res => res.json())
        .then(data => {
            const logEl = document.getElementById('pollingLog');
            let info = 'Status: ' + (data.running ? '🟢 AKTIF' : '🔴 NONAKTIF');
            info += '\n\n--- LOG TERAKHIR ---\n';
            info += data.last_log || '(Belum ada log)';
            logEl.textContent = info;
        });
}

function refreshPollingLog() {
    checkPollingStatus();
}

// Auto refresh every 10 seconds jika polling aktif
setInterval(() => {
    const statusBadge = document.querySelector('.bg-green-100');
    if (statusBadge && statusBadge.textContent.includes('POLLING AKTIF')) {
        checkPollingStatus();
    }
}, 10000);
</script>
@endpush
