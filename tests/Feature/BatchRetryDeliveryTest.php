<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;
use Ranetrace\Laravel\Services\RanetracePauseManager;
use Ranetrace\Php\Http\ResponsePolicy;

/*
 * These tests run the batch job through a real database queue rather than
 * calling handle() by hand, because what they guard lives in the framework's
 * queue plumbing: dispatch serializes the job, release() re-pushes that
 * serialized payload with a delay, and the ShouldBeUnique lock is held across
 * the release. Time moves with travel(), which the database queue reads too.
 */

beforeEach(function (): void {
    Config::set('database.default', 'testing');
    Config::set('queue.default', 'database');
    Config::set('queue.connections.database.connection', 'testing');

    $this->loadMigrationsFrom(dirname(__DIR__, 2).'/vendor/orchestra/testbench-core/laravel/migrations');
});

/**
 * Fake the API with a script of responses, one per request, falling back to a
 * full 200 once the script runs out. A script entry is either a status code,
 * or a list of event names the 200 reports as unprocessed.
 *
 * Every request is tallied per event name: `attempted` counts every post,
 * `accepted` only the posts the API answered 200 and processed the item.
 *
 * @param  list<int|list<string>>  $script
 * @return ArrayObject<string, array<string, int>>
 */
function fakeRanetraceEventsApi(array $script = []): ArrayObject
{
    $deliveries = new ArrayObject(['attempted' => [], 'accepted' => []]);

    Http::fake(function (Request $request) use (&$script, $deliveries) {
        $names = array_column($request->data()['events'] ?? [], 'event_name');
        $answer = array_shift($script) ?? [];

        $attempted = $deliveries['attempted'];

        foreach ($names as $name) {
            $attempted[$name] = ($attempted[$name] ?? 0) + 1;
        }

        $deliveries['attempted'] = $attempted;

        if (is_int($answer)) {
            return Http::response(['message' => 'Server error'], $answer);
        }

        $unprocessedIndexes = array_keys(array_intersect($names, $answer));
        $accepted = $deliveries['accepted'];

        foreach ($names as $index => $name) {
            if (! in_array($index, $unprocessedIndexes, true)) {
                $accepted[$name] = ($accepted[$name] ?? 0) + 1;
            }
        }

        $deliveries['accepted'] = $accepted;

        return Http::response([
            'items' => [
                'received' => count($names),
                'processed' => count($names) - count($unprocessedIndexes),
                'unprocessed' => count($unprocessedIndexes),
            ],
            'unprocessed_indexes' => $unprocessedIndexes,
        ], 200);
    });

    return $deliveries;
}

function runOneQueuedJob(): void
{
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);
}

function queuedJobCount(): int
{
    return DB::table('jobs')->count();
}

test('a transiently failed batch is sent exactly once across its retry and the next work run', function (): void {
    $deliveries = fakeRanetraceEventsApi([500]);
    $buffer = app(RanetraceBatchBuffer::class);

    foreach (['first', 'second', 'third'] as $name) {
        $buffer->addItem('events', ['event_name' => $name]);
    }

    Artisan::call('ranetrace:work');
    expect(queuedJobCount())->toBe(1);

    // The 500: the items go back to the buffer and the job is released for 60s.
    runOneQueuedJob();
    expect($deliveries['attempted'])->toBe(['first' => 1, 'second' => 1, 'third' => 1])
        ->and($deliveries['accepted'])->toBe([])
        ->and($buffer->count('events'))->toBe(3)
        ->and(queuedJobCount())->toBe(1);

    // A work run inside the retry gap, with a new item arriving: the unique
    // lock is still held by the released job, so nothing new is queued.
    $buffer->addItem('events', ['event_name' => 'fourth']);
    Artisan::call('ranetrace:work');
    expect(queuedJobCount())->toBe(1);

    // Before the backoff has passed the released job is not available yet.
    runOneQueuedJob();
    expect(queuedJobCount())->toBe(1)
        ->and($deliveries['accepted'])->toBe([]);

    $this->travel(61)->seconds();

    // The retry reads the buffer, which holds the re-buffered items and the
    // new one, and nothing else holds any of them.
    runOneQueuedJob();
    expect(queuedJobCount())->toBe(0)
        ->and($buffer->count('events'))->toBe(0);

    // The next work run finds nothing left to send.
    Artisan::call('ranetrace:work');
    runOneQueuedJob();

    // The lock was released with the successful attempt, so later items drain.
    $buffer->addItem('events', ['event_name' => 'fifth']);
    Artisan::call('ranetrace:work');
    expect(queuedJobCount())->toBe(1);
    runOneQueuedJob();

    expect($deliveries['accepted'])->toBe(['first' => 1, 'second' => 1, 'third' => 1, 'fourth' => 1, 'fifth' => 1])
        ->and($deliveries['attempted'])->toBe(['first' => 2, 'second' => 2, 'third' => 2, 'fourth' => 1, 'fifth' => 1])
        ->and($buffer->count('events'))->toBe(0)
        ->and(queuedJobCount())->toBe(0);
});

test('a batch that exhausts every retry is kept through the pause and sent exactly once after it lifts', function (): void {
    $deliveries = fakeRanetraceEventsApi([500, 500, 500, 500]);
    $buffer = app(RanetraceBatchBuffer::class);
    $pauseManager = app(RanetracePauseManager::class);

    foreach (['first', 'second'] as $name) {
        $buffer->addItem('events', ['event_name' => $name]);
    }

    Artisan::call('ranetrace:work');
    runOneQueuedJob();

    foreach ([60, 300, 900] as $backoff) {
        $this->travel($backoff + 1)->seconds();
        runOneQueuedJob();
    }

    expect($deliveries['attempted'])->toBe(['first' => 4, 'second' => 4])
        ->and($deliveries['accepted'])->toBe([])
        ->and($buffer->count('events'))->toBe(2)
        ->and($pauseManager->isFeaturePaused('events'))->toBeTrue()
        ->and(queuedJobCount())->toBe(0);

    // While paused, a work run sends nothing.
    Artisan::call('ranetrace:work');
    expect(queuedJobCount())->toBe(0);

    $this->travel(ResponsePolicy::PAUSE_SECONDS + 1)->seconds();

    // The unique lock went with the last attempt, so the drain can dispatch.
    Artisan::call('ranetrace:work');
    expect(queuedJobCount())->toBe(1);
    runOneQueuedJob();

    expect($deliveries['accepted'])->toBe(['first' => 1, 'second' => 1])
        ->and($buffer->count('events'))->toBe(0);
});

test('an item a retry leaves unprocessed is sent again once, and the processed ones are not', function (): void {
    $deliveries = fakeRanetraceEventsApi([500, ['second']]);
    $buffer = app(RanetraceBatchBuffer::class);

    foreach (['first', 'second', 'third'] as $name) {
        $buffer->addItem('events', ['event_name' => $name]);
    }

    Artisan::call('ranetrace:work');
    runOneQueuedJob();

    $this->travel(61)->seconds();
    runOneQueuedJob();

    expect($buffer->count('events'))->toBe(1);

    Artisan::call('ranetrace:work');
    runOneQueuedJob();

    expect($deliveries['accepted'])->toBe(['first' => 1, 'third' => 1, 'second' => 1])
        ->and($deliveries['attempted'])->toBe(['first' => 2, 'second' => 3, 'third' => 2])
        ->and($buffer->count('events'))->toBe(0);
});
