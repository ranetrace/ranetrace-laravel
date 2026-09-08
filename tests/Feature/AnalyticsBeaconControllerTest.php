<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Ranetrace\Laravel\Jobs\HandlePageVisitJob;
use Ranetrace\Laravel\Support\VisitVerificationMark;

/**
 * The beacon endpoint leaves a mark and nothing else. It sends no visit, reads
 * no state and answers the same for every well-formed token, so the tests below
 * are about the gates, the shape of the token, and where the mark lands.
 */
beforeEach(function (): void {
    $this->withoutMiddleware(VerifyCsrfToken::class);

    config([
        'ranetrace.website_analytics.enabled' => true,
        'ranetrace.website_analytics.beacon.enabled' => true,
        'ranetrace.batch.cache_driver' => 'array',
    ]);

    Cache::store('array')->flush();
});

/**
 * Post a beacon for the given token.
 */
function postBeacon(string $token): Illuminate\Testing\TestResponse
{
    return test()->postJson(route('ranetrace.analytics.verify'), ['token' => $token]);
}

test('it refuses a beacon while the beacon is switched off', function (): void {
    config(['ranetrace.website_analytics.beacon.enabled' => false]);

    postBeacon((string) Str::uuid())
        ->assertForbidden()
        ->assertExactJson([
            'success' => false,
            'message' => 'Verification beacon is not enabled',
        ]);
});

test('it refuses a beacon while analytics is off, even with the beacon flag on', function (): void {
    // The route is mounted at boot, so an app that turned analytics off after
    // this request's page was rendered still answers, and must answer 403.
    config(['ranetrace.website_analytics.enabled' => false]);

    postBeacon((string) Str::uuid())
        ->assertForbidden()
        ->assertExactJson([
            'success' => false,
            'message' => 'Website analytics is not enabled',
        ]);
});

test('it refuses a beacon while Ranetrace itself is disabled', function (): void {
    config(['ranetrace.enabled' => false]);

    postBeacon((string) Str::uuid())->assertForbidden();
});

test('it rejects a token that is not a uuid', function (mixed $token): void {
    test()->postJson(route('ranetrace.analytics.verify'), $token === null ? [] : ['token' => $token])
        ->assertStatus(422)
        ->assertExactJson([
            'success' => false,
            'message' => 'Validation failed',
        ]);
})->with([
    'missing' => null,
    'empty' => '',
    'not a uuid' => 'not-a-uuid',
    'a uuid with a script tag glued on' => '3f1b0c7e-1f4a-4a2b-9a2f-0d7f1a4b8c9d</script>',
    'an array' => [['nested' => 'value']],
]);

test('a valid token is marked, and the mark is the key the job reads', function (): void {
    $token = (string) Str::uuid();

    postBeacon($token)
        ->assertOk()
        ->assertExactJson([
            'success' => true,
            'message' => 'Beacon received',
        ]);

    // The literal key is pinned here as well as in the shared helper: writer and
    // reader agreeing with each other but not with what shipped would be a
    // silent always-unverified regression.
    expect(Cache::store('array')->get('ranetrace:visit_verified:'.$token))->toBeTrue()
        ->and(VisitVerificationMark::key($token))->toBe('ranetrace:visit_verified:'.$token);
});

test('an unknown token is answered exactly like a known one', function (): void {
    $known = (string) Str::uuid();
    $unknown = (string) Str::uuid();

    $first = postBeacon($known);
    $second = postBeacon($unknown);

    // A prober must not be able to learn which tokens are live by comparing
    // answers, so status and body are identical.
    expect($second->getStatusCode())->toBe($first->getStatusCode())
        ->and($second->getContent())->toBe($first->getContent());
});

test('a second beacon for the same token changes nothing', function (): void {
    $token = (string) Str::uuid();

    postBeacon($token)->assertOk();
    postBeacon($token)->assertOk();

    expect(Cache::store('array')->get(VisitVerificationMark::key($token)))->toBeTrue();
});

test('marking one token leaves another unmarked', function (): void {
    $marked = (string) Str::uuid();
    $unmarked = (string) Str::uuid();

    postBeacon($marked)->assertOk();

    expect(Cache::store('array')->has(VisitVerificationMark::key($marked)))->toBeTrue()
        ->and(Cache::store('array')->has(VisitVerificationMark::key($unmarked)))->toBeFalse();
});

test('the mark outlives the job wait window by a minute', function (): void {
    config(['ranetrace.website_analytics.beacon.wait_seconds' => 15]);

    $this->travelTo(Carbon::create(2026, 1, 1, 10, 0, 0));

    $token = (string) Str::uuid();
    postBeacon($token)->assertOk();

    // A busy queue runs the delayed job late; inside the margin the mark must
    // still be readable, or a beacon that did arrive reads as absent.
    $this->travelTo(Carbon::create(2026, 1, 1, 10, 1, 14)); // wait + 59s
    expect(Cache::store('array')->has(VisitVerificationMark::key($token)))->toBeTrue();

    $this->travelTo(Carbon::create(2026, 1, 1, 10, 1, 16)); // wait + 61s
    expect(Cache::store('array')->has(VisitVerificationMark::key($token)))->toBeFalse();

    $this->travelBack();
});

test('the mark lands in the ranetrace store, not the host default', function (): void {
    // The host default may be `array`, which is per-process: the beacon's POST
    // and the queued job that reads its mark are never the same process.
    config([
        'cache.stores.ranetrace_beacon_test' => ['driver' => 'array'],
        'ranetrace.batch.cache_driver' => 'ranetrace_beacon_test',
    ]);

    $token = (string) Str::uuid();
    postBeacon($token)->assertOk();

    expect(Cache::store('ranetrace_beacon_test')->has(VisitVerificationMark::key($token)))->toBeTrue()
        ->and(Cache::store('array')->has(VisitVerificationMark::key($token)))->toBeFalse();
});

test('a beacon post is not itself counted as a page visit', function (): void {
    Bus::fake();

    postBeacon((string) Str::uuid())->assertOk();

    // The beacon route sits in the `web` group, where the capture middleware
    // also lives. A beacon that counted itself would inflate every page it
    // fires from, and each of those visits would ask for a beacon of its own.
    Bus::assertNotDispatched(HandlePageVisitJob::class);
});

test('the beacon route carries the configured rate limit', function (): void {
    $this->configOverrides = ['ranetrace.website_analytics.beacon.throttle' => '7,2'];
    $this->reloadApplication();

    $route = collect(app('router')->getRoutes())->first(
        fn ($route): bool => $route->getName() === 'ranetrace.analytics.verify'
    );

    expect($route->gatherMiddleware())->toContain('throttle:7,2', 'web');
});

test('the beacon route is not mounted when analytics is disabled at boot', function (): void {
    $this->configOverrides = ['ranetrace.website_analytics.enabled' => false];
    $this->reloadApplication();

    $named = collect(app('router')->getRoutes())->first(
        fn ($route): bool => $route->getName() === 'ranetrace.analytics.verify'
    );

    expect($named)->toBeNull();

    $this->withoutMiddleware(VerifyCsrfToken::class)
        ->postJson('/ranetrace/analytics/verify', ['token' => (string) Str::uuid()])
        ->assertNotFound();
});

/**
 * The endpoint sits on the capture path and must never throw uncaught into the
 * host app. A cache store it cannot resolve is the realistic way for that to
 * happen: `ranetrace.batch.cache_driver` naming a store `config/cache.php` does
 * not define makes `Cache::store()` throw inside the mark write.
 */
test('a broken cache store returns a clean 500 JSON instead of throwing', function (): void {
    config(['ranetrace.batch.cache_driver' => 'no-such-store']);

    postBeacon((string) Str::uuid())
        ->assertStatus(500)
        ->assertExactJson([
            'success' => false,
            'message' => 'Failed to process beacon',
        ]);
});
