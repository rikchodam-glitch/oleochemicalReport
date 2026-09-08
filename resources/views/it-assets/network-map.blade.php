@extends('layouts.app')

@section('title', 'Network Map — IT Assets')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('it-assets.index') }}" class="hover:text-slate-700">IT Assets</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">Network Map</span>
@endsection

@section('header-actions')
    <a href="{{ route('it-assets.index') }}"
       class="inline-flex items-center gap-2 px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
        &larr; Kembali ke IT Assets
    </a>
@endsection

@section('content')
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
<div class="flex flex-col lg:flex-row gap-6">

    {{-- ── Sidebar: aksi & legenda ─────────────────────────────────────── --}}
    <aside class="lg:w-72 shrink-0 space-y-4">
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <h3 class="text-sm font-semibold text-slate-900 mb-3">Skema Jaringan</h3>
            <div class="flex flex-col gap-2 mb-4">
                <button id="btnDrawLink" type="button"
                        class="inline-flex items-center justify-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
                    Gambar Jalur
                </button>
                <button id="btnCancelMode" type="button"
                        class="hidden inline-flex items-center justify-center gap-2 px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                    Batal
                </button>
            </div>
            <h4 class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2">Perangkat Belum Ditempatkan</h4>
            <p class="text-xs text-slate-400 mb-2">Klik perangkat, lalu klik lokasinya di peta.</p>
            <ul id="unplacedList" class="space-y-1 max-h-64 overflow-y-auto pr-1"></ul>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <h4 class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2">Legenda Perangkat</h4>
            <ul id="deviceLegend" class="space-y-1 mb-4"></ul>
            <h4 class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2">Legenda Kabel</h4>
            <ul id="cableLegend" class="space-y-1"></ul>
        </div>
    </aside>

    {{-- ── Peta ───────────────────────────────────────────────────────── --}}
    <div class="flex-1 space-y-3">
        <div id="network-map" class="h-[70vh] w-full rounded-xl border border-slate-200 z-0"></div>
        <p id="modeHint" class="text-sm text-slate-600"></p>
    </div>
</div>

{{-- ── Modal: pilih jenis kabel saat membuat jalur ─────────────────────── --}}
<div id="linkModal" class="hidden fixed inset-0 z-[1200] flex items-center justify-center p-4 bg-slate-900/50">
    <div class="bg-white rounded-xl border border-slate-200 w-full max-w-md p-5">
        <h3 class="text-base font-semibold text-slate-900 mb-4">Buat Jalur</h3>
        <div id="linkEndpoints" class="text-sm text-slate-600 mb-4"></div>
        <div class="space-y-3">
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1.5">Jenis Kabel</label>
                <select id="cableType"
                        class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white">
                    <option value="fiber_optic">Fiber Optic</option>
                    <option value="utp">UTP</option>
                    <option value="wireless">Wireless</option>
                    <option value="coaxial">Coaxial</option>
                    <option value="other">Lainnya</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1.5">Label (opsional)</label>
                <input id="linkLabel" type="text" maxlength="255"
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                       placeholder="Mis. Backbone Lt.2">
            </div>
        </div>
        <div class="flex justify-end gap-2 mt-5">
            <button id="btnCancelLink" type="button"
                    class="px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg">Batal</button>
            <button id="btnSaveLink" type="button"
                    class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg">Simpan</button>
        </div>
    </div>
</div>

@push('scripts')
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
    <script>
        'use strict';

        // Data yang dikirim controller (diproses hanya di halaman ini).
        const ASSETS = @json($assets) || [];
        const LINKS = @json($links) || [];
        const DEVICE_COLORS = @json($deviceTypeColors) || {};

        const CABLE_COLORS = {
            fiber_optic: '#f59e0b',
            utp: '#3b82f6',
            wireless: '#10b981',
            coaxial: '#ef4444',
            other: '#94a3b8',
        };
        const CABLE_LABELS = {
            fiber_optic: 'Fiber Optic',
            utp: 'UTP',
            wireless: 'Wireless',
            coaxial: 'Coaxial',
            other: 'Lainnya',
        };

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

        const map = L.map('network-map');
        L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
            maxZoom: 19,
            attribution: '&copy; Esri World Imagery',
        }).addTo(map);

        const markers = {};
        const linesLayer = L.layerGroup().addTo(map);

        const deviceLabels = {
            PC: 'PC', LAPTOP: 'Laptop', PRINTER: 'Printer', CCTV: 'CCTV', NVR: 'NVR',
            ACCESS_DOOR: 'Access Door', WIFI: 'WiFi', NETWORK: 'Network', UPS: 'UPS', OTHER: 'Lainnya',
        };

        function escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function colorFor(type) {
            return DEVICE_COLORS[type] || DEVICE_COLORS.OTHER || '#64748b';
        }

        function displayName(asset) {
            return asset.tag_name || asset.name || ('#' + asset.id);
        }

        function fmtPing(asset) {
            if (!asset.last_ping_status) return '';
            let out = 'Status: ' + escapeHtml(asset.last_ping_status);
            if (asset.last_ping_at) {
                out += ' @ ' + new Date(asset.last_ping_at).toLocaleString('id-ID');
            }
            return out;
        }

        function popupHtml(asset) {
            const type = (deviceLabels[asset.device_type] || asset.device_type || 'Perangkat');
            const loc = [asset.plant, asset.department, asset.location].filter(Boolean).join(' - ');
            let snapshot = '';
            if (asset.snapshot_url) {
                snapshot = '<img src="' + asset.snapshot_url + '" class="w-full rounded-lg border border-slate-200 mt-2" alt="Snapshot" loading="lazy">';
                if (asset.last_snapshot_at) {
                    snapshot += '<div class="text-xs text-slate-400 mt-1">Diambil: ' + new Date(asset.last_snapshot_at).toLocaleString('id-ID') + '</div>';
                }
            }
            return '<div class="text-sm space-y-1">'
                + '<div class="font-semibold">' + escapeHtml(displayName(asset)) + '</div>'
                + '<div class="text-slate-500">' + escapeHtml(type) + '</div>'
                + (asset.ip_address ? '<div>IP: <code class="text-xs">' + escapeHtml(asset.ip_address) + '</code></div>' : '')
                + (loc ? '<div class="text-xs text-slate-500">' + escapeHtml(loc) + '</div>' : '')
                + (fmtPing(asset) ? '<div class="text-xs">' + fmtPing(asset) + '</div>' : '')
                + snapshot
                + '</div>';
        }

        function addMarker(asset) {
            if (asset.latitude == null || asset.longitude == null) return;
            const marker = L.circleMarker([asset.latitude, asset.longitude], {
                radius: 10,
                color: '#ffffff',
                weight: 2,
                fillColor: colorFor(asset.device_type),
                fillOpacity: 0.9,
            }).addTo(map);
            marker.bindPopup(() => popupHtml(asset), { maxWidth: 320 });
            marker.on('click', function (e) {
                L.DomEvent.stopPropagation(e);
                if (state.mode === 'link') {
                    handleLinkMarkerClick(asset);
                }
            });
            markers[asset.id] = marker;
        }

        const state = {
            mode: 'idle',
            placingAssetId: null,
            linkFrom: null,
            linkTo: null,
        };
        const lines = {};

        const modeHint = document.getElementById('modeHint');
        const btnDrawLink = document.getElementById('btnDrawLink');
        const btnCancelMode = document.getElementById('btnCancelMode');

        function setHint(text) {
            modeHint.textContent = text;
        }

        function setMode(mode) {
            state.mode = mode;
            if (mode === 'idle') {
                state.placingAssetId = null;
                state.linkFrom = null;
                state.linkTo = null;
                btnDrawLink.classList.remove('hidden');
                btnCancelMode.classList.add('hidden');
                setHint('');
                document.getElementById('network-map').style.cursor = '';
            } else {
                btnDrawLink.classList.add('hidden');
                btnCancelMode.classList.remove('hidden');
            }
        }

        function findAsset(id) {
            return ASSETS.find(a => a.id === id) || null;
        }

        function linkPoints(link) {
            const from = findAsset(link.from_asset_id);
            const to = findAsset(link.to_asset_id);
            if (!from || !to) return null;
            if (from.latitude == null || from.longitude == null || to.latitude == null || to.longitude == null) return null;
            const coords = [[from.latitude, from.longitude]];
            (link.path_points || []).forEach(p => coords.push([p.lat, p.lng]));
            coords.push([to.latitude, to.longitude]);
            return coords;
        }

        function linkPopupHtml(link) {
            const color = CABLE_COLORS[link.cable_type] || CABLE_COLORS.other;
            const type = CABLE_LABELS[link.cable_type] || link.cable_type;
            const label = link.label ? '<div class="font-semibold">' + escapeHtml(link.label) + '</div>' : '';
            return '<div class="text-sm space-y-1">'
                + label
                + '<div>' + escapeHtml(link.from_tag || ('#' + link.from_asset_id)) + ' &rarr; ' + escapeHtml(link.to_tag || ('#' + link.to_asset_id)) + '</div>'
                + '<div class="text-slate-500">Jenis: ' + escapeHtml(type) + '</div>'
                + '<div style="width:100%;height:3px;background:' + color + ';border-radius:9999px;margin:4px 0;"></div>'
                + '<button type="button" data-link-id="' + link.id + '" class="link-delete text-xs text-red-600 hover:text-red-800">Hapus Jalur</button>'
                + '</div>';
        }

        function drawLink(link) {
            const coords = linkPoints(link);
            if (!coords) return null;
            const color = CABLE_COLORS[link.cable_type] || CABLE_COLORS.other;
            const style = { color: color, weight: 3, opacity: 0.9 };
            if (link.cable_type === 'wireless') style.dashArray = '6 6';
            const poly = L.polyline(coords, style).addTo(linesLayer);
            poly.bindPopup(linkPopupHtml(link), { maxWidth: 300 });
            lines[link.id] = poly;
            return poly;
        }

        function handleLinkMarkerClick(asset) {
            map.closePopup();
            if (!state.linkFrom) {
                state.linkFrom = asset;
                setHint('Klik marker tujuan untuk jalur dari ' + displayName(asset) + '.');
                return;
            }
            if (state.linkFrom.id === asset.id) {
                setHint('Marker asal dan tujuan tidak boleh sama.');
                return;
            }
            state.linkTo = asset;
            openLinkModal();
        }

        const linkModal = document.getElementById('linkModal');
        const linkEndpoints = document.getElementById('linkEndpoints');
        const btnCancelLink = document.getElementById('btnCancelLink');
        const btnSaveLink = document.getElementById('btnSaveLink');

        function openLinkModal() {
            linkEndpoints.textContent = displayName(state.linkFrom) + '  →  ' + displayName(state.linkTo);
            document.getElementById('linkLabel').value = '';
            linkModal.classList.remove('hidden');
        }

        function closeLinkModal() {
            linkModal.classList.add('hidden');
            state.linkFrom = null;
            state.linkTo = null;
            setMode('idle');
        }

        btnCancelLink.addEventListener('click', closeLinkModal);

        btnSaveLink.addEventListener('click', async () => {
            const cableType = document.getElementById('cableType').value;
            const label = document.getElementById('linkLabel').value.trim();
            const body = {
                from_asset_id: state.linkFrom.id,
                to_asset_id: state.linkTo.id,
                cable_type: cableType,
                label: label || null,
            };
            const res = await fetch('{{ route('it-assets.network-map.store-link') }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                body: JSON.stringify(body),
            });
            const data = await res.json();
            if (!res.ok) {
                alert(data.message || 'Gagal menyimpan jalur.');
                return;
            }
            const poly = drawLink(data.link);
            if (poly) poly.openPopup();
            closeLinkModal();
        });

        btnDrawLink.addEventListener('click', () => {
            if (Object.keys(markers).length < 2) {
                setHint('Setidaknya dua perangkat harus sudah ditempatkan untuk membuat jalur.');
                return;
            }
            setMode('link');
            setHint('Klik marker perangkat asal, lalu marker tujuan.');
        });

        btnCancelMode.addEventListener('click', () => setMode('idle'));

        function renderUnplaced() {
            const list = document.getElementById('unplacedList');
            const unplaced = ASSETS.filter(a => a.latitude == null || a.longitude == null);
            list.innerHTML = '';
            if (!unplaced.length) {
                list.innerHTML = '<li class="text-xs text-slate-400">Semua perangkat sudah ditempatkan.</li>';
                return;
            }
            unplaced.forEach(asset => {
                const item = document.createElement('li');
                const type = (deviceLabels[asset.device_type] || asset.device_type || 'Lainnya');
                item.className = 'flex items-center gap-2 px-2 py-1.5 rounded-lg hover:bg-slate-50 cursor-pointer text-xs text-slate-700';
                item.innerHTML = '<span class="w-2.5 h-2.5 rounded-full shrink-0" style="background:' + colorFor(asset.device_type) + '"></span>'
                    + '<span class="min-w-0 truncate">' + escapeHtml(displayName(asset)) + '<span class="text-slate-400"> · ' + escapeHtml(type) + '</span></span>';
                item.addEventListener('click', () => {
                    state.placingAssetId = asset.id;
                    setMode('place');
                    document.getElementById('network-map').style.cursor = 'crosshair';
                    setHint('Klik lokasi perangkat "' + displayName(asset) + '" di peta.');
                });
                list.appendChild(item);
            });
        }

        map.on('click', async (e) => {
            if (state.mode !== 'place' || !state.placingAssetId) return;
            const assetId = state.placingAssetId;
            const body = { asset_id: assetId, latitude: e.latlng.lat, longitude: e.latlng.lng };
            const res = await fetch('{{ route('it-assets.network-map.location') }}', {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                body: JSON.stringify(body),
            });
            const data = await res.json();
            if (!res.ok) {
                alert(data.message || 'Gagal menyimpan lokasi.');
                return;
            }
            const asset = data.asset;
            const idx = ASSETS.findIndex(a => a.id === asset.id);
            if (idx !== -1) ASSETS[idx] = asset;
            addMarker(asset);
            renderUnplaced();
            setMode('idle');
            map.flyTo([asset.latitude, asset.longitude], Math.max(map.getZoom(), 16));
        });

        const DELETE_LINK_URL = '{{ route('it-assets.network-map.destroy-link', ['networkLink' => '__ID__']) }}';

        linesLayer.on('popupopen', function (evt) {
            const btn = evt.popup.getElement().querySelector('.link-delete');
            if (!btn) return;
            btn.addEventListener('click', async () => {
                const linkId = btn.dataset.linkId;
                const res = await fetch(DELETE_LINK_URL.replace('__ID__', linkId), {
                    method: 'DELETE',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                });
                const data = await res.json();
                if (!res.ok) {
                    alert(data.message || 'Gagal menghapus jalur.');
                    return;
                }
                map.closePopup();
                if (lines[linkId]) {
                    linesLayer.removeLayer(lines[linkId]);
                    delete lines[linkId];
                }
            });
        });

        function renderLegends() {
            const deviceLegend = document.getElementById('deviceLegend');
            const cableLegend = document.getElementById('cableLegend');
            deviceLegend.innerHTML = '';
            Object.keys(DEVICE_COLORS).forEach(type => {
                const li = document.createElement('li');
                li.className = 'flex items-center gap-2 text-xs text-slate-600';
                li.innerHTML = '<span class="w-2.5 h-2.5 rounded-full shrink-0" style="background:' + DEVICE_COLORS[type] + '"></span>'
                    + (deviceLabels[type] || type);
                deviceLegend.appendChild(li);
            });
            cableLegend.innerHTML = '';
            Object.keys(CABLE_LABELS).forEach(type => {
                const li = document.createElement('li');
                li.className = 'flex items-center gap-2 text-xs text-slate-600';
                const line = type === 'wireless'
                    ? 'background-image: linear-gradient(90deg, ' + CABLE_COLORS[type] + ' 50%, transparent 50%); background-size: 6px 100%;'
                    : 'background:' + CABLE_COLORS[type] + ';';
                li.innerHTML = '<span class="w-6 h-0.5 shrink-0" style="' + line + '"></span>'
                    + (CABLE_LABELS[type] || type);
                cableLegend.appendChild(li);
            });
        }

        function init() {
            ASSETS.forEach(addMarker);
            LINKS.forEach(drawLink);
            renderLegends();
            renderUnplaced();

            const placed = ASSETS.filter(a => a.latitude != null && a.longitude != null);
            if (placed.length) {
                map.fitBounds(L.latLngBounds(placed.map(a => [a.latitude, a.longitude])).pad(0.2));
            } else {
                map.setView([-2.5, 118.0], 5);
            }
            setTimeout(() => map.invalidateSize(), 200);
        }

        init();
    </script>
@endpush
@endsection
