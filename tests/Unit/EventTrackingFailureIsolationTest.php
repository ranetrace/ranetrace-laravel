<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Ranetrace\Laravel\Ranetrace;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;

/**
 * trackEvent() must never throw from its capture body. This test does NOT fake
 * the queue/bus: with the queue disabled the job runs inline (sync), so a buffer
 * failure surfaces through trackEvent's own try/catch — which must swallow it.
 * (Event-name validation is intentionally loud and lives OUTSIDE the isolation;
 * that path is covered in RanetraceTest.)
 */
beforeEach(function (): void {
    Config::set('ranetrace.key', 'test-api-key');
    Config::set('ranetrace.enabled', true);
    Config::set('ranetrace.events.enabled', true);
    Config::set('ranetrace.events.queue', false);
});

test('trackEvent swallows a capture-body failure and never throws', function (): void {
    // Simulate the buffer write dying mid-capture. The sync queue runs the job
    // inline and re-throws, so the failure lands in trackEvent's try/catch.
    $this->mock(RanetraceBatchBuffer::class, function ($mock): void {
        $mock->shouldReceive('addItem')
            ->once()
            ->andThrow(new RuntimeException('buffer exploded mid-capture'));
    });

    // A VALID event name — validation is intentionally loud and outside the
    // isolation; the failure here is in the capture body and must be swallowed.
    expect(fn () => (new Ranetrace)->trackEvent('checkout_completed', ['k' => 'v']))
        ->not->toThrow(Throwable::class);
});
