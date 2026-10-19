{{-- =========================================================================
     Apple-style UI layer untuk halaman Condition Monitoring & Reliability.
     Semua aturan di-scope di dalam .ap supaya tidak bocor ke halaman lain.
     Referensi: apple.com — kanvas putih, tile abu #f5f5f7, tombol pill,
     aksen tunggal Apple Blue #0071e3, hairline tipis, shadow sangat hemat.
     ========================================================================= --}}
<style>
    .ap {
        --ap-bg: #ffffff;
        --ap-ink: #1d1d1f;
        --ap-ink-80: rgba(0, 0, 0, .8);
        --ap-ink-48: rgba(0, 0, 0, .48);
        --ap-line: rgba(0, 0, 0, .08);
        --ap-blue: #0071e3;
        --ap-link: #0066cc;
        --ap-bright: #2997ff;
        --ap-green: #30d158;
        --ap-amber: #ff9f0a;
        --ap-red: #ff453a;
        --ap-gray: #8e8e93;
        font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Display', 'SF Pro Text',
            'Helvetica Neue', 'Segoe UI', Roboto, Arial, sans-serif;
        color: var(--ap-ink);
        background: var(--ap-bg);
        margin: -24px;
        padding: 28px 24px 64px;
        letter-spacing: -0.374px;
        -webkit-font-smoothing: antialiased;
        min-height: calc(100vh - 64px);
    }

    .ap *,
    .ap *::before,
    .ap *::after { box-sizing: border-box; }

    .ap h1, .ap h2, .ap h3, .ap h4, .ap p { margin: 0; }
    .ap button { font-family: inherit; }
    .ap :focus-visible { outline: 2px solid var(--ap-blue); outline-offset: 2px; }

    .ap-wrap { max-width: 1180px; margin: 0 auto; }
    .ap-stack > * + * { margin-top: 24px; }

    /* ---------- Tipografi ---------- */
    .ap-eyebrow {
        font-size: 12px; line-height: 1.33; letter-spacing: -0.12px;
        color: var(--ap-ink-48); font-weight: 400;
    }
    .ap-display {
        font-size: clamp(30px, 4.2vw, 56px); line-height: 1.07; letter-spacing: -0.28px;
        font-weight: 600;
    }
    .ap-lede {
        font-size: clamp(17px, 1.5vw, 21px); line-height: 1.19; letter-spacing: 0.196px;
        font-weight: 400; color: var(--ap-ink-80); max-width: 680px;
    }
    .ap-h2 {
        font-size: clamp(22px, 2.4vw, 26px); line-height: 1.14; letter-spacing: 0.196px;
        font-weight: 600;
    }
    .ap-h3 { font-size: 21px; line-height: 1.19; letter-spacing: 0.231px; font-weight: 600; }
    .ap-caption { font-size: 14px; line-height: 1.29; letter-spacing: -0.224px; color: var(--ap-ink-48); }
    .ap-micro { font-size: 12px; line-height: 1.33; letter-spacing: -0.12px; color: var(--ap-ink-48); }
    .ap-micro .ap-dot { vertical-align: middle; margin-right: 2px; }
    .ap-num {
        font-size: 34px; line-height: 1.1; letter-spacing: -0.374px; font-weight: 600;
        font-variant-numeric: tabular-nums;
    }
    .ap-num-sm {
        font-size: 21px; line-height: 1.19; letter-spacing: 0.231px; font-weight: 600;
        font-variant-numeric: tabular-nums;
    }
    .ap-faint { color: var(--ap-ink-48); font-weight: 400; }
    .ap-mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; letter-spacing: 0; }
    .ap-link { color: var(--ap-link); text-decoration: none; }
    .ap-link:hover { text-decoration: underline; text-underline-offset: 2px; }
    .ap-nowrap { white-space: nowrap; }

    /* ---------- Tile hero (abu terang di atas kanvas putih, gaya apple.com) ---------- */
    .ap-hero {
        background: #f5f5f7; color: var(--ap-ink); border-radius: 18px;
        padding: clamp(28px, 4vw, 56px) clamp(20px, 4vw, 48px) clamp(24px, 3vw, 40px);
        overflow: hidden;
    }
    .ap-hero .ap-eyebrow { color: var(--ap-ink-48); }
    .ap-hero .ap-lede { color: var(--ap-ink-80); }
    .ap-hero-top {
        display: flex; flex-wrap: wrap; gap: 24px 40px;
        align-items: flex-end; justify-content: space-between;
    }
    .ap-hero-tools { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 10px; }
    .ap-hero-label { display: block; margin-bottom: 6px; font-size: 12px; letter-spacing: -0.12px; color: var(--ap-ink-48); }
    .ap-hero .ap-select, .ap-hero .ap-input { background: #fff; border-color: transparent; }

    .ap-stats {
        display: grid; grid-template-columns: repeat(3, minmax(0, 1fr));
        margin-top: clamp(28px, 4vw, 44px);
        border-top: 1px solid var(--ap-line);
    }
    .ap-stat {
        appearance: none; background: none; border: 0; color: inherit; text-align: left;
        padding: 22px 24px 4px 0; cursor: pointer; display: block; width: 100%;
    }
    .ap-stat + .ap-stat { border-left: 1px solid var(--ap-line); padding-left: 28px; }
    .ap-stat-num {
        display: block; font-size: clamp(28px, 3.4vw, 44px); line-height: 1.05;
        letter-spacing: -0.374px; font-weight: 600; font-variant-numeric: tabular-nums;
        transition: color .2s ease;
    }
    .ap-stat-num small { font-size: 17px; font-weight: 400; color: var(--ap-ink-48); letter-spacing: -0.374px; }
    .ap-stat-label {
        display: block; margin-top: 8px; font-size: 14px; line-height: 1.29;
        letter-spacing: -0.224px; color: var(--ap-ink-48); transition: color .2s ease;
    }
    .ap-stat:hover .ap-stat-num { color: var(--ap-blue); }
    .ap-stat:hover .ap-stat-label { color: var(--ap-ink); }
    .ap-stat--static { cursor: default; }
    .ap-stat--static:hover .ap-stat-num { color: inherit; }
    .ap-stat--static:hover .ap-stat-label { color: var(--ap-ink-48); }
    .ap-stats--4 { grid-template-columns: repeat(4, minmax(0, 1fr)); }
    .ap-stat-num--sm { font-size: clamp(22px, 2.4vw, 32px); }

    .ap-hero-bar {
        display: flex; height: 6px; width: 100%; margin-top: 28px;
        border-radius: 980px; overflow: hidden; background: #d2d2d7;
    }
    .ap-hero-bar span { display: block; height: 100%; }
    .ap-hero-legend {
        display: flex; flex-wrap: wrap; align-items: center; gap: 8px 20px;
        margin-top: 12px; font-size: 12px; letter-spacing: -0.12px; color: var(--ap-ink-48);
    }
    .ap-hero-legend .ap-hero-pct { margin-left: auto; color: var(--ap-ink); font-weight: 600; }
    .ap-legend-item { display: inline-flex; align-items: center; gap: 7px; }

    .ap-alert {
        display: flex; flex-wrap: wrap; align-items: center; gap: 10px 14px;
        width: 100%; margin-top: 24px; padding: 14px 18px; border: 0; border-radius: 8px;
        background: #fff; color: var(--ap-ink); text-align: left; cursor: pointer;
        font-size: 14px; line-height: 1.29; letter-spacing: -0.224px;
        box-shadow: inset 0 0 0 1px rgba(0, 0, 0, .04);
        transition: background .2s ease;
    }
    .ap-alert:hover { background: #fafafc; }
    .ap-alert strong { font-weight: 600; }
    .ap-alert-cta { margin-left: auto; color: var(--ap-blue); font-weight: 600; white-space: nowrap; }

    /* ---------- Kartu & grid ---------- */
    .ap-section { margin-top: clamp(28px, 4vw, 48px); }
    .ap-section-head {
        display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between;
        gap: 8px 24px; margin-bottom: 16px;
    }
    .ap-card {
        background: #fff; border-radius: 18px; padding: 24px;
        border: 0; box-shadow: inset 0 0 0 1px rgba(0, 0, 0, .06);
    }
    .ap-card--flush { padding: 0; overflow: hidden; }
    .ap-tile {
        background: #f5f5f7; border-radius: 18px; padding: 20px; border: 0;
    }
    .ap-grid-2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
    .ap-grid-3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; }
    .ap-grid-5 { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 16px; }

    /* ---------- Bar komposisi ---------- */
    .ap-bar {
        display: flex; height: 4px; width: 100%; overflow: hidden;
        border-radius: 980px; background: #d2d2d7;
    }
    .ap-bar span { display: block; height: 100%; }
    .ap-fill-ok { background: var(--ap-green); }
    .ap-fill-stop { background: var(--ap-gray); }
    .ap-fill-warn { background: var(--ap-amber); }

    /* ---------- Kontrol ---------- */
    .ap-btn {
        display: inline-flex; align-items: center; justify-content: center; gap: 7px;
        border-radius: 980px; padding: 8px 18px; border: 1px solid transparent;
        font-size: 14px; line-height: 1.43; letter-spacing: -0.224px;
        text-decoration: none; cursor: pointer; transition: background .2s ease, color .2s ease;
        white-space: nowrap;
    }
    .ap-btn--primary { background: var(--ap-blue); color: #fff; }
    .ap-btn--primary:hover { background: #0077ed; }
    .ap-btn--glass {
        background: #f5f5f7; color: var(--ap-ink);
    }
    .ap-btn--glass:hover { background: #e8e8ed; }
    .ap-hero .ap-btn--glass {
        background: #fff; box-shadow: inset 0 0 0 1px rgba(0, 0, 0, .10);
    }
    .ap-hero .ap-btn--glass:hover { background: #fafafc; }
    .ap-btn--pill {
        background: transparent; color: var(--ap-link); border-color: rgba(0, 102, 204, .45);
        border-radius: 980px;
    }
    .ap-btn--pill:hover { background: rgba(0, 113, 227, .06); text-decoration: underline; }
    .ap-btn--quiet {
        background: #f5f5f7; color: var(--ap-ink-80);
    }
    .ap-btn--quiet:hover { background: #e8e8ed; }
    .ap-btn:disabled { opacity: .4; cursor: not-allowed; }

    .ap-select, .ap-input {
        appearance: none; -webkit-appearance: none; font-family: inherit;
        background: #fff; color: var(--ap-ink-80);
        border: 1px solid rgba(0, 0, 0, .10); border-radius: 980px;
        padding: 8px 18px; font-size: 14px; line-height: 1.43; letter-spacing: -0.224px;
        min-height: 38px;
    }
    .ap-select {
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' fill='none' stroke='%236e6e73' stroke-width='1.6' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
        background-repeat: no-repeat; background-position: right 16px center;
        padding-right: 36px;
    }
    .ap-field { display: block; }
    .ap-field--grow { flex: 1 1 240px; min-width: 200px; }
    .ap-field-label { display: block; margin-bottom: 6px; font-size: 12px; letter-spacing: -0.12px; color: var(--ap-ink-48); }
    .ap-search { position: relative; }
    .ap-search svg {
        position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
        width: 15px; height: 15px; color: var(--ap-gray); pointer-events: none;
    }
    .ap-search input { padding-left: 34px; width: 240px; max-width: 100%; }
    .ap-toolbar { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12px; }
    .ap-toolbar .ap-btn { min-height: 38px; }

    /* ---------- Chip / segmented control ---------- */
    .ap-chips {
        display: inline-flex; flex-wrap: wrap; gap: 2px; padding: 3px;
        border-radius: 980px; background: #e8e8ed;
    }
    .ap-chip {
        appearance: none; border: 0; cursor: pointer; white-space: nowrap;
        display: inline-flex; align-items: center; gap: 6px;
        border-radius: 980px; padding: 6px 13px; background: transparent;
        color: var(--ap-ink-80); font-size: 12px; line-height: 1.33; letter-spacing: -0.12px;
        font-weight: 400; transition: background .15s ease, color .15s ease;
    }
    .ap-chip:hover { background: rgba(255, 255, 255, .7); }
    .ap-chip.is-active { background: #fff; color: var(--ap-ink); font-weight: 600; }
    .ap-chip--soft { background: #f5f5f7; }
    .ap-chip--soft:hover { background: #e8e8ed; }
    .ap-chip small { color: var(--ap-ink-48); font-weight: 400; }

    .ap-check { display: inline-flex; align-items: center; gap: 7px; font-size: 12px; color: var(--ap-ink-48); cursor: pointer; }
    .ap-check input { accent-color: var(--ap-blue); width: 14px; height: 14px; }

    /* ---------- Tabel ---------- */
    .ap-table-wrap { overflow-x: auto; }
    .ap-table { width: 100%; border-collapse: collapse; font-size: 14px; letter-spacing: -0.224px; }
    .ap-table th {
        text-align: left; font-weight: 400; font-size: 12px; letter-spacing: -0.12px;
        color: var(--ap-ink-48); padding: 11px 12px; white-space: nowrap;
        border-bottom: 1px solid var(--ap-line); text-transform: none;
    }
    .ap-table td {
        padding: 12px; color: var(--ap-ink-80); vertical-align: middle;
        border-bottom: 1px solid rgba(0, 0, 0, .05);
    }
    .ap-table tbody tr:last-child td { border-bottom: 0; }
    .ap-table tbody tr:hover td { background: #fafafc; }
    .ap-table .ap-tag { font-weight: 500; color: var(--ap-ink); text-decoration: none; }
    .ap-table .ap-tag:hover { color: var(--ap-link); text-decoration: underline; }
    .ap-empty { padding: 40px 16px; text-align: center; color: var(--ap-ink-48); font-size: 14px; }

    .ap-dot {
        width: 8px; height: 8px; border-radius: 50%; display: inline-block;
        flex: 0 0 auto;
    }
    .ap-dot--ok { background: var(--ap-green); }
    .ap-dot--warn { background: var(--ap-amber); }
    .ap-dot--bad { background: var(--ap-red); }
    .ap-dot--muted { background: var(--ap-gray); }

    .ap-badge {
        display: inline-flex; align-items: center; gap: 6px; white-space: nowrap;
        font-size: 12px; line-height: 1.33; letter-spacing: -0.12px; color: var(--ap-ink-80);
    }
    .ap-badge--muted { color: var(--ap-ink-48); }

    /* ---------- Tabs ---------- */
    .ap-tabs { margin-bottom: 20px; }
    .ap-tabs-nav {
        display: inline-flex; max-width: 100%; gap: 2px; padding: 3px; overflow-x: auto;
        border-radius: 980px; background: #e8e8ed; scrollbar-width: none;
    }
    .ap-tabs-nav::-webkit-scrollbar { display: none; }
    .ap-tab {
        flex: 0 0 auto; white-space: nowrap; text-decoration: none;
        border-radius: 980px; padding: 7px 15px; color: var(--ap-ink-80);
        font-size: 14px; line-height: 1.43; letter-spacing: -0.224px;
        transition: background .15s ease, color .15s ease;
    }
    .ap-tab:hover { background: rgba(255, 255, 255, .7); color: var(--ap-ink); }
    .ap-tab.is-active { background: #fff; color: var(--ap-ink); font-weight: 600; }

    /* ---------- Modal ---------- */
    .ap-modal {
        position: fixed; inset: 0; z-index: 60; display: none;
        align-items: center; justify-content: center; padding: 16px;
    }
    .ap-modal.is-open { display: flex; }
    .ap-modal-scrim {
        position: absolute; inset: 0; background: rgba(0, 0, 0, .48);
        -webkit-backdrop-filter: saturate(180%) blur(20px);
        backdrop-filter: saturate(180%) blur(20px);
    }
    .ap-modal-panel {
        position: relative; display: flex; flex-direction: column;
        width: 100%; max-width: 660px; max-height: 85vh; overflow: hidden;
        background: #fff; border-radius: 12px; box-shadow: rgba(0, 0, 0, .22) 3px 5px 30px 0;
    }
    .ap-modal-head {
        display: flex; align-items: flex-start; justify-content: space-between; gap: 16px;
        padding: 20px 24px; border-bottom: 1px solid var(--ap-line);
    }
    .ap-modal-close {
        appearance: none; border: 0; background: transparent; color: var(--ap-ink-48);
        width: 28px; height: 28px; border-radius: 50%; cursor: pointer;
        display: inline-flex; align-items: center; justify-content: center;
    }
    .ap-modal-close:hover { background: #f5f5f7; color: var(--ap-ink); }
    .ap-modal-list { flex: 1 1 auto; overflow-y: auto; }
    .ap-modal-row {
        display: flex; flex-wrap: wrap; align-items: center; gap: 8px 10px;
        padding: 11px 24px; border-bottom: 1px solid rgba(0, 0, 0, .05);
    }
    .ap-modal-row:last-child { border-bottom: 0; }
    .ap-modal-row:hover { background: #fafafc; }
    .ap-modal-foot {
        display: flex; align-items: center; justify-content: space-between; gap: 16px;
        padding: 14px 24px; border-top: 1px solid var(--ap-line); background: #fafafc;
    }

    /* ---------- Pagination ---------- */
    .ap-pagination { display: flex; align-items: center; gap: 4px; }
    .ap-page-btn {
        appearance: none; background: #fff; border: 0; cursor: pointer;
        box-shadow: inset 0 0 0 1px rgba(0, 0, 0, .08);
        border-radius: 8px; padding: 6px 12px; font-size: 12px; letter-spacing: -0.12px;
        color: var(--ap-ink-80); transition: background .15s ease;
    }
    .ap-page-btn:hover { background: #f5f5f7; }
    .ap-page-btn:disabled { opacity: .35; cursor: not-allowed; }
    .ap-page-label { padding: 0 8px; font-size: 12px; color: var(--ap-ink-48); font-variant-numeric: tabular-nums; }

    /* ---------- Detail kecil ---------- */
    .ap-pt-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
    .ap-pt-pct { font-size: 21px; letter-spacing: 0.231px; font-weight: 600; font-variant-numeric: tabular-nums; }
    .ap-pt-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 2px; margin-top: 18px; border-radius: 8px; background: #f5f5f7; overflow: hidden; }
    .ap-pt-cell {
        appearance: none; border: 0; background: transparent; cursor: pointer;
        padding: 12px 8px; text-align: left; transition: background .15s ease;
    }
    .ap-pt-cell:hover { background: #e8e8ed; }
    .ap-pt-cell .ap-caption { display: block; margin-top: 2px; }
    .ap-pt-foot { margin-top: 16px; display: flex; flex-wrap: wrap; gap: 6px; }

    .ap-kpi { display: flex; flex-direction: column; gap: 6px; }
    .ap-kpi-label { font-size: 12px; letter-spacing: -0.12px; color: var(--ap-ink-48); }
    .ap-kpi-value { font-size: 34px; line-height: 1.05; letter-spacing: -0.374px; font-weight: 600; font-variant-numeric: tabular-nums; }
    .ap-kpi-value small { font-size: 14px; font-weight: 400; color: var(--ap-ink-48); letter-spacing: -0.224px; }
    .ap-kpi-foot { font-size: 12px; letter-spacing: -0.12px; color: var(--ap-ink-48); }

    .ap-hr { height: 1px; background: var(--ap-line); border: 0; margin: 20px 0; }
    .ap-chart { position: relative; height: 280px; }

    @media (max-width: 1024px) {
        .ap-grid-5 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .ap-grid-3 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .ap-stats--4 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 820px) {
        .ap { margin: -24px; padding: 20px 16px 48px; }
        .ap-stats { grid-template-columns: 1fr; }
        .ap-stat { padding: 18px 0; }
        .ap-stat + .ap-stat {
            border-left: 0; border-top: 1px solid var(--ap-line);
            padding-left: 0;
        }
        .ap-grid-2, .ap-grid-3 { grid-template-columns: 1fr; }
        .ap-grid-5 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .ap-hero-tools { width: 100%; }
        .ap-search input { width: 100%; }
        .ap-field--grow { flex-basis: 100%; }
    }
</style>
