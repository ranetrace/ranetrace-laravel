<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Ranetrace\Laravel\Jobs\SendHeartbeatToRanetraceJob;
use Ranetrace\Laravel\Services\RanetraceApiClient;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;
use Ranetrace\Laravel\Services\RanetracePauseManager;
use Ranetrace\Laravel\Support\BatchConfig;
use Ranetrace\Laravel\Support\InternalLogger;
use Ranetrace\Php\Http\Heartbeat;

beforeEach(function (): void {
    Config::set('ranetrace.key', 'test-api-key');
    Config::set('ranetrace.batch.cache_driver', 'array');
    Cache::store('array')->flush();
});

function handleHeartbeatJob(): void
{
    (new SendHeartbeatToRanetraceJob)->handle(app(RanetraceApiClient::class));
}

/**
 * How many lines of today's internal log contain $message.
 */
function internalLogLinesContaining(string $message): int
{
    $file = InternalLogger::todaysFile();

    if (! is_file($file)) {
        return 0;
    }

    return count(array_filter(
        explode("\n", (string) file_get_contents($file)),
        static fn (string $line): bool => str_contains($line, $message),
    ));
}

test('the heartbeat posts the shared core body for this package to the heartbeat endpoint', function (): void {
    Http::fake(['api.ranetrace.com/*' => Http::response(['success' => true], 200)]);

    handleHeartbeatJob();

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        expect($request->method())->toBe('POST')
            ->and($request->url())->toBe('https://api.ranetrace.com/v1/heartbeat')
            ->and($body)->toBe(Heartbeat::forPackage('Laravel', 'ranetrace/ranetrace-laravel')->body())
            ->and($body['sdk'])->toBe('laravel')
            ->and($body['sdk_version'])->toBeString()->not->toBe('')
            ->and(mb_strlen($body['sdk_version']))->toBeLessThanOrEqual(50);

        return true;
    });
});

test('an accepted heartbeat stamps when it was sent', function (): void {
    $this->freezeTime();
    Http::fake(['api.ranetrace.com/*' => Http::response(['success' => true], 200)]);

    handleHeartbeatJob();

    expect(BatchConfig::cacheStore()->get(SendHeartbeatToRanetraceJob::LAST_SENT_KEY))->toBe(now()->timestamp);
});

test('a heartbeat that is not accepted changes nothing and leaves one line in the internal log', function (Closure $answer): void {
    $attempts = 0;
    Http::fake(['api.ranetrace.com/*' => function () use ($answer, &$attempts) {
        $attempts++;

        return $answer();
    }]);
    $pauseManager = app(RanetracePauseManager::class);
    $buffer = app(RanetraceBatchBuffer::class);

    handleHeartbeatJob();

    expect($attempts)->toBe(1)
        ->and($pauseManager->getGlobalPause())->toBeNull()
        ->and(BatchConfig::cacheStore()->get(SendHeartbeatToRanetraceJob::LAST_SENT_KEY))->toBeNull()
        ->and(internalLogLinesContaining('Heartbeat was not accepted'))->toBe(1);

    foreach (RanetraceBatchBuffer::TYPES as $type) {
        expect($pauseManager->getFeaturePause($type))->toBeNull()
            ->and($buffer->count($type))->toBe(0);
    }
})->with([
    'a rejected key' => [fn () => Http::response(['success' => false, 'error' => ['message' => 'Unauthenticated.']], 401)],
    'a malformed body' => [fn () => Http::response(['success' => false, 'error' => ['message' => 'The sdk field is required.']], 422)],
    'a rate limit' => [fn () => Http::response(['success' => false, 'error' => ['message' => 'Too Many Attempts.']], 429, ['Retry-After' => '60'])],
    'a server error' => [fn () => Http::response(['message' => 'Server Error'], 500)],
    'no answer' => [fn () => throw new ConnectionException('Connection refused')],
]);

test('the internal log line of a heartbeat that is not accepted names the status and the message', function (): void {
    Http::fake(['api.ranetrace.com/*' => Http::response(['success' => false, 'error' => ['message' => 'Unauthenticated.']], 401)]);

    handleHeartbeatJob();

    expect((string) file_get_contents(InternalLogger::todaysFile()))
        ->toContain('Heartbeat was not accepted {"status":401,"message":"Unauthenticated."}');
});

test('heartbeat jobs handed over together send one request, and one a minute later sends again', function (): void {
    $this->freezeTime();
    Http::fake(['api.ranetrace.com/*' => Http::response(['success' => true], 200)]);

    handleHeartbeatJob();
    $this->travel(SendHeartbeatToRanetraceJob::SEND_CLAIM_SECONDS - 1)->seconds();
    handleHeartbeatJob();

    Http::assertSentCount(1);

    $this->travel(2)->seconds();
    handleHeartbeatJob();

    Http::assertSentCount(2);
});

test('without an ingest key the heartbeat sends nothing and throws nothing', function (?string $key): void {
    Http::fake();
    Config::set('ranetrace.key', $key);

    handleHeartbeatJob();

    Http::assertNothingSent();
    expect(BatchConfig::cacheStore()->get(SendHeartbeatToRanetraceJob::LAST_SENT_KEY))->toBeNull();
})->with([
    'null' => [null],
    'empty' => [''],
    'whitespace' => ["\t\n "],
]);

test('a heartbeat whose cache store cannot be written sends nothing, throws nothing and logs the failure', function (): void {
    Http::fake();
    useBatchCacheStoreWhoseAddThrows();

    handleHeartbeatJob();

    Http::assertNothingSent();
    expect(internalLogLinesContaining('Heartbeat failed {"exception":"cache backend unavailable"}'))->toBe(1);
});

test('a heartbeat job the queue worker ends leaves one warning in the internal log', function (): void {
    (new SendHeartbeatToRanetraceJob)->failed(new RuntimeException('timed out'));

    expect(internalLogLinesContaining('timed out'))->toBe(1);
});

test('the heartbeat job is tried once', function (): void {
    expect((new SendHeartbeatToRanetraceJob)->tries)->toBe(1);
});
