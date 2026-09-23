@extends('layouts.app')

@section('title', $itAsset->tag_name ?? $itAsset->name ?? 'Detail Perangkat')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('it-assets.index') }}" class="hover:text-slate-700">IT Assets</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">{{ $itAsset->tag_name ?? $itAsset->name ?? 'Detail Perangkat' }}</span>
@endsection

@section('header-actions')
    <a href="{{ route('it-assets.index') }}"
       class="inline-flex items-center gap-2 px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
        &larr; Kembali ke Daftar
    </a>
@endsection

@section('content')
<div class="space-y-6">

    @if(session('success'))
        <div class="bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-lg">
            {{ session('success') }}
        </div>
    @endif
    @if(session('error'))
        <div class="bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3 rounded-lg">
            {{ session('error') }}
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- ── Informasi Perangkat ─────────────────────────────── --}}
        <div class="lg:col-span-2">
            <div class="bg-white rounded-xl border border-slate-200 p-6">
                <div class="flex items-start justify-between mb-4">
                    <div>
                        <span class="inline-flex px-2 py-0.5 text-xs font-medium bg-slate-100 text-slate-700 rounded mb-2">
                            {{ $deviceTypeLabel }}
                        </span>
                        <h3 class="text-base font-semibold text-slate-900">
                            {{ $itAsset->tag_name ?? $itAsset->name ?? '-' }}
                        </h3>
                        @if($itAsset->tag_name && $itAsset->name)
                            <p class="text-xs text-slate-400 mt-0.5">{{ $itAsset->name }}</p>
                        @endif
                    </div>
                    <x-status-badge :status="$itAsset->status" />
                </div>

                <dl class="grid grid-cols-1 md:grid-cols-3 gap-x-6 gap-y-4">
                    <div>
                        <dt class="text-xs font-medium text-slate-500 uppercase tracking-wide">Brand</dt>
                        <dd class="mt-1 text-sm text-slate-900">{{ $itAsset->brand ?? '-' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-slate-500 uppercase tracking-wide">Model</dt>
                        <dd class="mt-1 text-sm text-slate-900">{{ $itAsset->model ?? '-' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-slate-500 uppercase tracking-wide">Serial Number</dt>
                        <dd class="mt-1 font-mono text-sm text-slate-900">{{ $itAsset->serial_number ?? '-' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-slate-500 uppercase tracking-wide">IP Address</dt>
                        <dd class="mt-1 font-mono text-sm text-slate-900">{{ $itAsset->ip_address ?? '-' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-slate-500 uppercase tracking-wide">Plant</dt>
                        <dd class="mt-1 text-sm text-slate-900">{{ $itAsset->plant ?? '-' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-slate-500 uppercase tracking-wide">Departemen</dt>
                        <dd class="mt-1 text-sm text-slate-900">{{ $itAsset->department ?? '-' }}</dd>
                    </div>
                    <div class="md:col-span-3">
                        <dt class="text-xs font-medium text-slate-500 uppercase tracking-wide">Lokasi</dt>
                        <dd class="mt-1 text-sm text-slate-900">{{ $itAsset->location ?? '-' }}</dd>
                    </div>

                    @include('it-assets.partials._location-info')
                    @if($itAsset->application)
                    <div class="md:col-span-3">
                        <dt class="text-xs font-medium text-slate-500 uppercase tracking-wide">Application</dt>
                        <dd class="mt-1 text-sm text-slate-900">{{ $itAsset->application }}</dd>
                    </div>
                    @endif
                    <div>
                        <dt class="text-xs font-medium text-slate-500 uppercase tracking-wide">Data Source</dt>
                        <dd class="mt-1 text-sm text-slate-900">{{ $itAsset->data_source === 'import_excel' ? 'Import Excel' : 'Manual' }}</dd>
                    </div>
                    @if($itAsset->imported_at)
                    <div>
                        <dt class="text-xs font-medium text-slate-500 uppercase tracking-wide">Diimport</dt>
                        <dd class="mt-1 text-sm text-slate-900">{{ $itAsset->imported_at->format('d/m/Y H:i') }}</dd>
                    </div>
                    @endif
                </dl>

                {{-- Spec tambahan (JSON) --}}
                @if(!empty($itAsset->spec))
                    <div class="border-t border-slate-100 mt-5 pt-5">
                        <h4 class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-3">Spesifikasi Tambahan</h4>
                        <dl class="grid grid-cols-1 md:grid-cols-3 gap-x-6 gap-y-3">
                            @php
                                $specItems = $itAsset->device_type === 'PC'
                                    ? $itAsset->pcSpecItems()
                                    : ($itAsset->device_type === 'CCTV' ? $itAsset->cctvSpecItems() : $itAsset->spec);
                            @endphp
                            @foreach($specItems as $key => $value)
                                <div>
                                    <dt class="text-[11px] text-slate-400 uppercase tracking-wide">{{ str_replace('_', ' ', $key) }}</dt>
                                    <dd class="mt-0.5 text-sm text-slate-800 break-words">{{ $value }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                @endif
            </div>
        </div>

        {{-- ── Status Ping ─────────────────────────────────────── --}}
        <div>
            <div class="bg-white rounded-xl border border-slate-200 p-6 h-full">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-sm font-semibold text-slate-900">Status Ping</h3>
                    <span id="ping-badge-{{ $itAsset->id }}">
                        @if($itAsset->last_ping_status === 'online')
                            <x-status-badge status="active" :label="'Online' . ($itAsset->last_ping_latency !== null ? ' (' . $itAsset->last_ping_latency . 'ms)' : '')" />
                        @elseif($itAsset->last_ping_status === 'offline')
                            <x-status-badge status="error" label="Offline" />
                        @else
                            <x-status-badge status="inactive" label="Belum Dicek" />
                        @endif
                    </span>
                </div>

                @if($itAsset->last_ping_at)
                    <p class="text-xs text-slate-400 mb-4">Terakhir dicek: {{ $itAsset->last_ping_at->format('d/m/Y H:i:s') }}</p>
                @endif

                @if($itAsset->ip_address)
                    <button type="button" id="ping-btn-{{ $itAsset->id }}"
                            onclick="pingDevice({{ $itAsset->id }}, this)"
                            class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
                        Ping Sekarang
                    </button>
                @else
                    <p class="text-xs text-slate-400 italic">Perangkat ini tidak punya IP address.</p>
                @endif
                <a href="{{ route('it-assets.edit', $itAsset) }}"
                   class="mt-2 inline-flex items-center justify-center w-full px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                    Edit Perangkat
                </a>


                @if($recentPingLogs->isNotEmpty())
                    <div class="border-t border-slate-100 mt-5 pt-4">
                        <h4 class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-3">Riwayat Ping</h4>
                        <div class="space-y-2 max-h-64 overflow-y-auto">
                            @foreach($recentPingLogs as $log)
                                <div class="flex items-center justify-between text-xs">
                                    <span class="text-slate-500">{{ $log->checked_at->format('d/m/Y H:i:s') }}</span>
                                    <span class="font-medium {{ $log->status === 'online' ? 'text-green-600' : 'text-red-500' }}">
                                        {{ ucfirst($log->status) }}{{ $log->latency_ms !== null ? ' · ' . $log->latency_ms . 'ms' : '' }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- ── Panel CCTV Snapshot ─────────────────────────────────── --}}
    @if($itAsset->supportsSnapshot())
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-slate-900 flex items-center gap-2">
                    <svg class="w-4 h-4 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                    </svg>
                    CCTV Snapshot
                </h3>
                <div class="flex items-center gap-2">
                    <button type="button" onclick="openModal('snapshot-settings')"
                            class="px-3 py-1.5 border border-slate-300 hover:bg-slate-50 text-slate-700 text-xs font-medium rounded-lg transition-colors">
                        Pengaturan
                    </button>
                    <button type="button" id="snapshot-btn"
                            onclick="captureSnapshot({{ $itAsset->id }}, this)"
                            {{ empty($itAsset->snapshot_url) ? 'disabled' : '' }}
                            class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 disabled:opacity-40 disabled:cursor-not-allowed text-white text-xs font-medium rounded-lg transition-colors">
                        Ambil Snapshot
                    </button>
                </div>
            </div>
            <div class="p-6">
                @if(empty($itAsset->snapshot_url))
                    <p class="text-sm text-slate-400 italic">
                        Belum ada URL snapshot untuk perangkat ini. Klik "Pengaturan" untuk mengisi
                        URL RTSP (rtsp://...) atau HTTP snapshot endpoint (http://...) kamera.
                    </p>
                @else
                    <div id="snapshot-container">
                        @if($itAsset->last_snapshot_path)
                            <img id="snapshot-image"
                                 src="{{ Illuminate\Support\Facades\Storage::disk(config('cctv.snapshot_disk'))->url($itAsset->last_snapshot_path) }}"
                                 alt="Snapshot {{ $itAsset->tag_name }}"
                                 class="w-full max-w-xl rounded-lg border border-slate-200">
                            <p id="snapshot-timestamp" class="text-xs text-slate-400 mt-2">
                                Diambil: {{ $itAsset->last_snapshot_at?->format('d/m/Y H:i:s') ?? '-' }}
                            </p>
                        @else
                            <div id="snapshot-placeholder" class="w-full max-w-xl h-48 rounded-lg border border-dashed border-slate-300 flex items-center justify-center">
                                <p class="text-sm text-slate-400">Belum ada snapshot. Klik "Ambil Snapshot" di atas.</p>
                            </div>
                        @endif
                    </div>

                    @if($itAsset->last_snapshot_error)
                        <p id="snapshot-error" class="text-xs text-red-600 mt-3">
                            Percobaan terakhir gagal: {{ $itAsset->last_snapshot_error }}
                        </p>
                    @else
                        <p id="snapshot-error" class="text-xs text-red-600 mt-3 hidden"></p>
                    @endif
                @endif
            </div>
        </div>

        {{-- Modal pengaturan URL & kredensial snapshot --}}
        <x-modal name="snapshot-settings" title="Pengaturan CCTV Snapshot" max-width="lg">
            <form method="POST" action="{{ route('it-assets.snapshot-settings', $itAsset) }}" class="space-y-4">
                @csrf
                @method('PUT')

                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1.5">URL Snapshot</label>
                    <input type="text" name="snapshot_url" value="{{ old('snapshot_url', $itAsset->snapshot_url) }}"
                           placeholder="rtsp://192.168.x.x:554/Streaming/Channels/101 atau http://192.168.x.x/snapshot.jpg"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <p class="text-[11px] text-slate-400 mt-1">
                        Gunakan <span class="font-mono">rtsp://</span> untuk stream video (diambil 1 frame lewat ffmpeg),
                        atau <span class="font-mono">http(s)://</span> untuk endpoint snapshot langsung dari kamera.
                    </p>
                    @error('snapshot_url')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1.5">Username</label>
                        <input type="text" name="snapshot_username" value="{{ old('snapshot_username', $itAsset->snapshot_username) }}"
                               autocomplete="off"
                               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1.5">Password</label>
                        <input type="password" name="snapshot_password" value=""
                               placeholder="{{ $itAsset->snapshot_password ? '••••••••' : '' }}"
                               autocomplete="new-password"
                               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <p class="text-[11px] text-slate-400 mt-1">Kosongkan bila tidak ingin mengubah password.</p>
                    </div>
                </div>

                <div class="flex justify-end gap-2 pt-2 border-t border-slate-100">
                    <button type="button" onclick="closeModal('snapshot-settings')"
                            class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                        Batal
                    </button>
                    <button type="submit"
                            class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
                        Simpan
                    </button>
                </div>
            </form>
        </x-modal>
    @endif

</div>
@endsection

@push('scripts')
<script>
const itAssetPingUrlTemplate = '{{ route('it-assets.ping', ['itAsset' => '__ID__']) }}';
const itAssetSnapshotUrlTemplate = '{{ route('it-assets.snapshot', ['itAsset' => '__ID__']) }}';

/**
 * Ping satu perangkat lewat AJAX dan perbarui badge status ping inline
 * tanpa reload halaman. Sama seperti it-assets/index.blade.php.
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
    .then(res => res.json().then(data => ({ ok: res.ok, data })))
    .then(({ ok, data }) => {
        if (!ok || !data.success) {
            alert(data.message || 'Gagal melakukan ping.');
            return;
        }

        const badge = document.getElementById('ping-badge-' + id);
        if (!badge) return;

        if (data.status === 'online') {
            const latencyText = data.latency_ms !== null ? ' (' + data.latency_ms + 'ms)' : '';
            badge.innerHTML = '<span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">'
                + '<span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>Online' + latencyText + '</span>';
        } else {
            badge.innerHTML = '<span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700">'
                + '<span class="w-1.5 h-1.5 rounded-full bg-red-500"></span>Offline</span>';
        }
    })
    .catch(err => alert('Error: ' + err.message))
    .finally(() => {
        button.disabled = false;
        button.textContent = originalText;
    });
}

/**
 * Ambil snapshot terbaru lewat AJAX dan perbarui gambar/timestamp inline
 * tanpa reload halaman. ffmpeg di server bisa butuh beberapa detik untuk
 * merespons, jadi tombol dinonaktifkan sambil menunggu.
 *
 * @param {number} id
 * @param {HTMLButtonElement} button
 */
function captureSnapshot(id, button) {
    const originalText = button.textContent;
    button.disabled = true;
    button.textContent = 'Mengambil...';

    const url = itAssetSnapshotUrlTemplate.replace('__ID__', id);
    const errorEl = document.getElementById('snapshot-error');

    fetch(url, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
    })
    .then(res => res.json().then(data => ({ ok: res.ok, data })))
    .then(({ ok, data }) => {
        if (!ok || !data.success) {
            if (errorEl) {
                errorEl.textContent = 'Percobaan terakhir gagal: ' + (data.message || 'Gagal mengambil snapshot.');
                errorEl.classList.remove('hidden');
            }
            return;
        }

        if (errorEl) errorEl.classList.add('hidden');

        const container = document.getElementById('snapshot-container');
        let img = document.getElementById('snapshot-image');

        if (!img && container) {
            container.innerHTML = '<img id="snapshot-image" class="w-full max-w-xl rounded-lg border border-slate-200">'
                + '<p id="snapshot-timestamp" class="text-xs text-slate-400 mt-2"></p>';
            img = document.getElementById('snapshot-image');
        }

        if (img) {
            // Tambahkan cache-buster supaya browser tidak menampilkan gambar lama
            // dari cache meski nama file berbeda tiap capture.
            img.src = data.snapshot_url + '?t=' + Date.now();
        }

        const ts = document.getElementById('snapshot-timestamp');
        if (ts) ts.textContent = 'Diambil: ' + data.captured_at;
    })
    .catch(err => {
        if (errorEl) {
            errorEl.textContent = 'Error: ' + err.message;
            errorEl.classList.remove('hidden');
        }
    })
    .finally(() => {
        button.disabled = false;
        button.textContent = originalText;
    });
}
</script>
@endpush
