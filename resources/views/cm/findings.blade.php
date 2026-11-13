@extends('layouts.app')

@section('title', 'Finding CM — Condition Monitoring')
@section('breadcrumb')
    <a href="{{ route('dashboard') }}" class="hover:text-[#1d1d1f]">Dashboard</a>
    <span class="text-[#d2d2d7]">/</span>
    <a href="{{ route('cm.overview') }}" class="hover:text-[#1d1d1f]">Condition Monitoring</a>
    <span class="text-[#d2d2d7]">/</span>
    <span class="text-[#1d1d1f] font-medium">Finding CM</span>
@endsection

@section('content')
    @include('partials._apple-ui')

    <div class="ap">
        <div class="ap-wrap">
            @include('cm._tabs')

            <section class="ap-hero" style="margin-bottom:24px;">
                <p class="ap-eyebrow">Condition Monitoring</p>
                <h2 class="ap-display" style="margin-top:10px;">Temuan condition monitoring.</h2>
                <p class="ap-lede" style="margin-top:14px;">
                    Daftar finding terbuka beserta riwayat penyelesaiannya.
                </p>
            </section>

    {{-- Summary Cards --}}
    <div class="ap-grid-4" style="margin-bottom:24px;">
        <div class="ap-tile ap-tile--warn">
            <p class="ap-micro"><span class="ap-dot"></span> Finding Open</p>
            <p class="ap-num" style="margin-top:8px;">{{ $totalOpen }}</p>
        </div>
        <div class="ap-tile ap-tile--bad">
            <p class="ap-micro"><span class="ap-dot"></span> Open &gt; 7 Hari</p>
            <p class="ap-num" style="margin-top:8px;">{{ $openOver7 }}</p>
        </div>
        <div class="ap-tile ap-tile--ok">
            <p class="ap-micro"><span class="ap-dot"></span> Closed</p>
            <p class="ap-num" style="margin-top:8px;">{{ $totalClosed }}</p>
        </div>
        <div class="ap-tile ap-tile--neutral">
            <p class="ap-micro">Total Finding</p>
            <p class="ap-num" style="margin-top:8px;">{{ $totalFindings }}</p>
        </div>
    </div>











    {{-- Ringkasan beban kerja finding --}}
    <div class="ap-grid-2" style="margin-bottom:24px;">
        <div class="ap-card">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;">
                <div>
                    <h3 class="ap-h3">Beban open per PIC</h3>
                    <p class="ap-caption" style="margin-top:4px;">Klik PIC untuk menyaring daftar finding.</p>
                </div>
                <p class="ap-micro">{{ number_format($totalOpen) }} open</p>
            </div>
            <div style="margin-top:18px;display:flex;flex-direction:column;gap:14px;">
                @forelse($openByPic as $row)
                    @php
                        $isTanpaPic = $row->pic_label === 'Tanpa PIC';
                        $filterPicValue = $isTanpaPic ? '__none' : $row->pic_label;
                        $openCount = (int) $row->open_count;
                        $over7Count = (int) $row->over7_count;
                        $barWidth = $maxOpenPic > 0 ? round(($openCount / $maxOpenPic) * 100) : 0;
                    @endphp
                    <a href="{{ route('cm.findings', ['pic' => $filterPicValue]) }}" style="display:block;text-decoration:none;color:inherit;">
                        <div style="display:flex;align-items:baseline;justify-content:space-between;gap:12px;">
                            <span style="font-size:13px;font-weight:600;color:#1d1d1f;">{{ $row->pic_label }}</span>
                            <span class="ap-micro">
                                <strong style="color:#1d1d1f;">{{ $openCount }}</strong> open
                                @if($over7Count > 0)
                                    &middot; <span style="color:#cf3327;">{{ $over7Count }} &gt; 7 hari</span>
                                @endif
                            </span>
                        </div>
                        <div style="margin-top:6px;height:6px;border-radius:999px;background:#edeff2;overflow:hidden;">
                            <div style="height:100%;width:{{ $barWidth }}%;border-radius:999px;background:{{ $isTanpaPic ? '#86868b' : '#2f6fbf' }};"></div>
                        </div>
                    </a>
                @empty
                    <p class="ap-caption">Tidak ada finding open.</p>
                @endforelse
            </div>
        </div>

        <div class="ap-card">
            <h3 class="ap-h3">Fokus tindak lanjut</h3>
            <p class="ap-caption" style="margin-top:4px;">Prioritas PM engineer dari finding yang masih open.</p>
            <div class="ap-grid-3" style="margin-top:16px;">
                <a href="{{ route('cm.findings', ['pic' => '__none']) }}" class="ap-tile ap-tile--info" style="display:block;text-decoration:none;color:inherit;">
                    <p class="ap-micro"><span class="ap-dot"></span> Tanpa PIC</p>
                    <p class="ap-num" style="margin-top:8px;">{{ number_format($openTanpaPic) }}</p>
                    <p class="ap-micro" style="margin-top:4px;">Tentukan PIC</p>
                </a>
                <div class="ap-tile ap-tile--neutral">
                    <p class="ap-micro">Equipment terdampak</p>
                    <p class="ap-num" style="margin-top:8px;">{{ number_format($openEquipmentCount) }}</p>
                    <p class="ap-micro" style="margin-top:4px;">Punya finding open</p>
                </div>
                <div class="ap-tile ap-tile--bad">
                    <p class="ap-micro"><span class="ap-dot ap-dot--bad"></span> Open &gt; 30 hari</p>
                    <p class="ap-num" style="margin-top:8px;">{{ number_format($openAging['>30']) }}</p>
                    <p class="ap-micro" style="margin-top:4px;">Perlu eskalasi</p>
                </div>
            </div>

            <div style="margin-top:20px;">
                <div style="display:flex;align-items:baseline;justify-content:space-between;gap:12px;">
                    <p class="ap-micro" style="font-weight:600;color:#1d1d1f;">Umur open</p>
                    <p class="ap-micro">{{ number_format($totalOpen) }} finding</p>
                </div>
                @php
                    $agingMeta = [
                        '0-7'  => ['label' => '0-7 hari', 'color' => '#30d158'],
                        '8-30' => ['label' => '8-30 hari', 'color' => '#ff9f0a'],
                        '>30'  => ['label' => '> 30 hari', 'color' => '#ff453a'],
                    ];
                    $maxAging = max(1, max($openAging));
                @endphp
                <div style="margin-top:10px;display:flex;flex-direction:column;gap:8px;">
                    @foreach($agingMeta as $key => $meta)
                        <div style="display:flex;align-items:center;gap:10px;">
                            <span class="ap-micro" style="width:64px;flex-shrink:0;">{{ $meta['label'] }}</span>
                            <div style="flex:1;height:6px;border-radius:999px;background:#edeff2;overflow:hidden;">
                                <div style="height:100%;width:{{ round(($openAging[$key] / $maxAging) * 100) }}%;border-radius:999px;background:{{ $meta['color'] }};"></div>
                            </div>
                            <span class="ap-micro" style="width:34px;text-align:right;color:#1d1d1f;font-weight:600;">{{ $openAging[$key] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    {{-- Filters --}}
    <div class="ap-card p-4 mb-6">
        <form id="findingsFilterForm" class="ap-toolbar">
            <div class="ap-field">
                <label class="ap-field-label" for="findingsPt">PT</label>
                <select id="findingsPt" name="pt" class="findings-filter ap-select">
                    <option value="">Semua PT</option>
                    @foreach($ptList as $pt)
                        <option value="{{ $pt }}" {{ $filterPt === $pt ? 'selected' : '' }}>{{ $pt }}</option>
                    @endforeach
                </select>
            </div>
            <div class="ap-field">
                <label class="ap-field-label" for="findingsStatus">Status</label>
                <select id="findingsStatus" name="status" class="findings-filter ap-select">
                    <option value="">Semua Status</option>
                    <option value="open" {{ $filterStatus === 'open' ? 'selected' : '' }}>Open</option>
                    <option value="closed" {{ $filterStatus === 'closed' ? 'selected' : '' }}>Closed</option>
                </select>
            </div>
            <div class="ap-field">
                <label class="ap-field-label" for="findingsPic">PIC</label>
                <select id="findingsPic" name="pic" class="findings-filter ap-select">
                    <option value="">Semua PIC</option>
                    <option value="__none" {{ $filterPic === '__none' ? 'selected' : '' }}>Tanpa PIC</option>
                    @foreach($picList as $pic)
                        <option value="{{ $pic }}" {{ $filterPic === $pic ? 'selected' : '' }}>{{ $pic }}</option>
                    @endforeach
                </select>
            </div>
            <div class="ap-field ap-field--grow">
                <label class="ap-field-label" for="findingsSearch">Cari</label>
                <input type="text" name="search" id="findingsSearch" value="{{ $search }}"
                       placeholder="Cari tag equipment atau deskripsi..."
                       class="ap-input" style="width:100%;">
            </div>
            <button type="submit" class="ap-btn ap-btn--primary">
                Filter
            </button>
            <a href="{{ route('cm.findings') }}" class="ap-btn ap-btn--glass">
                Reset
            </a>
            <a href="{{ route('cm.export-findings', ['pt' => $filterPt, 'status' => $filterStatus, 'pic' => $filterPic]) }}"
               class="ap-btn ap-btn--glass">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                Export Excel
            </a>
        </form>
    </div>

    {{-- Findings List --}}
    <div id="findingsList" class="space-y-3">
        @include('cm._findings-list', ['findings' => $findings])
    </div>
        </div>
    </div>

    @push('scripts')
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var filterForm = document.getElementById('findingsFilterForm');
        var findingsList = document.getElementById('findingsList');
        var debounceTimer;

        /**
         * Ambil semua nilai filter dari form lalu fetch findings via AJAX.
         */
        function loadFindings() {
            var params = new URLSearchParams();
            if (filterForm) {
                var formData = new FormData(filterForm);
                formData.forEach(function(value, key) {
                    if (value) params.set(key, value);
                });
            }

            var url = '{{ route("cm.findings") }}' + '?' + params.toString();
            window.history.replaceState({}, '', url);

                    fetch(url + '&ajax=1')
                .then(function(r) { return r.text(); })
                .then(function(html) {
                    findingsList.innerHTML = html;
                    // Re-attach event listener ke link pagination yang baru
                    attachPaginationHandlers();
                })
                .catch(function(err) {
                    console.error('Error loading findings:', err);
                });
        }

        /**
         * Intercept klik pada link pagination di dalam findingsList
         * supaya pindah halaman via AJAX, bukan reload penuh.
         */
        function attachPaginationHandlers() {
            findingsList.querySelectorAll('a[href*="page="]').forEach(function(link) {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    var pageUrl = link.getAttribute('href');
                    if (!pageUrl) return;

                    window.history.replaceState({}, '', pageUrl);

                    var ajaxUrl = pageUrl + (pageUrl.indexOf('?') === -1 ? '?' : '&') + 'ajax=1';
                    fetch(ajaxUrl)
                        .then(function(r) { return r.text(); })
                        .then(function(html) {
                            findingsList.innerHTML = html;
                            attachPaginationHandlers();
                        })
                        .catch(function(err) {
                            console.error('Error loading findings page:', err);
                        });
                });
            });
        }

        // Dropdown PT dan Status — langsung filter saat berubah
        if (filterForm) {
            filterForm.querySelectorAll('.findings-filter').forEach(function(sel) {
                sel.addEventListener('change', function() {
                    loadFindings();
                });
            });

            // Submit form via AJAX
            filterForm.addEventListener('submit', function(e) {
                e.preventDefault();
                loadFindings();
            });
        }

        // Input search — debounce 400ms
        var searchInput = document.getElementById('findingsSearch');
        if (searchInput) {
            searchInput.addEventListener('keyup', function() {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(function() {
                    loadFindings();
                }, 400);
            });
        }

        // Pasang handler pagination saat pertama kali load
        attachPaginationHandlers();
    });
    </script>
    @endpush
@endsection
