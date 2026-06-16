@php($surfaces = $surfaces ?? [])
<section class="rt-panel">
    <div class="rt-panel__head">
        <h2 class="rt-panel__title">Registered surfaces</h2>
    </div>
    <div class="rt-panel__body">
        @forelse ($surfaces as $surface)
            <div class="rt-kv">
                <span class="rt-kv__key">
                    {{ $surface['label'] }}
                    @if (! empty($surface['note']))
                        <span class="rt-surface__note">{{ $surface['note'] }}</span>
                    @endif
                </span>
                <span class="rt-kv__val">
                    <span class="rt-pill rt-pill--{{ $surface['ok'] ? 'ok' : 'muted' }}">{{ $surface['ok'] ? 'Active' : 'Off' }}</span>
                </span>
            </div>
        @empty
            <div class="rt-empty">No registration data available.</div>
        @endforelse
    </div>
</section>
