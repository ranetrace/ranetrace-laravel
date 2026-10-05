<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Ranetrace\Laravel\Jobs\SendBatchToRanetraceJob;
use Ranetrace\Laravel\Services\RanetraceApiClient;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;
use Ranetrace\Laravel\Services\RanetracePauseManager;

/*
 * A float JSON has no spelling for (INF, -INF, NAN) reaches a log record's
 * context through Monolog, which takes anything. The cache buffer does not
 * encode, so such an item used to get in, fail the JSON encode of its whole
 * batch at send, and hold up to a thousand good items through the retries and
 * the pause that followed. These tests follow it from the log call to the
 * request body, and pin that an item already in the buffer that cannot be
 * encoded is dropped at send rather than holding up its batch.
 */

beforeEach(function (): void {
    Config::set('ranetrace.key', 'test-api-key');
    Config::set('ranetrace.batch.cache_driver', 'array');
    Config::set('ranetrace.logging.enabled', true);
    Config::set('ranetrace.logging.queue', false);
    Config::set('logging.channels.ranetrace', ['driver' => 'ranetrace', 'level' => 'debug']);

    Cache::store('array')->flush();

    Http::fake(['*' => Http::response(['success' => true], 200)]);
});

function sendLogsBatch(): void
{
    (new SendBatchToRanetraceJob('logs', 100))->handle(
        app(RanetraceApiClient::class),
        app(RanetraceBatchBuffer::class),
        app(RanetracePauseManager::class),
    );
}

/**
 * @return list<array<string, mixed>>
 */
function sentLogs(): array
{
    $logs = [];

    Http::assertSent(function (Request $request) use (&$logs): bool {
        $logs = $request->data()['logs'] ?? [];

        return true;
    });

    return $logs;
}

test('a log record with INF in its context is sent in one request beside a valid one, carrying the string INF', function (): void {
    Log::channel('ranetrace')->error('Conversion ratio computed', ['ratio' => INF, 'nested' => ['drift' => -INF]]);
    Log::channel('ranetrace')->error('A valid record', ['ratio' => 0.5]);

    sendLogsBatch();

    $logs = sentLogs();

    Http::assertSentCount(1);

    expect(array_column($logs, 'message'))->toBe(['Conversion ratio computed', 'A valid record'])
        ->and($logs[0]['context'])->toBe(['ratio' => 'INF', 'nested' => ['drift' => '-INF']])
        ->and($logs[1]['context'])->toBe(['ratio' => 0.5])
        ->and(app(RanetraceBatchBuffer::class)->count('logs'))->toBe(0)
        ->and(app(RanetracePauseManager::class)->isFeaturePaused('logs'))->toBeFalse();
});

test('an unencodable item already in the buffer is dropped at send while the good items in its batch are delivered', function (): void {
    $logger = Mockery::spy(LoggerInterface::class);
    Log::shouldReceive('channel')->with('ranetrace_internal')->andReturn($logger);

    $buffer = app(RanetraceBatchBuffer::class);

    // What an older version buffered: the cache store keeps the float as it is.
    $buffer->addItem('logs', ['level' => 'error', 'message' => 'before', 'channel' => 'app', 'timestamp' => '2026-10-05T10:00:00+00:00']);
    $buffer->addItem('logs', ['level' => 'error', 'message' => 'poison', 'channel' => 'app', 'timestamp' => '2026-10-05T10:00:00+00:00', 'context' => ['ratio' => NAN]]);
    $buffer->addItem('logs', ['level' => 'error', 'message' => 'after', 'channel' => 'app', 'timestamp' => '2026-10-05T10:00:00+00:00']);

    sendLogsBatch();

    Http::assertSentCount(1);

    expect(array_column(sentLogs(), 'message'))->toBe(['before', 'after'])
        ->and($buffer->count('logs'))->toBe(0)
        ->and(app(RanetracePauseManager::class)->isFeaturePaused('logs'))->toBeFalse();

    $logger->shouldHaveReceived('error')->withArgs(
        fn (string $message, array $context): bool => $message === 'Dropped items that could not be encoded as JSON'
            && $context === ['type' => 'logs', 'dropped' => 1]
    );
});

test('a batch of nothing but unencodable items sends nothing and pauses nothing', function (): void {
    $buffer = app(RanetraceBatchBuffer::class);
    $buffer->addItem('logs', ['message' => 'poison', 'context' => ['ratio' => INF]]);

    sendLogsBatch();

    Http::assertNothingSent();

    expect($buffer->count('logs'))->toBe(0)
        ->and(app(RanetracePauseManager::class)->isFeaturePaused('logs'))->toBeFalse();
});
