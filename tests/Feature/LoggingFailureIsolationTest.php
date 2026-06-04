<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;

/**
 * The Monolog handler sits in the host application's Log::*() call path, so the
 * package's Core Rule is that it must NEVER throw back into the caller. These
 * tests deliberately do NOT fake the bus: the capture runs inline (queue
 * disabled → dispatchSync), so a failure mid-capture surfaces through the
 * handler's own try/catch — exactly the path being guarded.
 */
beforeEach(function (): void {
    config([
        'ranetrace.enabled' => true,
        'ranetrace.logging.enabled' => true,
        'ranetrace.logging.queue' => false,
        'logging.channels.ranetrace' => [
            'driver' => 'ranetrace',
            'level' => 'debug',
        ],
    ]);
});

test('the handler never throws into the host when capture fails', function (): void {
    // Simulate the cache backend dying mid-capture: the buffer write the job
    // performs blows up. dispatchSync propagates that exception straight back
    // into the handler's write(), so this exercises the real try/catch.
    $this->mock(RanetraceBatchBuffer::class, function ($mock): void {
        $mock->shouldReceive('addItem')
            ->once()
            ->andThrow(new RuntimeException('cache backend exploded'));
    });

    // The host's Log::error() call must return normally. If write()'s
    // try/catch were removed, this exception would escape and fail the test.
    expect(fn () => Log::channel('ranetrace')->error('trigger a capture failure'))
        ->not->toThrow(Throwable::class);
});
