@extends('layouts.app')

@section('title', 'Daftar IP — IT Assets')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('it-assets.index') }}" class="hover:text-slate-700">IT Assets</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Daftar IP</span>
@endsection

@section('content')
    @if(session('success'))
        <div class="mb-4 px-4 py-3 bg-green-50 border border-green-200 rounded-lg text-sm text-green-800">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 px-4 py-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-800">{{ session('error') }}</div>
    @endif
    @if($errors->any())
        <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg text-sm text-red-800">
            <ul class="list-disc list-inside space-y-1">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100">
            <h3 class="text-sm font-semibold text-slate-900">Tambah Subnet (Generate IP)</h3>
            <p class="text-[11px] text-slate-400 mt-1">Masukkan VLAN dan subnet (/24 dst). Network otomatis dihitung 192.168.[VLAN].0, lalu host usable dibuat otomatis.</p>
        </div>
        <form method="POST" action="{{ route('it-assets.ip-addresses.store') }}" class="p-6 grid grid-cols-1 md:grid-cols-5 gap-4 items-end">
            @csrf
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1.5">VLAN <span class="text-red-500">*</span></label>
                <input type="number" name="vlan" value="{{ old('vlan') }}" min="1" max="255"
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                       placeholder="contoh: 41" required>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1.5">Subnet <span class="text-red-500">*</span></label>
                <select name="cidr"
                        class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white" required>
                    @foreach(range(24, 30) as $c)
                        <option value="{{ $c }}" {{ old('cidr') == $c ? 'selected' : '' }}>/{{ $c }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1.5">Nama</label>
                <input type="text" name="name" value="{{ old('name') }}"
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                       placeholder="opsional">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1.5">Keterangan</label>
                <input type="text" name="description" value="{{ old('description') }}"
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                       placeholder="opsional">
            </div>
            <div>
                <button type="submit"
                        class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">Generate IP</button>
            </div>
        </form>
    </div>

    <div class="mt-6 bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100">
            <h3 class="text-sm font-semibold text-slate-900">Subnet Tersedia</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-100">
                <thead>
                    <tr class="text-left text-xs text-slate-400 uppercase tracking-wide">
                        <th class="px-6 py-3">VLAN</th>
                        <th class="px-6 py-3">Network</th>
                        <th class="px-6 py-3">Netmask</th>
                        <th class="px-6 py-3">Broadcast</th>
                        <th class="px-6 py-3">IP Tersedia</th>
                        <th class="px-6 py-3">IP Terpakai</th>
                        <th class="px-6 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($networks as $network)
                        <tr>
                            <td class="px-6 py-3 text-sm text-slate-800">{{ $network->vlan }}</td>
                            <td class="px-6 py-3 text-sm font-mono text-slate-800">{{ $network->network }}/{{ $network->cidr }}</td>
                            <td class="px-6 py-3 text-sm font-mono text-slate-600">{{ $network->netmask }}</td>
                            <td class="px-6 py-3 text-sm font-mono text-slate-600">{{ $network->broadcast }}</td>
                            <td class="px-6 py-3 text-sm text-slate-800">{{ $network->available_count }}</td>
                            <td class="px-6 py-3 text-sm text-slate-800">{{ $network->assigned_count }}</td>
                            <td class="px-6 py-3 text-right">
                                <button type="button" class="ip-toggle px-3 py-1.5 text-xs font-medium text-blue-600 hover:text-blue-700"
                                        data-network="{{ $network->id }}" data-url="{{ route('it-assets.ip-addresses.show', $network) }}">Lihat IP</button>
                                <form method="POST" action="{{ route('it-assets.ip-addresses.destroy', $network) }}" class="inline"
                                      onsubmit="return confirm('Hapus subnet {{ $network->prefix() }} beserta semua IP-nya?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="px-3 py-1.5 text-xs font-medium text-red-600 hover:text-red-700">Hapus</button>
                                </form>
                            </td>
                        </tr>
                        <tr class="ip-detail hidden" data-detail="{{ $network->id }}">
                            <td colspan="7" class="px-6 py-4 bg-slate-50">
                                <p class="ip-placeholder text-xs text-slate-400 mb-2">Memuat daftar IP&hellip;</p>
                                <div class="hidden ip-list"></div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-10 text-center text-sm text-slate-400">Belum ada subnet. Tambahkan lewat form di atas.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <style>
        .ip-asset-hit:hover { background-color: #eff6ff; }
    </style>

    @push('scripts')
    <script>
    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function ipStatusBadge(status) {
        if (status === 'assigned') {
            return '<span class="px-2 py-0.5 text-[11px] font-medium bg-blue-50 text-blue-700 rounded">Terpakai</span>';
        }
        if (status === 'reserved') {
            return '<span class="px-2 py-0.5 text-[11px] font-medium bg-slate-100 text-slate-600 rounded">Reserved</span>';
        }
        return '<span class="px-2 py-0.5 text-[11px] font-medium bg-green-50 text-green-700 rounded">Tersedia</span>';
    }

    function loadIpDetail(btn) {
        const detail = document.querySelector('[data-detail="' + btn.dataset.network + '"]');
        const list = detail.querySelector('.ip-list');
        const placeholder = detail.querySelector('.ip-placeholder');
        fetch(btn.dataset.url)
            .then(function (res) { return res.json(); })
            .then(function (data) {
                placeholder.classList.add('hidden');
                list.classList.remove('hidden');
                list.dataset.loaded = '1';
                // data.ips sudah urut numerik dari server.
                // Konteks jaringan ditempelkan ke tiap IP supaya popup bisa
                // menyimpan penetapan perangkat dan memuat ulang daftar.
                data.ips.forEach(function (ip) {
                    ip.networkId = btn.dataset.network;
                    ip.assignUrl = IP_ASSIGN_URL.replace('__ID__', ip.id);
                    ip.deviceUrl = IP_DEVICE_URL.replace('__ID__', ip.id);
                });
                list.innerHTML = '<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2">'
                    + data.ips.map(function (ip, idx) {
                        return '<button type="button" data-idx="' + idx + '" class="ip-cell text-left flex items-center justify-between gap-2 px-3 py-1.5 bg-white border border-slate-200 rounded-lg text-xs hover:border-blue-300 hover:bg-blue-50 transition-colors">'
                            + '<span class="font-mono text-slate-800">' + escapeHtml(ip.address) + '</span>'
                            + '<span class="flex items-center gap-2 min-w-0">' + ipStatusBadge(ip.status)
                            + (ip.asset ? '<span class="text-slate-500 truncate max-w-[120px]">' + escapeHtml(ip.asset.tag_name) + '</span>' : '')
                            + '</span></button>';
                    }).join('') + '</div>';
                list.querySelectorAll('.ip-cell').forEach(function (cell) {
                    cell.addEventListener('click', function () {
                        showIpPopup(data.ips[parseInt(cell.dataset.idx, 10)], cell);
                    });
                });
            })
            .catch(function () {
                placeholder.textContent = 'Gagal memuat daftar IP.';
            });
    }

    function showIpPopup(ip, anchor) {
        closeIpPopup();
        const a = ip.asset;
        let body;

        if (a) {
            const loc = [a.plant, a.department, a.location].filter(Boolean).join(' - ');
            const ping = a.last_ping_status
                ? '<div class="text-xs mt-1">Status ping: <strong>' + escapeHtml(a.last_ping_status) + '</strong>'
                    + (a.last_ping_latency != null ? ' (' + a.last_ping_latency + 'ms)' : '') + '</div>'
                : '';
            body = '<div class="font-semibold text-slate-900">' + escapeHtml(a.tag_name) + '</div>'
                + '<div class="text-slate-500">' + escapeHtml(a.device_type_label || a.device_type || 'Perangkat') + '</div>'
                + (a.name && a.name !== a.tag_name ? '<div class="text-xs text-slate-500 mt-0.5">' + escapeHtml(a.name) + '</div>' : '')
                + '<div class="mt-1">IP: <code class="text-xs">' + escapeHtml(ip.address) + '</code> ' + ipStatusBadge(ip.status) + '</div>'
                + (loc ? '<div class="text-xs text-slate-500 mt-0.5">' + escapeHtml(loc) + '</div>' : '')
                + ping
                + '<a href="' + escapeHtml(a.url) + '" class="inline-block mt-2 text-xs text-blue-600 hover:text-blue-700 font-medium">Detail perangkat &rarr;</a>';
        } else {
            body = '<div class="font-semibold text-slate-900 font-mono">' + escapeHtml(ip.address) + '</div>'
                + '<div class="mt-1">' + ipStatusBadge(ip.status) + '</div>'
                + '<div class="text-xs text-slate-500 mt-1">Belum dipakai perangkat.</div>';
        }

        const rect = anchor.getBoundingClientRect();
        const pop = document.createElement('div');
        pop.id = 'ipPopup';
        pop.className = 'fixed bg-white rounded-lg border border-slate-200 shadow-lg text-sm p-3 whitespace-normal';
        pop.style.maxWidth = '280px';
        pop.style.zIndex = '9999';
        pop.style.left = (rect.left + rect.width / 2) + 'px';
        pop.style.top = (rect.top - 6) + 'px';
        pop.style.transform = 'translate(-50%, -100%)';
        pop.innerHTML = body + ipActionButtons(ip);
        document.body.appendChild(pop);

        pop.querySelectorAll('[data-ip-action]').forEach(function (button) {
            button.addEventListener('click', function () {
                if (button.dataset.ipAction === 'pick') {
                    renderAssetPicker(ip, pop);
                    return;
                }

                if (button.dataset.ipAction === 'clear'
                    && confirm('Kosongkan IP ' + ip.address + ' dan kembalikan ke status tersedia?')) {
                    assignIp(ip, null);
                }
            });
        });

        const pr = pop.getBoundingClientRect();
        const margin = 8;
        if (pr.left < margin) {
            pop.style.left = (parseFloat(pop.style.left) + (margin - pr.left)) + 'px';
        } else if (pr.right > window.innerWidth - margin) {
            pop.style.left = (parseFloat(pop.style.left) - (pr.right - (window.innerWidth - margin))) + 'px';
        }
        if (pr.top < margin) {
            pop.style.top = (rect.bottom + 8) + 'px';
            pop.style.transform = 'translate(-50%, 0)';
        }
    }

    function closeIpPopup() {
        const p = document.getElementById('ipPopup');
        if (p) p.remove();
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.ip-toggle').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const detail = document.querySelector('[data-detail="' + btn.dataset.network + '"]');
                const list = detail.querySelector('.ip-list');
                if (detail.classList.contains('hidden')) {
                    detail.classList.remove('hidden');
                    btn.textContent = 'Tutup IP';
                    if (list.dataset.loaded !== '1') {
                        loadIpDetail(btn);
                    }
                } else {
                    detail.classList.add('hidden');
                    btn.textContent = 'Lihat IP';
                }
            });
        });

        document.addEventListener('click', function (e) {
            // Klik dari dalam popup tidak boleh menutup popup. Isi popup bisa
            // sudah diganti saat handler ini berjalan (tombol yang diklik jadi
            // terlepas dari DOM), sehingga pemeriksaan contains() saja tidak
            // cukup - composedPath() mencatat jejak elemen saat klik terjadi.
            const path = typeof e.composedPath === 'function' ? e.composedPath() : [];

            for (let i = 0; i < path.length; i++) {
                if (path[i] && path[i].id === 'ipPopup') {
                    return;
                }
            }

            const p = document.getElementById('ipPopup');
            if (p && !p.contains(e.target) && !e.target.closest('.ip-cell')) {
                closeIpPopup();
            }
        });
        window.addEventListener('scroll', closeIpPopup, { passive: true });
    });
    /**
     * Cari perangkat (autocomplete) lalu tampilkan hasilnya sebagai daftar.
     */
    function searchAssets(term, results) {
        results.innerHTML = '<div class="text-slate-400 text-xs p-1">Memuat...</div>';
        fetch(ASSET_SEARCH_URL + '?q=' + encodeURIComponent(term), { headers: { 'Accept': 'application/json' } })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                const assets = data.assets || [];
                if (assets.length === 0) {
                    results.innerHTML = '<div class="text-slate-400 text-xs p-1">Perangkat tidak ditemukan.</div>';
                    return;
                }

                results.innerHTML = assets.map(function (asset) {
                    const info = [asset.device_type, asset.plant, asset.location].filter(Boolean).join(' - ');
                    const tukar = asset.ip_address
                        ? '<button type="button" data-swap="' + asset.id + '" class="px-2 py-0.5 text-[11px] font-medium bg-white border border-slate-300 text-slate-600 rounded">Tukar IP</button>'
                        : '';

                    return '<div class="flex items-center justify-between gap-2 py-1.5 border-b border-slate-100">'
                        + '<button type="button" data-asset="' + asset.id + '" class="ip-asset-hit text-left flex-1 min-w-0 rounded px-1 py-0.5">'
                        + '<div class="text-xs font-medium text-slate-800">' + escapeHtml(asset.tag_name) + '</div>'
                        + (info ? '<div class="text-[11px] text-slate-500">' + escapeHtml(info) + '</div>' : '')
                        + (asset.ip_address ? '<div class="text-[11px] text-amber-600">IP sekarang: '
                            + escapeHtml(asset.ip_address) + '</div>' : '')
                        + '</button>'
                        + tukar
                        + '</div>';
                }).join('');

                results.querySelectorAll('[data-asset]').forEach(function (button) {
                    button.addEventListener('click', function () {
                        assignIp(currentPickerIp, parseInt(button.dataset.asset, 10), false);
                    });
                });

                results.querySelectorAll('[data-swap]').forEach(function (button) {
                    button.addEventListener('click', function () {
                        if (confirm('Tukar IP? Perangkat ini akan memakai IP ini, dan perangkat yang sekarang memakainya menerima IP lama perangkat tersebut.')) {
                            assignIp(currentPickerIp, parseInt(button.dataset.swap, 10), true);
                        }
                    });
                });
            })
            .catch(function () {
                results.innerHTML = '<div class="text-red-600 text-xs p-1">Gagal memuat daftar perangkat.</div>';
            });
    }

    /**
     * Simpan penetapan perangkat untuk sebuah IP (assetId null = kosongkan).
     */
    function assignIp(ip, assetId, swap) {
        if (!ip || !ip.assignUrl) {
            return;
        }

        fetch(ip.assignUrl, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': CSRF_TOKEN
            },
            body: JSON.stringify({ it_asset_id: assetId, swap: !!swap })
        })
            .then(readJson)
            .then(function (result) {
                if (!result.ok) {
                    const error = document.getElementById('ipAssetError');
                    const message = result.body && result.body.message ? result.body.message : 'Gagal menyimpan penetapan.';
                    if (error) { error.textContent = message; }
                    return;
                }

                showIpToast(result.body.message);
                closeIpPopup();
                reloadNetworkGrid(ip.networkId);
            })
            .catch(function () {
                const error = document.getElementById('ipAssetError');
                if (error) { error.textContent = 'Gagal menghubungi server.'; }
            });
    }

    /**
     * Notifikasi ringkas di kanan bawah setelah penetapan berhasil.
     */
    function showIpToast(message) {
        const toast = document.createElement('div');
        toast.className = 'fixed bottom-4 right-4 bg-slate-900 text-white text-sm px-4 py-2 rounded-lg shadow-lg';
        toast.style.zIndex = '10000';
        toast.textContent = message;
        document.body.appendChild(toast);
        setTimeout(function () { toast.remove(); }, 3500);
    }

    /**
     * Muat ulang daftar IP satu subnet supaya status terbaru langsung tampil.
     */
    function reloadNetworkGrid(networkId) {
        const toggle = document.querySelector('.ip-toggle[data-network="' + networkId + '"]');
        if (toggle) {
            loadIpDetail(toggle);
        }
    }

    // ===== Penetapan perangkat pada sebuah IP (popup Daftar IP) =====
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const CSRF_TOKEN = csrfMeta ? csrfMeta.content : '';
    const ASSET_SEARCH_URL = '{{ route('it-assets.ip-addresses.asset-search') }}';
    const IP_ASSIGN_URL = '{{ route('it-assets.ip-addresses.assign', ['ipAddress' => '__ID__']) }}';
    const IP_DEVICE_URL = '{{ route('it-assets.ip-addresses.store-device', ['ipAddress' => '__ID__']) }}';
    const DEVICE_TYPES = @json($deviceTypes);

    let currentPickerIp = null;

    /**
     * Tombol aksi di popup: Tambahkan (IP tersedia) atau Ganti + Kosongkan
     * (IP sudah dipakai perangkat).
     */
    function ipActionButtons(ip) {
        if (ip.asset) {
            return '<div class="flex items-center gap-2 mt-2 pt-2 border-t border-slate-100">'
                + '<button type="button" data-ip-action="pick" class="px-2 py-1 text-xs font-medium bg-blue-600 text-white rounded hover:bg-blue-700">Ganti perangkat</button>'
                + '<button type="button" data-ip-action="clear" class="px-2 py-1 text-xs font-medium bg-white border border-slate-300 text-slate-600 rounded hover:bg-slate-50">Kosongkan</button>'
                + '</div>';
        }

        if (ip.status === 'available') {
            return '<div class="mt-2 pt-2 border-t border-slate-100">'
                + '<button type="button" data-ip-action="pick" class="px-2 py-1 text-xs font-medium bg-blue-600 text-white rounded hover:bg-blue-700">Tambahkan perangkat</button>'
                + '</div>';
        }

        return '<div class="text-xs text-slate-400 mt-2 pt-2 border-t border-slate-100">'
            + 'IP reserved: ubah statusnya dulu agar bisa dipakai.</div>';
    }

    /**
     * Ubah isi popup menjadi pilihan perangkat: memakai perangkat yang sudah
     * ada, atau membuat perangkat baru langsung dari sini.
     */
    function renderAssetPicker(ip, pop, mode) {
        currentPickerIp = ip;
        pop.style.maxWidth = '340px';
        pop.innerHTML = '<div class="font-semibold text-slate-900 font-mono">' + escapeHtml(ip.address) + '</div>'
            + '<div class="text-xs text-slate-500 mt-0.5">'
            + (ip.asset ? 'Ganti perangkat pemakai IP ini' : 'Tambahkan perangkat pemakai IP ini') + '</div>'
            + '<div class="flex items-center gap-1 mt-2">'
            + pickerTab('existing', 'Perangkat yang ada', mode !== 'new')
            + pickerTab('new', 'Tambah perangkat baru', mode === 'new')
            + '</div>'
            + '<div id="ipPickerBody" class="mt-2"></div>';

        pop.querySelectorAll('[data-picker-tab]').forEach(function (button) {
            button.addEventListener('click', function () {
                renderAssetPicker(ip, pop, button.dataset.pickerTab);
            });
        });

        if (mode === 'new') {
            renderNewDeviceForm(ip, pop);
            return;
        }

        renderExistingDeviceSearch(ip, pop);
    }

    /**
     * Tombol tab pemilih mode (perangkat yang ada / perangkat baru).
     */
    function pickerTab(mode, label, active) {
        return '<button type="button" data-picker-tab="' + mode + '" class="px-2 py-1 text-xs rounded '
            + (active ? 'bg-slate-900 text-white' : 'ip-asset-hit bg-white border border-slate-300 text-slate-600')
            + '">' + label + '</button>';
    }

    /**
     * Mode "perangkat yang ada": kolom pencarian + daftar hasil.
     */
    function renderExistingDeviceSearch(ip, pop) {
        const body = document.getElementById('ipPickerBody');
        body.innerHTML = '<input type="text" id="ipAssetSearch" placeholder="Cari tag, nama, atau IP perangkat..." autocomplete="off" '
            + 'class="w-full px-2 py-1 text-xs border border-slate-300 rounded focus:outline-none focus:ring-1 focus:ring-blue-500">'
            + '<div id="ipAssetResults" class="mt-2 overflow-y-auto" style="max-height: 13rem;"></div>'
            + '<div class="mt-2 pt-2 border-t border-slate-100 flex items-center justify-between gap-2">'
            + '<span id="ipAssetError" class="text-[11px] text-red-600"></span>'
            + '<button type="button" id="ipAssetCancel" class="px-2 py-1 text-xs font-medium bg-white border border-slate-300 text-slate-600 rounded">Batal</button>'
            + '</div>';

        const input = document.getElementById('ipAssetSearch');
        const results = document.getElementById('ipAssetResults');
        let timer = null;

        input.focus();
        searchAssets('', results);
        input.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(function () { searchAssets(input.value.trim(), results); }, 250);
        });
        document.getElementById('ipAssetCancel').addEventListener('click', closeIpPopup);
    }

    /**
     * Mode "tambah perangkat baru": form ringkas yang langsung memakai IP ini.
     */
    function renderNewDeviceForm(ip, pop) {
        const body = document.getElementById('ipPickerBody');
        const options = DEVICE_TYPES.map(function (type) {
            return '<option value="' + escapeHtml(type) + '">' + escapeHtml(type.replace(/_/g, ' ')) + '</option>';
        }).join('');

        body.innerHTML = '<div class="flex flex-col gap-1">'
            + '<label class="text-[11px] text-slate-500">Tipe perangkat *</label>'
            + '<select id="newDeviceType" class="w-full px-2 py-1 text-xs border border-slate-300 rounded">' + options + '</select>'
            + deviceField('newDeviceTag', 'Tag perangkat *', 'mis. PC-CES-01')
            + deviceField('newDeviceName', 'Nama / pengguna', 'mis. Novila')
            + deviceField('newDevicePlant', 'Plant', 'mis. CES')
            + deviceField('newDeviceDepartment', 'Departemen', '')
            + deviceField('newDeviceLocation', 'Lokasi', '')
            + '<div class="text-[11px] text-slate-500 mt-1">IP yang dipakai: <code>' + escapeHtml(ip.address) + '</code></div>'
            + '<div class="mt-1 pt-2 border-t border-slate-100 flex items-center justify-between gap-2">'
            + '<span id="ipDeviceError" class="text-[11px] text-red-600"></span>'
            + '<span class="flex gap-1">'
            + '<button type="button" id="ipDeviceCancel" class="px-2 py-1 text-xs font-medium bg-white border border-slate-300 text-slate-600 rounded">Batal</button>'
            + '<button type="button" id="ipDeviceSave" class="px-2 py-1 text-xs font-medium bg-blue-600 text-white rounded">Simpan dan pakai IP</button>'
            + '</span></div></div>';

        document.getElementById('ipDeviceCancel').addEventListener('click', closeIpPopup);
        document.getElementById('ipDeviceSave').addEventListener('click', function () {
            createDevice(ip);
        });
    }

    /**
     * Satu field teks pada form perangkat baru.
     */
    function deviceField(id, label, placeholder) {
        return '<label class="text-[11px] text-slate-500">' + label + '</label>'
            + '<input type="text" id="' + id + '" placeholder="' + placeholder + '" '
            + 'class="w-full px-2 py-1 text-xs border border-slate-300 rounded focus:outline-none focus:ring-1 focus:ring-blue-500">';
    }

    /**
     * Simpan perangkat baru dari form popup, lalu pakai IP ini untuknya.
     */
    function createDevice(ip) {
        const error = document.getElementById('ipDeviceError');
        const payload = {
            device_type: document.getElementById('newDeviceType').value,
            tag_name: document.getElementById('newDeviceTag').value.trim(),
            name: document.getElementById('newDeviceName').value.trim(),
            plant: document.getElementById('newDevicePlant').value.trim(),
            department: document.getElementById('newDeviceDepartment').value.trim(),
            location: document.getElementById('newDeviceLocation').value.trim()
        };

        if (payload.tag_name === '') {
            error.textContent = 'Tag perangkat wajib diisi.';
            return;
        }

        error.textContent = '';

        fetch(ip.deviceUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': CSRF_TOKEN
            },
            body: JSON.stringify(payload)
        })
            .then(readJson)
            .then(function (result) {
                if (!result.ok) {
                    error.textContent = (result.body && result.body.message)
                        ? result.body.message
                        : 'Gagal menyimpan perangkat.';
                    return;
                }

                showIpToast(result.body.message);
                closeIpPopup();
                reloadNetworkGrid(ip.networkId);
            })
            .catch(function () {
                error.textContent = 'Gagal menghubungi server.';
            });
    }

    /**
     * Baca balasan sebagai JSON; balasan non-JSON dianggap gagal.
     */
    function readJson(res) {
        return res.text().then(function (text) {
            let body = null;
            try {
                body = JSON.parse(text);
            } catch (e) {
                body = null;
            }

            return { ok: res.ok && body !== null, body: body };
        });
    }

    </script>
    @endpush
@endsection
