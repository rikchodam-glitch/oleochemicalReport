@extends('layouts.app')

@section('title', $itAsset->exists ? 'Edit Perangkat IT' : 'Tambah Perangkat IT')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('it-assets.index') }}" class="hover:text-slate-700">IT Assets</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">{{ $itAsset->exists ? 'Edit Perangkat' : 'Tambah Perangkat' }}</span>
@endsection

@section('content')
    @if ($errors->any())
        <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg text-sm text-red-800">
            <ul class="list-disc list-inside space-y-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST"
          action="{{ $itAsset->exists ? route('it-assets.update', $itAsset) : route('it-assets.store') }}"
          class="max-w-4xl space-y-6">
        @csrf
        @if($itAsset->exists)
            @method('PUT')
        @endif

        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100">
                <h3 class="text-sm font-semibold text-slate-900">Informasi Umum</h3>
            </div>
            <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1.5">Tipe Perangkat <span class="text-red-500">*</span></label>
                    <select name="device_type" id="device-type-select"
                            class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white">
                        @foreach($deviceTypes as $type)
                            <option value="{{ $type }}" {{ old('device_type', $itAsset->device_type) === $type ? 'selected' : '' }}>
                                {{ $deviceTypeLabels[$type] ?? $type }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1.5">Status</label>
                    <select name="status"
                            class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white">
                        <option value="active" {{ old('status', $itAsset->status) === 'active' ? 'selected' : '' }}>Aktif</option>
                        <option value="inactive" {{ old('status', $itAsset->status) === 'inactive' ? 'selected' : '' }}>Nonaktif</option>
                        <option value="needs_review" {{ old('status', $itAsset->status) === 'needs_review' ? 'selected' : '' }}>Perlu Review</option>
                        <option value="spare" {{ old('status', $itAsset->status) === 'spare' ? 'selected' : '' }}>Spare</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1.5">Tag Name</label>
                    <input type="text" name="tag_name" value="{{ old('tag_name', $itAsset->tag_name) }}"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="contoh: CCTV-EPE-1">
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1.5">Nama</label>
                    <input type="text" name="name" value="{{ old('name', $itAsset->name) }}"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="Nama perangkat / penanggung jawab">
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1.5">Brand</label>
                    <input type="text" name="brand" value="{{ old('brand', $itAsset->brand) }}"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="contoh: Hikvision">
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1.5">Model</label>
                    <input type="text" name="model" value="{{ old('model', $itAsset->model) }}"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="contoh: DS-2CD2043G2-I">
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1.5">Serial Number</label>
                    <input type="text" name="serial_number" value="{{ old('serial_number', $itAsset->serial_number) }}"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1.5">IP Address</label>
                    <div class="relative" id="ip-combobox">
                        <input type="text" id="ip-address-input" value="{{ old('ip_address', $itAsset->ip_address) }}"
                               placeholder="Ketik IP / VLAN untuk mencari&hellip;" autocomplete="off"
                               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <input type="hidden" name="ip_address" id="ip-address-value" value="{{ old('ip_address', $itAsset->ip_address) }}">
                        <svg class="absolute right-3 top-2.5 w-4 h-4 text-slate-400 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                        <ul id="ip-options" class="hidden absolute z-20 mt-1 w-full max-h-60 overflow-auto bg-white border border-slate-300 rounded-lg shadow-lg"></ul>
                    </div>
                    <p class="text-[11px] text-slate-400 mt-1">Pilih dari pool IP yang tersedia (kelola lewat menu Daftar IP).</p>
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1.5">Plant</label>
                    <input type="text" name="plant" value="{{ old('plant', $itAsset->plant) }}"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1.5">Department</label>
                    <input type="text" name="department" value="{{ old('department', $itAsset->department) }}"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1.5">Lokasi</label>
                    <input type="text" name="location" value="{{ old('location', $itAsset->location) }}"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="Gedung / lantai / ruangan">
                </div>

                <div class="md:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 mb-1.5">Aplikasi / Penggunaan</label>
                    <textarea name="application" rows="2"
                              class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                              placeholder="Kegunaan perangkat di lapangan">{{ old('application', $itAsset->application) }}</textarea>
                </div>

                <div class="md:col-span-2 {{ $itAsset->device_type === 'PC' ? 'hidden' : '' }}" id="specJsonSection">
                    <label class="block text-xs font-medium text-slate-500 mb-1.5">Spesifikasi (JSON opsional)</label>
                    <textarea name="spec" rows="3" spellcheck="false"
                              class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                              placeholder='{"cpu": "i5", "ram": "8GB"}'>{{ old('spec', $itAsset->spec ? json_encode($itAsset->spec) : '') }}</textarea>
                    <p class="text-[11px] text-slate-400 mt-1">Isi dengan format JSON bila perlu data tambahan per tipe perangkat.</p>
                </div>
            </div>
        </div>

        <div id="pcSpecSection" class="bg-white rounded-xl border border-slate-200 overflow-hidden {{ $itAsset->device_type === 'PC' ? '' : 'hidden' }}">
            @php
                $pcSpec = $itAsset->spec ?? [];
                $pcMon = $pcSpec['monitor'] ?? [];
                $pcCpu = $pcSpec['cpu'] ?? [];
                $pcRam = $pcCpu['ram'] ?? [];
            @endphp
            <div class="px-6 py-4 border-b border-slate-100">
                <h3 class="text-sm font-semibold text-slate-900">Spesifikasi PC</h3>
                <p class="text-[11px] text-slate-400 mt-1">Isi detail monitor, CPU, RAM, dan penyimpanan. S/N = nomor seri perangkat.</p>
            </div>
            <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 mb-1.5">Monitor</label>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-slate-500 mb-1.5">Model Monitor</label>
                            <input type="text" name="spec_pc_monitor_model" value="{{ old('spec_pc_monitor_model', $pcMon['model'] ?? '') }}"
                                   class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                   placeholder="contoh: Dell P2422H">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-500 mb-1.5">S/N Monitor</label>
                            <input type="text" name="spec_pc_monitor_sn" value="{{ old('spec_pc_monitor_sn', $pcMon['serial_number'] ?? '') }}"
                                   class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        </div>
                    </div>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 mb-1.5">CPU</label>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-slate-500 mb-1.5">Model CPU</label>
                            <input type="text" name="spec_pc_cpu_model" value="{{ old('spec_pc_cpu_model', $pcCpu['model'] ?? '') }}"
                                   class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                   placeholder="contoh: Core i5-12400">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-500 mb-1.5">S/N CPU</label>
                            <input type="text" name="spec_pc_cpu_sn" value="{{ old('spec_pc_cpu_sn', $pcCpu['serial_number'] ?? '') }}"
                                   class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        </div>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1.5">Kapasitas RAM</label>
                    <input type="text" name="spec_pc_ram_capacity" value="{{ old('spec_pc_ram_capacity', $pcRam['capacity'] ?? '') }}"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="contoh: 16GB">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1.5">Jenis RAM</label>
                    <input type="text" name="spec_pc_ram_type" value="{{ old('spec_pc_ram_type', $pcRam['type'] ?? '') }}"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="contoh: DDR4">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1.5">Penyimpanan</label>
                    <select name="spec_pc_storage"
                            class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white">
                        <option value="">&mdash; Pilih &mdash;</option>
                        <option value="SSD" {{ old('spec_pc_storage', $pcCpu['storage'] ?? '') === 'SSD' ? 'selected' : '' }}>SSD</option>
                        <option value="HDD" {{ old('spec_pc_storage', $pcCpu['storage'] ?? '') === 'HDD' ? 'selected' : '' }}>HDD</option>
                    </select>
                </div>
            </div>
        </div>

        <div id="cctvSpecSection" class="bg-white rounded-xl border border-slate-200 overflow-hidden {{ $itAsset->device_type === 'CCTV' ? '' : 'hidden' }}">
            @php
                $cctvSpec = $itAsset->spec ?? [];
            @endphp
            <div class="px-6 py-4 border-b border-slate-100">
                <h3 class="text-sm font-semibold text-slate-900">Spesifikasi CCTV</h3>
                <p class="text-[11px] text-slate-400 mt-1">Isi arah sorot (Pointing), NVR tempat tampil (Show), dan nomor channel pada NVR.</p>
            </div>
            <div class="p-6 grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1.5">Pointing</label>
                    <input type="text" name="spec_cctv_pointing" value="{{ old('spec_cctv_pointing', $cctvSpec['pointing'] ?? '') }}"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="contoh: Menghadap pintu utama">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1.5">Show (NVR)</label>
                    <select name="spec_cctv_show"
                            class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white">
                        <option value="">&mdash; Pilih NVR &mdash;</option>
                        @foreach($nvrOptions as $nvr)
                            @php
                                $nvrVal = $nvr->tag_name ?: ($nvr->name ?: ('#' . $nvr->id));
                            @endphp
                            <option value="{{ $nvrVal }}" {{ (old('spec_cctv_show', $cctvSpec['show'] ?? '') === $nvrVal) ? 'selected' : '' }}>{{ $nvrVal }}</option>
                        @endforeach
                    </select>
                    <p class="text-[11px] text-slate-400 mt-1">Berdasarkan daftar perangkat NVR yang terdaftar.</p>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1.5">Channel</label>
                    <input type="text" name="spec_cctv_channel" value="{{ old('spec_cctv_channel', $cctvSpec['channel'] ?? '') }}"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="contoh: 3">
                </div>
            </div>
        </div>

        <div id="snapshotSection" class="bg-white rounded-xl border border-slate-200 overflow-hidden {{ in_array($itAsset->device_type, ['CCTV', 'NVR'], true) ? '' : 'hidden' }}">
            <div class="px-6 py-4 border-b border-slate-100">
                <h3 class="text-sm font-semibold text-slate-900">CCTV Snapshot</h3>
            </div>
            <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 mb-1.5">URL Snapshot</label>
                    <input type="text" name="snapshot_url" value="{{ old('snapshot_url', $itAsset->snapshot_url) }}"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                           placeholder="rtsp://... atau http://...">
                    <p class="text-[11px] text-slate-400 mt-1">
                        rtsp:// untuk stream video (diambil 1 frame lewat ffmpeg), atau http(s):// untuk endpoint snapshot langsung.
                    </p>
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1.5">Username</label>
                    <input type="text" name="snapshot_username" value="{{ old('snapshot_username', $itAsset->snapshot_username) }}"
                           autocomplete="off"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1.5">Password</label>
                    <input type="password" name="snapshot_password" value=""
                           placeholder="{{ $itAsset->snapshot_password ? '••••••••' : '' }}"
                           autocomplete="new-password"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <p class="text-[11px] text-slate-400 mt-1">Kosongkan bila tidak ingin mengubah password.</p>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <button type="submit"
                    class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
                {{ $itAsset->exists ? 'Simpan Perubahan' : 'Simpan Perangkat' }}
            </button>
            <a href="{{ $itAsset->exists ? route('it-assets.show', $itAsset) : route('it-assets.index') }}"
               class="px-5 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                Batal
            </a>
        </div>
    </form>
@endsection

@push('scripts')
<script>
/**
 * Tampilkan atau sembunyikan bagian CCTV Snapshot berdasarkan tipe
 * perangkat yang dipilih. Bagian ini hanya relevan untuk CCTV dan NVR.
 */
function toggleSnapshotSection() {
    const select = document.getElementById('device-type-select');
    const section = document.getElementById('snapshotSection');
    if (!select || !section) return;

    const show = select.value === 'CCTV' || select.value === 'NVR';
    section.classList.toggle('hidden', !show);
}

/**
 * Untuk tipe PC, tampilkan form spesifikasi terstruktur (pcSpecSection) dan
 * sembunyikan textarea JSON mentah (specJsonSection). Untuk tipe lain,
 * kebalikannya: JSON mentah ditampilkan.
 */
function togglePcSpecSection() {
    const select = document.getElementById('device-type-select');
    const pcSection = document.getElementById('pcSpecSection');
    const jsonSection = document.getElementById('specJsonSection');
    if (!select || !pcSection || !jsonSection) return;

    const show = select.value === 'PC';
    pcSection.classList.toggle('hidden', !show);
    jsonSection.classList.toggle('hidden', show);
}

function toggleCctvSpecSection() {
    const select = document.getElementById('device-type-select');
    const section = document.getElementById('cctvSpecSection');
    if (!select || !section) return;

    section.classList.toggle('hidden', select.value !== 'CCTV');
}

var IP_GROUPS = @json($ipGroups);

function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}

function ipComboboxInit() {
    const input = document.getElementById('ip-address-input');
    const hidden = document.getElementById('ip-address-value');
    const list = document.getElementById('ip-options');
    if (!input || !hidden || !list) return;

    const groups = (typeof IP_GROUPS !== 'undefined') ? (IP_GROUPS || []) : [];

    function close() {
        list.classList.add('hidden');
    }

    function render(query) {
        const q = (query || '').toLowerCase().trim();
        list.innerHTML = '';
        let any = false;

        groups.forEach(function (group) {
            const matched = group.ips.filter(function (ip) {
                return !q || ip.address.toLowerCase().includes(q) || group.label.toLowerCase().includes(q);
            });
            if (!matched.length) return;
            any = true;

            const header = document.createElement('li');
            header.className = 'px-3 py-1.5 text-[11px] uppercase tracking-wide text-slate-400 bg-slate-50';
            header.textContent = group.label;
            list.appendChild(header);

            matched.forEach(function (ip) {
                const item = document.createElement('li');
                item.className = 'px-3 py-2 cursor-pointer text-sm text-slate-700 hover:bg-blue-50 flex items-center justify-between gap-2';
                item.innerHTML = '<span class="font-mono">' + escapeHtml(ip.address) + '</span>'
                    + '<span class="text-[11px] font-sans text-slate-400">' + escapeHtml(group.label) + '</span>';
                item.addEventListener('click', function () {
                    hidden.value = ip.address;
                    input.value = ip.address;
                    close();
                    list.querySelectorAll('li').forEach(function (el) { el.classList.remove('bg-blue-100'); });
                    item.classList.add('bg-blue-100');
                });
                list.appendChild(item);
            });
        });

        if (!any) {
            list.innerHTML = '<li class="px-3 py-2 text-sm text-slate-400">Tidak ada IP yang cocok.</li>';
        }

        list.classList.remove('hidden');
    }

    input.addEventListener('focus', function () { render(input.value); });
    input.addEventListener('input', function () { render(input.value); });
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') e.preventDefault();
        if (e.key === 'Escape') close();
    });
    input.addEventListener('blur', function () {
        const v = input.value;
        const found = groups.some(function (g) {
            return g.ips.some(function (ip) { return ip.address === v; });
        });
        hidden.value = found ? v : '';
    });
    document.addEventListener('click', function (e) {
        if (input !== e.target && !list.contains(e.target)) close();
    });
}

document.addEventListener('DOMContentLoaded', function () {
    const select = document.getElementById('device-type-select');
    if (select) {
        select.addEventListener('change', function () {
            toggleSnapshotSection();
            togglePcSpecSection();
            toggleCctvSpecSection();
        });
    }
    toggleSnapshotSection();
    togglePcSpecSection();
    toggleCctvSpecSection();

    ipComboboxInit();
});
</script>
@endpush

