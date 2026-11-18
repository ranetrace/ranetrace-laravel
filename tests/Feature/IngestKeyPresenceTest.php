<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Ranetrace\Laravel\Ranetrace;
use Ranetrace\Laravel\Services\RanetraceApiClient;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;
use Ranetrace\Laravel\Support\CoreConfig;
use Ranetrace\Laravel\Support\InternalLogger;

beforeEach(function (): void {
    Http::fake();
});

/**
 * Report an error and track an event, each buffered at once rather than
 * queued, and return the buffer they land in when they are captured.
 */
function bufferAfterAnErrorAndAnEvent(): RanetraceBatchBuffer
{
    Config::set('ranetrace.errors.queue', false);
    Config::set('ranetrace.events.queue', false);

    $ranetrace = new Ranetrace;
    $ranetrace->report(new RuntimeException('boom'));
    $ranetrace->trackEvent('button_clicked');

    return app(RanetraceBatchBuffer::class);
}

test('a key that is missing or only whitespace captures nothing, so nothing is buffered or sent', function (?string $key): void {
    Config::set('ranetrace.key', $key);

    $buffer = bufferAfterAnErrorAndAnEvent();

    expect($buffer->count('errors'))->toBe(0)
        ->and($buffer->count('events'))->toBe(0);

    Http::assertNothingSent();
})->with('missing ingest keys');

test('a real key still captures', function (): void {
    Config::set('ranetrace.key', 'ingest-key');

    $buffer = bufferAfterAnErrorAndAnEvent();

    expect($buffer->count('errors'))->toBe(1)
        ->and($buffer->count('events'))->toBe(1);
});

test('ranetrace:test fails on a key of only whitespace', function (): void {
    Config::set('ranetrace.key', '   ');

    $this->artisan('ranetrace:test')
        ->expectsOutputToContain('Ranetrace ingest API key is not set.')
        ->assertFailed();

    Http::assertNothingSent();
});

test('the feature test commands report a key of only whitespace as not set, and fail', function (string $command): void {
    Queue::fake();
    Config::set('ranetrace.key', '   ');

    $exitCode = Artisan::call($command);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('❌ Ranetrace API key is not set!');
})->with([
    'errors' => ['ranetrace:test-errors'],
    'events' => ['ranetrace:test-events'],
    'logging' => ['ranetrace:test-logging'],
]);

test('a key that is not a string counts as missing for capture, the commands and the client', function (mixed $key): void {
    Config::set('ranetrace.key', $key);

    $buffer = bufferAfterAnErrorAndAnEvent();

    expect(Ranetrace::isCaptureEnabled('errors'))->toBeFalse()
        ->and($buffer->count('errors'))->toBe(0)
        ->and($buffer->count('events'))->toBe(0)
        ->and(CoreConfig::make()->key())->toBe('')
        ->and((new RanetraceApiClient)->hasKey())->toBeFalse();

    Artisan::call('ranetrace:status');

    expect(Artisan::output())->toContain('Ingest API key: Not configured')
        ->toContain('✗ Overall status: issues detected');

    expect(Artisan::call('ranetrace:test'))->toBe(1)
        ->and(Artisan::output())->toContain('Ranetrace ingest API key is not set.');

    expect(Artisan::call('ranetrace:test-errors'))->toBe(1)
        ->and(Artisan::output())->toContain('❌ Ranetrace API key is not set!');

    Http::assertNothingSent();
})->with([
    'an integer' => [12345],
    'true' => [true],
    'false' => [false],
    'a list' => [['ingest-key']],
]);

test('a non-string key leaves nothing in the internal log, since nothing tried to capture', function (): void {
    Config::set('ranetrace.key', 12345);
    Config::set('ranetrace.errors.queue', false);

    (new Ranetrace)->report(new RuntimeException('boom'));

    expect(glob(dirname(InternalLogger::channelPath()).'/ranetrace-internal-*.log'))->toBe([]);
});
