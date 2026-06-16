{{--
    The refreshable fragment: every panel that reflects live state. Rendered
    both inside the shell (index.blade.php) and standalone by the panels
    endpoint (Phase 5), so the poller can swap #rt-panels without a JSON rebuild.
    Receives the full DashboardData::collect() payload + view chrome via @include.
    Wide panels span the full grid via the rt-panel--wide modifier.
--}}
@include('ranetrace::dashboard.panels._health')

<div class="rt-grid">
    @include('ranetrace::dashboard.panels._checks')
    @include('ranetrace::dashboard.panels._config')
    @include('ranetrace::dashboard.panels._pauses')
    @include('ranetrace::dashboard.panels._failed_jobs')
    @include('ranetrace::dashboard.panels._surfaces')
    @include('ranetrace::dashboard.panels._environment')
    @include('ranetrace::dashboard.panels._buffers')
    @include('ranetrace::dashboard.panels._log')
</div>
