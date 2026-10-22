{{-- Tab navigasi Condition Monitoring — segmented control bergaya Apple --}}
<div class="ap-tabs">
    <nav class="ap-tabs-nav" aria-label="Navigasi Condition Monitoring">
        <a href="{{ route('cm.overview') }}"
           class="ap-tab {{ request()->routeIs('cm.overview') ? 'is-active' : '' }}">Overview</a>
        <a href="{{ route('cm.equipment-status') }}"
           class="ap-tab {{ request()->routeIs('cm.equipment-status') ? 'is-active' : '' }}">Equipment Status</a>
        <a href="{{ route('cm.report-analysis') }}"
           class="ap-tab {{ request()->routeIs('cm.report-analysis') ? 'is-active' : '' }}">Report &amp; Analysis</a>
        <a href="{{ route('cm.findings') }}"
           class="ap-tab {{ request()->routeIs('cm.findings') ? 'is-active' : '' }}">Finding CM</a>
        <a href="{{ route('cm.monitoring') }}"
           class="ap-tab {{ request()->routeIs('cm.monitoring') ? 'is-active' : '' }}">CM Monitoring</a>
    </nav>
</div>