<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Pest\Browser\Execution;
use Ranetrace\Laravel\Support\VisitVerificationMark;

/**
 * The human-verification beacon, driven by a real browser.
 *
 * The render tests can only say what the script contains. Whether it actually
 * posts, and when, is browser behaviour, and it is the one thing that decides
 * whether a real visitor is counted: a beacon that never fires reports the visit
 * as unverified, and the app drops unverified visits from every number.
 */
const BEACON_PROBE_TOKEN = '3f1b0c7e-1f4a-4a2b-9a2f-0d7f1a4b8c9d';

/**
 * Registers a page that carries the beacon for one known view token, and a
 * plain page to navigate away to.
 *
 * The token is set on the request by hand because the capture middleware,
 * which normally mints it, rejects the headless browser's user agent as a bot.
 */
function beaconProbePage(int $delayMs, bool $holdLoad = false): void
{
    config([
        'ranetrace.javascript_errors.enabled' => false,
        'ranetrace.website_analytics.beacon.enabled' => true,
        'ranetrace.website_analytics.beacon.delay_ms' => $delayMs,
    ]);

    Route::get('/beacon-probe', function () use ($holdLoad): string {
        request()->attributes->set('ranetrace_view_token', BEACON_PROBE_TOKEN);

        return Blade::render(<<<'BLADE'
            <!DOCTYPE html>
            <html>
            <head><title>Beacon probe</title></head>
            <body>
                <h1>Beacon probe</h1>
                @if($holdLoad)
                    <img src="http://10.255.255.1/never-answers.png" alt="">
                @endif
                @ranetraceErrorTracking
            </body>
            </html>
        BLADE, ['holdLoad' => $holdLoad]);
    })->middleware('web');

    Route::get('/beacon-elsewhere', fn (): string => '<!DOCTYPE html><html><body><h1>Elsewhere</h1></body></html>')
        ->middleware('web');
}

function beaconMarkIsSet(): bool
{
    return Cache::store(config('ranetrace.batch.cache_driver'))->has(VisitVerificationMark::key(BEACON_PROBE_TOKEN));
}

it('marks a visible page as verified without any interaction', function (): void {
    beaconProbePage(delayMs: 0);

    visit('/beacon-probe')->assertSee('Beacon probe');

    // The amphp HTTP server runs in-process, so the event loop must be ticked
    // for the beacon's fetch() to be handled. waitForExpectation() does that;
    // a plain sleep() would deadlock.
    Execution::instance()->waitForExpectation(function (): void {
        expect(beaconMarkIsSet())->toBeTrue();
    });
});

it('does not wait for the page to finish loading', function (): void {
    // An image from a non-routable address keeps `load` from firing for as
    // long as the connection attempt hangs. A visitor who reads a page whose
    // slowest image never arrives is still a visitor.
    beaconProbePage(delayMs: 0, holdLoad: true);

    visit('/beacon-probe', ['waitUntil' => 'domcontentloaded'])->assertSee('Beacon probe');

    // The amphp HTTP server runs in-process, so the event loop must be ticked
    // for the beacon's fetch() to be handled. waitForExpectation() does that;
    // a plain sleep() would deadlock.
    Execution::instance()->waitForExpectation(function (): void {
        expect(beaconMarkIsSet())->toBeTrue();
    });
});

it('posts on leaving the page when the configured delay has not elapsed yet', function (): void {
    // A delay far longer than the test: only the pagehide path can post here.
    beaconProbePage(delayMs: 600000);

    visit('/beacon-probe')
        ->assertSee('Beacon probe')
        ->navigate('/beacon-elsewhere')
        ->assertSee('Elsewhere');

    Execution::instance()->waitForExpectation(function (): void {
        expect(beaconMarkIsSet())->toBeTrue();
    });
});
