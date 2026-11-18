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
        $mock->shouldReceive('getGlobalPause')->andThrow(new RuntimeException('cache backend unavailable'));
    });

    // The command must not let the raw exception escape: it logs and exits non-zero.
    $this->artisan('ranetrace:work')->assertFailed();
});

test('ranetrace:work reports a pause from the one read that found it active', function (string $method, array $arguments, string $line): void {
    // The entry's cache lifetime is the pause's end, so it can expire between
    // two reads. Read twice, the second read finds nothing to report.
    Bus::fake();
    Config::set('ranetrace.key', 'test-key');
    app(RanetraceBatchBuffer::class)->addItem('events', ['event_name' => 'e1']);
    $pause = ['paused_until' => now()->addMinutes(15)->toIso8601String(), 'reason' => '429'];

    $this->mock(RanetracePauseManager::class, function ($mock) use ($method, $arguments, $pause): void {
        $mock->shouldReceive('getGlobalPause')->andReturnNull()->byDefault();
        $mock->shouldReceive($method)->with(...$arguments)->once()->andReturn($pause);
        $mock->shouldReceive('isActive')->passthru();
    });

    $this->artisan('ranetrace:work')
        ->expectsOutputToContain(sprintf($line, $pause['paused_until']))
        ->assertSuccessful();

    Bus::assertNotDispatched(SendBatchToRanetraceJob::class);
})->with([
    'global' => ['getGlobalPause', [], 'Ranetrace is globally paused until %s (reason: 429)'],
    'feature' => ['getFeaturePause', ['events'], "Feature 'events' is paused until %s (reason: 429)"],
]);

test('ranetrace:work sends past a pause entry that does not say when it ends', function (mixed $entry): void {
    // The pause store only writes well-formed entries, so this takes one
    // another writer or a damaged cache left behind. Read as a pause, or as a
    // reason to stop, it would hold every run back until someone clears it.
    Bus::fake();
    Config::set('ranetrace.key', 'test-key');
    Cache::store('array')->forever('ranetrace.global.pause', $entry);
    Cache::store('array')->forever('ranetrace.feature.events.pause', $entry);
    app(RanetraceBatchBuffer::class)->addItem('events', ['event_name' => 'e1']);

    $this->artisan('ranetrace:work')
        ->expectsOutputToContain('Dispatched batch job for events: 1 items')
        ->assertSuccessful();

    Bus::assertDispatched(SendBatchToRanetraceJob::class);
})->with([
    'not an array' => ['paused'],
    'an array without paused_until' => [['reason' => '429']],
    'a paused_until that is no date' => [['paused_until' => 'not a date', 'reason' => '429']],
]);

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
})->with('missing ingest keys');

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
        $mock->shouldReceive('getGlobalPause')->andThrow(new RuntimeException('cache backend unavailable'));
    });

    $this->artisan('ranetrace:work')
        ->expectsOutputToContain('Ranetrace: ranetrace:work could not run. Is the cache/queue backend available? See the ranetrace_internal log.')
        ->doesntExpectOutputToContain("\u{2014}")
        ->assertFailed();
});
