@extends('layouts.app')

@section('title', 'CM Monitoring — Condition Monitoring')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="text-[#6e6e73] hover:text-[#1d1d1f]">Dashboard</a>
    <span class="text-[#d2d2d7]">/</span>
    <a href="{{ route('cm.overview') }}" class="text-[#6e6e73] hover:text-[#1d1d1f]">Condition Monitoring</a>
    <span class="text-[#d2d2d7]">/</span>
    <span class="text-[#1d1d1f] font-medium">CM Monitoring</span>
@endsection

@section('content')
    @include('partials._apple-ui')

    @php
        $pct        = $ringkasan['pct'];
        $pctOf      = fn($part, $total) => $total > 0 ? round(($part / $total) * 100, 2) : 0;
        $bulanShort = [1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
                       7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'];
        $bulanSebelumLabel = ($bulanIni - 1 >= $bulanMulai) ? ($bulanShort[$bulanIni - 1] ?? '') : '';
    @endphp

    <div class="ap">
        <div class="ap-wrap">
            @include('cm._tabs')

            {{-- ============ HERO: RINGKASAN BULAN ACUAN ============ --}}
            <section class="ap-hero">
                <div class="ap-hero-top">
                    <div>
                        <p class="ap-eyebrow">Condition Monitoring &middot; {{ $bulanNama }} {{ $filterTahun }}</p>
                        <h2 class="ap-display" style="margin-top:10px;">Cakupan data {{ $pct }}%.</h2>
                        <p class="ap-lede" style="margin-top:14px;">
                            {{ $ringkasan['covered'] }} dari {{ $ringkasan['total'] }} equipment sudah tercakup
                            pada {{ $bulanNama }} {{ $filterTahun }}. Pilih angka di bawah untuk melihat
                            daftar equipment-nya.
                        </p>
                    </div>

                    <div class="ap-hero-tools">
                        <form method="GET" action="{{ route('cm.monitoring') }}">
                            <label for="filter-tahun" class="ap-hero-label">Tahun</label>
                            <select id="filter-tahun" name="tahun" onchange="this.form.submit()"
                                    class="ap-select">
                                @foreach($tahunList as $th)
                                    <option value="{{ $th }}" {{ (int) $filterTahun === (int) $th ? 'selected' : '' }}>{{ $th }}</option>
                                @endforeach
                            </select>
                        </form>
                        <a href="{{ route('cm.export-monitoring', ['tahun' => $filterTahun]) }}"
                           class="ap-btn ap-btn--primary">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M12 10v6m0 0l-3-3m3 3l3-3M7 20h10a2 2 0 002-2V5a2 2 0 00-2-2H9.586a1 1 0 00-.707.293L5.293 6.879A1 1 0 005 7.586V18a2 2 0 002 2z"/>
                            </svg>
                            Export
                        </a>
                    </div>
                </div>

                <div class="ap-stats">
                    <button type="button" class="ap-stat" data-open="sudah">
                        <span class="ap-stat-num">{{ $ringkasan['sudah'] }}<small>/{{ $ringkasan['total'] }}</small></span>
                        <span class="ap-stat-label">Sudah diambil &mdash; data {{ $bulanNama }} sudah masuk</span>
                    </button>
                    <button type="button" class="ap-stat" data-open="belum">
                        <span class="ap-stat-num">{{ $ringkasan['belum'] }}<small>/{{ $ringkasan['total'] }}</small></span>
                        <span class="ap-stat-label">Belum diambil &mdash; belum ada data {{ $bulanNama }}</span>
                    </button>
                    <button type="button" class="ap-stat" data-open="free">
                        <span class="ap-stat-num">{{ $ringkasan['stop'] }}<small>/{{ $ringkasan['total'] }}</small></span>
                        <span class="ap-stat-label">Stop / tidak running &mdash; perlu di-arrange</span>
                    </button>
                </div>

                <div class="ap-hero-bar" role="img"
                     aria-label="Sudah {{ $ringkasan['sudah'] }}, stop {{ $ringkasan['stop'] }}, belum {{ $ringkasan['belum'] }}">
                    <span class="ap-fill-ok" style="width: {{ $pctOf($ringkasan['sudah'], $ringkasan['total']) }}%"></span>
                    <span class="ap-fill-stop" style="width: {{ $pctOf($ringkasan['stop'], $ringkasan['total']) }}%"></span>
                    <span class="ap-fill-warn" style="width: {{ $pctOf($ringkasan['belum'], $ringkasan['total']) }}%"></span>
                </div>
                <div class="ap-hero-legend">
                    <span class="ap-legend-item"><span class="ap-dot ap-dot--ok"></span> Sudah {{ $ringkasan['sudah'] }}</span>
                    <span class="ap-legend-item"><span class="ap-dot ap-dot--muted"></span> Stop {{ $ringkasan['stop'] }}</span>
                    <span class="ap-legend-item"><span class="ap-dot ap-dot--warn"></span> Belum {{ $ringkasan['belum'] }}</span>
                    <span class="ap-hero-pct">{{ $pct }}% tercakup</span>
                </div>

                @if($ringkasan['perlu'] > 0)
                    <button type="button" class="ap-alert" data-open="issue">
                        <span class="ap-dot ap-dot--bad"></span>
                        <span>
                            <strong>{{ $ringkasan['perlu'] }} equipment perlu tindakan</strong> &mdash;
                            2 bulan berturut belum diambil atau stop
                            @if($bulanSebelumLabel)
                                ({{ $bulanSebelumLabel }} &amp; {{ $bulanShort[$bulanIni] ?? '' }} {{ $filterTahun }})
                            @endif
                        </span>
                        <span class="ap-alert-cta">Lihat daftar &rsaquo;</span>
                    </button>
                @endif
            </section>

            {{-- ============ KARTU PER PT (CES / EPE / NPA) ============ --}}
            <section class="ap-section">
                <div class="ap-section-head">
                    <div>
                        <h3 class="ap-h2">Cakupan per PT</h3>
                        <p class="ap-caption" style="margin-top:6px;">
                            Diurutkan dari PT dengan equipment belum diambil terbanyak.
                        </p>
                    </div>
                    <p class="ap-micro">Bulan acuan: {{ $bulanNama }} {{ $filterTahun }}</p>
                </div>

                <div class="ap-grid-3">
                    @foreach($ptSummary as $s)
                        <article class="ap-card">
                            <div class="ap-pt-head">
                                <div>
                                    <p class="ap-h3">{{ $s['pt'] }}</p>
                                    <p class="ap-caption" style="margin-top:2px;">
                                        {{ $s['total'] }} equipment &middot; {{ $s['covered'] }}/{{ $s['total'] }} tercakup
                                    </p>
                                </div>
                                <p class="ap-pt-pct">{{ $s['pct'] }}%</p>
                            </div>

                            <div class="ap-bar" style="margin-top:16px;">
                                <span class="ap-fill-ok" style="width: {{ $pctOf($s['sudah'], $s['total']) }}%"></span>
                                <span class="ap-fill-stop" style="width: {{ $pctOf($s['stop'], $s['total']) }}%"></span>
                                <span class="ap-fill-warn" style="width: {{ $pctOf($s['belum'], $s['total']) }}%"></span>
                            </div>

                            <div class="ap-pt-grid">
                                <button type="button" class="ap-pt-cell" data-open="sudah" data-pt="{{ $s['pt'] }}">
                                    <span class="ap-num-sm">{{ $s['sudah'] }}<span class="ap-faint" style="font-size:12px;">/{{ $s['total'] }}</span></span>
                                    <span class="ap-caption">Sudah</span>
                                </button>
                                <button type="button" class="ap-pt-cell" data-open="belum" data-pt="{{ $s['pt'] }}">
                                    <span class="ap-num-sm">{{ $s['belum'] }}<span class="ap-faint" style="font-size:12px;">/{{ $s['total'] }}</span></span>
                                    <span class="ap-caption">Belum</span>
                                </button>
                                <button type="button" class="ap-pt-cell" data-open="free" data-pt="{{ $s['pt'] }}">
                                    <span class="ap-num-sm">{{ $s['stop'] }}<span class="ap-faint" style="font-size:12px;">/{{ $s['total'] }}</span></span>
                                    <span class="ap-caption">Stop</span>
                                </button>
                            </div>

                            <div class="ap-pt-foot">
                                @if($s['belum2'] || $s['stop2'] || $s['campur'])
                                    @if($s['belum2'])
                                        <button type="button" class="ap-chip ap-chip--soft" data-open="belum2" data-pt="{{ $s['pt'] }}">
                                            <span class="ap-dot ap-dot--warn"></span>
                                            {{ $s['belum2'] }} belum 2 bln
                                        </button>
                                    @endif
                                    @if($s['stop2'])
                                        <button type="button" class="ap-chip ap-chip--soft" data-open="stop2" data-pt="{{ $s['pt'] }}">
                                            <span class="ap-dot ap-dot--muted"></span>
                                            {{ $s['stop2'] }} stop 2 bln
                                        </button>
                                    @endif
                                    @if($s['campur'])
                                        <button type="button" class="ap-chip ap-chip--soft" data-open="campur" data-pt="{{ $s['pt'] }}">
                                            <span class="ap-dot ap-dot--bad"></span>
                                            {{ $s['campur'] }} belum/stop
                                        </button>
                                    @endif
                                @else
                                    <p class="ap-micro">Tidak ada temuan 2 bulan berturut.</p>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>

            {{-- ============ REKAP PER EQUIPMENT ============ --}}
            <section id="eq-table" class="ap-section">
                <div class="ap-card ap-card--flush">
                    <div style="display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:12px 24px;padding:24px 24px 16px;">
                        <div>
                            <h3 class="ap-h3">Rekap bulanan per equipment</h3>
                            <p class="ap-caption" style="margin-top:4px;">
                                <span style="color:#248a3d;">&#10003;</span> sudah,
                                <span class="ap-dot ap-dot--muted"></span> stop,
                                <span style="color:#c7c7cc;">&mdash;</span> belum.
                                Klik tag untuk detail &amp; foto.
                            </p>
                        </div>
                        <div class="ap-search">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                                <circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>
                            </svg>
                            <input type="text" id="search-eq" class="ap-input" placeholder="Cari tag equipment..." autocomplete="off">
                        </div>
                    </div>

                    <div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px 12px;padding:0 24px 16px;">
                        <div class="ap-chips" role="group" aria-label="Filter PT">
                            <button type="button" class="ap-chip chip-pt" data-pt="">
                                Semua PT <small>({{ $ringkasan['total'] }})</small>
                            </button>
                            @foreach($ptSummary as $s)
                                <button type="button" class="ap-chip chip-pt" data-pt="{{ $s['pt'] }}">
                                    {{ $s['pt'] }} <small>({{ $s['total'] }})</small>
                                </button>
                            @endforeach
                        </div>

                        <div class="ap-chips" role="group" aria-label="Filter status">
                            <button type="button" class="ap-chip chip-status" data-status="">Semua</button>
                            <button type="button" class="ap-chip chip-status" data-status="belum">Belum ({{ $ringkasan['belum'] }})</button>
                            <button type="button" class="ap-chip chip-status" data-status="free">Stop ({{ $ringkasan['stop'] }})</button>
                            <button type="button" class="ap-chip chip-status" data-status="sudah">Sudah ({{ $ringkasan['sudah'] }})</button>
                            <button type="button" class="ap-chip chip-status" data-status="issue">2 bln+ ({{ $ringkasan['perlu'] }})</button>
                        </div>

                        <label class="ap-check" style="margin-left:auto;">
                            <input type="checkbox" id="hide-full">
                            Sembunyikan 100%
                        </label>
                    </div>

                    <div class="ap-table-wrap">
                        <table class="ap-table">
                            <thead>
                                <tr>
                                    <th style="padding-left:24px;min-width:160px;">Equipment</th>
                                    <th>PT</th>
                                    <th>Status</th>
                                    @foreach($bulanTampil as $b)
                                        <th style="text-align:center;min-width:34px;padding-left:4px;padding-right:4px;">{{ $bulanShort[$b] ?? $b }}</th>
                                    @endforeach
                                    <th style="text-align:center;">Terakhir</th>
                                    <th style="text-align:right;padding-right:24px;min-width:122px;">Coverage</th>
                                </tr>
                            </thead>
                            <tbody id="eq-tbody">
                                @foreach($eqRows as $r)
                                    <tr class="eq-row {{ $loop->index >= 20 ? 'hidden' : '' }}"
                                        data-tag="{{ strtolower($r['tag']) }}"
                                        data-tag-label="{{ $r['tag'] }}"
                                        data-pt="{{ $r['pt'] }}"
                                        data-status="{{ $r['status'] }}"
                                        data-status-label="{{ $r['status_label'] }}"
                                        data-issue="{{ $r['issue'] }}"
                                        data-issue-label="{{ $r['issue_label'] }}"
                                        data-last="{{ $r['last'] ?? '-' }}"
                                        data-url="{{ $r['url'] }}"
                                        data-pct="{{ $r['pct'] }}">
                                        <td style="padding-left:24px;">
                                            <a href="{{ $r['url'] }}" class="ap-tag ap-mono">{{ $r['tag'] }}</a>
                                        </td>
                                        <td><span class="ap-micro">{{ $r['pt'] }}</span></td>
                                        <td>
                                            @if($r['status'] === 'sudah')
                                                <span class="ap-badge"><span class="ap-dot ap-dot--ok"></span>Sudah</span>
                                            @elseif($r['status'] === 'free')
                                                <span class="ap-badge"><span class="ap-dot ap-dot--muted"></span>Stop</span>
                                            @else
                                                <span class="ap-badge"><span class="ap-dot ap-dot--warn"></span>Belum</span>
                                            @endif
                                        </td>
                                        @foreach($bulanTampil as $b)
                                            @php $st = $r['months'][$b] ?? 'belum'; @endphp
                                            <td style="text-align:center;padding-left:4px;padding-right:4px;">
                                                @if($st === 'sudah')
                                                    <span style="color:#248a3d;font-size:13px;" title="Sudah diambil">&#10003;</span>
                                                @elseif($st === 'free')
                                                    <span class="ap-dot ap-dot--muted" title="Stop / tidak running"></span>
                                                @else
                                                    <span style="color:#c7c7cc;" title="Belum diambil">&mdash;</span>
                                                @endif
                                            </td>
                                        @endforeach
                                        <td style="text-align:center;"><span class="ap-micro">{{ $r['last'] ?? '—' }}</span></td>
                                        <td style="text-align:right;padding-right:24px;">
                                            <div style="display:flex;align-items:center;justify-content:flex-end;gap:8px;">
                                                <span class="ap-micro" style="font-weight:600;color:{{ $r['pct'] < 50 ? '#d70015' : '#1d1d1f' }};">{{ $r['pct'] }}%</span>
                                                <span class="ap-micro">{{ $r['covered'] }}/{{ $r['total'] }}</span>
                                            </div>
                                            <div class="ap-bar" style="margin-top:6px;height:3px;">
                                                <span style="width:{{ $r['pct'] }}%;background:{{ $r['pct'] >= 80 ? '#30d158' : ($r['pct'] >= 50 ? '#ff9f0a' : '#ff453a') }};"></span>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                                <tr id="eq-empty" class="hidden">
                                    <td colspan="{{ count($bulanTampil) + 5 }}" class="ap-empty">
                                        Tidak ada equipment yang cocok dengan filter.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;padding:14px 24px;border-top:1px solid rgba(0,0,0,.06);">
                        <p id="table-count" class="ap-micro"></p>
                        <div class="ap-pagination">
                            <button type="button" id="prev-page" class="ap-page-btn">&lsaquo; Sebelumnya</button>
                            <span id="page-label" class="ap-page-label">1 / 1</span>
                            <button type="button" id="next-page" class="ap-page-btn">Berikutnya &rsaquo;</button>
                        </div>
                    </div>
                </div>
            </section>

            {{-- ============ MODAL: DAFTAR EQUIPMENT ============ --}}
            <div id="eq-modal" class="ap-modal" aria-hidden="true">
                <div class="ap-modal-scrim" data-close-modal></div>
                <div class="ap-modal-panel" role="dialog" aria-modal="true" aria-labelledby="eq-modal-title">
                    <div class="ap-modal-head">
                        <div>
                            <h3 id="eq-modal-title" class="ap-h3"></h3>
                            <p id="eq-modal-sub" class="ap-caption" style="margin-top:4px;"></p>
                        </div>
                        <button type="button" class="ap-modal-close" data-close-modal aria-label="Tutup">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                                <path d="M6 6l12 12M18 6L6 18"/>
                            </svg>
                        </button>
                    </div>
                    <div id="eq-modal-list" class="ap-modal-list"></div>
                    <div class="ap-modal-foot">
                        <span id="eq-modal-count" class="ap-micro"></span>
                        <button type="button" id="eq-modal-apply" class="ap-btn ap-btn--primary">
                            Tampilkan di tabel
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M5 12h14M13 6l6 6-6 6"/>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const rows         = Array.from(document.querySelectorAll('.eq-row'));
    const emptyRow     = document.getElementById('eq-empty');
    const searchInput  = document.getElementById('search-eq');
    const hideFull     = document.getElementById('hide-full');
    const countEl      = document.getElementById('table-count');
    const pageLabel    = document.getElementById('page-label');
    const prevBtn      = document.getElementById('prev-page');
    const nextBtn      = document.getElementById('next-page');
    const ptChips      = Array.from(document.querySelectorAll('.chip-pt'));
    const statusChips  = Array.from(document.querySelectorAll('.chip-status'));

    const BULAN         = @json($bulanNama . ' ' . $filterTahun);
    const BULAN_SEBELUM = @json($bulanSebelumLabel);

    // Hanya status BULAN ACUAN yang dipakai untuk filter & daftar equipment.
    const state = { pt: '', status: '', issueType: '', q: '', page: 1, size: 20, hideFull: false };

    function paintChips() {
        ptChips.forEach(function (b) {
            b.classList.toggle('is-active', (b.dataset.pt || '') === state.pt);
        });
        statusChips.forEach(function (b) {
            b.classList.toggle('is-active', (b.dataset.status || '') === state.status);
        });
    }

    function matches(row) {
        if (state.pt && row.dataset.pt !== state.pt) return false;
        if (state.status === 'issue') {
            if (!row.dataset.issue) return false;
            if (state.issueType && row.dataset.issue !== state.issueType) return false;
        } else if (state.status && row.dataset.status !== state.status) {
            return false;
        }
        if (state.hideFull && Number(row.dataset.pct) >= 100) return false;
        if (state.q && !(row.dataset.tag || '').includes(state.q)) return false;
        return true;
    }

    function render() {
        const list  = rows.filter(matches);
        const pages = Math.max(1, Math.ceil(list.length / state.size));
        if (state.page > pages) state.page = pages;

        const start = (state.page - 1) * state.size;
        const shown = list.slice(start, start + state.size);

        rows.forEach(function (r) { r.classList.add('hidden'); });
        shown.forEach(function (r) { r.classList.remove('hidden'); });
        if (emptyRow) emptyRow.classList.toggle('hidden', list.length > 0);

        if (countEl) {
            countEl.textContent = list.length
                ? 'Menampilkan ' + (start + 1) + '–' + (start + shown.length) + ' dari ' + list.length + ' equipment'
                : 'Tidak ada equipment yang cocok';
        }
        if (pageLabel) pageLabel.textContent = state.page + ' / ' + pages;
        if (prevBtn) prevBtn.disabled = state.page <= 1;
        if (nextBtn) nextBtn.disabled = state.page >= pages;
    }

    ptChips.forEach(function (b) {
        b.addEventListener('click', function () {
            state.pt = b.dataset.pt || '';
            state.page = 1;
            paintChips();
            render();
        });
    });
    statusChips.forEach(function (b) {
        b.addEventListener('click', function () {
            state.status = b.dataset.status || '';
            state.issueType = '';
            state.page = 1;
            paintChips();
            render();
        });
    });
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            state.q = searchInput.value.toLowerCase().trim();
            state.page = 1;
            render();
        });
    }
    if (hideFull) {
        hideFull.addEventListener('change', function () {
            state.hideFull = hideFull.checked;
            state.page = 1;
            render();
        });
    }
    if (prevBtn) {
        prevBtn.addEventListener('click', function () {
            if (state.page > 1) { state.page--; render(); }
        });
    }
    if (nextBtn) {
        nextBtn.addEventListener('click', function () {
            state.page++; render();
        });
    }

    // ---------------- Modal daftar equipment ----------------
    const modal      = document.getElementById('eq-modal');
    const modalTitle = document.getElementById('eq-modal-title');
    const modalSub   = document.getElementById('eq-modal-sub');
    const modalList  = document.getElementById('eq-modal-list');
    const modalCount = document.getElementById('eq-modal-count');
    const applyBtn   = document.getElementById('eq-modal-apply');

    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    const meta = {
        sudah: {
            title: 'Equipment sudah diambil data',
            sub: 'Data ' + BULAN + ' sudah masuk.',
            test: function (r) { return r.dataset.status === 'sudah'; }
        },
        belum: {
            title: 'Equipment belum diambil data',
            sub: 'Belum ada data untuk ' + BULAN + '. Tindak lanjuti pengambilan data.',
            test: function (r) { return r.dataset.status === 'belum'; }
        },
        free: {
            title: 'Equipment tidak running (stop)',
            sub: 'Berstatus stop pada ' + BULAN + '. Perlu di-arrange agar running pada bulan berikutnya.',
            test: function (r) { return r.dataset.status === 'free'; }
        },
        issue: {
            title: 'Perlu tindakan — 2 bulan berturut',
            sub: (BULAN_SEBELUM ? BULAN_SEBELUM + ' & ' : '') + BULAN + ': belum diambil / stop.',
            test: function (r) { return !!r.dataset.issue; }
        },
        belum2: {
            title: 'Belum diambil 2 bulan berturut',
            sub: (BULAN_SEBELUM ? BULAN_SEBELUM + ' & ' : '') + BULAN + ': tidak ada data sama sekali.',
            test: function (r) { return r.dataset.issue === 'belum2'; }
        },
        stop2: {
            title: 'Stop 2 bulan berturut',
            sub: (BULAN_SEBELUM ? BULAN_SEBELUM + ' & ' : '') + BULAN + ': equipment tidak running. Perlu di-arrange agar running.',
            test: function (r) { return r.dataset.issue === 'stop2'; }
        },
        campur: {
            title: 'Belum / stop 2 bulan berturut',
            sub: (BULAN_SEBELUM ? BULAN_SEBELUM + ' & ' : '') + BULAN + ': salah satu bulan belum ada data, bulan lain stop.',
            test: function (r) { return r.dataset.issue === 'campur'; }
        }
    };

    function badgeFor(row) {
        if (row.dataset.issue === 'stop2') return ['ap-dot--muted', 'stop 2 bln'];
        if (row.dataset.issue === 'campur') return ['ap-dot--bad', 'belum/stop'];
        if (row.dataset.issue === 'belum2') return ['ap-dot--warn', 'belum 2 bln'];
        if (row.dataset.status === 'sudah') return ['ap-dot--ok', 'Sudah'];
        if (row.dataset.status === 'free') return ['ap-dot--muted', 'Stop'];
        return ['ap-dot--warn', 'Belum'];
    }

    function openModal(type, pt) {
        const m = meta[type] || meta.belum;
        const list = rows.filter(function (r) {
            if (pt && r.dataset.pt !== pt) return false;
            return m.test(r);
        });

        if (modalTitle) modalTitle.textContent = m.title + (pt ? ' — ' + pt : '');
        if (modalSub) modalSub.textContent = m.sub + ' (hanya bulan acuan)';

        if (modalList) {
            if (!list.length) {
                modalList.innerHTML = '<p class="ap-empty">Tidak ada equipment pada kategori ini.</p>';
            } else {
                modalList.innerHTML = list.map(function (r) {
                    const badge = badgeFor(r);
                    return '<div class="ap-modal-row">' +
                        '<a href="' + esc(r.dataset.url) + '" class="ap-tag ap-mono">' + esc(r.dataset.tagLabel) + '</a>' +
                        '<span class="ap-micro">' + esc(r.dataset.pt) + '</span>' +
                        '<span class="ap-badge" style="margin-left:auto;"><span class="ap-dot ' + badge[0] + '"></span>' + badge[1] + '</span>' +
                        '<span class="ap-micro" style="width:112px;text-align:right;">terakhir: ' + esc(r.dataset.last) + '</span>' +
                        '</div>';
                }).join('');
            }
        }
        if (modalCount) modalCount.textContent = list.length + ' equipment';
        if (applyBtn) {
            applyBtn.dataset.type = type;
            applyBtn.dataset.pt = pt || '';
        }

        if (modal) {
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
        }
    }

    function closeModal() {
        if (!modal) return;
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    document.addEventListener('click', function (e) {
        const opener = e.target.closest('[data-open]');
        if (opener) {
            openModal(opener.dataset.open, opener.dataset.pt || '');
            return;
        }
        if (e.target.closest('[data-close-modal]')) closeModal();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeModal();
    });

    // Terapkan kategori modal sebagai filter tabel, lalu scroll ke tabel.
    if (applyBtn) {
        applyBtn.addEventListener('click', function () {
            const type = applyBtn.dataset.type || '';
            state.pt = applyBtn.dataset.pt || '';
            state.page = 1;
            state.status = (type === 'sudah' || type === 'belum' || type === 'free') ? type : 'issue';
            state.issueType = (type === 'belum2' || type === 'stop2' || type === 'campur') ? type : '';
            paintChips();
            render();
            closeModal();
            const table = document.getElementById('eq-table');
            if (table) table.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    }

    paintChips();
    render();
});
</script>
@endpush
