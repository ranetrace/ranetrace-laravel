<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Vite;

beforeEach(function (): void {
    config([
        'ranetrace.javascript_errors.enabled' => true,
    ]);
});

/**
 * The runtime config object the rendered snippet carries.
 *
 * @return array<string, mixed>
 */
function renderedTrackerConfig(string $html): array
{
    preg_match('/const config = (\{.*\});/', $html, $matches);

    return json_decode($matches[1] ?? '', true, 512, JSON_THROW_ON_ERROR);
}

test('error tracker view renders to valid output', function (): void {
    // Regression guard: `view(...)` alone does not compile the Blade template;
    // only render() does. A directive/PHP syntax error in the view (e.g. the
    // un-compiled `<script@if` nonce conditional) surfaces here as a
    // ViewException and would 500 every host page using the snippet.
    $html = view('ranetrace::error-tracker')->render();

    expect($html)
        ->toContain('Ranetrace JavaScript Error Tracking')
        ->toContain("window.addEventListener('error'")
        ->toContain("window.addEventListener('unhandledrejection'")
        ->not->toContain('@if')
        ->not->toContain('@endif');
});

/**
 * The script itself lives once, in `ranetrace/ranetrace-php`. This view is a
 * wrapper: if its logic ever comes back inline, the two copies drift again and
 * the relays start validating different payloads. The source file, not the
 * rendered output, is what this asserts on.
 */
test('the view carries no copy of the capture script', function (string $marker): void {
    $source = (string) file_get_contents(__DIR__.'/../../resources/views/error-tracker.blade.php');

    expect($source)->not->toContain($marker);
})->with([
    'addEventListener(',
    'KEEPALIVE_SIZE_LIMIT',
    'function sendError(',
    'window.Ranetrace',
]);

test('the view pulls the script from the shared php sdk', function (): void {
    $source = (string) file_get_contents(__DIR__.'/../../resources/views/error-tracker.blade.php');

    expect($source)->toContain('\Ranetrace\Php\JavaScript\CaptureScript::withConfig(');
});

test('the config block carries the laravel route and configured values', function (): void {
    config([
        'ranetrace.javascript_errors.sample_rate' => 0.25,
        'ranetrace.javascript_errors.capture_console_errors' => true,
        'ranetrace.javascript_errors.max_breadcrumbs' => 5,
        'ranetrace.javascript_errors.ignored_errors' => ['Only this one'],
    ]);

    expect(renderedTrackerConfig(view('ranetrace::error-tracker')->render()))
        ->toMatchArray([
            'endpoint' => route('ranetrace.javascript-errors.store'),
            'enabled' => true,
            'sampleRate' => 0.25,
            'captureConsoleErrors' => true,
            'maxBreadcrumbs' => 5,
            'ignoredErrors' => ['Only this one'],
        ]);
});

test('the config block defaults to the controller ignore list, so script and relay filter alike', function (): void {
    $config = renderedTrackerConfig(view('ranetrace::error-tracker')->render());

    expect($config['ignoredErrors'])
        ->toBe(Ranetrace\Laravel\Http\Controllers\JavaScriptErrorController::DEFAULT_IGNORED_ERRORS)
        ->and($config['sampleRate'])->toBe(1.0)
        ->and($config['captureConsoleErrors'])->toBeFalse()
        ->and($config['maxBreadcrumbs'])->toBe(20);
});

/**
 * This relay sits behind the `web` group's CSRF middleware, so the snippet has to
 * hand the script a token: without it every captured error is rejected with a 419.
 */
test('the snippet carries the csrf token and the script sends it as a header', function (): void {
    session()->put('_token', 'test-csrf-token');

    $html = view('ranetrace::error-tracker')->render();

    expect(renderedTrackerConfig($html)['csrfToken'])->toBe('test-csrf-token')
        ->and($html)->toContain("requestHeaders['X-CSRF-TOKEN'] = config.csrfToken;");
});

test('ranetraceErrorTracking directive renders the snippet into a host page', function (): void {
    $html = Blade::render('<html><head>@ranetraceErrorTracking</head><body></body></html>');

    expect($html)
        ->toContain('Ranetrace JavaScript Error Tracking')
        ->toContain('<script');
});

test('error tracker view renders nothing when feature is disabled', function (): void {
    config(['ranetrace.javascript_errors.enabled' => false]);

    expect(mb_trim(view('ranetrace::error-tracker')->render()))->toBe('');
});

test('error tracker script tag includes nonce when a CSP nonce is set', function (): void {
    Vite::useCspNonce('test-nonce-value');

    $html = view('ranetrace::error-tracker')->render();

    expect($html)->toContain('nonce="test-nonce-value"');
});

/*
 * The human-verification beacon rides the same directive as the error script,
 * on its own flag and on this view having been handed a token by the capture
 * middleware. The two are independent: either, both or neither can render.
 */

/**
 * The runtime config object the rendered beacon carries.
 *
 * @return array<string, mixed>
 */
function renderedBeaconConfig(string $html): array
{
    preg_match('/var config = (\{.*\});/', $html, $matches);

    return json_decode($matches[1] ?? '', true, 512, JSON_THROW_ON_ERROR);
}

/**
 * Render the directive's view with (or without) a view token on the request,
 * which is how the capture middleware hands one to the beacon.
 */
function renderWithViewToken(?string $token): string
{
    if ($token !== null) {
        request()->attributes->set('ranetrace_view_token', $token);
    }

    return view('ranetrace::error-tracker')->render();
}

test('the beacon renders with its endpoint, token and a keepalive post', function (): void {
    config(['ranetrace.website_analytics.beacon.enabled' => true]);

    $token = '3f1b0c7e-1f4a-4a2b-9a2f-0d7f1a4b8c9d';
    $html = renderWithViewToken($token);

    expect($html)
        ->toContain('Ranetrace human-verification beacon')
        // keepalive lets the post survive a navigation away from the page.
        ->toContain('keepalive: true')
        ->and(renderedBeaconConfig($html))
        ->toMatchArray([
            'endpoint' => route('ranetrace.analytics.verify'),
            'token' => $token,
            'delayMs' => 1500,
        ]);
});

test('the beacon delay comes from config', function (): void {
    config([
        'ranetrace.website_analytics.beacon.enabled' => true,
        'ranetrace.website_analytics.beacon.delay_ms' => 4000,
    ]);

    expect(renderedBeaconConfig(renderWithViewToken('3f1b0c7e-1f4a-4a2b-9a2f-0d7f1a4b8c9d'))['delayMs'])
        ->toBe(4000);
});

test('the beacon carries the csrf token, since its route sits behind the web group', function (): void {
    config(['ranetrace.website_analytics.beacon.enabled' => true]);
    session()->put('_token', 'test-csrf-token');

    $html = renderWithViewToken('3f1b0c7e-1f4a-4a2b-9a2f-0d7f1a4b8c9d');

    expect(renderedBeaconConfig($html)['csrfToken'])->toBe('test-csrf-token')
        ->and($html)->toContain("'X-CSRF-TOKEN': config.csrfToken");
});

test('the beacon renders nothing without a view token', function (): void {
    config(['ranetrace.website_analytics.beacon.enabled' => true]);

    // No token means no visit is waiting to be verified, so there is nothing to
    // post back about.
    expect(renderWithViewToken(null))->not->toContain('Ranetrace human-verification beacon');
});

test('the beacon renders nothing while its flag is off, token or not', function (): void {
    config(['ranetrace.website_analytics.beacon.enabled' => false]);

    expect(renderWithViewToken('3f1b0c7e-1f4a-4a2b-9a2f-0d7f1a4b8c9d'))
        ->not->toContain('Ranetrace human-verification beacon');
});

test('the beacon renders on its own when javascript error tracking is off', function (): void {
    config([
        'ranetrace.javascript_errors.enabled' => false,
        'ranetrace.website_analytics.beacon.enabled' => true,
    ]);

    $html = renderWithViewToken('3f1b0c7e-1f4a-4a2b-9a2f-0d7f1a4b8c9d');

    expect($html)
        ->toContain('Ranetrace human-verification beacon')
        ->not->toContain('Ranetrace JavaScript Error Tracking');
});

test('both scripts render when both flags are on', function (): void {
    config([
        'ranetrace.javascript_errors.enabled' => true,
        'ranetrace.website_analytics.beacon.enabled' => true,
    ]);

    $html = renderWithViewToken('3f1b0c7e-1f4a-4a2b-9a2f-0d7f1a4b8c9d');

    expect($html)
        ->toContain('Ranetrace JavaScript Error Tracking')
        ->toContain('Ranetrace human-verification beacon')
        // Two separate script elements, not one wrapping both. (Counting the
        // closing tag: the shared capture script mentions an opening one inside
        // a comment.)
        ->and(mb_substr_count($html, '</script>'))->toBe(2);
});

test('the beacon script tag includes the nonce when a CSP nonce is set', function (): void {
    config([
        'ranetrace.javascript_errors.enabled' => false,
        'ranetrace.website_analytics.beacon.enabled' => true,
    ]);
    Vite::useCspNonce('test-nonce-value');

    expect(renderWithViewToken('3f1b0c7e-1f4a-4a2b-9a2f-0d7f1a4b8c9d'))
        ->toContain('nonce="test-nonce-value"');
});

test('a token that tries to close the script tag cannot break out of it', function (): void {
    config([
        'ranetrace.javascript_errors.enabled' => false,
        'ranetrace.website_analytics.beacon.enabled' => true,
    ]);

    // The route validates the token as a uuid, so this value can never come
    // back from a browser; the escaping is the second line, and the one that
    // holds if the attribute is ever set from somewhere else.
    $html = renderWithViewToken('</script><script>alert(1)</script>');

    // The config object is printed through @json, which escapes the angle
    // brackets, so the payload stays inside the string literal it landed in and
    // the page still holds exactly one script element.
    expect(mb_substr_count($html, '<script'))->toBe(1)
        ->and(mb_substr_count($html, '</script>'))->toBe(1)
        ->and($html)->not->toContain('alert(1)</script>');
});

test('the directive renders the beacon into a host page', function (): void {
    config(['ranetrace.website_analytics.beacon.enabled' => true]);
    request()->attributes->set('ranetrace_view_token', '3f1b0c7e-1f4a-4a2b-9a2f-0d7f1a4b8c9d');

    // Install stays one line: the beacon needs no directive of its own.
    expect(Blade::render('<html><body>@ranetraceErrorTracking</body></html>'))
        ->toContain('Ranetrace human-verification beacon');
});
