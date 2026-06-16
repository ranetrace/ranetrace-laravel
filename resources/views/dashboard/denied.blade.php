<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Ranetrace — Access denied</title>
    <link rel="stylesheet" href="{{ route('ranetrace.assets.css', ['v' => $assetVersion]) }}">
</head>
<body>
    <main class="rt-container rt-denied">
        <section class="rt-panel">
            <div class="rt-panel__head">
                <h1 class="rt-panel__title">Ranetrace dashboard — access denied</h1>
            </div>
            <div class="rt-panel__body">
                <p>This dashboard is locked down outside the <code class="rt-code">local</code> environment until you grant access — the same model as Laravel Horizon, Pulse, and Telescope.</p>
                <p>To allow access, define the <code class="rt-code">viewRanetrace</code> gate in <code class="rt-code">app/Providers/AppServiceProvider.php</code>, inside <code class="rt-code">boot()</code>:</p>
                @verbatim
                <pre class="rt-code-block"><code>use Illuminate\Support\Facades\Gate;

Gate::define('viewRanetrace', function ($user) {
    return in_array($user->email, [
        'admin@example.com',
    ]);
});</code></pre>
                @endverbatim
                <p class="rt-hint">The closure receives the authenticated user (or <code class="rt-code">null</code> for a guest). Return any truthy value to allow, falsy to deny.</p>
            </div>
        </section>
    </main>
</body>
</html>
