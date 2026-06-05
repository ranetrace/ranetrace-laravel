<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;

/**
 * The public JS-error endpoint must never throw uncaught into the host app,
 * even when capture fails mid-request. This test does NOT fake the bus: the
 * capture runs inline (queue disabled → dispatchSync), so a buffer failure
 * surfaces through the controller's own try/catch — which must convert it to a
 * clean 500 JSON response rather than a leaked error page.
 */
beforeEach(function (): void {
    $this->withoutMiddleware(VerifyCsrfToken::class);
    config([
        'ranetrace.javascript_errors.enabled' => true,
        'ranetrace.javascript_errors.queue' => false,
    ]);
});

test('a capture failure returns a clean 500 JSON instead of throwing', function (): void {
    // Simulate the buffer write dying mid-capture. dispatchSync propagates that
    // straight into the controller's store() try/catch.
    $this->mock(RanetraceBatchBuffer::class, function ($mock): void {
        $mock->shouldReceive('addItem')
            ->once()
            ->andThrow(new RuntimeException('buffer exploded mid-capture'));
    });

    $response = $this->postJson(route('ranetrace.javascript-errors.store'), [
        'message' => 'Test error',
        'url' => 'https://example.com/',
    ]);

    $response->assertStatus(500)
        ->assertExactJson([
            'success' => false,
            'message' => 'Failed to process error',
        ]);
});
