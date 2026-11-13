<?php

declare(strict_types=1);

use Illuminate\Bus\Dispatcher;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Ranetrace\Laravel\Jobs\HandleJavaScriptErrorJob;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;
use Ranetrace\Laravel\Support\Core;
use Ranetrace\Laravel\Support\CoreConfig;
use Ranetrace\Php\JavaScript\ErrorItemBuilder;

/**
 * @return array<string, mixed>
 */
function dispatchedTestJavaScriptError(): array
{
    $errorData = null;

    Bus::assertDispatched(HandleJavaScriptErrorJob::class, function (HandleJavaScriptErrorJob $job) use (&$errorData): bool {
        $errorData = $job->getErrorData();

        return true;
    });

    return $errorData;
}

beforeEach(function (): void {
    Bus::fake();
    // The bus is faked, so no job runs. On a connection that runs a job at
    // once the command counts what its own run buffered, finds nothing and
    // fails, so these tests sit on one that hands the job to a worker.
    Config::set('queue.default', 'database');
    Config::set('ranetrace.javascript_errors.enabled', true);
    Config::set('ranetrace.key', 'test-key');
});

test('ranetrace:test-javascript-errors dispatches an item with the same keys the relay builder produces', function (): void {
    $this->artisan('ranetrace:test-javascript-errors')->assertSuccessful();

    $errorData = dispatchedTestJavaScriptError();
    $relayItem = (new ErrorItemBuilder(CoreConfig::make(), Core::scrubber()))->build(
        payload: [],
        userAgent: null,
        userId: null,
        sessionId: null,
    );

    expect(array_keys($errorData))->toBe(array_keys($relayItem))
        ->and(array_keys($errorData['browser_info']))->toBe(array_keys($relayItem['browser_info']))
        ->and($errorData['message'])->toBe('Test JavaScript error from ranetrace:test-javascript-errors')
        ->and($errorData['context'])->toBe(['source' => 'cli-test']);
});

test('ranetrace:test-javascript-errors reports the browser name and version a real Chrome report carries', function (): void {
    $this->artisan('ranetrace:test-javascript-errors')->assertSuccessful();

    $browserInfo = dispatchedTestJavaScriptError()['browser_info'];

    expect(array_slice(array_keys($browserInfo), -2))->toBe(['name', 'version'])
        ->and($browserInfo['name'])->toBe('Chrome')
        ->and($browserInfo['version'])->toMatch('/^\d+$/');
});

test('ranetrace:test-javascript-errors queues its item when the JavaScript errors queue is on, like the relay', function (): void {
    $this->artisan('ranetrace:test-javascript-errors')
        ->expectsOutputToContain('queued')
        ->assertSuccessful();

    Bus::assertNotDispatchedSync(HandleJavaScriptErrorJob::class);
});

test('ranetrace:test-javascript-errors buffers its item at once when the JavaScript errors queue is off, like the relay', function (): void {
    Config::set('ranetrace.javascript_errors.queue', false);
    Bus::swap(app(Dispatcher::class));

    $this->artisan('ranetrace:test-javascript-errors')
        ->expectsOutputToContain('✅ Test JavaScript error buffered for Ranetrace.')
        ->expectsOutputToContain('php artisan ranetrace:work')
        ->doesntExpectOutputToContain('Test JavaScript error sent to Ranetrace')
        ->assertSuccessful();

    expect(app(RanetraceBatchBuffer::class)->count('javascript_errors'))->toBe(1);
});

test('ranetrace:test-javascript-errors dispatches nothing when JavaScript error tracking is disabled', function (): void {
    Config::set('ranetrace.javascript_errors.enabled', false);

    $this->artisan('ranetrace:test-javascript-errors')->assertSuccessful();

    Bus::assertNotDispatched(HandleJavaScriptErrorJob::class);
});

test('ranetrace:test-javascript-errors dispatches nothing and fails when the key is missing', function (?string $key): void {
    Config::set('ranetrace.key', $key);

    $this->artisan('ranetrace:test-javascript-errors')->assertFailed();

    Bus::assertNotDispatched(HandleJavaScriptErrorJob::class);
})->with([
    'null' => [null],
    'empty' => [''],
    'whitespace' => ['   '],
]);
