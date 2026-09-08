<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Ranetrace\Laravel\Jobs\HandlePageVisitJob;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;
use Ranetrace\Laravel\Support\VisitVerificationMark;

/**
 * The delayed page visit job resolves `verified_human` by reading the mark the
 * beacon endpoint left for its view token. These tests run the job directly and
 * inspect what actually reached the buffer, which is what will reach the wire.
 */
beforeEach(function (): void {
    Config::set('ranetrace.batch.cache_driver', 'array');
    Cache::store('array')->flush();
});

/**
 * Run the job and return the single payload it buffered.
 *
 * @param  array<string, mixed>  $visitData
 * @return array<string, mixed>
 */
function bufferedPageVisit(array $visitData): array
{
    $buffer = new RanetraceBatchBuffer;

    (new HandlePageVisitJob($visitData))->handle($buffer);

    return $buffer->getItems('page_visits', 1)[0]['data'];
}

test('a marked view is buffered as verified, and the mark is consumed', function (): void {
    $token = (string) Str::uuid();
    VisitVerificationMark::put($token);

    $payload = bufferedPageVisit([
        'path' => '/pricing',
        'verified_human' => false,
        'view_token' => $token,
    ]);

    expect($payload['verified_human'])->toBeTrue()
        // The token is a local correlation handle: it must never reach the wire.
        ->and($payload)->not->toHaveKey('view_token')
        // Read and forget in one call, so a mark is not left behind per page view.
        ->and(Cache::store('array')->has(VisitVerificationMark::key($token)))->toBeFalse();
});

test('an unmarked view is buffered as unverified', function (): void {
    $payload = bufferedPageVisit([
        'path' => '/pricing',
        'verified_human' => false,
        'view_token' => (string) Str::uuid(),
    ]);

    expect($payload['verified_human'])->toBeFalse()
        ->and($payload)->not->toHaveKey('view_token');
});

test('a visit carrying no token is buffered untouched', function (): void {
    // The beacon is off: the visit says nothing about verification rather than
    // claiming a false, so the flag must not appear out of nowhere.
    $payload = bufferedPageVisit(['path' => '/pricing']);

    expect($payload)->not->toHaveKey('verified_human')
        ->and($payload)->not->toHaveKey('view_token')
        ->and($payload['path'])->toBe('/pricing');
});

test('one view mark verifies only its own visit', function (): void {
    $marked = (string) Str::uuid();
    VisitVerificationMark::put($marked);

    $other = bufferedPageVisit([
        'path' => '/other',
        'verified_human' => false,
        'view_token' => (string) Str::uuid(),
    ]);

    $own = bufferedPageVisit([
        'path' => '/own',
        'verified_human' => false,
        'view_token' => $marked,
    ]);

    expect($other['verified_human'])->toBeFalse()
        ->and($own['verified_human'])->toBeTrue();
});

test('the allow-list ships verified_human and can never ship the view token', function (): void {
    $allowed = (new ReflectionMethod(HandlePageVisitJob::class, 'getAllowedKeys'))
        ->invoke(new HandlePageVisitJob([]));

    expect($allowed)->toContain('verified_human')
        ->and($allowed)->not->toContain('view_token');
});

test('the job reads the mark from the ranetrace store, not the host default', function (): void {
    Config::set('cache.stores.ranetrace_job_test', ['driver' => 'array']);
    Config::set('ranetrace.batch.cache_driver', 'ranetrace_job_test');

    $token = (string) Str::uuid();

    // Written where the endpoint writes it, which is the store the job must read.
    VisitVerificationMark::put($token);

    expect(bufferedPageVisit([
        'path' => '/pricing',
        'verified_human' => false,
        'view_token' => $token,
    ])['verified_human'])->toBeTrue();
});
