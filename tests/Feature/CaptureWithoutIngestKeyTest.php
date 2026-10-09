<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Ranetrace\Laravel\Analytics\Middleware\TrackPageVisit;
use Ranetrace\Laravel\Jobs\HandleJavaScriptErrorJob;
use Ranetrace\Laravel\Jobs\HandleLogJob;
use Ranetrace\Laravel\Jobs\HandlePageVisitJob;
use Ranetrace\Laravel\Ranetrace;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;

/**
 * Logs, JavaScript errors and page visits follow the rule errors and events
 * already did: without an ingest key nothing is captured, because a buffered
 * item that can never be sent only fills the buffer and goes out stale once a
 * key is set.
 */
beforeEach(function (): void {
    Config::set('ranetrace.batch.cache_driver', 'array');
    Cache::store('array')->flush();
    Http::fake();
    Config::set('logging.channels.ranetrace', ['driver' => 'ranetrace', 'level' => 'debug']);
});

dataset('ingest keys that cannot authenticate', [
    'null' => [null],
    'whitespace' => ['   '],
]);

/**
 * Headers a real browser sends, so the visit passes the bot checks.
 *
 * @return array<string, string>
 */
function browserHeadersWithoutIngestKey(): array
{
    return [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
        'Accept-Language' => 'en-US,en;q=0.9',
    ];
}

/**
 * @return array<string, mixed>
 */
function javaScriptErrorWithoutIngestKey(): array
{
    return [
        'message' => 'Test error message',
        'stack' => 'Error: Test\n  at test.js:10',
        'type' => 'Error',
        'filename' => 'test.js',
        'line' => 10,
        'column' => 5,
        'url' => 'https://example.com/test',
        'timestamp' => now()->toISOString(),
    ];
}

// --- logging ---

test('a log is not captured without an ingest key, and logging still does not throw', function (?string $key): void {
    Config::set('ranetrace.key', $key);
    Config::set('ranetrace.logging.queue', false);

    expect(fn () => Log::channel('ranetrace')->error('Test error message'))->not->toThrow(Throwable::class)
        ->and(app(RanetraceBatchBuffer::class)->count('logs'))->toBe(0);

    Http::assertNothingSent();
})->with('ingest keys that cannot authenticate');

test('a queued log is not dispatched without an ingest key', function (?string $key): void {
    Bus::fake();
    Config::set('ranetrace.key', $key);

    Log::channel('ranetrace')->error('Test error message');

    Bus::assertNotDispatched(HandleLogJob::class);
})->with('ingest keys that cannot authenticate');

test('a log is still captured with an ingest key', function (): void {
    Config::set('ranetrace.logging.queue', false);

    Log::channel('ranetrace')->error('Test error message');

    expect(app(RanetraceBatchBuffer::class)->count('logs'))->toBe(1);
});

// --- JavaScript errors ---

test('the JavaScript error endpoint answers without an ingest key as it does with the feature off', function (?string $key): void {
    Bus::fake();
    $this->withoutMiddleware(VerifyCsrfToken::class);

    Config::set('ranetrace.javascript_errors.enabled', false);
    $flagOff = $this->postJson(route('ranetrace.javascript-errors.store'), javaScriptErrorWithoutIngestKey());

    Config::set('ranetrace.javascript_errors.enabled', true);
    Config::set('ranetrace.key', $key);
    $keyMissing = $this->postJson(route('ranetrace.javascript-errors.store'), javaScriptErrorWithoutIngestKey());

    $keyMissing->assertStatus(403);

    expect($keyMissing->status())->toBe($flagOff->status())
        ->and($keyMissing->json())->toBe($flagOff->json());

    Bus::assertNotDispatched(HandleJavaScriptErrorJob::class);
})->with('ingest keys that cannot authenticate');

test('a JavaScript error is not buffered or sent without an ingest key', function (?string $key): void {
    $this->withoutMiddleware(VerifyCsrfToken::class);
    Config::set('ranetrace.javascript_errors.queue', false);
    Config::set('ranetrace.key', $key);

    $this->postJson(route('ranetrace.javascript-errors.store'), javaScriptErrorWithoutIngestKey());

    expect(app(RanetraceBatchBuffer::class)->count('javascript_errors'))->toBe(0);

    Http::assertNothingSent();
})->with('ingest keys that cannot authenticate');

test('a JavaScript error is still buffered with an ingest key', function (): void {
    $this->withoutMiddleware(VerifyCsrfToken::class);
    Config::set('ranetrace.javascript_errors.queue', false);

    $this->postJson(route('ranetrace.javascript-errors.store'), javaScriptErrorWithoutIngestKey())
        ->assertOk();

    expect(app(RanetraceBatchBuffer::class)->count('javascript_errors'))->toBe(1);
});

test('an app booted without an ingest key mounts no relay and renders no capture script, as with the feature off', function (?string $key): void {
    // The provider decides at boot whether to mount the relay route, so the
    // app is rebuilt without a key rather than having it removed afterwards.
    $this->configOverrides = [
        'ranetrace.key' => $key,
        'ranetrace.javascript_errors.enabled' => true,
    ];
    $this->reloadApplication();

    expect(Route::has('ranetrace.javascript-errors.store'))->toBeFalse()
        ->and(Blade::render('<html><body>@ranetraceErrorTracking</body></html>'))->not->toContain('<script');
})->with('ingest keys that cannot authenticate');

// --- page visits ---

test('a page visit is not captured without an ingest key and the page answers unchanged', function (?string $key): void {
    Config::set('ranetrace.website_analytics.queue', false);

    $withKey = $this->withHeaders(browserHeadersWithoutIngestKey())->get('/test-page');
    expect(app(RanetraceBatchBuffer::class)->count('page_visits'))->toBe(1);
    app(RanetraceBatchBuffer::class)->clear('page_visits');
    Cache::store('array')->flush();

    Config::set('ranetrace.key', $key);
    $withoutKey = $this->withHeaders(browserHeadersWithoutIngestKey())->get('/test-page');

    expect($withoutKey->status())->toBe($withKey->status())
        ->and($withoutKey->getContent())->toBe($withKey->getContent())
        ->and(app(RanetraceBatchBuffer::class)->count('page_visits'))->toBe(0);

    Http::assertNothingSent();
})->with('ingest keys that cannot authenticate');

test('a queued page visit is not dispatched without an ingest key', function (?string $key): void {
    Bus::fake();
    Config::set('ranetrace.key', $key);

    $this->withHeaders(browserHeadersWithoutIngestKey())->get('/test-page')->assertOk();

    Bus::assertNotDispatched(HandlePageVisitJob::class);
})->with('ingest keys that cannot authenticate');

test('a page visit is still captured with an ingest key', function (): void {
    Config::set('ranetrace.website_analytics.queue', false);

    $this->withHeaders(browserHeadersWithoutIngestKey())->get('/test-page')->assertOk();

    expect(app(RanetraceBatchBuffer::class)->count('page_visits'))->toBe(1);
});

test('a page without an ingest key renders no verification beacon', function (?string $key): void {
    // The beacon's token comes only from a captured visit, so with no capture
    // there is no token and nothing to render.
    Bus::fake();
    Config::set('ranetrace.javascript_errors.enabled', false);
    Config::set('ranetrace.website_analytics.beacon.enabled', true);
    Config::set('queue.default', 'database');

    Route::get('/no-key-page', fn () => Blade::render(
        '<html><body>@ranetraceErrorTracking</body></html>'
    ))->middleware(['web', TrackPageVisit::class]);

    $withKey = $this->withHeaders(browserHeadersWithoutIngestKey())->get('/no-key-page');

    Config::set('ranetrace.key', $key);
    $withoutKey = $this->withHeaders(browserHeadersWithoutIngestKey())->get('/no-key-page');

    $withoutKey->assertOk();

    expect($withKey->getContent())->toContain('<script')
        ->and($withoutKey->getContent())->not->toContain('<script');
})->with('ingest keys that cannot authenticate');

test('a feature whose flag the config leaves out falls back to the default config/ranetrace.php ships', function (string $feature, bool $isCaptured): void {
    Config::set("ranetrace.{$feature}", []);

    expect(Ranetrace::isCaptureEnabled($feature))->toBe($isCaptured);
})->with([
    'errors' => ['errors', true],
    'events' => ['events', true],
    'logging' => ['logging', false],
    'website analytics' => ['website_analytics', false],
    'javascript errors' => ['javascript_errors', false],
]);
