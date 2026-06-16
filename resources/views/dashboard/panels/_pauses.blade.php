@php
    $reasons = [
        '401' => 'Invalid or revoked API key',
        '403' => 'Subscription or permission issue',
        '413' => 'Payload too large — client bug, investigate',
        '422' => 'Validation failed — schema drift',
        '429' => 'Rate limited — auto-resumes',
        '500' => 'Ranetrace backend error',
    ];
    $global = $status['pauses']['global'] ?? null;
    $featurePauses = array_filter($status['pauses']['features'] ?? [], fn ($p): bool => $p !== null);

    $describe = function (?array $pause) use ($reasons): array {
        $reason = (string) ($pause['reason'] ?? '');
        $secs = (int) ($pause['time_remaining_seconds'] ?? 0);
        $remaining = $secs <= 0 ? 'expired' : ($secs >= 60 ? floor($secs / 60).'m '.($secs % 60).'s' : $secs.'s');

        return [
            'label' => $reasons[$reason] ?? ($reason !== '' ? "Reason {$reason}" : 'Paused'),
            'remaining' => $remaining,
        ];
    };
@endphp
<section class="rt-panel">
    <div class="rt-panel__head">
        <h2 class="rt-panel__title">Pauses</h2>
    </div>
    <div class="rt-panel__body">
        @if (! $global && empty($featurePauses))
            <div class="rt-kv">
                <span class="rt-kv__key">Status</span>
                <span class="rt-kv__val"><span class="rt-pill rt-pill--ok">No active pauses</span></span>
            </div>
        @else
            @if ($global)
                @php($info = $describe($global))
                <div class="rt-kv">
                    <span class="rt-kv__key">
                        <span class="rt-pill rt-pill--bad">Global</span>
                        {{ $info['label'] }}
                    </span>
                    <span class="rt-kv__val">{{ $info['remaining'] }}</span>
                </div>
            @endif

            @foreach ($featurePauses as $feature => $pause)
                @php($info = $describe($pause))
                <div class="rt-kv">
                    <span class="rt-kv__key">
                        <span class="rt-pill rt-pill--{{ ($pause['paused'] ?? false) ? 'bad' : 'warn' }}">{{ $feature }}</span>
                        {{ $info['label'] }}
                    </span>
                    <span class="rt-kv__val">{{ $info['remaining'] }}</span>
                </div>
            @endforeach

            <p class="rt-hint">Clear manually with <code>php artisan ranetrace:pause-clear</code>.</p>
        @endif
    </div>
</section>
