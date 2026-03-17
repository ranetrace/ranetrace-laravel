<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Ranetrace\Laravel\Jobs\HandleEventJob;
use Ranetrace\Laravel\Jobs\SendBatchToRanetraceJob;
use Ranetrace\Laravel\Services\RanetraceApiClient;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;
use Ranetrace\Laravel\Services\RanetracePauseManager;

beforeEach(function (): void {
    Config::set('ranetrace.key', 'test-api-key');
    Config::set('ranetrace.batch.cache_driver', 'array');
    Config::set('ranetrace.batch.buffer_ttl', 3600);
    Config::set('ranetrace.batch.size', 100);

    Cache::store('array')->flush();
    Queue::fake();
    Http::fake();
});

test('events are added to buffer', function (): void {
    Config::set('ranetrace.events.enabled', true);
    Config::set('ranetrace.events.queue', true);

    $buffer = app(RanetraceBatchBuffer::class);

    $job = new HandleEventJob(['event_name' => 'test_event']);
    $job->handle($buffer);

    expect($buffer->count('events'))->toBe(1);
});

test('events are added to buffer without auto-dispatch', function (): void {
    Config::set('ranetrace.batch.events.size', 2);
    Cache::store('array')->flush();

    $buffer = app(RanetraceBatchBuffer::class);

    // Add first item
    $job1 = new HandleEventJob(['event_name' => 'event1']);
    $job1->handle($buffer);

    expect($buffer->count('events'))->toBe(1);
    Queue::assertNothingPushed();

    // Add second item - no auto-dispatch
    $job2 = new HandleEventJob(['event_name' => 'event2']);
    $job2->handle($buffer);

    expect($buffer->count('events'))->toBe(2);
    Queue::assertNothingPushed();
});

test('batch job sends multiple items in one request', function (): void {
    Http::fake([
        'api.ranetrace.com/*' => Http::response([
            'success' => true,
            'received' => 3,
            'processed' => 3,
        ], 200),
    ]);

    $buffer = app(RanetraceBatchBuffer::class);

    // Add items to buffer
    $buffer->addItem('events', ['event_name' => 'event1']);
    $buffer->addItem('events', ['event_name' => 'event2']);
    $buffer->addItem('events', ['event_name' => 'event3']);

    // Process batch
    $batchJob = new SendBatchToRanetraceJob('events', 10);
    $batchJob->handle(
        app(RanetraceApiClient::class),
        $buffer,
        app(RanetracePauseManager::class)
    );

    // Verify single API call was made with all items
    Http::assertSentCount(1);

    Http::assertSent(function ($request): bool {
        $body = json_decode($request->body(), true);

        return str_contains($request->url(), '/events/store')
            && isset($body['events'])
            && count($body['events']) === 3;
    });

    // Buffer should be cleared
    expect($buffer->count('events'))->toBe(0);
});

test('different types maintain separate buffers', function (): void {
    $buffer = app(RanetraceBatchBuffer::class);

    $eventJob = new HandleEventJob(['event_name' => 'test']);
    $eventJob->handle($buffer);

    $logJob = new Ranetrace\Laravel\Jobs\HandleLogJob(['message' => 'test log']);
    $logJob->handle($buffer);

    expect($buffer->count('events'))->toBe(1);
    expect($buffer->count('logs'))->toBe(1);
});

test('batch job respects max items limit', function (): void {
    Http::fake([
        'api.ranetrace.com/*' => Http::response([
            'success' => true,
            'received' => 5,
            'processed' => 5,
        ], 200),
    ]);

    $buffer = app(RanetraceBatchBuffer::class);

    // Add 10 items
    for ($i = 1; $i <= 10; $i++) {
        $buffer->addItem('events', ['event_name' => "event{$i}"]);
    }

    expect($buffer->count('events'))->toBe(10);

    // Process batch with limit of 5 (atomically removes 5 items)
    $batchJob = new SendBatchToRanetraceJob('events', 5);
    $batchJob->handle(
        app(RanetraceApiClient::class),
        $buffer,
        app(RanetracePauseManager::class)
    );

    // Only 5 should be sent
    Http::assertSent(function ($request): bool {
        $body = json_decode($request->body(), true);

        return isset($body['events']) && count($body['events']) === 5;
    });

    // 5 should remain in buffer (items were atomically removed before sending)
    expect($buffer->count('events'))->toBe(5);
});

test('empty buffer does not make api calls', function (): void {
    Http::fake();

    $buffer = app(RanetraceBatchBuffer::class);

    $batchJob = new SendBatchToRanetraceJob('events', 10);
    $batchJob->handle(
        app(RanetraceApiClient::class),
        $buffer,
        app(RanetracePauseManager::class)
    );

    Http::assertNothingSent();
});

test('failed batch items are re-added to buffer for retry', function (): void {
    // Override the Http fake from beforeEach with a failing response
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::fake([
        '*' => Http::response([
            'success' => false,
            'message' => 'Server error',
        ], 500),
    ]);

    $buffer = app(RanetraceBatchBuffer::class);

    // Add 3 items
    $buffer->addItem('events', ['event_name' => 'event1']);
    $buffer->addItem('events', ['event_name' => 'event2']);
    $buffer->addItem('events', ['event_name' => 'event3']);

    expect($buffer->count('events'))->toBe(3);

    // Try to process batch (will fail and re-add to buffer)
    $batchJob = new SendBatchToRanetraceJob('events', 10);

    $exceptionThrown = false;
    try {
        $batchJob->handle(
            app(RanetraceApiClient::class),
            $buffer,
            app(RanetracePauseManager::class)
        );
    } catch (Throwable $e) {
        $exceptionThrown = true;
    }

    expect($exceptionThrown)->toBeTrue('Exception should be thrown when API fails');

    // Items should be back in buffer for retry
    expect($buffer->count('events'))->toBe(3);
});
