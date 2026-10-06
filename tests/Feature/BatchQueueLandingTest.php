<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Ranetrace\Laravel\Dashboard\DashboardData;
use Ranetrace\Laravel\Jobs\HandleEventJob;
use Ranetrace\Laravel\Jobs\SendBatchToRanetraceJob;

/**
 * The default connection is `database` with its default queue `jobs`, and
 * `redis` is another connection whose default queue is `high`.
 */
beforeEach(function (): void {
    Config::set('ranetrace.batch.cache_driver', 'array');
    Cache::store('array')->flush();
    Config::set('queue.default', 'database');
    Config::set('queue.connections.database.queue', 'jobs');
    Config::set('queue.connections.redis.queue', 'high');
    Config::set('ranetrace.batch.queue_name', null);
    // The dashboard's default gate allows the local environment.
    $this->app['env'] = 'local';
});

/**
 * The status payload's config as `ranetrace:status --json` prints it.
 *
 * @return array<string, mixed>
 */
function statusJsonConfig(): array
{
    Artisan::call('ranetrace:status', ['--json' => true]);

    return json_decode(mb_trim(Artisan::output()), true)['config'];
}

/**
 * The value the dashboard's Configuration panel shows for the batch queue.
 */
function dashboardBatchQueue(TestResponse $response): string
{
    preg_match('/Batch queue<\/span>\s*<span class="rt-kv__val rt-kv__val--mono">([^<]*)<\/span>/', $response->getContent(), $matches);

    return html_entity_decode($matches[1] ?? '', ENT_QUOTES);
}

$withoutQueueRoutes = fn (): bool => ! app()->bound('queue.routes');
$queueRoutesSkipReason = 'Queue routes arrived in Laravel 13, so on Laravel 12 no host can route a job away from the default connection.';
$withoutQueueForwards = fn (): bool => ! method_exists(app('queue'), 'forward');
$queueForwardsSkipReason = 'This Laravel version has no queue forwards.';

test('status and dashboard name the queue and connection a route sends the batch job to', function (): void {
    Queue::route(SendBatchToRanetraceJob::class, 'ranetrace', 'redis');

    $this->artisan('ranetrace:status')
        ->expectsOutput('Queue Name: ranetrace on redis')
        ->assertSuccessful();

    expect(statusJsonConfig())
        ->toMatchArray(['queue_name' => null, 'queue_landing' => 'ranetrace', 'queue_connection' => 'redis'])
        ->and(dashboardBatchQueue($this->get('/ranetrace')))->toBe('ranetrace on redis');
})->skip($withoutQueueRoutes, $queueRoutesSkipReason);

test('status and dashboard name a configured batch queue on the default connection without a connection', function (): void {
    Config::set('ranetrace.batch.queue_name', 'ranetrace');

    $this->artisan('ranetrace:status')
        ->expectsOutput('Queue Name: ranetrace')
        ->assertSuccessful();

    expect(statusJsonConfig())
        ->toMatchArray(['queue_name' => 'ranetrace', 'queue_landing' => 'ranetrace', 'queue_connection' => 'database'])
        ->and(dashboardBatchQueue($this->get('/ranetrace')))->toBe('ranetrace');
});

test('status and dashboard name the connection default queue when nothing names a batch queue', function (): void {
    $this->artisan('ranetrace:status')
        ->expectsOutput("Queue Name: the connection's default queue")
        ->assertSuccessful();

    expect(statusJsonConfig())
        ->toMatchArray(['queue_name' => null, 'queue_landing' => null, 'queue_connection' => 'database'])
        ->and(dashboardBatchQueue($this->get('/ranetrace')))->toBe("the connection's default queue");
});

test('a route that names only a connection lands the batch job on that connection default queue', function (): void {
    Queue::route(SendBatchToRanetraceJob::class, connection: 'redis');

    $this->artisan('ranetrace:status')
        ->expectsOutput('Queue Name: the default queue on redis')
        ->assertSuccessful();

    expect(statusJsonConfig())
        ->toMatchArray(['queue_name' => null, 'queue_landing' => null, 'queue_connection' => 'redis'])
        ->and(dashboardBatchQueue($this->get('/ranetrace')))->toBe('the default queue on redis');
})->skip($withoutQueueRoutes, $queueRoutesSkipReason);

test('a route to the default connection names the queue without a connection', function (): void {
    Queue::route(SendBatchToRanetraceJob::class, 'ranetrace', 'database');

    $this->artisan('ranetrace:status')
        ->expectsOutput('Queue Name: ranetrace')
        ->assertSuccessful();

    expect(statusJsonConfig())
        ->toMatchArray(['queue_landing' => 'ranetrace', 'queue_connection' => 'database']);
})->skip($withoutQueueRoutes, $queueRoutesSkipReason);

test('a configured batch queue name wins over the queue a route names but follows its connection', function (): void {
    Config::set('ranetrace.batch.queue_name', 'jobs');
    Queue::route(SendBatchToRanetraceJob::class, 'ranetrace', 'redis');

    $this->artisan('ranetrace:status')
        ->expectsOutput('Queue Name: jobs on redis')
        ->assertSuccessful();

    expect(statusJsonConfig())
        ->toMatchArray(['queue_name' => 'jobs', 'queue_landing' => 'jobs', 'queue_connection' => 'redis']);
})->skip($withoutQueueRoutes, $queueRoutesSkipReason);

test('a route for another Ranetrace job leaves the batch queue line alone', function (): void {
    Queue::route(HandleEventJob::class, 'ranetrace', 'redis');

    $this->artisan('ranetrace:status')
        ->expectsOutput("Queue Name: the connection's default queue")
        ->assertSuccessful();

    expect(statusJsonConfig())
        ->toMatchArray(['queue_landing' => null, 'queue_connection' => 'database']);
})->skip($withoutQueueRoutes, $queueRoutesSkipReason);

test('a forwarded batch queue is named where it lands and where it was forwarded from', function (): void {
    Config::set('ranetrace.batch.queue_name', 'ranetrace');
    Queue::forward('ranetrace', 'jobs');

    $this->artisan('ranetrace:status')
        ->expectsOutput('Queue Name: jobs (forwarded from ranetrace)')
        ->assertSuccessful();

    expect(statusJsonConfig())
        ->toMatchArray(['queue_name' => 'ranetrace', 'queue_landing' => 'jobs', 'queue_connection' => 'database'])
        ->and(dashboardBatchQueue($this->get('/ranetrace')))->toBe('jobs (forwarded from ranetrace)');
})->skip($withoutQueueForwards, $queueForwardsSkipReason);

test('a batch queue forwarded to another connection names that connection', function (): void {
    Config::set('ranetrace.batch.queue_name', 'ranetrace');
    Queue::forward('ranetrace', 'high', 'redis');

    $this->artisan('ranetrace:status')
        ->expectsOutput('Queue Name: high on redis (forwarded from ranetrace)')
        ->assertSuccessful();

    expect(statusJsonConfig())
        ->toMatchArray(['queue_name' => 'ranetrace', 'queue_landing' => 'high', 'queue_connection' => 'redis']);
})->skip($withoutQueueForwards, $queueForwardsSkipReason);

test('without queue routes the batch job lands on the configured queue of the default connection', function (): void {
    Config::set('ranetrace.batch.queue_name', 'ranetrace');
    app()->offsetUnset('queue.routes');

    expect(app(DashboardData::class)->collectStatus()['config'])
        ->toMatchArray(['queue_name' => 'ranetrace', 'queue_landing' => 'ranetrace', 'queue_connection' => 'database']);
});
