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
                    <input type="text" id="ip-address-search" placeholder="Cari IP&hellip;" autocomplete="off"
                           class="w-full mb-1.5 border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <select name="ip_address" id="ip-address-select"
                            class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white">
                        <option value="">&mdash; Pilih IP &mdash;</option>
                        @foreach($ipGroups as $group)
                            <optgroup label="{{ $group['label'] }}">
                                @foreach($group['ips'] as $ip)
                                    <option value="{{ $ip['address'] }}" {{ (old('ip_address', $itAsset->ip_address) === $ip['address'] || $ip['selected']) ? 'selected' : '' }}>{{ $ip['address'] }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
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

function filterIpOptions() {
    const search = document.getElementById('ip-address-search');
    const select = document.getElementById('ip-address-select');
    if (!search || !select) return;

    const q = search.value.toLowerCase().trim();

    Array.from(select.options).forEach(function (opt) {
        if (opt.value === '') return;
        const match = opt.textContent.toLowerCase().includes(q);
        opt.style.display = q && !match ? 'none' : '';
        opt.disabled = q && !match;
    });

    Array.from(select.querySelectorAll('optgroup')).forEach(function (og) {
        const anyVisible = Array.from(og.querySelectorAll('option')).some(function (o) {
            return o.style.display !== 'none';
        });
        og.style.display = anyVisible ? '' : 'none';
    });
}

document.addEventListener('DOMContentLoaded', function () {
    const select = document.getElementById('device-type-select');
    if (select) {
        select.addEventListener('change', function () {
            toggleSnapshotSection();
            togglePcSpecSection();
        });
    }
    toggleSnapshotSection();
    togglePcSpecSection();

    const ipSearch = document.getElementById('ip-address-search');
    const ipSelect = document.getElementById('ip-address-select');
    if (ipSearch && ipSelect) {
        ipSearch.addEventListener('input', filterIpOptions);
    }
});
</script>
@endpush

