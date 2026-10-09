<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Ranetrace\Laravel\Dashboard\Checks\CacheDriverCheck;
use Ranetrace\Laravel\Dashboard\Checks\CheckLevel;
use Ranetrace\Laravel\Dashboard\DashboardData;
use Ranetrace\Laravel\Jobs\HandlePageVisitJob;
use Ranetrace\Laravel\Jobs\SendBatchToRanetraceJob;
use Ranetrace\Laravel\Services\RanetraceApiClient;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;
use Ranetrace\Laravel\Services\RanetracePauseManager;
use Ranetrace\Laravel\Support\VisitVerificationMark;

/**
 * `RANETRACE_BATCH_CACHE_DRIVER=null` arrives as null and
 * `RANETRACE_BATCH_CACHE_DRIVER=` as an empty string: both mean the app's
 * default store, which the TestCase pins to `array`, and so does a value of
 * nothing but spaces, which names no store either.
 */
dataset('unset batch values', [
    'null' => [null],
    'blank' => [''],
    'spaces' => ['  '],
]);

beforeEach(function (): void {
    Cache::store('array')->flush();
    // The dashboard's default gate allows the local environment.
    $this->app['env'] = 'local';
});

test('an unset batch cache driver buffers into the app default store', function (?string $unsetValue): void {
    Config::set('ranetrace.batch.cache_driver', $unsetValue);

    $buffer = new RanetraceBatchBuffer;

    expect($buffer->addItem('events', ['event_name' => 'signup']))->toBeTrue()
        ->and($buffer->count('events'))->toBe(1)
        ->and(Cache::store('array')->get('ranetrace:buffer:events'))->toHaveCount(1);
})->with('unset batch values');

test('an unset batch cache driver keeps pauses in the app default store', function (?string $unsetValue): void {
    Config::set('ranetrace.batch.cache_driver', $unsetValue);

    app(RanetracePauseManager::class)->setFeaturePause('errors', 900, '429');

    expect(app(RanetracePauseManager::class)->isFeaturePaused('errors'))->toBeTrue();
})->with('unset batch values');

test('an unset batch cache driver keeps the beacon mark in the app default store', function (?string $unsetValue): void {
    Config::set('ranetrace.batch.cache_driver', $unsetValue);

    VisitVerificationMark::put('view-token');

    expect(Cache::store('array')->has(VisitVerificationMark::key('view-token')))->toBeTrue()
        ->and(VisitVerificationMark::pull('view-token'))->toBeTrue();
})->with('unset batch values');

test('a page visit with an unset batch cache driver is throttled and scored in the app default store', function (?string $unsetValue): void {
    Config::set('ranetrace.batch.cache_driver', $unsetValue);
    Bus::fake();

    $this->withHeaders([
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/91.0',
    ])->get('/')->assertOk();

    Bus::assertDispatched(HandlePageVisitJob::class);
    expect(Cache::store('array')->get('ranetrace:request_frequency:127.0.0.1'))->toBe(1);
})->with('unset batch values');

test('a batch sent with an unset batch cache driver drains the buffer the dashboard reads', function (?string $unsetValue): void {
    Config::set('ranetrace.batch.cache_driver', $unsetValue);
    Http::fake(['api.ranetrace.com/*' => Http::response(['success' => true], 200)]);

    $buffer = new RanetraceBatchBuffer;
    $buffer->addItem('events', ['event_name' => 'signup']);
    $this->travel(601)->seconds();

    (new SendBatchToRanetraceJob('events', 10))->handle(app(RanetraceApiClient::class), $buffer, new RanetracePauseManager);

    $status = app(DashboardData::class)->collectStatus();

    expect($status['buffers']['features']['events'])->toBe(0)
        ->and($status['drain']['last_batch']['events'])->toBeInt()
        ->and($status['drain']['stalled'])->toBe([]);
})->with('unset batch values');

test('the dashboard names the app default store for an unset batch cache driver', function (?string $unsetValue): void {
    Config::set('ranetrace.batch.cache_driver', $unsetValue);

    $response = $this->get('/ranetrace');

    $response->assertOk();
    expect(dashboardPanelValue($response->getContent(), 'Cache driver'))->toBe("array (the app's default store)");
})->with('unset batch values');

test('ranetrace:status names the app default store for an unset batch cache driver', function (?string $unsetValue): void {
    Config::set('ranetrace.batch.cache_driver', $unsetValue);

    $this->artisan('ranetrace:status')
        ->expectsOutputToContain("Cache driver: array (the app's default store)")
        ->assertSuccessful();
})->with('unset batch values');

test('ranetrace:status --json reports the resolved store for an unset batch cache driver', function (?string $unsetValue): void {
    Config::set('ranetrace.batch.cache_driver', $unsetValue);

    $config = app(DashboardData::class)->collectStatus()['config'];

    expect($config['cache_driver'])->toBe('array')
        ->and($config['cache_driver_is_app_default'])->toBeTrue();
})->with('unset batch values');

test('ranetrace:status names the connection default queue for an unset batch queue name', function (?string $unsetValue): void {
    Config::set('ranetrace.batch.queue_name', $unsetValue);

    $this->artisan('ranetrace:status')
        ->expectsOutputToContain("Queue name: the connection's default queue")
        ->assertSuccessful();
})->with('unset batch values');

test('the dashboard names the connection default queue for an unset batch queue name', function (?string $unsetValue): void {
    Config::set('ranetrace.batch.queue_name', $unsetValue);

    expect(dashboardPanelValue($this->get('/ranetrace')->getContent(), 'Batch queue'))->toBe("the connection's default queue");
})->with('unset batch values');

test('a batch job with an unset queue name goes to the connection default queue', function (?string $unsetValue): void {
    Config::set('ranetrace.batch.queue_name', $unsetValue);

    expect((new SendBatchToRanetraceJob('events'))->queue)->toBeNull();
})->with('unset batch values');

test('the cache driver check flags the app default store when it is volatile', function (?string $unsetValue): void {
    Config::set('ranetrace.batch.cache_driver', $unsetValue);

    $result = (new CacheDriverCheck)->run(app(DashboardData::class)->collectStatus());

    expect($result->level)->toBe(CheckLevel::Warn)
        ->and($result->title)->toBe("Volatile cache driver \"array\" (the app's default store)");
})->with('unset batch values');

test('the cache driver check passes a durable app default store by its name', function (?string $unsetValue): void {
    Config::set('cache.default', 'file');
    Config::set('ranetrace.batch.cache_driver', $unsetValue);

    $result = (new CacheDriverCheck)->run(app(DashboardData::class)->collectStatus());

    expect($result->level)->toBe(CheckLevel::Pass)
        ->and($result->title)->toBe("Cache driver \"file\" (the app's default store) persists between requests");
})->with('unset batch values');

test('a batch cache driver set explicitly is used and named without the default note', function (): void {
    Config::set('cache.default', 'file');
    Config::set('ranetrace.batch.cache_driver', 'array');

    app(RanetraceBatchBuffer::class)->addItem('events', ['event_name' => 'signup']);
    $result = (new CacheDriverCheck)->run(app(DashboardData::class)->collectStatus());

    expect(Cache::store('array')->get('ranetrace:buffer:events'))->toHaveCount(1)
        ->and($result->level)->toBe(CheckLevel::Warn)
        ->and($result->title)->toBe('Volatile cache driver "array"');

    $this->artisan('ranetrace:status')
        ->expectsOutputToContain('Cache driver: array')
        ->doesntExpectOutputToContain('default store')
        ->assertSuccessful();
});

test('a batch queue name set explicitly is shown as it is', function (): void {
    Config::set('ranetrace.batch.queue_name', 'ranetrace');

    $this->artisan('ranetrace:status')
        ->expectsOutputToContain('Queue name: ranetrace')
        ->assertSuccessful();

    expect((new SendBatchToRanetraceJob('events'))->queue)->toBe('ranetrace');
});

test('only the batch config resolver reads the batch cache driver or a queue name from config', function (): void {
    $readers = [];

    foreach (File::allFiles(dirname(__DIR__, 2).'/src') as $file) {
        if (preg_match('/config\([^;\n]*(queue_name|batch\.cache_driver)/', $file->getContents()) === 1) {
            $readers[] = $file->getRelativePathname();
        }
    }

    expect($readers)->toBe(['Support/BatchConfig.php']);
});
