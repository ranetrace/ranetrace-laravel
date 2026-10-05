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

test('cache driver check warns on a custom-named store on the array driver outside production', function (): void {
    Config::set('cache.stores.fast', ['driver' => 'array']);
    Config::set('ranetrace.batch.cache_driver', 'fast');

    $result = runChecks()['cache_driver'];

    expect($result->level)->toBe(CheckLevel::Warn)
        ->and($result->title)->toBe('Volatile cache driver "fast" on the "array" driver');
});

test('cache driver check fails on a custom-named store on the array driver in production', function (): void {
    Config::set('cache.stores.fast', ['driver' => 'array']);
    Config::set('ranetrace.batch.cache_driver', 'fast');
    $this->app['env'] = 'production';

    $result = runChecks()['cache_driver'];

    expect($result->level)->toBe(CheckLevel::Fail)
        ->and($result->title)->toBe('Volatile cache driver "fast" on the "array" driver in production');
});

test('cache driver check passes a custom-named store on a durable driver', function (): void {
    Config::set('cache.stores.durable', ['driver' => 'file', 'path' => storage_path('framework/cache/data')]);
    Config::set('ranetrace.batch.cache_driver', 'durable');

    $result = runChecks()['cache_driver'];

    expect($result->level)->toBe(CheckLevel::Pass)
        ->and($result->title)->toBe('Cache driver "durable" on the "file" driver persists between requests');
});

test('cache driver check fails on a store that is not configured, in any environment', function (): void {
    $check = new Ranetrace\Laravel\Dashboard\Checks\CacheDriverCheck;
    $status = ['config' => ['cache_driver' => 'missing', 'cache_driver_is_app_default' => false]];

    $result = $check->run($status);

    expect($result->level)->toBe(CheckLevel::Fail)
        ->and($result->title)->toBe('Cache driver "missing" is not a configured cache store')
        ->and($result->remediation)->toContain('config/cache.php')
        ->not->toContain("\u{2014}");
});

test('drain stalled check fails when buffered items wait past the drain window with no drain', function (): void {
    app(RanetraceBatchBuffer::class)->addItem('events', ['event_name' => 'e1']);

    // Age the item past the stale window so it is genuinely overdue.
    $this->travel(DashboardData::DRAIN_STALE_SECONDS + 1)->seconds();

    expect(runChecks()['drain_stalled']->level)->toBe(CheckLevel::Fail);
});

test('drain stalled check passes for a freshly buffered item awaiting its first drain', function (): void {
    app(RanetraceBatchBuffer::class)->addItem('events', ['event_name' => 'e1']);

    // A just-buffered item is waiting for the next ranetrace:work run, not
    // stalled, even though no successful drain has been recorded yet.
    expect(runChecks()['drain_stalled']->level)->toBe(CheckLevel::Pass);
});

test('buffer capacity check flags an active overflow as a failure', function (): void {
    Cache::store('array')->put('ranetrace:buffer:events:overflow', true, 60);

    expect(runChecks()['buffer_capacity']->level)->toBe(CheckLevel::Fail);
});

test('queue worker check warns when a non-default queue is configured', function (): void {
    Config::set('ranetrace.batch.queue_name', 'ranetrace');

    expect(runChecks()['queue_worker']->level)->toBe(CheckLevel::Warn);
});

test('queue worker check passes when no queue name is configured', function (): void {
    expect(runChecks()['queue_worker']->level)->toBe(CheckLevel::Pass);
});

test('queue worker check passes when a set queue name is the connection default queue', function (): void {
    Config::set('queue.default', 'database');
    Config::set('queue.connections.database.queue', 'jobs');
    Config::set('ranetrace.batch.queue_name', 'jobs');
    Config::set('ranetrace.errors.queue_name', 'jobs');

    expect(runChecks()['queue_worker']->level)->toBe(CheckLevel::Pass);
});

test('queue worker check warns about a queue literally named default on a connection whose default queue is another', function (): void {
    Config::set('queue.default', 'database');
    Config::set('queue.connections.database.queue', 'jobs');
    Config::set('ranetrace.events.queue_name', 'default');

    $result = runChecks()['queue_worker'];

    expect($result->level)->toBe(CheckLevel::Warn)
        ->and($result->title)->toContain('default');
});

test('queue worker check judges a queue name against the default connection, not another connection', function (): void {
    Config::set('queue.default', 'redis');
    Config::set('queue.connections.redis.queue', 'high');
    Config::set('queue.connections.database.queue', 'jobs');
    Config::set('ranetrace.batch.queue_name', 'jobs');

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

test('the volatile cache driver advice speaks in two sentences, with no em-dash', function (): void {
    // The house writing rule keeps the dash out of anything the package says,
    // and this remediation line is rendered verbatim on the dashboard.
    $remediation = runChecks()['cache_driver']->remediation;

    expect($remediation)
        ->toBe('Fine locally, but buffers/pauses will not survive in production. Use a durable store there.')
        ->not->toContain("\u{2014}");
});

class ThrowingCheck implements Ranetrace\Laravel\Dashboard\Checks\Check
{
    public function run(array $status): Ranetrace\Laravel\Dashboard\Checks\CheckResult
    {
        throw new RuntimeException('boom');
    }
}
