@extends('layouts.app')

@section('title', 'Detail Teknisi')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('technicians.index') }}" class="hover:text-slate-700">Teknisi</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">{{ $technician->name }}</span>
@endsection

@section('header-actions')
    <button onclick="broadcastMessage()"
            class="inline-flex items-center gap-2 px-4 py-2 border border-blue-300 hover:bg-blue-50 text-blue-700 text-sm font-medium rounded-lg transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"/>
        </svg>
        Kirim Pesan Telegram
    </button>
    <a href="{{ route('technicians.edit', $technician) }}"
       class="inline-flex items-center gap-2 px-4 py-2 border border-amber-300 hover:bg-amber-50 text-amber-700 text-sm font-medium rounded-lg transition-colors">
        Edit Teknisi
    </a>
    <form method="POST" action="{{ route('technicians.destroy', $technician) }}" class="inline"
          onsubmit="return confirm('Hapus teknisi ini? Semua laporan terkait akan tetap tersimpan.')">
        @csrf
        @method('DELETE')
        <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 border border-red-300 hover:bg-red-50 text-red-700 text-sm font-medium rounded-lg transition-colors">
            Hapus
        </button>
    </form>
@endsection

@section('content')
    <div x-data="technicianDetail()" class="space-y-6">

        <!-- Profile + Stats Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Profile -->
            <div>
                <div class="bg-white rounded-xl border border-slate-200 p-6">
                    <div class="text-center mb-4">
                        <div class="w-16 h-16 rounded-full bg-blue-100 flex items-center justify-center mx-auto">
                            <span class="text-2xl font-bold text-blue-600">{{ substr($technician->name, 0, 1) }}</span>
                        </div>
                        <h3 class="font-semibold text-slate-900 mt-3">{{ $technician->name }}</h3>
                        <x-status-badge :status="$technician->status" class="mt-1" />
                    </div>
                    <dl class="space-y-3 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-slate-500">NIK</dt>
                            <dd class="font-medium text-slate-900 font-mono">{{ $technician->nik ?? '-' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-slate-500">Group</dt>
                            <dd class="font-medium text-slate-900">{{ $technician->group ? (\App\Models\Technician::GROUPS[$technician->group] ?? $technician->group) : '-' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-slate-500">Bagian</dt>
                            <dd class="font-medium text-slate-900">{{ $technician->section ? (\App\Models\Technician::SECTIONS[$technician->section] ?? $technician->section) : '-' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-slate-500">Departemen</dt>
                            <dd class="font-medium text-slate-900">{{ $technician->department?->name ?? '-' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-slate-500">Telegram</dt>
                            <dd class="font-medium text-slate-900">{{ $technician->telegram_username ? '@' . $technician->telegram_username : '-' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-slate-500">Telegram ID</dt>
                            <dd class="font-medium text-slate-900 font-mono">{{ $technician->telegram_id ?? '-' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-slate-500">Terakhir Aktif</dt>
                            <dd class="font-medium text-slate-900">{{ $technician->last_active_at ? $technician->last_active_at->diffForHumans() : '-' }}</dd>
                        </div>
                        @if($technician->approved_at)
                        <div class="flex justify-between">
                            <dt class="text-slate-500">Disetujui</dt>
                            <dd class="font-medium text-slate-900">{{ $technician->approved_at->format('d/m/Y') }}</dd>
                        </div>
                        @endif
                    </dl>
                </div>
            </div>

            <!-- Stats -->
            <div class="lg:col-span-2 space-y-6">
                <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                    <div class="bg-white rounded-xl border border-slate-200 p-4 text-center">
                        <p class="text-2xl font-bold text-blue-600">{{ $stats['total_reports'] }}</p>
                        <p class="text-xs text-slate-500 mt-1">Total Laporan</p>
                    </div>
                    <div class="bg-white rounded-xl border border-green-200 p-4 text-center">
                        <p class="text-2xl font-bold text-green-600">{{ $stats['completed_reports'] }}</p>
                        <p class="text-xs text-slate-500 mt-1">Selesai</p>
                    </div>
                    <div class="bg-white rounded-xl border border-amber-200 p-4 text-center">
                        <p class="text-2xl font-bold text-amber-600">{{ $stats['pending_reports'] }}</p>
                        <p class="text-xs text-slate-500 mt-1">Draft</p>
                    </div>
                    <div class="bg-white rounded-xl border border-violet-200 p-4 text-center">
                        <p class="text-2xl font-bold text-violet-600">{{ $stats['last_report'] ? $stats['last_report']->format('d/m') : '-' }}</p>
                        <p class="text-xs text-slate-500 mt-1">Laporan Terakhir</p>
                    </div>
                    <div class="bg-white rounded-xl border border-teal-200 p-4 text-center">
                        <p class="text-2xl font-bold text-teal-600">{{ $stats['reports_this_month'] ?? 0 }}</p>
                        <p class="text-xs text-slate-500 mt-1">Bulan Ini</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Assigned Assets -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100">
                <h3 class="text-sm font-semibold text-slate-900">Asset yang Ditugaskan</h3>
            </div>
            <div class="px-6 py-4">
                @php
                    $assignedAssets = $technician->assignedAssets()->with(['company', 'area', 'subArea'])->get();
                @endphp
                @if($assignedAssets->isNotEmpty())
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        @foreach($assignedAssets as $asset)
                            <div class="flex items-start gap-3 p-3 bg-purple-50 border border-purple-200 rounded-lg">
                                <div class="w-8 h-8 rounded-full bg-purple-600 flex items-center justify-center text-white text-xs font-bold shrink-0 mt-0.5">
                                    {{ substr($asset->description ?? $asset->tech_ident_no, 0, 1) }}
                                </div>
                                <div class="min-w-0 flex-1">
                                    <a href="{{ route('assets.show', $asset) }}" class="text-sm font-medium text-slate-900 hover:text-blue-600 truncate block">
                                        {{ $asset->tech_ident_no ?? $asset->equipment_no ?? '-' }}
                                    </a>
                                    <p class="text-xs text-slate-500 truncate">{{ $asset->description }}</p>
                                    <p class="text-[11px] text-slate-400 mt-0.5">
                                        {{ $asset->area?->code ?? '-' }} / {{ $asset->subArea?->code ?? '-' }}
                                    </p>
                                    @if($asset->pivot->note)
                                        <p class="text-xs text-slate-500 italic mt-1">Catatan: {{ $asset->pivot->note }}</p>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="text-center py-6 text-sm text-slate-400">Belum ditugaskan ke asset mana pun.</div>
                @endif
            </div>
        </div>

        <!-- Koneksi Bot Telegram -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-slate-900">Koneksi Bot Telegram</h3>
                @if($technician->telegram_id)
                    <a href="{{ route('bot.index') }}#teknisi-aktif"
                       class="text-xs text-blue-600 hover:text-blue-700 font-medium">
                        Lihat di Panel Bot
                    </a>
                @endif
            </div>
            <div class="px-6 py-4">
                @if($technician->telegram_id)
                    @php
                        $lastActive = $technician->last_active_at;
                        if ($lastActive && $lastActive->gt(now()->subDays(7))) {
                            $badgeKelas = 'bg-green-100 text-green-700';
                            $badgeLabel = 'Terhubung & Aktif';
                        } elseif ($lastActive) {
                            $badgeKelas = 'bg-amber-100 text-amber-700';
                            $badgeLabel = 'Terhubung, Tidak Aktif';
                        } else {
                            $badgeKelas = 'bg-slate-100 text-slate-500';
                            $badgeLabel = 'Terhubung, Belum Pernah Aktif';
                        }
                    @endphp
                    <div class="flex items-center gap-2 mb-4">
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium {{ $badgeKelas }}">
                            <span class="w-1.5 h-1.5 rounded-full
                                {{ $lastActive && $lastActive->gt(now()->subDays(7)) ? 'bg-green-500' : ($lastActive ? 'bg-amber-400' : 'bg-slate-400') }}">
                            </span>
                            {{ $badgeLabel }}
                        </span>
                    </div>
                    <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                        <div>
                            <dt class="text-slate-500 text-xs mb-0.5">Telegram ID</dt>
                            <dd class="font-mono text-slate-900">{{ $technician->telegram_id }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-500 text-xs mb-0.5">Username</dt>
                            <dd class="text-slate-900">{{ $technician->telegram_username ? '@' . $technician->telegram_username : '-' }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-500 text-xs mb-0.5">Terakhir Aktif</dt>
                            <dd class="text-slate-900">
                                @if($lastActive)
                                    {{ $lastActive->format('d/m/Y H:i') }}
                                    <span class="text-xs text-slate-400">({{ $lastActive->diffForHumans() }})</span>
                                @else
                                    <span class="text-slate-400">-</span>
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-slate-500 text-xs mb-0.5">Laporan via Bot</dt>
                            <dd class="font-semibold text-slate-900">{{ $botStats['reports_via_bot'] }}</dd>
                        </div>
                        @if($botStats['laporan_terakhir_via_bot'])
                        <div class="col-span-2">
                            <dt class="text-slate-500 text-xs mb-0.5">Laporan Terakhir via Bot</dt>
                            <dd class="text-slate-900">{{ $botStats['laporan_terakhir_via_bot']->format('d/m/Y') }}</dd>
                        </div>
                        @endif
                    </dl>
                @else
                    <div class="flex items-center gap-3 py-2">
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-500">
                            <span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span>
                            Belum Terhubung
                        </span>
                        <span class="text-xs text-slate-400">Teknisi belum pernah mendaftar via bot.</span>
                    </div>
                @endif
            </div>
        </div>

        <!-- Recent Reports -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100">
                <h3 class="font-medium text-slate-900">Riwayat Laporan</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-100">
                            <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Tanggal</th>
                            <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Deskripsi</th>
                            <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Tipe</th>
                            <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @forelse($technician->reports as $report)
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="px-5 py-3.5 text-slate-600">{{ $report->report_date->format('d/m/Y') }}</td>
                                <td class="px-5 py-3.5">
                                    <p class="text-slate-700 truncate max-w-xs">{{ Str::limit($report->work_description, 50) }}</p>
                                </td>
                                <td class="px-5 py-3.5">
                                    <x-status-badge :status="$report->report_type" />
                                </td>
                                <td class="px-5 py-3.5">
                                    <x-status-badge :status="$report->status" />
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-5 py-8 text-center text-sm text-slate-400">Belum ada laporan.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Modal Kirim Pesan -->
        <div x-show="showBroadcastModal"
             class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50"
             x-cloak>
            <div class="bg-white rounded-xl shadow-xl max-w-lg w-full overflow-hidden"
                 @@click.away="showBroadcastModal = false">
                <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-slate-900">Kirim Pesan ke {{ $technician->name }}</h3>
                    <button @@click="showBroadcastModal = false" class="text-slate-400 hover:text-slate-600">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
                <div class="p-6 space-y-4">
                    @if(!$technician->telegram_id)
                        <div class="flex items-center gap-2 p-3 bg-amber-50 border border-amber-200 rounded-lg">
                            <svg class="w-5 h-5 text-amber-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                            </svg>
                            <p class="text-xs text-amber-700">Teknisi belum memiliki Telegram ID. Pesan akan disimpan sebagai log.</p>
                        </div>
                    @elseif($technician->status !== 'active')
                        <div class="flex items-center gap-2 p-3 bg-amber-50 border border-amber-200 rounded-lg">
                            <svg class="w-5 h-5 text-amber-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                            </svg>
                            <p class="text-xs text-amber-700">Teknisi tidak aktif. Pesan tetap dikirim jika Telegram ID tersedia.</p>
                        </div>
                    @endif

                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Pesan <span class="text-red-500">*</span></label>
                        <textarea x-model="broadcastMessage" rows="4"
                                  class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                                  placeholder="Tulis pesan untuk teknisi..."></textarea>
                    </div>
                </div>
                <div class="px-6 py-4 border-t border-slate-100 flex justify-end gap-2">
                    <button @@click="showBroadcastModal = false" class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg">Batal</button>
                    <button @@click="sendMessage" :disabled="!broadcastMessage.trim()"
                            class="px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm font-medium rounded-lg">
                        <span x-show="!sending">Kirim Pesan</span>
                        <span x-show="sending" class="flex items-center gap-2">
                            <svg class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                            Mengirim...
                        </span>
                    </button>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
function technicianDetail() {
    return {
        showBroadcastModal: false,
        broadcastMessage: '',
        sending: false,

        openBroadcast() {
            this.showBroadcastModal = true;
            this.broadcastMessage = '';
        },

        sendMessage() {
            if (!this.broadcastMessage.trim()) return;
            this.sending = true;

            fetch('{{ route('technicians.broadcast', $technician) }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ message: this.broadcastMessage }),
            })
            .then(res => res.json())
            .then(data => {
                this.showBroadcastModal = false;
                if (data.success) {
                    alert(data.message);
                } else {
                    alert('Gagal: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(err => alert('Terjadi kesalahan jaringan.'))
            .finally(() => { this.sending = false; });
        },
    };
}

// Untuk tombol Kirim Pesan di header (non-Alpine)
function broadcastMessage() {
    const el = document.querySelector('[x-data]');
    if (el && el.__x) {
        el.__x.$data.openBroadcast();
    }
}
</script>
@endpush
