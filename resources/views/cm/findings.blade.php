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
            <a href="{{ route('cm.export-findings', ['pt' => $filterPt, 'status' => $filterStatus]) }}"
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
