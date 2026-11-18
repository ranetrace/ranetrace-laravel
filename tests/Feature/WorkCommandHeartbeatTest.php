<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Ranetrace\Laravel\Jobs\SendBatchToRanetraceJob;
use Ranetrace\Laravel\Jobs\SendHeartbeatToRanetraceJob;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;
use Ranetrace\Laravel\Services\RanetracePauseManager;
use Ranetrace\Laravel\Support\InternalLogger;
use Ranetrace\Php\Http\Heartbeat;

test('a run with empty buffers dispatches one heartbeat on the batch queue', function (): void {
    Bus::fake();
    Config::set('ranetrace.batch.queue_name', 'ranetrace-batches');

    $this->artisan('ranetrace:work')
        ->expectsOutput('No batches to send.')
        ->expectsOutput('Dispatched heartbeat job.')
        ->assertSuccessful();

    Bus::assertDispatchedTimes(SendHeartbeatToRanetraceJob::class, 1);
    Bus::assertDispatched(SendHeartbeatToRanetraceJob::class, fn (SendHeartbeatToRanetraceJob $job): bool => $job->queue === 'ranetrace-batches');
});

test('without a batch queue name the heartbeat goes to the connection default queue', function (): void {
    Bus::fake();
    Config::set('ranetrace.batch.queue_name', null);

    $this->artisan('ranetrace:work')->assertSuccessful();

    Bus::assertDispatched(SendHeartbeatToRanetraceJob::class, fn (SendHeartbeatToRanetraceJob $job): bool => $job->queue === null);
});

test('the heartbeat follows a queue route that sends the batch job to another connection', function (): void {
    Bus::fake();
    useTwoQueueConnections();
    Queue::route(SendBatchToRanetraceJob::class, 'ranetrace', 'redis');

    $this->artisan('ranetrace:work')->assertSuccessful();

    Bus::assertDispatched(
        SendHeartbeatToRanetraceJob::class,
        fn (SendHeartbeatToRanetraceJob $job): bool => $job->connection === 'redis' && $job->queue === 'ranetrace',
    );
})->skip(withoutQueueRoutes(...), QUEUE_ROUTES_SKIP_REASON);

test('a run within the interval of the last heartbeat dispatches none, and the first run after it dispatches one', function (): void {
    $this->freezeTime();
    Bus::fake();

    $this->artisan('ranetrace:work')->assertSuccessful();
    $this->travel(Heartbeat::INTERVAL_SECONDS - 1)->seconds();
    $this->artisan('ranetrace:work')->doesntExpectOutput('Dispatched heartbeat job.')->assertSuccessful();

    Bus::assertDispatchedTimes(SendHeartbeatToRanetraceJob::class, 1);

    $this->travel(1)->seconds();
    $this->artisan('ranetrace:work')->expectsOutput('Dispatched heartbeat job.')->assertSuccessful();

    Bus::assertDispatchedTimes(SendHeartbeatToRanetraceJob::class, 2);
});

test('runs per type within one interval dispatch one heartbeat between them', function (): void {
    Bus::fake();

    foreach (RanetraceBatchBuffer::TYPES as $type) {
        $this->artisan('ranetrace:work', ['--type' => $type])->assertSuccessful();
    }

    Bus::assertDispatchedTimes(SendHeartbeatToRanetraceJob::class, 1);
});

test('without an ingest key no heartbeat is dispatched', function (?string $key): void {
    Bus::fake();
    Config::set('ranetrace.key', $key);

    $this->artisan('ranetrace:work')->assertSuccessful();

    Bus::assertNotDispatched(SendHeartbeatToRanetraceJob::class);
})->with('missing ingest keys');

test('under a global pause no heartbeat is dispatched', function (): void {
    Bus::fake();
    app(RanetracePauseManager::class)->setGlobalPause(900, '401');

    $this->artisan('ranetrace:work')->assertSuccessful();

    Bus::assertNotDispatched(SendHeartbeatToRanetraceJob::class);
});

test('with the master switch off no heartbeat is dispatched', function (mixed $enabled): void {
    Bus::fake();
    Config::set('ranetrace.enabled', $enabled);

    $this->artisan('ranetrace:work')->assertSuccessful();

    Bus::assertNotDispatched(SendHeartbeatToRanetraceJob::class);
})->with([
    'false' => [false],
    'an empty string from env()' => [''],
]);

test('a paused feature does not stop the heartbeat', function (): void {
    Bus::fake();
    app(RanetracePauseManager::class)->setFeaturePause('errors', 900, '429');
    app(RanetraceBatchBuffer::class)->addItem('errors', ['message' => 'a']);

    $this->artisan('ranetrace:work')->assertSuccessful();

    Bus::assertNotDispatched(SendBatchToRanetraceJob::class);
    Bus::assertDispatchedTimes(SendHeartbeatToRanetraceJob::class, 1);
});

test('a run for one type still dispatches the heartbeat', function (): void {
    Bus::fake();

    $this->artisan('ranetrace:work', ['--type' => 'errors'])->assertSuccessful();

    Bus::assertDispatchedTimes(SendHeartbeatToRanetraceJob::class, 1);
});

test('a run that dispatches a batch dispatches the heartbeat as well', function (): void {
    Bus::fake();
    app(RanetraceBatchBuffer::class)->addItem('events', ['event_name' => 'e1']);

    $this->artisan('ranetrace:work')
        ->expectsOutput('Dispatched batch job for events: 1 items')
        ->expectsOutput('Dispatched heartbeat job.')
        ->assertSuccessful();

    Bus::assertDispatchedTimes(SendBatchToRanetraceJob::class, 1);
    Bus::assertDispatchedTimes(SendHeartbeatToRanetraceJob::class, 1);
});

test('on the sync connection a heartbeat the API does not accept leaves the run successful and nothing paused', function (Closure $answer): void {
    $heartbeats = 0;
    Http::fake(['api.ranetrace.com/*' => function (Request $request) use ($answer, &$heartbeats) {
        $heartbeats += str_ends_with($request->url(), Heartbeat::PATH) ? 1 : 0;

        return $answer();
    }]);
    $pauseManager = app(RanetracePauseManager::class);

    $this->artisan('ranetrace:work')->assertSuccessful();

    expect($heartbeats)->toBe(1)
        ->and($pauseManager->getGlobalPause())->toBeNull();

    foreach (RanetraceBatchBuffer::TYPES as $type) {
        expect($pauseManager->getFeaturePause($type))->toBeNull();
    }
})->with([
    'a server error' => [fn () => Http::response(['message' => 'Server Error'], 500)],
    'no answer' => [fn () => throw new ConnectionException('Connection refused')],
]);

test('a heartbeat that cannot be dispatched leaves the run successful with its batches dispatched', function (): void {
    $dispatchedBatches = 0;
    Bus::shouldReceive('dispatch')
        ->with(Mockery::type(SendHeartbeatToRanetraceJob::class))
        ->once()
        ->andThrow(new RuntimeException('queue backend unavailable'));
    Bus::shouldReceive('dispatch')
        ->with(Mockery::type(SendBatchToRanetraceJob::class))
        ->andReturnUsing(function () use (&$dispatchedBatches): void {
            $dispatchedBatches++;
        });

    $buffer = app(RanetraceBatchBuffer::class);
    $buffer->addItem('errors', ['message' => 'a']);
    $buffer->addItem('events', ['event_name' => 'e1']);

    $this->artisan('ranetrace:work')
        ->expectsOutput('Dispatched 2 batch job(s).')
        ->doesntExpectOutput('Dispatched heartbeat job.')
        ->assertSuccessful();

    expect($dispatchedBatches)->toBe(2)
        ->and((string) file_get_contents(InternalLogger::todaysFile()))
        ->toContain('Heartbeat job could not be dispatched {"exception":"queue backend unavailable"}');
});

test('a heartbeat that could not be dispatched is not tried again within the interval', function (): void {
    $attempts = 0;
    Bus::shouldReceive('dispatch')
        ->with(Mockery::type(SendHeartbeatToRanetraceJob::class))
        ->andReturnUsing(function () use (&$attempts): never {
            $attempts++;

            throw new RuntimeException('queue backend unavailable');
        });

    $this->artisan('ranetrace:work')->assertSuccessful();
    $this->artisan('ranetrace:work')->assertSuccessful();

    expect($attempts)->toBe(1);
});

test('a cache store that cannot claim the heartbeat leaves the run successful with its batches dispatched', function (): void {
    Bus::fake();
    useBatchCacheStoreWhoseAddThrows();
    app(RanetraceBatchBuffer::class)->addItem('events', ['event_name' => 'e1']);

    $this->artisan('ranetrace:work')
        ->expectsOutput('Dispatched batch job for events: 1 items')
        ->assertSuccessful();

    Bus::assertDispatchedTimes(SendBatchToRanetraceJob::class, 1);
    Bus::assertNotDispatched(SendHeartbeatToRanetraceJob::class);
});
