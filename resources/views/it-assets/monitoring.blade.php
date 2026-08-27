@extends('layouts.app')

@section('title', 'Monitoring IT Assets')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('it-assets.index') }}" class="hover:text-slate-700">IT Assets</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Monitoring</span>
@endsection

@section('header-actions')
    @if($unreadNotificationCount > 0)
        <form method="POST" action="{{ route('it-assets.notifications.read-all') }}">
            @csrf
            <button type="submit"
                    class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                Tandai Semua Dibaca ({{ $unreadNotificationCount }})
            </button>
        </form>
    @endif
@endsection

@section('content')
    <!-- Ringkasan Status -->
    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4 mb-6">
        <x-stat-card label="Total Perangkat" :value="$summary['total']" icon="box" />
        <x-stat-card label="Online" :value="$summary['online']" value-color="text-green-600" icon="users" />
        <x-stat-card label="Offline" :value="$summary['offline']" value-color="text-red-600" icon="alert" />
        <x-stat-card label="Ping Tinggi" :value="$summary['high_latency']" value-color="text-amber-600" icon="alert" />
        <x-stat-card label="Error Snapshot" :value="$summary['snapshot_error']" value-color="text-purple-600" icon="document" />
        <x-stat-card label="Belum Dicek" :value="$summary['not_checked']" value-color="text-slate-400" icon="document" />
    </div>

    <!-- Perlu Perhatian -->
    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-slate-900">Perlu Perhatian</h3>
            <span class="text-xs text-slate-400">{{ $issues->count() }} perangkat</span>
        </div>

        @if($issues->isEmpty())
            <div class="px-5 py-10 text-center text-sm text-slate-400">
                Semua perangkat dalam kondisi normal.
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-100">
                            <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Perangkat</th>
                            <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Tipe</th>
                            <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">IP Address</th>
                            <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Masalah</th>
                            <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Latency</th>
                            <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Terakhir Dicek</th>
                            <th class="text-left text-xs font-medium text-slate-500 uppercase tracking-wide px-5 py-3">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @foreach($issues as $item)
                            @php $a = $item['asset']; @endphp
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="px-5 py-3.5">
                                    <a href="{{ route('it-assets.show', $a) }}" class="font-medium text-slate-900 hover:text-blue-600">
                                        {{ $a->tag_name ?? $a->name ?? ('#' . $a->id) }}
                                    </a>
                                </td>
                                <td class="px-5 py-3.5">
                                    <span class="inline-flex px-2 py-0.5 text-xs font-medium bg-slate-100 text-slate-700 rounded">
                                        {{ $deviceTypeLabels[$a->device_type] ?? $a->device_type }}
                                    </span>
                                </td>
                                <td class="px-5 py-3.5">
                                    <span class="font-mono text-xs text-slate-700">{{ $a->ip_address ?? '-' }}</span>
                                </td>
                                <td class="px-5 py-3.5">
                                    <div class="flex flex-wrap gap-1.5">
                                        @if(in_array('offline', $item['problems'], true))
                                            <x-status-badge status="error" label="Offline" />
                                        @endif
                                        @if(in_array('high_latency', $item['problems'], true))
                                            <x-status-badge status="pending" :label="'Ping Tinggi (' . $a->last_ping_latency . ' ms)'" />
                                        @endif
                                        @if(in_array('snapshot_error', $item['problems'], true))
                                            <x-status-badge status="needs_review" label="Snapshot Error" />
                                        @endif
                                    </div>
                                </td>
                                <td class="px-5 py-3.5 text-slate-600">
                                    {{ $a->last_ping_latency !== null ? $a->last_ping_latency . ' ms' : '-' }}
                                </td>
                                <td class="px-5 py-3.5 text-xs text-slate-400">
                                    {{ $a->last_ping_at?->format('d/m/Y H:i') ?? '-' }}
                                </td>
                                <td class="px-5 py-3.5">
                                    <div class="flex items-center gap-2.5">
                                        @if($a->ip_address)
                                            <button type="button" onclick="pingDevice({{ $a->id }}, this)"
                                                    class="text-blue-600 hover:text-blue-700 text-xs font-medium">
                                                Ping
                                            </button>
                                        @endif
                                        <a href="{{ route('it-assets.show', $a) }}" class="text-slate-500 hover:text-blue-600 text-xs font-medium">Detail</a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection


@push('scripts')
<script>
const itAssetPingUrlTemplate = '{{ route('it-assets.ping', ['itAsset' => '__ID__']) }}';

/**
 * Ping satu perangkat lalu muat ulang halaman agar ringkasan dan daftar
 * "Perlu Perhatian" langsung ter-update.
 *
 * @param {number} id
 * @param {HTMLButtonElement} button
 */
function pingDevice(id, button) {
    const originalText = button.textContent;
    button.disabled = true;
    button.textContent = 'Ping...';

    const url = itAssetPingUrlTemplate.replace('__ID__', id);

    fetch(url, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
    })
    .then(res => res.json())
    .then(data => {
        if (!data.success) {
            alert(data.message || 'Gagal melakukan ping.');
            return;
        }
        window.location.reload();
    })
    .catch(err => alert('Error: ' + err.message))
    .finally(() => {
        button.disabled = false;
        button.textContent = originalText;
    });
}
</script>
@endpush

