<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
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
