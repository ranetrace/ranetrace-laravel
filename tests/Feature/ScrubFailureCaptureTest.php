<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Ranetrace\Laravel\Facades\Ranetrace;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;

/**
 * Laravel turns every PHP warning into an ErrorException, so a pattern PCRE
 * refuses to compile is thrown from inside the scrubber. Here the cause is a
 * list of `scrubbing.extra_keys` too long for one pattern, which a host can
 * write into its own config. The capture must still arrive, with the strings
 * the scrubber could not read replaced by the placeholder: before, the log
 * record and the error report were dropped and the browser got a 500, with
 * nothing to show for it but a line in the internal log.
 *
 * Nothing is faked: the capture runs inline under the application's own error
 * handler, into the array buffer.
 */
beforeEach(function (): void {
    config([
        'ranetrace.batch.cache_driver' => 'array',
        'ranetrace.errors.queue' => false,
        'ranetrace.logging.queue' => false,
        'ranetrace.javascript_errors.queue' => false,
        'ranetrace.scrubbing.extra_keys' => array_map(
            static fn (int $number): string => "customer_secret_field_number_{$number}",
            range(1, 2_000),
        ),
        'logging.channels.ranetrace' => [
            'driver' => 'ranetrace',
            'level' => 'debug',
        ],
    ]);

    Cache::store('array')->flush();
});

/**
 * The one item of $type the capture path buffered.
 *
 * @return array<string, mixed>
 */
function bufferedItemAfterScrubFailure(string $type): array
{
    $items = (new RanetraceBatchBuffer)->getItems($type, 10);

    expect($items)->toHaveCount(1);

    return $items[0]['data'];
}

test('an error whose scrub fails is buffered with its message redacted and its type and place intact', function (): void {
    $line = __LINE__ + 1;
    Ranetrace::report(new RuntimeException('Connection refused for password=hunter2'));

    $item = bufferedItemAfterScrubFailure('errors');

    expect($item['message'])->toBe('[REDACTED]')
        ->and($item['type'])->toBe(RuntimeException::class)
        ->and($item['line'])->toBe($line)
        ->and($item['file'])->toEndWith('ScrubFailureCaptureTest.php')
        ->and(json_encode($item))->not->toContain('hunter2');
});

test('a log record whose scrub fails is buffered with its message redacted and its context scrubbed', function (): void {
    Log::channel('ranetrace')->error('Charge failed for token=tok_live_123', ['order' => 912, 'password' => 'hunter2']);

    $item = bufferedItemAfterScrubFailure('logs');

    expect($item['message'])->toBe('[REDACTED]')
        ->and($item['level'])->toBe('error')
        ->and($item['context'])->toBe(['order' => 912, 'password' => '[REDACTED]']);
});

test('a browser error whose scrub fails is accepted and buffered with its message redacted', function (): void {
    $this->withoutMiddleware(VerifyCsrfToken::class);

    $this->postJson(route('ranetrace.javascript-errors.store'), [
        'message' => 'Login failed for token=abc123',
        'type' => 'TypeError',
        'url' => 'https://example.com/checkout',
    ])->assertOk();

    $item = bufferedItemAfterScrubFailure('javascript_errors');

    expect($item['message'])->toBe('[REDACTED]')
        ->and($item['type'])->toBe('TypeError')
        ->and($item['url'])->toBe('https://example.com/checkout');
});
