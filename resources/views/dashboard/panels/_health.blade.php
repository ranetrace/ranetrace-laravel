@php
    $healthy = $status['healthy'] ?? false;
    $checkedAt = $status['timestamp'] ?? null;
    $refresh = (int) ($refresh ?? 0);
    $env = $environment ?? [];
@endphp
<header class="rt-header">
    <div>
        <h1 class="rt-header__title">
            Ranetrace
            <span class="rt-badge rt-badge--{{ $healthy ? 'ok' : 'bad' }}">
                {{ $healthy ? 'Healthy' : 'Issues detected' }}
            </span>
        </h1>
        <div class="rt-header__meta">
            <span>Environment <b>{{ $env['env'] ?? 'unknown' }}</b></span>
            @if (! empty($env['package']))
                <span>Package <b>{{ $env['package'] }}</b></span>
            @endif
            @if ($checkedAt)
                <span>Checked <b>{{ \Illuminate\Support\Carbon::parse($checkedAt)->diffForHumans() }}</b></span>
            @endif
        </div>
    </div>
    <div class="rt-header__actions">
        @if (! empty($hostedUrl))
            <a class="rt-btn" href="{{ $hostedUrl }}" target="_blank" rel="noopener noreferrer">View captured data ↗</a>
        @endif
        <div class="rt-refresh">
            {{ $refresh > 0 ? "Auto-refresh every {$refresh}s" : 'Auto-refresh off' }}
        </div>
    </div>
</header>
