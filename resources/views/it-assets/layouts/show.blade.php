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
            <label for="unplacedTypeFilter" class="sr-only">Filter jenis perangkat</label>
            <select id="unplacedTypeFilter"
                    class="w-full mb-2 border border-slate-300 rounded-lg px-2 py-1.5 text-xs text-slate-700 bg-white focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                <option value="">Semua Jenis</option>
            </select>
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
        <div id="denahWrap" class="relative rounded-xl border border-slate-200 bg-white overflow-hidden select-none" style="min-height:420px; cursor:grab;">
            <div id="stage" class="relative w-full" style="transform-origin:0 0;">
                <div id="bgBox" class="pointer-events-none"></div>
                <svg id="svgLinks" class="absolute inset-0 w-full h-full pointer-events-none" viewBox="0 0 100 100" preserveAspectRatio="none"></svg>
                <div id="markersLayer" class="absolute inset-0 pointer-events-none"></div>
            </div>
            <div id="zoomControls" class="absolute top-2 right-2 z-30 flex items-center gap-1 bg-white rounded-lg border border-slate-200 shadow px-1 py-1">
                <button id="zoomOut" type="button" title="Perkecil" class="w-7 h-7 rounded-md text-slate-600 hover:bg-slate-100 transition-colors text-sm font-bold leading-none">&minus;</button>
                <span id="zoomLabel" class="w-12 text-center text-xs font-medium text-slate-600">100%</span>
                <button id="zoomIn" type="button" title="Perbesar" class="w-7 h-7 rounded-md text-slate-600 hover:bg-slate-100 transition-colors text-sm font-bold leading-none">+</button>
                <button id="zoomReset" type="button" title="Reset zoom" class="w-7 h-7 rounded-md text-slate-600 hover:bg-slate-100 transition-colors" aria-label="Reset">
                    <svg class="w-4 h-4 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v6h6M20 20v-6h-6M4 10a8 8 0 0116 0M20 14a8 8 0 01-16 0"/></svg>
                </button>
            </div>
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
        const deviceLabels = { PC: 'PC', LAPTOP: 'Laptop', PRINTER: 'Printer', CCTV: 'CCTV', NVR: 'NVR', ACCESS_DOOR: 'Access Door', WIFI: 'WiFi', NETWORK: 'Network', SWITCH_MANAGED: 'Switch Managed', SWITCH_POE_UNMANAGED: 'Switch PoE Unmanaged', ACCESS_POINT: 'Access Point', HUB: 'HUB', UPS: 'UPS', OTHER: 'Lainnya' };

        // Ikon (logo) per jenis perangkat — dibedakan dari bentuknya.
        const DEVICE_ICONS = {
            PC: '<path d="M4 5h16v11H4zM9 19h6M12 16v3"/>',
            LAPTOP: '<path d="M4 6h16v9H4zM2 18h20"/>',
            PRINTER: '<path d="M7 8V4h10v4M9 14h6M3 10a2 2 0 012-2h14a2 2 0 012 2v5h-4v4H7v-4H3z"/>',
            CCTV: '<path d="M4 7h12a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V9a2 2 0 012-2zm16 3l4-2v8l-4-2z"/>',
            NVR: '<path d="M4 4h16v6H4zM4 14h16v6H4zM8 7h.01M8 17h.01"/>',
            ACCESS_DOOR: '<path d="M5 3h14a1 1 0 011 1v16a1 1 0 01-1 1H5a1 1 0 01-1-1V4a1 1 0 011-1zM15 13h2"/>',
            WIFI: '<path d="M12 18h.01M8.3 14.3a5.5 5.5 0 017.4 0M5.1 11.1a10 10 0 0113.8 0M2 8a15 15 0 0120 0"/>',
            NETWORK: '<path d="M12 3a3 3 0 100 6 3 3 0 000-6zM12 15a3 3 0 100 6 3 3 0 000-6zM5 9a3 3 0 100 6 3 3 0 000-6zM19 9a3 3 0 100 6 3 3 0 000-6zM8 7l2 3M16 7l-2 3M10 14l-2 2M14 14l2 2"/>',
            SWITCH_MANAGED: '<path d="M3 5h18v6H3zM3 13h18v6H3zM7 8h.01M7 16h.01M11 8h6M11 16h6"/>',
            SWITCH_POE_UNMANAGED: '<path d="M3 5h18v6H3zM3 13h18v6H3zM7 8h.01M7 16h.01M12 8h4M12 16h4M13 2l-1.5 2.5h2.5L12 7"/>',
            ACCESS_POINT: '<path d="M12 3a9 9 0 019 9M12 3a9 9 0 00-9 9M12 6a6 6 0 016 6M12 6a6 6 0 00-6 6M12 9a3 3 0 013 3M12 9a3 3 0 00-3 3M12 21a2 2 0 100-4 2 2 0 000 4z"/>',
            HUB: '<path d="M12 6a2 2 0 100-4 2 2 0 000 4zM12 14a2 2 0 100-4 2 2 0 000 4zM6 18a2 2 0 100-4 2 2 0 000 4zM18 18a2 2 0 100-4 2 2 0 000 4zM10 8l-3 6M14 8l3 6M12 14v4"/>',
            UPS: '<path d="M13 2L3 14h8l-1 8 10-12h-8l1-8z"/>',
            OTHER: '<circle cx="12" cy="12" r="7"/>',
        };

        function deviceIconHtml(type) {
            const icon = DEVICE_ICONS[type] || DEVICE_ICONS.OTHER;
            return '<svg class="block mx-auto" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:13px;height:13px;">' + icon + '</svg>';
        }

        function lockBadgeHtml() {
            return '<span class="absolute -top-1 -right-1 w-3.5 h-3.5 rounded-full bg-amber-500 border border-white flex items-center justify-center">'
                + '<svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="width:8px;height:8px;"><path d="M7 11h10v9H7zM9 11V8a3 3 0 016 0v3"/></svg>'
                + '</span>';
        }

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

        const denahWrap = document.getElementById('denahWrap');
        const stage = document.getElementById('stage');
        const bgBox = document.getElementById('bgBox');
        const svgLinks = document.getElementById('svgLinks');
        const markersLayer = document.getElementById('markersLayer');
        const loading = document.getElementById('loading');
        const modeHint = document.getElementById('modeHint');
        const btnDrawLink = document.getElementById('btnDrawLink');
        const btnCancelMode = document.getElementById('btnCancelMode');
        const zoomLabel = document.getElementById('zoomLabel');
        const zoomInBtn = document.getElementById('zoomIn');
        const zoomOutBtn = document.getElementById('zoomOut');
        const zoomResetBtn = document.getElementById('zoomReset');

        const state = { mode: 'idle', placingAssetId: null, linkFrom: null, linkTo: null };
        const itemsMap = {};
        const pathEls = {};
        let zoom = 1;
        let pan = { x: 0, y: 0 };
        let panned = false;
        let unplacedTypeFilter = '';
        // Untuk re-render PDF pada resolusi sesuai zoom (agar tidak blur).
        let pdfDoc = null;
        let pdfPage = null;
        let pdfBaseVp = null;
        let pdfBaseScale = 1;
        let pdfTimer = null;
        let pdfRenderSeq = 0;

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

        function contentPct(clientX, clientY) {
            const r = denahWrap.getBoundingClientRect();
            const px = clientX - r.left;
            const py = clientY - r.top;
            const cx = (px - pan.x) / zoom;
            const cy = (py - pan.y) / zoom;
            const w = stage.offsetWidth || 1;
            const h = stage.offsetHeight || 1;
            return {
                x: Math.max(0, Math.min(100, (cx / w) * 100)),
                y: Math.max(0, Math.min(100, (cy / h) * 100)),
            };
        }

        function applyStage() {
            stage.style.transform = 'translate(' + pan.x + 'px,' + pan.y + 'px) scale(' + zoom + ')';
            if (zoomLabel) zoomLabel.textContent = Math.round(zoom * 100) + '%';
            updateMarkerScale();
            schedulePdfRerender();
        }

        function updateMarkerScale() {
            const s = 1 / zoom;
            Object.values(itemsMap).forEach(entry => {
                entry.el.style.transform = 'translate(-50%, -50%) scale(' + s + ')';
            });
        }

        function setZoomCenter(newZoom, clientX, clientY) {
            const r = denahWrap.getBoundingClientRect();
            const px = clientX - r.left;
            const py = clientY - r.top;
            const cx = (px - pan.x) / zoom;
            const cy = (py - pan.y) / zoom;
            newZoom = Math.max(0.3, Math.min(6, newZoom));
            pan.x = px - cx * newZoom;
            pan.y = py - cy * newZoom;
            zoom = newZoom;
            applyStage();
        }

        denahWrap.addEventListener('wheel', (e) => {
            e.preventDefault();
            const factor = e.deltaY < 0 ? 1.15 : 0.87;
            setZoomCenter(zoom * factor, e.clientX, e.clientY);
        }, { passive: false });

        zoomInBtn.addEventListener('click', () => {
            const r = denahWrap.getBoundingClientRect();
            setZoomCenter(zoom * 1.25, r.left + r.width / 2, r.top + r.height / 2);
        });
        zoomOutBtn.addEventListener('click', () => {
            const r = denahWrap.getBoundingClientRect();
            setZoomCenter(zoom / 1.25, r.left + r.width / 2, r.top + r.height / 2);
        });
        zoomResetBtn.addEventListener('click', () => {
            zoom = 1;
            pan = { x: 0, y: 0 };
            applyStage();
        });

        // Pan (geser) dengan drag di area kosong denah.
        let panCtx = null;
        denahWrap.addEventListener('pointerdown', (e) => {
            if (e.button !== 0 || e.target.closest('.pointer-events-auto')) return;
            panCtx = { x: e.clientX, y: e.clientY, px: pan.x, py: pan.y, started: false };
            panned = false;
        });
        document.addEventListener('pointermove', (e) => {
            if (!panCtx) return;
            const dx = e.clientX - panCtx.x;
            const dy = e.clientY - panCtx.y;
            if (!panCtx.started && (Math.abs(dx) + Math.abs(dy)) < 6) return;
            panCtx.started = true;
            panned = true;
            pan.x = panCtx.px + dx;
            pan.y = panCtx.py + dy;
            applyStage();
        });
        document.addEventListener('pointerup', () => { panCtx = null; });

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
                .then(pdf => { pdfDoc = pdf; return pdf.getPage(Math.min(LAYOUT.page || 1, pdf.numPages)); })
                .then(page => {
                    pdfPage = page;
                    pdfBaseVp = page.getViewport({ scale: 1 });
                    pdfBaseScale = 1600 / pdfBaseVp.width;
                    return renderPageCanvas(page, pdfScaleForContainer());
                })
                .then(canvas => { setBgCanvas(canvas); loading.style.display = 'none'; })
                .catch(err => {
                    bgBox.innerHTML = '<div class="text-red-600 text-sm p-4">Gagal memuat denah: ' + escapeHtml(err.message) + '</div>';
                    loading.style.display = 'none';
                });
        }

        function renderPageCanvas(page, scale) {
            const viewport = page.getViewport({ scale: scale });
            const canvas = document.createElement('canvas');
            canvas.width = viewport.width;
            canvas.height = viewport.height;
            canvas.className = 'block w-full h-auto';
            return page.render({ canvasContext: canvas.getContext('2d'), viewport: viewport }).promise.then(() => canvas);
        }

        function setBgCanvas(canvas) {
            const old = bgBox.querySelector('canvas');
            bgBox.appendChild(canvas);
            if (old && old !== canvas) old.remove();
        }

        function pdfScaleForContainer() {
            const dpr = window.devicePixelRatio || 1;
            const containerW = denahWrap.clientWidth || 1400;
            const s = (containerW * Math.max(1, zoom) * dpr) / pdfBaseVp.width;
            return Math.max(s, pdfBaseScale);
        }

        function rerenderPdf() {
            if (!pdfPage || !pdfBaseVp) return;
            const seq = ++pdfRenderSeq;
            const maxWidth = 8000;
            let scale = pdfScaleForContainer();
            if (pdfBaseVp.width * scale > maxWidth) scale = maxWidth / pdfBaseVp.width;
            renderPageCanvas(pdfPage, scale).then(canvas => {
                if (pdfPage && seq === pdfRenderSeq) setBgCanvas(canvas);
            });
        }

        function schedulePdfRerender() {
            if (LAYOUT.file_type !== 'pdf' || !pdfPage) return;
            clearTimeout(pdfTimer);
            pdfTimer = setTimeout(rerenderPdf, 140);
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

            const ar = anchorEl.getBoundingClientRect();
            const pop = document.createElement('div');
            pop.id = 'tempPopup';
            pop.className = 'fixed bg-white rounded-lg border border-slate-200 shadow-lg text-sm p-3 whitespace-normal';
            pop.style.maxWidth = '300px';
            pop.style.maxHeight = '72vh';
            pop.style.overflowY = 'auto';
            pop.style.zIndex = '9999';
            pop.style.left = (ar.left + ar.width / 2) + 'px';
            pop.style.top = (ar.top - 6) + 'px';
            pop.style.transform = 'translate(-50%, -100%)';
            pop.innerHTML = html;
            document.body.appendChild(pop);

            // Jaga popup tetap berada di dalam layar (tidak terpotong frame).
            const rect = pop.getBoundingClientRect();
            const margin = 8;
            if (rect.left < margin) {
                pop.style.left = (parseFloat(pop.style.left) + (margin - rect.left)) + 'px';
            } else if (rect.right > window.innerWidth - margin) {
                pop.style.left = (parseFloat(pop.style.left) - (rect.right - (window.innerWidth - margin))) + 'px';
            }
            if (rect.top < margin) {
                // Ruang di atas marker tidak cukup, tampilkan di bawah marker.
                pop.style.top = (ar.bottom + 8) + 'px';
                pop.style.transform = 'translate(-50%, 0)';
            }
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
            let actions;
            if (item.is_locked) {
                actions = '<button type="button" data-act="unlock" class="px-2 py-1 text-xs font-medium bg-slate-100 hover:bg-slate-200 text-slate-700 rounded">Buka Kunci</button>';
            } else {
                actions = '<button type="button" data-act="lock" class="px-2 py-1 text-xs font-medium bg-slate-100 hover:bg-slate-200 text-slate-700 rounded">Kunci</button>'
                    + '<button type="button" data-act="remove" class="px-2 py-1 text-xs font-medium text-red-600 hover:text-red-800">Hapus</button>';
            }
            const html = '<div class="font-semibold">' + escapeHtml(displayName(asset)) + '</div>'
                + '<div class="text-slate-500">' + escapeHtml(deviceLabels[asset.device_type] || asset.device_type || 'Perangkat') + '</div>'
                + (item.is_locked ? '<div class="text-xs text-amber-600 mt-1">Status: Terkunci</div>' : '')
                + (asset.ip_address ? '<div class="mt-1">IP: <code class="text-xs">' + escapeHtml(asset.ip_address) + '</code></div>' : '')
                + (loc ? '<div class="text-xs text-slate-500">' + escapeHtml(loc) + '</div>' : '')
                + (asset.last_ping_status ? '<div class="text-xs mt-1">Status: ' + escapeHtml(asset.last_ping_status) + '</div>' : '')
                + snap
                + '<div class="mt-2 flex gap-2">' + actions + '</div>';
            showPopup(html, el);

            const lockBtn = document.querySelector('#tempPopup [data-act="lock"], #tempPopup [data-act="unlock"]');
            if (lockBtn) lockBtn.addEventListener('click', () => toggleLockItem(item.it_asset_id));
            const removeBtn = document.querySelector('#tempPopup [data-act="remove"]');
            if (removeBtn) removeBtn.addEventListener('click', () => removePlacement(item.it_asset_id));
        }

        async function toggleLockItem(assetId) {
            const entry = itemsMap[assetId];
            if (!entry) return;
            const res = await fetch(TOGGLE_LOCK_URL.replace('__ID__', entry.item.id), {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            });
            const data = await res.json();
            if (!res.ok) {
                alert(data.message || 'Gagal mengubah status kunci.');
                return;
            }
            entry.item.is_locked = data.item.is_locked;
            closePopup();
            refreshMarker(entry);
            renderPlaced();
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
            el.style.width = '26px';
            el.style.height = '26px';
            el.style.borderRadius = '50%';
            el.style.display = 'flex';
            el.style.alignItems = 'center';
            el.style.justifyContent = 'center';
            el.style.background = colorFor(asset.device_type);
            el.style.border = '3px solid #fff';
            el.style.boxShadow = '0 1px 4px rgba(0,0,0,.4)';
            el.style.color = '#fff';
            el.title = displayName(asset);

            const entry = { item, el };
            markersLayer.appendChild(el);
            itemsMap[asset.id] = entry;

            refreshMarker(entry);
            bindMarkerEvents(entry);
            renderPlaced();
            return el;
        }

        function refreshMarker(entry) {
            const { item, el } = entry;
            const asset = item.asset;
            el.style.left = item.x + '%';
            el.style.top = item.y + '%';
            el.innerHTML = deviceIconHtml(asset.device_type) + (item.is_locked ? lockBadgeHtml() : '');
            if (item.is_locked) {
                el.style.cursor = 'pointer';
                el.style.borderStyle = 'dashed';
                el.style.borderColor = '#f59e0b';
            } else {
                el.style.cursor = 'move';
                el.style.borderStyle = 'solid';
                el.style.borderColor = '#fff';
            }
        }

        function bindMarkerEvents(entry) {
            const { item, el } = entry;
            const asset = item.asset;
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
                if (state.mode === 'link' || item.is_locked) return;
                const start = { x: item.x, y: item.y };
                let moved = false;
                const onMove = (ev) => {
                    const p = contentPct(ev.clientX, ev.clientY);
                    if (Math.abs(p.x - start.x) > 0.01 || Math.abs(p.y - start.y) > 0.01) moved = true;
                    item.x = p.x;
                    item.y = p.y;
                    el.style.left = p.x + '%';
                    el.style.top = p.y + '%';
                    if (moved) redrawLinks();
                };
                const onUp = () => {
                    document.removeEventListener('pointermove', onMove);
                    document.removeEventListener('pointerup', onUp);
                    if (moved) saveMove(item);
                };
                document.addEventListener('pointermove', onMove);
                document.addEventListener('pointerup', onUp);
            });
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
        const TOGGLE_LOCK_URL = '{{ route('it-assets.layouts.toggle-lock', ['item' => '__ID__']) }}';

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
            closePopup();
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
            if (panned) { panned = false; return; }
            const assetId = state.placingAssetId;
            const pct = contentPct(e.clientX, e.clientY);
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

        // Popup posisi fixed; tutup saat halaman digulir agar tidak "mengambang"
        // jauh dari marker yang ikut bergeser karena scroll.
        window.addEventListener('scroll', closePopup, { passive: true });

        function renderPlaced() {
            const list = document.getElementById('placedList');
            list.innerHTML = '';
            if (!Object.keys(itemsMap).length) {
                list.innerHTML = '<li class="text-xs text-slate-400">Belum ada perangkat di denah ini.</li>';
                return;
            }
            Object.values(itemsMap).forEach(entry => {
                const asset = entry.item.asset;
                const locked = entry.item.is_locked;
                const li = document.createElement('li');
                li.className = 'flex items-center gap-2 px-2 py-1.5 rounded-lg hover:bg-slate-50 text-xs text-slate-700';
                li.innerHTML = '<span class="w-2.5 h-2.5 rounded-full shrink-0" style="background:' + colorFor(asset.device_type) + '"></span>'
                    + '<span class="min-w-0 flex-1 truncate" title="' + escapeHtml(displayName(asset)) + '">' + escapeHtml(displayName(asset)) + '</span>'
                    + (locked ? '<span class="flex items-center justify-center shrink-0" title="Terkunci"><svg viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2.5" style="width:12px;height:12px"><path d="M7 11h10v9H7zM9 11V8a3 3 0 016 0v3"/></svg></span>' : '')
                    + (locked ? '' : '<button type="button" class="placed-remove text-slate-400 hover:text-red-600 px-1" title="Keluarkan dari denah">&times;</button>');
                const removeBtn = li.querySelector('.placed-remove');
                if (removeBtn) removeBtn.addEventListener('click', () => removePlacement(asset.id));
                list.appendChild(li);
            });
        }

        function renderUnplacedTypes() {
            const sel = document.getElementById('unplacedTypeFilter');
            if (!sel) return;
            const current = unplacedTypeFilter;
            const types = [...new Set(UNPLACED.map(a => a.device_type))].sort();
            sel.innerHTML = '';
            const optAll = document.createElement('option');
            optAll.value = '';
            optAll.textContent = 'Semua Jenis';
            sel.appendChild(optAll);
            types.forEach(type => {
                const opt = document.createElement('option');
                opt.value = type;
                opt.textContent = deviceLabels[type] || type;
                sel.appendChild(opt);
            });
            if (current && types.includes(current)) {
                sel.value = current;
                unplacedTypeFilter = current;
            } else {
                sel.value = '';
                unplacedTypeFilter = '';
            }
        }

        function renderUnplaced() {
            renderUnplacedTypes();
            const list = document.getElementById('unplacedList');
            list.innerHTML = '';
            if (!UNPLACED.length) {
                list.innerHTML = '<li class="text-xs text-slate-400">Semua perangkat aktif sudah ditempatkan.</li>';
                return;
            }
            const filtered = unplacedTypeFilter
                ? UNPLACED.filter(asset => asset.device_type === unplacedTypeFilter)
                : UNPLACED;
            if (!filtered.length) {
                list.innerHTML = '<li class="text-xs text-slate-400">Tidak ada perangkat untuk jenis ini.</li>';
                return;
            }
            filtered.forEach(asset => {
                const li = document.createElement('li');
                li.className = 'flex items-center gap-2 px-2 py-1.5 rounded-lg hover:bg-slate-50 cursor-pointer text-xs text-slate-700';
                li.innerHTML = '<span class="w-2.5 h-2.5 rounded-full shrink-0" style="background:' + colorFor(asset.device_type) + '"></span>'
                    + '<span class="min-w-0 truncate">' + escapeHtml(displayName(asset)) + '<span class="text-slate-400"> · ' + escapeHtml(deviceLabels[asset.device_type] || asset.device_type) + '</span></span>';
                li.addEventListener('click', (e) => {
                    e.stopPropagation();
                    showUnplacedPopup(asset, li);
                });
                list.appendChild(li);
            });
        }

        function showUnplacedPopup(asset, anchorEl) {
            const typeLabel = deviceLabels[asset.device_type] || asset.device_type || 'Perangkat';
            const rows = [];
            rows.push(['Tipe', typeLabel]);
            if (asset.ip_address) rows.push(['IP', asset.ip_address]);
            if (asset.plant) rows.push(['Plant', asset.plant]);
            if (asset.department) rows.push(['Department', asset.department]);
            if (asset.location) rows.push(['Lokasi', asset.location]);
            if (asset.last_ping_status) rows.push(['Status ping', asset.last_ping_status]);
            if (asset.spec_items) {
                Object.keys(asset.spec_items).forEach(function (k) {
                    const v = asset.spec_items[k];
                    if (v !== null && v !== undefined && v !== '') rows.push([k, v]);
                });
            }

            let table = '<table class="w-full text-xs mt-2">';
            rows.forEach(function (r) {
                table += '<tr>'
                    + '<td class="py-0.5 pr-2 text-slate-400 align-top whitespace-nowrap">' + escapeHtml(r[0]) + '</td>'
                    + '<td class="py-0.5 text-slate-800 break-words">' + escapeHtml(r[1]) + '</td>'
                    + '</tr>';
            });
            table += '</table>';

            const snap = asset.snapshot_url
                ? '<img src="' + escapeHtml(asset.snapshot_url) + '" alt="Snapshot" class="block w-full rounded-lg border border-slate-200 mt-2">'
                : '';

            const html = '<div class="font-semibold text-slate-900">' + escapeHtml(displayName(asset)) + '</div>'
                + '<div class="text-slate-500">' + escapeHtml(typeLabel) + '</div>'
                + table + snap
                + '<button type="button" data-act="place" class="mt-3 w-full px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-medium rounded-lg">Tempatkan di denah</button>';

            showPopup(html, anchorEl);

            const placeBtn = document.querySelector('#tempPopup [data-act="place"]');
            if (placeBtn) {
                placeBtn.addEventListener('click', function () {
                    state.placingAssetId = asset.id;
                    setMode('place');
                    denahWrap.style.cursor = 'crosshair';
                    setHint('Klik lokasi "' + displayName(asset) + '" di denah.');
                    closePopup();
                });
            }
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
            applyStage();
            renderBackground();
            (LAYOUT.items || []).forEach(addMarker);
            updateMarkerScale();
            (LAYOUT.links || []).forEach(drawLink);
            renderLegends();
            const unplacedTypeFilterEl = document.getElementById('unplacedTypeFilter');
            if (unplacedTypeFilterEl) {
                unplacedTypeFilterEl.addEventListener('change', function () {
                    unplacedTypeFilter = this.value;
                    renderUnplaced();
                });
            }
            renderUnplaced();
            renderPlaced();
        }

        init();
    </script>
@endpush

@endsection
