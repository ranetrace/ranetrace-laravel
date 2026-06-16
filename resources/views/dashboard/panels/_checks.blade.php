@php
    $checks = $checks ?? [];
    $levelClass = ['fail' => 'bad', 'warn' => 'warn', 'pass' => 'ok'];
    $fails = 0;
    $warns = 0;
    foreach ($checks as $check) {
        $fails += $check->level->value === 'fail' ? 1 : 0;
        $warns += $check->level->value === 'warn' ? 1 : 0;
    }
    $summaryLevel = $fails > 0 ? 'bad' : ($warns > 0 ? 'warn' : 'ok');
    $summaryText = $fails > 0
        ? "{$fails} failing"
        : ($warns > 0 ? $warns.' '.\Illuminate\Support\Str::plural('warning', $warns) : 'All clear');
@endphp
<section class="rt-panel rt-panel--wide">
    <div class="rt-panel__head">
        <h2 class="rt-panel__title">Checks</h2>
        <span class="rt-pill rt-pill--{{ $summaryLevel }}">{{ $summaryText }}</span>
    </div>
    <div class="rt-panel__body">
        @forelse ($checks as $check)
            <div class="rt-check">
                <span class="rt-pill rt-pill--{{ $levelClass[$check->level->value] ?? 'muted' }}">{{ ucfirst($check->level->value) }}</span>
                <div class="rt-check__text">
                    <div class="rt-check__title">{{ $check->title }}</div>
                    @if ($check->remediation !== '')
                        <div class="rt-check__fix">{{ $check->remediation }}</div>
                    @endif
                </div>
            </div>
        @empty
            <div class="rt-empty">No checks available.</div>
        @endforelse
    </div>
</section>
