<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;

beforeEach(function (): void {
    Config::set('ranetrace.batch.cache_driver', 'array');
    Config::set('ranetrace.batch.buffer_ttl', 3600);
    Config::set('ranetrace.batch.max_buffer_size', 1000);

    // Clear cache before each test
    Cache::store('array')->flush();
});

test('it can add items to the buffer', function (): void {
    $buffer = new RanetraceBatchBuffer;

    $buffer->addItem('events', ['event_name' => 'test_event']);

    expect($buffer->count('events'))->toBe(1);
});

test('it can retrieve items from the buffer and atomically removes them', function (): void {
    $buffer = new RanetraceBatchBuffer;

    $buffer->addItem('events', ['event_name' => 'event1']);
    $buffer->addItem('events', ['event_name' => 'event2']);
    $buffer->addItem('events', ['event_name' => 'event3']);

    expect($buffer->count('events'))->toBe(3);

    $items = $buffer->getItems('events', 2);

    expect($items)->toHaveCount(2);
    expect($items[0]['data']['event_name'])->toBe('event1');
    expect($items[1]['data']['event_name'])->toBe('event2');

    // Items should be removed from buffer after retrieval
    expect($buffer->count('events'))->toBe(1);
});

test('it can count items in the buffer', function (): void {
    $buffer = new RanetraceBatchBuffer;

    expect($buffer->count('events'))->toBe(0);

    $buffer->addItem('events', ['event_name' => 'event1']);
    $buffer->addItem('events', ['event_name' => 'event2']);

    expect($buffer->count('events'))->toBe(2);
});

test('oldestTimestamp returns null for an empty buffer', function (): void {
    expect((new RanetraceBatchBuffer)->oldestTimestamp('events'))->toBeNull();
});

test('oldestTimestamp returns the first (oldest) buffered item timestamp', function (): void {
    $buffer = new RanetraceBatchBuffer;

    $this->freezeTime();
    $oldest = now()->timestamp;
    $buffer->addItem('events', ['event_name' => 'first']);

    $this->travel(30)->seconds();
    $buffer->addItem('events', ['event_name' => 'second']);

    expect($buffer->oldestTimestamp('events'))->toBe($oldest);

    // Draining the oldest item promotes the next one to oldest (FIFO).
    $buffer->getItems('events', 1);
    expect($buffer->oldestTimestamp('events'))->toBe($oldest + 30);
});

test('it can clear all items for a type', function (): void {
    $buffer = new RanetraceBatchBuffer;

    $buffer->addItem('events', ['event_name' => 'event1']);
    $buffer->addItem('events', ['event_name' => 'event2']);
    $buffer->addItem('logs', ['message' => 'log1']);

    $buffer->clear('events');

    expect($buffer->count('events'))->toBe(0);
    expect($buffer->count('logs'))->toBe(1);
});

test('it maintains separate buffers for different types', function (): void {
    $buffer = new RanetraceBatchBuffer;

    $buffer->addItem('events', ['event_name' => 'event1']);
    $buffer->addItem('logs', ['message' => 'log1']);
    $buffer->addItem('page_visits', ['url' => 'https://example.com']);

    expect($buffer->count('events'))->toBe(1);
    expect($buffer->count('logs'))->toBe(1);
    expect($buffer->count('page_visits'))->toBe(1);
});

test('it assigns unique ids to each item', function (): void {
    $buffer = new RanetraceBatchBuffer;

    $buffer->addItem('events', ['event_name' => 'event1']);
    $buffer->addItem('events', ['event_name' => 'event2']);

    expect($buffer->count('events'))->toBe(2);

    $items = $buffer->getItems('events', 10);

    expect($items)->toHaveCount(2);
    expect($items[0]['id'])->not()->toBe($items[1]['id']);
    expect($items[0]['id'])->toBeString();
    expect($items[1]['id'])->toBeString();

    // After retrieval, buffer should be empty
    expect($buffer->count('events'))->toBe(0);
});

test('it includes timestamp with each item', function (): void {
    $buffer = new RanetraceBatchBuffer;

    $buffer->addItem('events', ['event_name' => 'event1']);

    expect($buffer->count('events'))->toBe(1);

    $items = $buffer->getItems('events', 10);

    expect($items)->toHaveCount(1);
    expect($items[0])->toHaveKey('timestamp');
    expect($items[0]['timestamp'])->toBeInt();
    expect($items[0]['timestamp'])->toBeLessThanOrEqual(now()->timestamp);

    // After retrieval, buffer should be empty
    expect($buffer->count('events'))->toBe(0);
});

test('it enforces max buffer size', function (): void {
    Config::set('ranetrace.batch.max_buffer_size', 3);
    $buffer = new RanetraceBatchBuffer;

    // Add 5 items, but only 3 should remain (most recent)
    $buffer->addItem('events', ['event_name' => 'event1']);
    $buffer->addItem('events', ['event_name' => 'event2']);
    $buffer->addItem('events', ['event_name' => 'event3']);
    $buffer->addItem('events', ['event_name' => 'event4']);
    $buffer->addItem('events', ['event_name' => 'event5']);

    expect($buffer->count('events'))->toBe(3);

    $items = $buffer->getItems('events', 10);

    // Should keep the most recent items (3, 4, 5)
    expect($items)->toHaveCount(3);
    expect($items[0]['data']['event_name'])->toBe('event3');
    expect($items[1]['data']['event_name'])->toBe('event4');
    expect($items[2]['data']['event_name'])->toBe('event5');

    // After retrieval, buffer should be empty
    expect($buffer->count('events'))->toBe(0);
});

test('it returns available types that have items', function (): void {
    $buffer = new RanetraceBatchBuffer;

    $buffer->addItem('events', ['event_name' => 'event1']);
    $buffer->addItem('logs', ['message' => 'log1']);

    $availableTypes = $buffer->getAvailableTypes();

    expect($availableTypes)->toContain('events');
    expect($availableTypes)->toContain('logs');
    expect($availableTypes)->not()->toContain('page_visits');
    expect($availableTypes)->not()->toContain('javascript_errors');
});

test('it returns empty array when no types have items', function (): void {
    $buffer = new RanetraceBatchBuffer;

    $availableTypes = $buffer->getAvailableTypes();

    expect($availableTypes)->toBeArray();
    expect($availableTypes)->toBeEmpty();
});

test('getAvailableTypes includes errors when the errors buffer has items', function (): void {
    $buffer = new RanetraceBatchBuffer;

    $buffer->addItem('errors', ['message' => 'boom']);

    expect($buffer->getAvailableTypes())->toContain('errors');
});

test('addItems adds multiple items in a single call', function (): void {
    $buffer = new RanetraceBatchBuffer;

    $buffer->addItems('events', [
        ['event_name' => 'event1'],
        ['event_name' => 'event2'],
        ['event_name' => 'event3'],
    ]);

    expect($buffer->count('events'))->toBe(3);

    $items = $buffer->getItems('events', 10);
    expect($items[0]['data']['event_name'])->toBe('event1');
    expect($items[2]['data']['event_name'])->toBe('event3');
});

test('addItems with an empty array is a no-op', function (): void {
    $buffer = new RanetraceBatchBuffer;

    $buffer->addItems('events', []);

    expect($buffer->count('events'))->toBe(0);
});

test('addItem returns true when the item is buffered', function (): void {
    expect((new RanetraceBatchBuffer)->addItem('events', ['event_name' => 'ok']))->toBeTrue();
});

test('addItems returns true when the items are buffered', function (): void {
    expect((new RanetraceBatchBuffer)->addItems('events', [['event_name' => 'ok']]))->toBeTrue();
});

test('addItems returns false and adds nothing when the cache lock cannot be acquired', function (): void {
    // 0s wait makes block() give up on the first failed acquire, with no real sleep.
    Config::set('ranetrace.batch.lock_wait', 0);
    $buffer = new RanetraceBatchBuffer;

    // Hold the buffer's lock so the add cannot acquire it.
    $lock = Cache::store('array')->lock('ranetrace:buffer:events:lock', 10);
    expect($lock->acquire())->toBeTrue();

    expect($buffer->addItems('events', [['event_name' => 'contended']]))->toBeFalse();
    expect($buffer->count('events'))->toBe(0);

    $lock->release();
});

test('getItems returns an empty array when the cache lock cannot be acquired', function (): void {
    Config::set('ranetrace.batch.lock_wait', 0);
    $buffer = new RanetraceBatchBuffer;

    // Seed an item with the lock free, then hold the lock and try to drain.
    $buffer->addItem('events', ['event_name' => 'kept']);

    $lock = Cache::store('array')->lock('ranetrace:buffer:events:lock', 10);
    expect($lock->acquire())->toBeTrue();

    expect($buffer->getItems('events', 10))->toBe([]);

    $lock->release();

    // The item was left untouched for the next drain.
    expect($buffer->count('events'))->toBe(1);
});

test('buffer overflow sets the overflow flag', function (): void {
    Config::set('ranetrace.batch.max_buffer_size', 3);
    $buffer = new RanetraceBatchBuffer;

    for ($i = 1; $i <= 5; $i++) {
        $buffer->addItem('events', ['event_name' => "event{$i}"]);
    }

    expect($buffer->count('events'))->toBe(3);
    expect(Cache::store('array')->get('ranetrace:buffer:events:overflow'))->toBeTrue();
});

test('draining the buffer below capacity clears the overflow flag', function (): void {
    Config::set('ranetrace.batch.max_buffer_size', 3);
    $buffer = new RanetraceBatchBuffer;

    for ($i = 1; $i <= 5; $i++) {
        $buffer->addItem('events', ['event_name' => "event{$i}"]);
    }
    expect(Cache::store('array')->get('ranetrace:buffer:events:overflow'))->toBeTrue();

    $buffer->getItems('events', 10);

    expect(Cache::store('array')->get('ranetrace:buffer:events:overflow'))->toBeNull();
});

test('the overflow warning speaks in one comma-joined sentence, with no em-dash', function (): void {
    // The house writing rule keeps the dash out of anything the package says,
    // and this warning is what an operator reads in the internal log tail.
    Config::set('ranetrace.batch.max_buffer_size', 3);

    $logged = [];
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('warning')->andReturnUsing(function (string $message) use (&$logged): void {
        $logged[] = $message;
    });
    Log::shouldReceive('channel')->with('ranetrace_internal')->andReturn($logger);

    $buffer = new RanetraceBatchBuffer;
    for ($i = 1; $i <= 5; $i++) {
        $buffer->addItem('events', ['event_name' => "event{$i}"]);
    }

    expect($logged)->toContain('Ranetrace buffer overflow, oldest items dropped');

    foreach ($logged as $message) {
        expect($message)->not->toContain("\u{2014}");
    }
});

// --- returning taken items ---

test('a returned item still reports its capture time as the oldest timestamp', function (): void {
    $buffer = new RanetraceBatchBuffer;

    $this->freezeTime();
    $capturedAt = now()->timestamp;
    $buffer->addItem('events', ['event_name' => 'stuck']);

    $this->travel(30)->minutes();
    $buffer->returnItems('events', $buffer->getItems('events', 10));

    expect($buffer->oldestTimestamp('events'))->toBe($capturedAt);
});

test('returned items keep their envelopes and go ahead of an item captured while they were out, in their original order', function (): void {
    $buffer = new RanetraceBatchBuffer;

    $buffer->addItems('events', [['event_name' => 'first'], ['event_name' => 'second']]);
    $taken = $buffer->getItems('events', 10);

    $buffer->addItem('events', ['event_name' => 'captured during the send']);
    $buffer->returnItems('events', $taken);

    $items = $buffer->getItems('events', 10);

    expect(array_slice($items, 0, 2))->toBe($taken)
        ->and(array_column(array_column($items, 'data'), 'event_name'))
        ->toBe(['first', 'second', 'captured during the send']);
});

test('returning items into a full buffer drops the returned items first and flags the overflow', function (): void {
    Config::set('ranetrace.batch.max_buffer_size', 3);
    $buffer = new RanetraceBatchBuffer;

    $buffer->addItems('events', [['event_name' => 'a'], ['event_name' => 'b'], ['event_name' => 'c']]);
    $taken = $buffer->getItems('events', 2);
    $buffer->addItems('events', [['event_name' => 'd'], ['event_name' => 'e']]);

    $buffer->returnItems('events', $taken);

    expect(Cache::store('array')->get('ranetrace:buffer:events:overflow'))->toBeTrue()
        ->and(array_column(array_column($buffer->getItems('events', 10), 'data'), 'event_name'))->toBe(['c', 'd', 'e']);
});

test('a returned list larger than the buffer keeps its newest items', function (): void {
    Config::set('ranetrace.batch.max_buffer_size', 5);
    $buffer = new RanetraceBatchBuffer;

    $buffer->addItems('events', array_map(fn (int $i): array => ['event_name' => "e{$i}"], range(1, 5)));
    $taken = $buffer->getItems('events', 10);

    Config::set('ranetrace.batch.max_buffer_size', 3);
    $buffer->returnItems('events', $taken);

    expect($buffer->getItems('events', 10))->toBe(array_slice($taken, 2));
});

test('returning an empty list leaves the buffer as it was', function (): void {
    $buffer = new RanetraceBatchBuffer;
    $buffer->addItem('events', ['event_name' => 'kept']);
    $before = Cache::store('array')->get('ranetrace:buffer:events');

    $buffer->returnItems('events', []);

    expect(Cache::store('array')->get('ranetrace:buffer:events'))->toBe($before);
});

test('a returned item captured longer ago than the buffer ttl is kept for a full ttl from its return', function (): void {
    Config::set('ranetrace.batch.buffer_ttl', 3600);
    $buffer = new RanetraceBatchBuffer;

    $this->freezeTime();
    $capturedAt = now()->timestamp;
    $buffer->addItem('events', ['event_name' => 'old']);
    $taken = $buffer->getItems('events', 10);

    $this->travel(2)->hours();
    $buffer->returnItems('events', $taken);

    $this->travel(59)->minutes();

    expect($buffer->count('events'))->toBe(1)
        ->and($buffer->oldestTimestamp('events'))->toBe($capturedAt);
});
