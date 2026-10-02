<?php

declare(strict_types=1);

use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Support\Facades\Cache;
use Ranetrace\Laravel\Facades\Ranetrace;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;

beforeEach(function (): void {
    config([
        'ranetrace.batch.cache_driver' => 'array',
        'ranetrace.errors.queue' => false,
    ]);

    Cache::store('array')->flush();
});

/**
 * The one error item the capture path buffered.
 *
 * @return array<string, mixed>
 */
function bufferedErrorItem(): array
{
    $items = (new RanetraceBatchBuffer)->getItems('errors', 10);

    expect($items)->toHaveCount(1);

    return $items[0]['data'];
}

function exceptionWithContext(): RuntimeException
{
    return new class('Checkout failed') extends RuntimeException
    {
        /**
         * @return array<string, mixed>
         */
        public function context(): array
        {
            return ['user_id' => 42, 'price' => 'price_123'];
        }
    };
}

test('a reported exception carries its own context() as exception_context', function (): void {
    Ranetrace::report(exceptionWithContext());

    expect(bufferedErrorItem()['exception_context'])->toBe(['user_id' => 42, 'price' => 'price_123']);
});

test('an exception reported through the handler wiring carries its own context()', function (): void {
    $handler = new Handler(app());
    Ranetrace::handles(new Exceptions($handler));

    $handler->report(exceptionWithContext());

    expect(bufferedErrorItem()['exception_context'])->toBe(['user_id' => 42, 'price' => 'price_123']);
});

test('an exception whose context() throws is still captured with a null exception_context', function (): void {
    Ranetrace::report(new class('Checkout failed') extends RuntimeException
    {
        /**
         * @return array<string, mixed>
         */
        public function context(): array
        {
            throw new LogicException('context unavailable');
        }
    });

    $item = bufferedErrorItem();

    expect($item['message'])->toBe('Checkout failed')
        ->and($item)->toHaveKey('exception_context')
        ->and($item['exception_context'])->toBeNull();
});
