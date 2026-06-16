<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Ranetrace\Laravel\Dashboard\Checks\CheckLevel;
use Ranetrace\Laravel\Dashboard\DashboardData;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;

beforeEach(function (): void {
    Config::set('ranetrace.batch.cache_driver', 'array');
    Cache::store('array')->flush();
});

/**
 * Index the check results by their stable name for easy assertions.
 *
 * @return array<string, Ranetrace\Laravel\Dashboard\Checks\CheckResult>
 */
function runChecks(): array
{
    $data = app(DashboardData::class);
    $results = $data->runChecks($data->collectStatus());

    return collect($results)->keyBy(fn ($r) => $r->name)->all();
}

test('the default registry runs every seed check', function (): void {
    $checks = runChecks();

    expect(array_keys($checks))->toEqualCanonicalizing([
        'api_key', 'cache_driver', 'drain_stalled', 'buffer_capacity', 'queue_worker', 'internal_logging',
    ]);
});

test('api key check fails when the key is missing', function (): void {
    Config::set('ranetrace.key', null);

    expect(runChecks()['api_key']->level)->toBe(CheckLevel::Fail);
});

test('api key check passes when the key is configured', function (): void {
    expect(runChecks()['api_key']->level)->toBe(CheckLevel::Pass); // TestCase sets a test key
});

test('cache driver check warns on a volatile driver outside production', function (): void {
    // The test environment is "testing"; array is volatile -> warn, not fail.
    expect(runChecks()['cache_driver']->level)->toBe(CheckLevel::Warn);
});

test('cache driver check fails on a volatile driver in production', function (): void {
    $this->app['env'] = 'production';

    expect(runChecks()['cache_driver']->level)->toBe(CheckLevel::Fail);
});

test('drain stalled check fails when a buffer has items but never drained', function (): void {
    app(RanetraceBatchBuffer::class)->addItem('events', ['event_name' => 'e1']);

    expect(runChecks()['drain_stalled']->level)->toBe(CheckLevel::Fail);
});

test('buffer capacity check flags an active overflow as a failure', function (): void {
    Cache::store('array')->put('ranetrace:buffer:events:overflow', true, 60);

    expect(runChecks()['buffer_capacity']->level)->toBe(CheckLevel::Fail);
});

test('queue worker check warns when a non-default queue is configured', function (): void {
    Config::set('ranetrace.batch.queue_name', 'ranetrace');

    expect(runChecks()['queue_worker']->level)->toBe(CheckLevel::Warn);
});

test('internal logging check warns when internal logging is disabled', function (): void {
    Config::set('ranetrace.internal_logging.enabled', false);

    expect(runChecks()['internal_logging']->level)->toBe(CheckLevel::Warn);
});

test('a check that throws is skipped, never breaking the set', function (): void {
    Config::set('ranetrace.dashboard.checks', [
        ThrowingCheck::class,
        Ranetrace\Laravel\Dashboard\Checks\ApiKeyCheck::class,
    ]);

    $data = app(DashboardData::class);
    $results = $data->runChecks($data->collectStatus());

    // The throwing check is dropped; the healthy one survives.
    expect($results)->toHaveCount(1)
        ->and($results[0]->name)->toBe('api_key');
});

class ThrowingCheck implements Ranetrace\Laravel\Dashboard\Checks\Check
{
    public function run(array $status): Ranetrace\Laravel\Dashboard\Checks\CheckResult
    {
        throw new RuntimeException('boom');
    }
}
