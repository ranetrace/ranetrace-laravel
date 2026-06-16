@php
    $failed = (int) ($status['failed_jobs_last_24h'] ?? 0);
    $level = $failed === 0 ? 'ok' : ($failed < 10 ? 'warn' : 'bad');
@endphp
<section class="rt-panel">
    <div class="rt-panel__head">
        <h2 class="rt-panel__title">Failed jobs (24h)</h2>
    </div>
    <div class="rt-panel__body">
        <div class="rt-stat">
            <span class="rt-stat__num rt-stat__num--{{ $level }}">{{ number_format($failed) }}</span>
            <span class="rt-stat__label">
                @if ($failed === 0)
                    No Ranetrace jobs failed in the last 24h.
                @elseif ($failed < 10)
                    Review with <code>php artisan queue:failed</code>.
                @else
                    High failure rate — check the internal log and the failed_jobs table.
                @endif
            </span>
        </div>
        <p class="rt-hint">Detected via the failed_jobs payload matching “Ranetrace”; a custom failed-jobs table or connection isn’t counted.</p>
    </div>
</section>
