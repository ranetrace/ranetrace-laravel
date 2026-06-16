@php
    $logs = $logs ?? [];
    $severe = ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];
@endphp
<section class="rt-panel rt-panel--wide">
    <div class="rt-panel__head">
        <h2 class="rt-panel__title">Internal log — warnings &amp; errors</h2>
        <span class="rt-pill rt-pill--muted">{{ count($logs) }} recent</span>
    </div>
    <div class="rt-panel__body">
        @forelse (array_reverse($logs) as $entry)
            <div class="rt-log">
                <span class="rt-pill rt-pill--{{ in_array($entry['level'], $severe, true) ? 'bad' : 'warn' }}">{{ $entry['level'] }}</span>
                <span class="rt-log__msg">{{ $entry['message'] }}</span>
                <span class="rt-log__time">{{ $entry['time'] }}</span>
            </div>
        @empty
            <div class="rt-empty">No recent warnings or errors — or the internal log file isn’t present yet.</div>
        @endforelse
    </div>
</section>
