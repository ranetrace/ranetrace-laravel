@php
    $buffers = $status['buffers']['features'] ?? [];
    $max = (int) ($status['buffers']['max_per_feature'] ?? 0);
    $total = (int) ($status['buffers']['total'] ?? 0);
    $lastBatch = $status['drain']['last_batch'] ?? [];
    $stalled = $status['drain']['stalled'] ?? [];
@endphp
<section class="rt-panel rt-panel--wide">
    <div class="rt-panel__head">
        <h2 class="rt-panel__title">Pipeline buffers</h2>
        <span class="rt-pill rt-pill--muted">{{ number_format($total) }} queued</span>
    </div>
    <div class="rt-panel__body">
        @forelse ($buffers as $feature => $count)
            @php
                $count = (int) $count;
                $pct = $max > 0 ? min(100, ($count / $max) * 100) : 0;
                $level = $pct >= 80 ? 'bad' : ($pct >= 50 ? 'warn' : 'ok');
                $bucket = (int) (round($pct / 5) * 5);
                $isStalled = in_array($feature, $stalled, true);
                $last = $lastBatch[$feature] ?? null;
            @endphp
            <div class="rt-buffer">
                <div class="rt-buffer__row">
                    <span class="rt-buffer__name">{{ $feature }}</span>
                    <span class="rt-buffer__count">{{ number_format($count) }} / {{ number_format($max) }} · {{ number_format($pct, 0) }}%</span>
                </div>
                <div class="rt-bar">
                    <div class="rt-bar__fill rt-bar__fill--{{ $level }} rt-w-{{ $bucket }}"></div>
                </div>
                <div class="rt-buffer__note {{ $isStalled ? 'rt-buffer__note--bad' : '' }}">
                    @if ($isStalled)
                        Drain stalled — buffered items aren't being sent.
                    @elseif ($last)
                        Last drained {{ \Illuminate\Support\Carbon::createFromTimestamp($last)->diffForHumans() }}.
                    @else
                        No successful drain recorded yet.
                    @endif
                </div>
            </div>
        @empty
            <div class="rt-empty">No buffer data available.</div>
        @endforelse

        @if (! empty($stalled))
            <p class="rt-hint">Stalled drains usually mean the worker isn't running. Ensure <code>ranetrace:work</code> is scheduled every minute.</p>
        @endif

        <div class="rt-footnote">
            Capacity {{ number_format($max) }} items/feature · buffer TTL {{ (int) config('ranetrace.batch.buffer_ttl', 3600) }}s · oldest items drop on overflow.
        </div>
    </div>
</section>
