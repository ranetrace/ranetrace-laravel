@php($env = $environment ?? [])
<section class="rt-panel">
    <div class="rt-panel__head">
        <h2 class="rt-panel__title">Environment</h2>
    </div>
    <div class="rt-panel__body">
        <div class="rt-kv">
            <span class="rt-kv__key">App environment</span>
            <span class="rt-kv__val rt-kv__val--mono">{{ $env['env'] ?? '—' }}</span>
        </div>
        <div class="rt-kv">
            <span class="rt-kv__key">Package</span>
            <span class="rt-kv__val rt-kv__val--mono">{{ $env['package'] ?? 'dev' }}</span>
        </div>
        <div class="rt-kv">
            <span class="rt-kv__key">Laravel</span>
            <span class="rt-kv__val rt-kv__val--mono">{{ $env['laravel'] ?? '—' }}</span>
        </div>
        <div class="rt-kv">
            <span class="rt-kv__key">PHP</span>
            <span class="rt-kv__val rt-kv__val--mono">{{ $env['php'] ?? '—' }}</span>
        </div>
        <div class="rt-kv">
            <span class="rt-kv__key">Queue connection</span>
            <span class="rt-kv__val rt-kv__val--mono">{{ $env['queue'] ?? '—' }}</span>
        </div>
        <div class="rt-kv">
            <span class="rt-kv__key">Cache store</span>
            <span class="rt-kv__val rt-kv__val--mono">{{ $env['cache'] ?? '—' }}</span>
        </div>
    </div>
</section>
