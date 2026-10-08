<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Ranetrace\Laravel\Jobs\SendBatchToRanetraceJob;
use Ranetrace\Laravel\Services\RanetraceApiClient;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;
use Ranetrace\Laravel\Services\RanetracePauseManager;

beforeEach(function (): void {
    Config::set('ranetrace.key', 'test-api-key');
    Config::set('ranetrace.batch.cache_driver', 'array');
    Cache::store('array')->flush();
});

test('the job is configured for 4 total attempts (1 initial + 3 retries)', function (): void {
    $job = new SendBatchToRanetraceJob('events');

    expect($job->tries)->toBe(4);
});

test('the uniqueness lock spans the full retry envelope', function (): void {
    $job = new SendBatchToRanetraceJob('events');

    // backoff() sums to 1260s; uniqueFor must cover that plus per-attempt runtime.
    expect($job->uniqueFor)->toBeGreaterThanOrEqual(1260);
});

/**
 * Where a batch goes is the shared endpoint table's answer, so a type nothing
 * knows how to address is refused there rather than posted to a guess. It is a
 * programming error rather than a capture failure, so it throws and the job's
 * failed() hook takes the feature down for the contracted pause.
 */
test('a batch of an unknown type is refused by the endpoint table', function (): void {
    $buffer = app(RanetraceBatchBuffer::class);
    $buffer->addItem('page_views', ['anything' => true]);

    expect(fn () => (new SendBatchToRanetraceJob('page_views', 10))->handle(
        app(RanetraceApiClient::class),
        $buffer,
        app(RanetracePauseManager::class),
    ))->toThrow(InvalidArgumentException::class, 'Unknown batch type: page_views');
});

test('the pre-flight size guard trims an over-budget batch and returns the overflow', function (): void {
    $job = new SendBatchToRanetraceJob('errors');

    // 5 items × ~1MB each ≈ 5MB, over the ~4.5MB budget → some get deferred.
    $items = array_map(fn (int $i): array => [
        'id' => "id-{$i}",
        'data' => ['blob' => str_repeat('a', 1_000_000)],
        'timestamp' => 0,
    ], range(1, 5));

    $itemsProp = new ReflectionProperty($job, 'items');
    $itemsProp->setValue($job, $items);

    $deferred = (new ReflectionMethod($job, 'trimToByteBudget'))->invoke($job);
    $kept = $itemsProp->getValue($job);

    expect(count($deferred))->toBeGreaterThan(0)
        ->and(count($kept))->toBeGreaterThanOrEqual(1)
        ->and(count($kept) + count($deferred))->toBe(5);
});

test('the pre-flight size guard leaves an under-budget batch intact', function (): void {
    $job = new SendBatchToRanetraceJob('errors');

    $items = array_map(
        fn (int $i): array => ['id' => "id-{$i}", 'data' => ['n' => $i], 'timestamp' => 0],
        range(1, 10)
    );

    $itemsProp = new ReflectionProperty($job, 'items');
    $itemsProp->setValue($job, $items);

    $deferred = (new ReflectionMethod($job, 'trimToByteBudget'))->invoke($job);

    expect($deferred)->toBe([])
        ->and(count($itemsProp->getValue($job)))->toBe(10);
});

test('the pre-flight size guard drops an item it cannot encode and keeps at least one encodable item', function (): void {
    $job = new SendBatchToRanetraceJob('errors');

    $items = [
        ['id' => 'poison', 'data' => ['message' => 'poison', 'line' => NAN], 'timestamp' => 0],
        ['id' => 'big', 'data' => ['message' => 'big', 'blob' => str_repeat('x', 5_000_000)], 'timestamp' => 0],
        ['id' => 'next', 'data' => ['message' => 'next'], 'timestamp' => 0],
    ];

    $itemsProp = new ReflectionProperty($job, 'items');
    $itemsProp->setValue($job, $items);

    $deferred = (new ReflectionMethod($job, 'trimToByteBudget'))->invoke($job);

    expect(array_column($itemsProp->getValue($job), 'id'))->toBe(['big'])
        ->and(array_column($deferred, 'id'))->toBe(['next']);
});

test('a successful batch records the last-batch timestamp for the type', function (): void {
    Http::fake([
        'api.ranetrace.com/*' => Http::response(['success' => true], 200),
    ]);

    $buffer = app(RanetraceBatchBuffer::class);
    $buffer->addItem('events', ['event_name' => 'event1']);

    $job = new SendBatchToRanetraceJob('events', 10);
    $job->handle(
        app(RanetraceApiClient::class),
        $buffer,
        app(RanetracePauseManager::class)
    );

    expect(Cache::store('array')->get(SendBatchToRanetraceJob::LAST_BATCH_PREFIX.'events'))
        ->toBeInt();
});

test('an empty batch does not record a last-batch timestamp', function (): void {
    Http::fake();

    $buffer = app(RanetraceBatchBuffer::class);

    $job = new SendBatchToRanetraceJob('events', 10);
    $job->handle(
        app(RanetraceApiClient::class),
        $buffer,
        app(RanetracePauseManager::class)
    );

    expect(Cache::store('array')->get(SendBatchToRanetraceJob::LAST_BATCH_PREFIX.'events'))
        ->toBeNull();
});

// --- response/pause matrix ---

test('a 429 honors the Retry-After header for the pause duration', function (): void {
    $this->freezeTime();

    Http::fake([
        'api.ranetrace.com/*' => Http::response(['error' => ['message' => 'slow down']], 429, ['Retry-After' => '120']),
    ]);

    $buffer = app(RanetraceBatchBuffer::class);
    $buffer->addItem('events', ['event_name' => 'e1']);

    $pauseManager = app(RanetracePauseManager::class);
    (new SendBatchToRanetraceJob('events', 10))->handle(app(RanetraceApiClient::class), $buffer, $pauseManager);

    $pause = $pauseManager->getFeaturePause('events');

    expect($pause['reason'])->toBe('429')
        ->and(Carbon::parse($pause['paused_until'])->timestamp - now()->timestamp)->toBe(120)
        ->and($buffer->count('events'))->toBe(1); // re-buffered for after the pause
});

test('a 429 without Retry-After falls back to a 60-second pause, never 0', function (): void {
    $this->freezeTime();

    Http::fake([
        'api.ranetrace.com/*' => Http::response(['error' => ['message' => 'slow down']], 429),
    ]);

    $buffer = app(RanetraceBatchBuffer::class);
    $buffer->addItem('events', ['event_name' => 'e1']);

    $pauseManager = app(RanetracePauseManager::class);
    (new SendBatchToRanetraceJob('events', 10))->handle(app(RanetraceApiClient::class), $buffer, $pauseManager);

    $pause = $pauseManager->getFeaturePause('events');

    // Regression guard: an absent Retry-After previously produced a 0-second pause.
    expect(Carbon::parse($pause['paused_until'])->timestamp - now()->timestamp)->toBe(60)
        ->and($pauseManager->isFeaturePaused('events'))->toBeTrue();
});

test('a 401 sets a global pause and re-buffers the whole batch', function (): void {
    Http::fake([
        'api.ranetrace.com/*' => Http::response(['error' => ['message' => 'invalid key']], 401),
    ]);

    $buffer = app(RanetraceBatchBuffer::class);
    $buffer->addItem('events', ['event_name' => 'e1']);
    $buffer->addItem('events', ['event_name' => 'e2']);

    $pauseManager = app(RanetracePauseManager::class);
    (new SendBatchToRanetraceJob('events', 10))->handle(app(RanetraceApiClient::class), $buffer, $pauseManager);

    expect($pauseManager->isGloballyPaused())->toBeTrue()
        ->and($buffer->count('events'))->toBe(2); // entire batch returned to the buffer
});

test('a 422 pauses the feature and drops the malformed batch', function (): void {
    Http::fake([
        'api.ranetrace.com/*' => Http::response(['error' => ['message' => 'The events field is required.']], 422),
    ]);

    $buffer = app(RanetraceBatchBuffer::class);
    $buffer->addItem('events', ['event_name' => 'e1']);

    $pauseManager = app(RanetracePauseManager::class);
    (new SendBatchToRanetraceJob('events', 10))->handle(app(RanetraceApiClient::class), $buffer, $pauseManager);

    expect($pauseManager->isFeaturePaused('events'))->toBeTrue()
        ->and($buffer->count('events'))->toBe(0); // a malformed batch is NOT re-buffered
});

// --- putting a batch back ---

/**
 * Fake the events endpoint so that, while the batch is out, a new item is
 * captured into the buffer, and then answer with $answer: a status code, the
 * string "network" for a connection that never reached the API, or a list of
 * positions a 200 reports as unprocessed.
 *
 * @param  int|string|list<int>  $answer
 */
function fakeEventsApiCapturingDuringSend(int|string|array $answer): void
{
    Http::fake(['api.ranetrace.com/*' => function (Request $request) use ($answer) {
        app(RanetraceBatchBuffer::class)->addItem('events', ['event_name' => 'captured during the send']);

        if ($answer === 'network') {
            return (Http::failedConnection())($request);
        }

        if (is_int($answer)) {
            return Http::response(['message' => 'No'], $answer);
        }

        $received = count($request->data()['events']);

        return Http::response([
            'items' => [
                'received' => $received,
                'processed' => $received - count(array_unique($answer)),
                'unprocessed' => count(array_unique($answer)),
            ],
            'unprocessed_indexes' => $answer,
        ], 200);
    }]);
}

/**
 * @return list<array{id: string, data: array, timestamp: int}>
 */
function bufferedEvents(): array
{
    return Cache::store('array')->get('ranetrace:buffer:events', []);
}

function runEventsBatchJob(): void
{
    (new SendBatchToRanetraceJob('events', 10))->handle(
        app(RanetraceApiClient::class),
        app(RanetraceBatchBuffer::class),
        app(RanetracePauseManager::class),
    );
}

test('a batch put back after a send that did not deliver it keeps its envelopes, ahead of newer items', function (int|string|array $answer): void {
    $this->freezeTime();
    $buffer = app(RanetraceBatchBuffer::class);
    $buffer->addItems('events', [['event_name' => 'first'], ['event_name' => 'second']]);
    $captured = bufferedEvents();

    $this->travel(30)->minutes();
    fakeEventsApiCapturingDuringSend($answer);

    runEventsBatchJob();

    $buffered = bufferedEvents();

    expect(array_slice($buffered, 0, 2))->toBe($captured)
        ->and($buffered[2]['data']['event_name'])->toBe('captured during the send')
        ->and($buffered[2]['timestamp'])->toBe(now()->timestamp)
        ->and($buffer->oldestTimestamp('events'))->toBe(now()->subMinutes(30)->timestamp);
})->with([
    'a network error' => ['network'],
    'a server error' => [500],
    'an unexpected status' => [418],
    'a rejected key' => [401],
    'a rate limit' => [429],
    'items left unprocessed' => [[0, 1]],
]);

test('items deferred to keep the batch under the size limit keep their envelopes, ahead of newer items', function (): void {
    $this->freezeTime();
    $buffer = app(RanetraceBatchBuffer::class);
    $buffer->addItems('events', array_map(
        fn (int $i): array => ['event_name' => "e{$i}", 'blob' => str_repeat('a', 1_000_000)],
        range(1, 5),
    ));
    $captured = bufferedEvents();

    $this->travel(30)->minutes();
    fakeEventsApiCapturingDuringSend([]);

    runEventsBatchJob();

    $buffered = bufferedEvents();
    $deferredCount = count($buffered) - 1;

    expect($deferredCount)->toBeGreaterThan(0)
        ->and(array_slice($buffered, 0, $deferredCount))->toBe(array_slice($captured, -$deferredCount))
        ->and($buffered[$deferredCount]['data']['event_name'])->toBe('captured during the send');
});

test('a server error after deferring puts the sent items back ahead of the deferred ones', function (): void {
    $buffer = app(RanetraceBatchBuffer::class);
    $buffer->addItems('events', array_map(
        fn (int $i): array => ['event_name' => "e{$i}", 'blob' => str_repeat('a', 1_000_000)],
        range(1, 5),
    ));
    $captured = bufferedEvents();

    fakeEventsApiCapturingDuringSend(500);

    runEventsBatchJob();

    $buffered = bufferedEvents();

    expect(array_slice($buffered, 0, 5))->toBe($captured)
        ->and($buffered[5]['data']['event_name'])->toBe('captured during the send');
});

test('unprocessed positions that repeat or fall outside the batch put each named item back once, in batch order', function (): void {
    $buffer = app(RanetraceBatchBuffer::class);
    $buffer->addItems('events', [['event_name' => 'first'], ['event_name' => 'second'], ['event_name' => 'third']]);
    $captured = bufferedEvents();

    fakeEventsApiCapturingDuringSend([2, 0, 2, 7, -1]);

    runEventsBatchJob();

    $buffered = bufferedEvents();

    expect(array_slice($buffered, 0, 2))->toBe([$captured[0], $captured[2]])
        ->and(array_column(array_column($buffered, 'data'), 'event_name'))
        ->toBe(['first', 'third', 'captured during the send']);
});

test('on the sync queue, where a release puts nothing back on the queue, a failed batch stays buffered with its envelopes', function (): void {
    Config::set('queue.default', 'sync');
    $buffer = app(RanetraceBatchBuffer::class);
    $buffer->addItems('events', [['event_name' => 'first'], ['event_name' => 'second']]);
    $captured = bufferedEvents();

    fakeEventsApiCapturingDuringSend(500);

    SendBatchToRanetraceJob::dispatch('events', 10);

    expect(array_slice(bufferedEvents(), 0, 2))->toBe($captured)
        ->and(count(bufferedEvents()))->toBe(3);
});

test('a batch that fails while a crashed process holds the buffer lock is still put back whole', function (): void {
    Sleep::fake(syncWithCarbon: true);
    $buffer = app(RanetraceBatchBuffer::class);
    $buffer->addItems('events', [['event_name' => 'first'], ['event_name' => 'second']]);
    $captured = bufferedEvents();

    Http::fake(['api.ranetrace.com/*' => function () {
        Cache::store('array')->lock('ranetrace:buffer:events:lock', 10)->acquire();

        return Http::response(['message' => 'Server error'], 500);
    }]);

    runEventsBatchJob();

    expect(bufferedEvents())->toBe($captured);
});
