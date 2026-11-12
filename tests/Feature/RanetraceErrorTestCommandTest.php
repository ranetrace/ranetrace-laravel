<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;

beforeEach(function (): void {
    Config::set('ranetrace.batch.cache_driver', 'array');
    Cache::store('array')->flush();

    // Each error job runs at once, so what the command reported is in the
    // buffer when it returns.
    Config::set('ranetrace.errors.queue', false);
});

/**
 * The error items the command left in the buffer, keyed by message.
 *
 * @return array<string, array<string, mixed>>
 */
function bufferedTestErrors(): array
{
    test()->artisan('ranetrace:test-errors')->assertSuccessful();

    return collect(app(RanetraceBatchBuffer::class)->getItems('errors', 10))
        ->pluck('data')
        ->keyBy('message')
        ->all();
}

test('ranetrace:test-errors reports the four test errors it says it reported', function (): void {
    expect(array_keys(bufferedTestErrors()))->toBe([
        'Test exception from Ranetrace',
        'Test runtime exception',
        'Test exception with deeper stack trace',
        'Database connection failed: Connection refused on localhost:5432',
    ]);
});

test('the test error with the deeper stack trace carries more frames than the simple one', function (): void {
    $errors = bufferedTestErrors();

    expect(count($errors['Test exception with deeper stack trace']['frames']))
        ->toBeGreaterThan(count($errors['Test exception from Ranetrace']['frames']));
});
