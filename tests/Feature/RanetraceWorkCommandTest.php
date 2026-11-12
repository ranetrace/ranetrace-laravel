<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Ranetrace\Laravel\Jobs\SendBatchToRanetraceJob;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;
use Ranetrace\Laravel\Services\RanetracePauseManager;

beforeEach(function (): void {
    Config::set('ranetrace.batch.cache_driver', 'array');
    Cache::store('array')->flush();
});

test('ranetrace:work rejects an unknown --type', function (): void {
    $this->artisan('ranetrace:work', ['--type' => 'bogus'])
        ->expectsOutputToContain('Unknown type: bogus')
        ->assertFailed();
});

test('ranetrace:work accepts a valid --type', function (): void {
    $this->artisan('ranetrace:work', ['--type' => 'errors'])
        ->assertSuccessful();
});

test('ranetrace:work fails cleanly when the cache backend is unavailable', function (): void {
    // Pause/buffer state lives in the cache; simulate that backend being down.
    $this->mock(RanetracePauseManager::class, function ($mock): void {
        $mock->shouldReceive('isGloballyPaused')->andThrow(new RuntimeException('cache backend unavailable'));
    });

    // The command must not let the raw exception escape: it logs and exits non-zero.
    $this->artisan('ranetrace:work')->assertFailed();
});

test('without an ingest key ranetrace:work dispatches nothing, names what waits in the buffer and fails', function (?string $key): void {
    Bus::fake();
    Config::set('ranetrace.key', $key);
    $buffer = app(RanetraceBatchBuffer::class);
    $buffer->addItem('events', ['event_name' => 'e1']);
    $buffer->addItems('errors', [['message' => 'a'], ['message' => 'b'], ['message' => 'c']]);

    $this->artisan('ranetrace:work')
        ->expectsOutput('RANETRACE_KEY is not set, so nothing is sent. Waiting in the buffer: errors (3), events (1).')
        ->doesntExpectOutputToContain('Dispatched')
        ->assertFailed();

    Bus::assertNotDispatched(SendBatchToRanetraceJob::class);

    expect($buffer->count('errors'))->toBe(3)
        ->and($buffer->count('events'))->toBe(1);
})->with([
    'null' => [null],
    'empty' => [''],
    'whitespace' => ["\t\n "],
]);

test('without an ingest key and with nothing buffered ranetrace:work stays quiet and succeeds', function (): void {
    Bus::fake();
    Config::set('ranetrace.key', '');

    $this->artisan('ranetrace:work')
        ->expectsOutput('No batches to send.')
        ->doesntExpectOutputToContain('RANETRACE_KEY')
        ->assertSuccessful();

    Bus::assertNotDispatched(SendBatchToRanetraceJob::class);
});

test('without an ingest key ranetrace:work --type looks at that type alone', function (string $type, ?string $line): void {
    Bus::fake();
    Config::set('ranetrace.key', '');
    $buffer = app(RanetraceBatchBuffer::class);
    $buffer->addItems('errors', [['message' => 'a'], ['message' => 'b'], ['message' => 'c']]);
    $buffer->addItem('events', ['event_name' => 'e1']);

    $run = $this->artisan('ranetrace:work', ['--type' => $type]);

    if ($line === null) {
        $run->expectsOutput('No batches to send.')->doesntExpectOutputToContain('RANETRACE_KEY')->assertSuccessful();
    } else {
        $run->expectsOutput($line)->assertFailed();
    }

    Bus::assertNotDispatched(SendBatchToRanetraceJob::class);
})->with([
    'a type that holds items' => ['events', 'RANETRACE_KEY is not set, so nothing is sent. Waiting in the buffer: events (1).'],
    'a type that holds none' => ['logs', null],
]);

test('without an ingest key ranetrace:work says so before it says anything about a pause', function (): void {
    Bus::fake();
    Config::set('ranetrace.key', '');
    $pauseManager = app(RanetracePauseManager::class);
    $pauseManager->setGlobalPause(900, '401');
    $pauseManager->setFeaturePause('errors', 900, '429');
    app(RanetraceBatchBuffer::class)->addItem('errors', ['message' => 'a']);

    $this->artisan('ranetrace:work')
        ->expectsOutput('RANETRACE_KEY is not set, so nothing is sent. Waiting in the buffer: errors (1).')
        ->doesntExpectOutputToContain('paused')
        ->assertFailed();

    Bus::assertNotDispatched(SendBatchToRanetraceJob::class);
});

test('without an ingest key ranetrace:work still refuses an unknown --type', function (): void {
    Config::set('ranetrace.key', '');

    $this->artisan('ranetrace:work', ['--type' => 'bogus'])
        ->expectsOutputToContain('Unknown type: bogus')
        ->doesntExpectOutputToContain('RANETRACE_KEY')
        ->assertFailed();
});

test('without an ingest key ranetrace:work fails cleanly when the buffer cannot be read', function (): void {
    Config::set('ranetrace.key', '');
    $this->mock(RanetraceBatchBuffer::class, function ($mock): void {
        $mock->shouldReceive('count')->andThrow(new RuntimeException('cache backend unavailable'));
    });

    $this->artisan('ranetrace:work')
        ->expectsOutputToContain('Ranetrace: ranetrace:work could not run.')
        ->assertFailed();
});

test('with an ingest key ranetrace:work dispatches one batch job per type that holds items', function (): void {
    Bus::fake();
    $buffer = app(RanetraceBatchBuffer::class);
    $buffer->addItem('errors', ['message' => 'a']);
    $buffer->addItem('events', ['event_name' => 'e1']);

    $this->artisan('ranetrace:work')
        ->expectsOutput('Dispatched batch job for errors: 1 items')
        ->expectsOutput('Dispatched batch job for events: 1 items')
        ->assertSuccessful();

    Bus::assertDispatchedTimes(SendBatchToRanetraceJob::class, 2);
});

test('the backend-unavailable error line speaks in two sentences, with no em-dash', function (): void {
    // The house writing rule keeps the dash out of anything the package says,
    // and this line is what an operator reads on a failed scheduled run.
    $this->mock(RanetracePauseManager::class, function ($mock): void {
        $mock->shouldReceive('isGloballyPaused')->andThrow(new RuntimeException('cache backend unavailable'));
    });

    $this->artisan('ranetrace:work')
        ->expectsOutputToContain('Ranetrace: ranetrace:work could not run. Is the cache/queue backend available? See the ranetrace_internal log.')
        ->doesntExpectOutputToContain("\u{2014}")
        ->assertFailed();
});
