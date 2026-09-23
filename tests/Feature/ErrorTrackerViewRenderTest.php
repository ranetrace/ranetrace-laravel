<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Ranetrace\Php\JavaScript\CaptureScript;

beforeEach(function (): void {
    config([
        'ranetrace.javascript_errors.enabled' => true,
    ]);
});

/**
 * What a rendered capture script is recognised by.
 *
 * Its header comment used to serve as this marker. `ranetrace/ranetrace-php`
 * ships the script minified now, so every comment and every local name in it is
 * gone: the marker has to be something minification keeps. This is the public API
 * the script hangs on `window`, which is a property path rather than a local
 * name, is unique to this script, and reads the same in the minified and the
 * unminified spelling, so it holds across the dependency bump too.
 */
function captureScriptMarker(): string
{
    return 'window.Ranetrace.captureError';
}

/**
 * The runtime config object the rendered snippet carries.
 *
 * The variable the config is assigned to is a one-letter name in the minified
 * script, and a different esbuild version may spell it differently, so the
 * literal is located by the script around it instead: a probe render gives the
 * exact prefix and suffix the substitution sits between, whatever the minifier
 * called things.
 *
 * @return array<string, mixed>
 */
function renderedTrackerConfig(string $html): array
{
    [$before, $after] = explode(
        '{"ranetraceProbe":true}',
        CaptureScript::withConfig(['ranetraceProbe' => true]),
        2,
    );

    $start = mb_strpos($html, $before);

    expect($start)->not->toBeFalse('the rendered output does not carry the capture script');

    $start += mb_strlen($before);
    $end = mb_strpos($html, $after, $start);

    expect($end)->not->toBeFalse('the capture script is truncated after the config literal');

    return json_decode(mb_substr($html, $start, $end - $start), true, 512, JSON_THROW_ON_ERROR);
}

test('error tracker view renders to valid output', function (): void {
    // Regression guard: `view(...)` alone does not compile the Blade template;
    // only render() does. A directive/PHP syntax error in the view (e.g. the
    // un-compiled `<script@if` nonce conditional) surfaces here as a
    // ViewException and would 500 every host page using the snippet.
    $html = view('ranetrace::error-tracker')->render();

    expect($html)
        ->toContain(captureScriptMarker())
        // Both capture listeners are registered. The event names are string
        // literals and the method is a property on `window`, so these read the
        // same minified and unminified; the quoting around them does not.
        ->toContain('window.addEventListener(')
        ->toContain('unhandledrejection')
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

    // The header name is a string literal, so it survives minification; the
    // `requestHeaders[...] = config.csrfToken` line around it does not, both
    // names being locals the minifier renames.
    expect(renderedTrackerConfig($html)['csrfToken'])->toBe('test-csrf-token')
        ->and($html)->toContain('X-CSRF-TOKEN');
});

test('ranetraceErrorTracking directive renders the snippet into a host page', function (): void {
    $html = Blade::render('<html><head>@ranetraceErrorTracking</head><body></body></html>');

    expect($html)
        ->toContain(captureScriptMarker())
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
 * What a rendered beacon is recognised by.
 *
 * Its explanatory header used to serve as this marker. Those notes are Blade
 * comments now and never reach the page, so the marker is a line of the beacon's
 * own code instead: the visibility gate, which nothing else in this output has.
 */
function beaconMarker(): string
{
    return "document.visibilityState !== 'visible'";
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
        ->toContain(beaconMarker())
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
    expect(renderWithViewToken(null))->not->toContain(beaconMarker());
});

test('the beacon renders nothing while its flag is off, token or not', function (): void {
    config(['ranetrace.website_analytics.beacon.enabled' => false]);

    expect(renderWithViewToken('3f1b0c7e-1f4a-4a2b-9a2f-0d7f1a4b8c9d'))
        ->not->toContain(beaconMarker());
});

test('the beacon renders on its own when javascript error tracking is off', function (): void {
    config([
        'ranetrace.javascript_errors.enabled' => false,
        'ranetrace.website_analytics.beacon.enabled' => true,
    ]);

    $html = renderWithViewToken('3f1b0c7e-1f4a-4a2b-9a2f-0d7f1a4b8c9d');

    expect($html)
        ->toContain(beaconMarker())
        ->not->toContain(captureScriptMarker());
});

test('both scripts render when both flags are on', function (): void {
    config([
        'ranetrace.javascript_errors.enabled' => true,
        'ranetrace.website_analytics.beacon.enabled' => true,
    ]);

    $html = renderWithViewToken('3f1b0c7e-1f4a-4a2b-9a2f-0d7f1a4b8c9d');

    expect($html)
        ->toContain(captureScriptMarker())
        ->toContain(beaconMarker())
        // Two separate script elements, not one wrapping both. Closing tags are
        // what is counted, because the shared capture script can mention an
        // opening one in prose without opening anything.
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
        ->toContain(beaconMarker());
});

/*
 * What the two views send to a browser, and what they must not send with it.
 *
 * These scripts are inlined into every page view of every site that installs the
 * package, so every byte of them is paid for by every visitor. The notes
 * explaining them are written for whoever maintains them, and Blade comments keep
 * those notes in the source and out of the output.
 *
 * The shared capture script is the one these guards can only half speak for. It
 * is a JavaScript file in `ranetrace/ranetrace-php`, not a Blade template, so
 * whatever comments it carries survive into whatever host inlines it. The second
 * test below pins that this wrapper adds none of its own around it, which holds
 * whatever that package ships; the third pins that it brings none either, which
 * only holds from the release that ships the script minified.
 */

test('the beacon ships no comments to the browser', function (): void {
    config([
        'ranetrace.javascript_errors.enabled' => false,
        'ranetrace.website_analytics.beacon.enabled' => true,
    ]);

    $html = renderWithViewToken('3f1b0c7e-1f4a-4a2b-9a2f-0d7f1a4b8c9d');

    expect($html)->toContain(beaconMarker())
        ->and(commentsIn($html))->toBe([]);
});

test('the error tracker view adds no comments of its own around the shared script', function (): void {
    config([
        'ranetrace.javascript_errors.enabled' => true,
        'ranetrace.website_analytics.beacon.enabled' => true,
    ]);

    $html = renderWithViewToken('3f1b0c7e-1f4a-4a2b-9a2f-0d7f1a4b8c9d');

    // Every comment line the page carries has to be one the shared capture script
    // brought with it. Its config values never appear in a comment, so the same
    // script configured any other way yields the same list.
    expect(commentsIn($html))->toBe(commentsIn(CaptureScript::withConfig(['enabled' => true])));
});

/**
 * The stronger form of the guard above: with the shared script minified, the
 * directive ships no comment at all, from either view. Some 9 KB of the notes
 * explaining the capture script used to be downloaded by every visitor of every
 * site that installs the package.
 *
 * It skips while the resolved `ranetrace/ranetrace-php` predates the minified
 * twin, because until then the script really does carry its comments and this
 * would be asserting against the wrong dependency rather than against this
 * package. Bumping the requirement starts it running; nothing here has to change
 * when that happens.
 */
test('the directive ships no comments at all once the shared script is minified', function (): void {
    config([
        'ranetrace.javascript_errors.enabled' => true,
        'ranetrace.website_analytics.beacon.enabled' => true,
    ]);

    $html = renderWithViewToken('3f1b0c7e-1f4a-4a2b-9a2f-0d7f1a4b8c9d');

    expect($html)->toContain(captureScriptMarker())
        ->and(commentsIn($html))->toBe([]);
})->skip(
    ! is_file(dirname((string) (new ReflectionClass(CaptureScript::class))->getFileName(), 3).'/resources/js/error-tracker.min.js'),
    'The resolved ranetrace/ranetrace-php still ships the unminified capture script, comments and all. Bump the requirement to the release that adds resources/js/error-tracker.min.js and this guard starts running.',
);

test('neither view renders a comment while its feature is off', function (): void {
    config([
        'ranetrace.javascript_errors.enabled' => false,
        'ranetrace.website_analytics.beacon.enabled' => false,
    ]);

    expect(commentsIn(renderWithViewToken('3f1b0c7e-1f4a-4a2b-9a2f-0d7f1a4b8c9d')))->toBe([]);
});

test('the directive renders no error script while the master switch is off', function (): void {
    // The provider reads the flags at boot to decide whether to mount the relay
    // route, so the app is rebuilt with the master switch off rather than
    // flipped afterwards.
    $this->configOverrides = [
        'ranetrace.enabled' => false,
        'ranetrace.javascript_errors.enabled' => true,
    ];
    $this->reloadApplication();

    $html = Blade::render('<html><body>@ranetraceErrorTracking</body></html>');

    expect($html)->not->toContain(captureScriptMarker())
        ->and($html)->not->toContain('<script');
});

test('a page served through the web group renders neither script while the master switch is off', function (): void {
    // The beacon half needs no gate of its own: its token comes only from the
    // capture middleware, which is neither mounted nor capturing with the
    // master switch off. This walks the real request path to pin that.
    $this->configOverrides = [
        'ranetrace.enabled' => false,
        'ranetrace.javascript_errors.enabled' => true,
        'ranetrace.website_analytics.enabled' => true,
        'ranetrace.website_analytics.beacon.enabled' => true,
    ];
    $this->reloadApplication();

    Route::get('/master-off-page', fn () => Blade::render(
        '<html><body>@ranetraceErrorTracking</body></html>'
    ))->middleware('web');

    $response = $this->withHeaders([
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
        'Accept-Language' => 'en-US,en;q=0.9',
    ])->get('/master-off-page');

    $response->assertOk();

    expect($response->getContent())
        ->not->toContain(captureScriptMarker())
        ->not->toContain(beaconMarker())
        ->not->toContain('<script');
});
