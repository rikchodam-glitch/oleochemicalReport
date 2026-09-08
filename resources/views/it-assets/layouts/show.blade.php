@extends('layouts.app')

@section('title', ($layout->label ?: $layout->name) . ' — Denah IT')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-slate-700">Dashboard</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('it-assets.index') }}" class="hover:text-slate-700">IT Assets</a>
    <span class="text-slate-300">/</span>
    <a href="{{ route('it-assets.layouts.index') }}" class="hover:text-slate-700">Denah IT</a>
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-medium">{{ $layout->label ?: $layout->name }}</span>
@endsection

@section('header-actions')
    <a href="{{ route('it-assets.layouts.index') }}"
       class="inline-flex items-center gap-2 px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
        &larr; Kembali ke Daftar Denah
    </a>
@endsection

@section('content')
{{-- Tab pemilihan denah --}}
<div class="flex flex-wrap gap-2 mb-4">
    @foreach($allLayouts as $l)
        <a href="{{ route('it-assets.layouts.show', $l) }}"
           class="px-3 py-1.5 rounded-lg text-sm font-medium transition-colors
                  {{ $l->id === $layout->id ? 'bg-blue-600 text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50' }}">
            {{ $l->label ?: $l->name }}
        </a>
    @endforeach
</div>

<div class="flex flex-col lg:flex-row gap-6">
    <aside class="lg:w-72 shrink-0 space-y-4">
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <h3 class="text-sm font-semibold text-slate-900 mb-3">Penempatan Perangkat</h3>
            <div class="flex flex-col gap-2 mb-4">
                <button id="btnDrawLink" type="button"
                        class="inline-flex items-center justify-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
                    Gambar Kabel
                </button>
                <button id="btnCancelMode" type="button"
                        class="hidden inline-flex items-center justify-center gap-2 px-4 py-2 border border-slate-300 hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition-colors">
                    Batal
                </button>
            </div>
            <h4 class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2">Terpasang di denah ini</h4>
            <ul id="placedList" class="space-y-1 max-h-56 overflow-y-auto pr-1 mb-4"></ul>
            <h4 class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2">Belum ditempatkan</h4>
            <p class="text-xs text-slate-400 mb-2">Klik perangkat, lalu klik posisinya di denah.</p>
            <ul id="unplacedList" class="space-y-1 max-h-56 overflow-y-auto pr-1"></ul>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <h4 class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2">Legenda Perangkat</h4>
            <ul id="deviceLegend" class="space-y-1 mb-4"></ul>
            <h4 class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2">Legenda Kabel</h4>
            <ul id="cableLegend" class="space-y-1"></ul>
        </div>
    </aside>

    <div class="flex-1 space-y-3">
        <div id="denahWrap" class="relative rounded-xl border border-slate-200 bg-white overflow-hidden" style="min-height:420px;">
            <div id="bgBox" class="pointer-events-none"></div>
            <svg id="svgLinks" class="absolute inset-0 w-full h-full pointer-events-none" viewBox="0 0 100 100" preserveAspectRatio="none"></svg>
            <div id="markersLayer" class="absolute inset-0 pointer-events-none"></div>
            <div id="loading" class="absolute inset-0 flex items-center justify-center text-slate-400 text-sm">Memuat denah&hellip;</div>
        </div>
        <p id="modeHint" class="text-sm text-slate-600"></p>
    </div>
</div>

<div id="linkModal" class="hidden fixed inset-0 z-[1200] flex items-center justify-center p-4 bg-slate-900/50">
    <div class="bg-white rounded-xl border border-slate-200 w-full max-w-md p-5">
        <h3 class="text-base font-semibold text-slate-900 mb-4">Buat Jalur Kabel</h3>
        <div id="linkEndpoints" class="text-sm text-slate-600 mb-4"></div>
        <div class="space-y-3">
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1.5">Jenis Kabel</label>
                <select id="cableType" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white">
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
    <script src="https://unpkg.com/pdfjs-dist@3.11.174/build/pdf.min.js"></script>
    <script>
        'use strict';

        const LAYOUT = @json($layoutData) || {};
        const UNPLACED = @json($unplaced) || [];
        const DEVICE_COLORS = @json($deviceTypeColors) || {};
        const CABLE_COLORS = { fiber_optic: '#f59e0b', utp: '#3b82f6', wireless: '#10b981', coaxial: '#ef4444', other: '#94a3b8' };
        const CABLE_LABELS = { fiber_optic: 'Fiber Optic', utp: 'UTP', wireless: 'Wireless', coaxial: 'Coaxial', other: 'Lainnya' };
        const deviceLabels = { PC: 'PC', LAPTOP: 'Laptop', PRINTER: 'Printer', CCTV: 'CCTV', NVR: 'NVR', ACCESS_DOOR: 'Access Door', WIFI: 'WiFi', NETWORK: 'Network', UPS: 'UPS', OTHER: 'Lainnya' };

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

        const denahWrap = document.getElementById('denahWrap');
        const bgBox = document.getElementById('bgBox');
        const svgLinks = document.getElementById('svgLinks');
        const markersLayer = document.getElementById('markersLayer');
        const loading = document.getElementById('loading');
        const modeHint = document.getElementById('modeHint');
        const btnDrawLink = document.getElementById('btnDrawLink');
        const btnCancelMode = document.getElementById('btnCancelMode');

        const state = { mode: 'idle', placingAssetId: null, linkFrom: null, linkTo: null };
        const itemsMap = {};
        const pathEls = {};

        function escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }

        function colorFor(type) {
            return DEVICE_COLORS[type] || DEVICE_COLORS.OTHER || '#64748b';
        }

        function displayName(asset) {
            return asset.tag_name || asset.name || ('#' + asset.id);
        }

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
                denahWrap.style.cursor = '';
            } else {
                btnDrawLink.classList.add('hidden');
                btnCancelMode.classList.remove('hidden');
            }
        }

        function computePct(clientX, clientY) {
            const r = denahWrap.getBoundingClientRect();
            return {
                x: Math.max(0, Math.min(100, ((clientX - r.left) / r.width) * 100)),
                y: Math.max(0, Math.min(100, ((clientY - r.top) / r.height) * 100)),
            };
        }

        function renderBackground() {
            bgBox.innerHTML = '';
            svgLinks.innerHTML = '';
            markersLayer.innerHTML = '';

            if (LAYOUT.file_type === 'image') {
                const img = document.createElement('img');
                img.src = LAYOUT.file_url;
                img.className = 'block w-full h-auto';
                img.onload = () => { loading.style.display = 'none'; };
                img.onerror = () => { loading.style.display = 'none'; };
                bgBox.appendChild(img);
                return;
            }

            if (typeof pdfjsLib === 'undefined') {
                bgBox.innerHTML = '<div class="text-red-600 text-sm p-4">Library PDF.js tidak termuat.</div>';
                loading.style.display = 'none';
                return;
            }

            pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://unpkg.com/pdfjs-dist@3.11.174/build/pdf.worker.min.js';
            pdfjsLib.getDocument(LAYOUT.file_url).promise
                .then(pdf => pdf.getPage(Math.min(LAYOUT.page || 1, pdf.numPages)))
                .then(page => {
                    const base = page.getViewport({ scale: 1 });
                    const scale = 1600 / base.width;
                    const viewport = page.getViewport({ scale: scale });
                    const canvas = document.createElement('canvas');
                    canvas.width = viewport.width;
                    canvas.height = viewport.height;
                    canvas.className = 'block w-full h-auto';
                    bgBox.appendChild(canvas);
                    return page.render({ canvasContext: canvas.getContext('2d'), viewport: viewport }).promise;
                })
                .then(() => { loading.style.display = 'none'; })
                .catch(err => {
                    bgBox.innerHTML = '<div class="text-red-600 text-sm p-4">Gagal memuat denah: ' + escapeHtml(err.message) + '</div>';
                    loading.style.display = 'none';
                });
        }

        let linksData = LAYOUT.links || [];

        function linkPts(link) {
            const a = itemsMap[link.from_asset_id];
            const b = itemsMap[link.to_asset_id];
            if (!a || !b) return null;
            return a.item.x + ',' + a.item.y + ' ' + b.item.x + ',' + b.item.y;
        }

        function drawLink(link) {
            const pts = linkPts(link);
            if (!pts) return null;
            const color = CABLE_COLORS[link.cable_type] || CABLE_COLORS.other;
            const poly = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
            poly.setAttribute('points', pts);
            poly.setAttribute('fill', 'none');
            poly.setAttribute('stroke', color);
            poly.setAttribute('stroke-width', '0.7');
            poly.setAttribute('stroke-linecap', 'round');
            poly.setAttribute('stroke-linejoin', 'round');
            poly.setAttribute('stroke-dasharray', link.cable_type === 'wireless' ? '2 1.5' : '');
            poly.setAttribute('style', 'pointer-events:stroke; cursor:pointer;');
            poly.dataset.linkId = link.id;
            poly.addEventListener('click', (e) => {
                e.stopPropagation();
                showLinkPopup(link, poly);
            });
            svgLinks.appendChild(poly);
            pathEls[link.id] = poly;
            return poly;
        }

        function redrawLinks() {
            Object.values(pathEls).forEach(poly => {
                const link = linksData.find(l => l.id == poly.dataset.linkId);
                if (link) {
                    const pts = linkPts(link);
                    if (pts) poly.setAttribute('points', pts);
                }
            });
        }

        function showPopup(html, anchorEl) {
            const existing = document.getElementById('tempPopup');
            if (existing) existing.remove();

            const wr = denahWrap.getBoundingClientRect();
            const ar = anchorEl.getBoundingClientRect();
            const pop = document.createElement('div');
            pop.id = 'tempPopup';
            pop.className = 'absolute z-20 bg-white rounded-lg border border-slate-200 shadow-lg text-sm p-3 whitespace-normal';
            pop.style.maxWidth = '280px';
            pop.style.left = Math.max(4, (ar.left - wr.left) + ar.width / 2) + 'px';
            pop.style.top = Math.max(4, (ar.top - wr.top) - 6) + 'px';
            pop.style.transform = 'translate(-50%, -100%)';
            pop.innerHTML = html;
            denahWrap.appendChild(pop);
        }

        function closePopup() {
            const existing = document.getElementById('tempPopup');
            if (existing) existing.remove();
        }

        function showMarkerPopup(item, el) {
            const asset = item.asset;
            const loc = [asset.plant, asset.department, asset.location].filter(Boolean).join(' - ');
            let snap = '';
            if (asset.snapshot_url) {
                snap = '<img src="' + asset.snapshot_url + '" class="block w-full rounded-lg border border-slate-200 mt-2" alt="Snapshot" loading="lazy">';
            }
            const html = '<div class="font-semibold">' + escapeHtml(displayName(asset)) + '</div>'
                + '<div class="text-slate-500">' + escapeHtml(deviceLabels[asset.device_type] || asset.device_type || 'Perangkat') + '</div>'
                + (asset.ip_address ? '<div class="mt-1">IP: <code class="text-xs">' + escapeHtml(asset.ip_address) + '</code></div>' : '')
                + (loc ? '<div class="text-xs text-slate-500">' + escapeHtml(loc) + '</div>' : '')
                + (asset.last_ping_status ? '<div class="text-xs mt-1">Status: ' + escapeHtml(asset.last_ping_status) + '</div>' : '')
                + snap;
            showPopup(html, el);
        }

        function showLinkPopup(link, poly) {
            const html = (link.label ? '<div class="font-semibold">' + escapeHtml(link.label) + '</div>' : '')
                + '<div>' + escapeHtml(link.from_tag || ('#' + link.from_asset_id)) + ' &rarr; ' + escapeHtml(link.to_tag || ('#' + link.to_asset_id)) + '</div>'
                + '<div class="text-slate-500">Jenis: ' + escapeHtml(CABLE_LABELS[link.cable_type] || link.cable_type) + '</div>'
                + '<button type="button" data-link-id="' + link.id + '" class="link-delete mt-2 text-xs text-red-600 hover:text-red-800">Hapus Jalur</button>';
            showPopup(html, poly);
            const btn = document.querySelector('#tempPopup .link-delete');
            if (btn) btn.addEventListener('click', () => deleteLink(link.id));
        }

        function addMarker(item) {
            const asset = item.asset;
            const el = document.createElement('div');
            el.className = 'pointer-events-auto absolute';
            el.style.left = item.x + '%';
            el.style.top = item.y + '%';
            el.style.width = '24px';
            el.style.height = '24px';
            el.style.transform = 'translate(-50%, -50%)';
            el.style.borderRadius = '9999px';
            el.style.background = colorFor(asset.device_type);
            el.style.border = '3px solid #fff';
            el.style.boxShadow = '0 1px 4px rgba(0,0,0,.4)';
            el.style.cursor = 'move';
            el.title = displayName(asset);

            el.addEventListener('click', (e) => {
                e.stopPropagation();
                if (state.mode === 'link') {
                    handleLinkMarkerClick(asset, item);
                } else {
                    showMarkerPopup(item, el);
                }
            });

            el.addEventListener('pointerdown', (e) => {
                e.stopPropagation();
                if (state.mode === 'link') return;
                const startX = e.clientX, startY = e.clientY;
                const origX = item.x, origY = item.y;
                const onMove = (ev) => {
                    const r = denahWrap.getBoundingClientRect();
                    const nx = Math.max(0, Math.min(100, origX + ((ev.clientX - startX) / r.width) * 100));
                    const ny = Math.max(0, Math.min(100, origY + ((ev.clientY - startY) / r.height) * 100));
                    item.x = nx;
                    item.y = ny;
                    el.style.left = nx + '%';
                    el.style.top = ny + '%';
                    redrawLinks();
                };
                const onUp = () => {
                    document.removeEventListener('pointermove', onMove);
                    document.removeEventListener('pointerup', onUp);
                    saveMove(item);
                };
                document.addEventListener('pointermove', onMove);
                document.addEventListener('pointerup', onUp);
            });

            markersLayer.appendChild(el);
            itemsMap[asset.id] = { item, el };
            renderPlaced();
            return el;
        }

        function removeMarker(assetId) {
            const entry = itemsMap[assetId];
            if (entry) entry.el.remove();
            delete itemsMap[assetId];
            Object.keys(pathEls).forEach(id => {
                const link = linksData.find(l => l.id == id);
                if (link && (link.from_asset_id === assetId || link.to_asset_id === assetId)) {
                    pathEls[id].remove();
                    delete pathEls[id];
                }
            });
            renderPlaced();
        }

        const PLACE_URL = '{{ route('it-assets.layouts.place', $layout) }}';
        const STORE_LINK_URL = '{{ route('it-assets.layouts.store-link', $layout) }}';
        const DELETE_LINK_URL = '{{ route('it-assets.network-links.destroy', ['networkLink' => '__ID__']) }}';
        const MOVE_ITEM_URL = '{{ route('it-assets.layouts.move-asset', ['item' => '__ID__']) }}';
        const REMOVE_ITEM_URL = '{{ route('it-assets.layouts.remove-asset', ['item' => '__ID__']) }}';

        async function saveMove(item) {
            if (item.id == null) return;
            const res = await fetch(MOVE_ITEM_URL.replace('__ID__', item.id), {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                body: JSON.stringify({ x: item.x, y: item.y }),
            });
            if (!res.ok) {
                const d = await res.json().catch(() => ({}));
                alert(d.message || 'Gagal memindahkan posisi.');
            }
        }

        async function deleteLink(linkId) {
            if (!confirm('Hapus jalur kabel ini?')) return;
            const res = await fetch(DELETE_LINK_URL.replace('__ID__', linkId), {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            });
            const data = await res.json();
            if (!res.ok) {
                alert(data.message || 'Gagal menghapus jalur.');
                return;
            }
            closePopup();
            if (pathEls[linkId]) {
                pathEls[linkId].remove();
                delete pathEls[linkId];
            }
            linksData = linksData.filter(l => l.id != linkId);
        }

        async function removePlacement(assetId) {
            const entry = itemsMap[assetId];
            if (!entry) return;
            if (!confirm('Keluarkan perangkat ini dari denah?')) return;
            const res = await fetch(REMOVE_ITEM_URL.replace('__ID__', entry.item.id), {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            });
            const data = await res.json();
            if (!res.ok) {
                alert(data.message || 'Gagal mengeluarkan perangkat.');
                return;
            }
            removeMarker(assetId);
            if (UNPLACED.findIndex(a => a.id === assetId) === -1) {
                UNPLACED.push(entry.item.asset);
            }
            renderUnplaced();
        }

        function handleLinkMarkerClick(asset, item) {
            closePopup();
            if (!state.linkFrom) {
                state.linkFrom = { asset, item };
                setHint('Klik marker tujuan untuk kabel dari ' + displayName(asset) + '.');
                return;
            }
            if (state.linkFrom.item.it_asset_id === item.it_asset_id) {
                setHint('Marker asal dan tujuan tidak boleh sama.');
                return;
            }
            state.linkTo = { asset, item };
            openLinkModal();
        }

        const linkModal = document.getElementById('linkModal');
        const linkEndpoints = document.getElementById('linkEndpoints');
        const btnCancelLink = document.getElementById('btnCancelLink');
        const btnSaveLink = document.getElementById('btnSaveLink');

        function openLinkModal() {
            linkEndpoints.textContent = displayName(state.linkFrom.asset) + '  →  ' + displayName(state.linkTo.asset);
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
                layout_id: LAYOUT.id,
                from_asset_id: state.linkFrom.item.it_asset_id,
                to_asset_id: state.linkTo.item.it_asset_id,
                cable_type: cableType,
                label: label || null,
            };
            const res = await fetch(STORE_LINK_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                body: JSON.stringify(body),
            });
            const data = await res.json();
            if (!res.ok) {
                alert(data.message || 'Gagal menyimpan jalur.');
                return;
            }
            linksData.push(data.link);
            drawLink(data.link);
            closeLinkModal();
        });

        btnDrawLink.addEventListener('click', () => {
            if (Object.keys(itemsMap).length < 2) {
                setHint('Setidaknya dua perangkat harus sudah terpasang untuk membuat kabel.');
                return;
            }
            setMode('link');
            setHint('Klik marker perangkat asal, lalu marker tujuan.');
        });

        btnCancelMode.addEventListener('click', () => setMode('idle'));

        denahWrap.addEventListener('click', async (e) => {
            if (state.mode !== 'place' || !state.placingAssetId) return;
            const assetId = state.placingAssetId;
            const pct = computePct(e.clientX, e.clientY);
            const res = await fetch(PLACE_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                body: JSON.stringify({ layout_id: LAYOUT.id, asset_id: assetId, x: pct.x, y: pct.y }),
            });
            const data = await res.json();
            if (!res.ok) {
                alert(data.message || 'Gagal menempatkan perangkat.');
                return;
            }
            addMarker(data.item);
            UNPLACED.splice(UNPLACED.findIndex(a => a.id === assetId), 1);
            renderUnplaced();
            setMode('idle');
        });

        document.addEventListener('click', (e) => {
            const pop = document.getElementById('tempPopup');
            if (pop && !pop.contains(e.target) && !e.target.closest('.pointer-events-auto')) {
                closePopup();
            }
        });

        function renderPlaced() {
            const list = document.getElementById('placedList');
            list.innerHTML = '';
            if (!Object.keys(itemsMap).length) {
                list.innerHTML = '<li class="text-xs text-slate-400">Belum ada perangkat di denah ini.</li>';
                return;
            }
            Object.values(itemsMap).forEach(entry => {
                const asset = entry.item.asset;
                const li = document.createElement('li');
                li.className = 'flex items-center gap-2 px-2 py-1.5 rounded-lg hover:bg-slate-50 text-xs text-slate-700';
                li.innerHTML = '<span class="w-2.5 h-2.5 rounded-full shrink-0" style="background:' + colorFor(asset.device_type) + '"></span>'
                    + '<span class="min-w-0 flex-1 truncate">' + escapeHtml(displayName(asset)) + '</span>'
                    + '<button type="button" class="placed-remove text-slate-400 hover:text-red-600 px-1" title="Keluarkan dari denah">&times;</button>';
                li.querySelector('.placed-remove').addEventListener('click', () => removePlacement(asset.id));
                list.appendChild(li);
            });
        }

        function renderUnplaced() {
            const list = document.getElementById('unplacedList');
            list.innerHTML = '';
            if (!UNPLACED.length) {
                list.innerHTML = '<li class="text-xs text-slate-400">Semua perangkat aktif sudah ditempatkan.</li>';
                return;
            }
            UNPLACED.forEach(asset => {
                const li = document.createElement('li');
                li.className = 'flex items-center gap-2 px-2 py-1.5 rounded-lg hover:bg-slate-50 cursor-pointer text-xs text-slate-700';
                li.innerHTML = '<span class="w-2.5 h-2.5 rounded-full shrink-0" style="background:' + colorFor(asset.device_type) + '"></span>'
                    + '<span class="min-w-0 truncate">' + escapeHtml(displayName(asset)) + '<span class="text-slate-400"> · ' + escapeHtml(deviceLabels[asset.device_type] || asset.device_type) + '</span></span>';
                li.addEventListener('click', () => {
                    state.placingAssetId = asset.id;
                    setMode('place');
                    denahWrap.style.cursor = 'crosshair';
                    setHint('Klik lokasi "' + displayName(asset) + '" di denah.');
                });
                list.appendChild(li);
            });
        }

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
            renderBackground();
            (LAYOUT.items || []).forEach(addMarker);
            (LAYOUT.links || []).forEach(drawLink);
            renderLegends();
            renderUnplaced();
            renderPlaced();
        }

        init();
    </script>
@endpush

@endsection
