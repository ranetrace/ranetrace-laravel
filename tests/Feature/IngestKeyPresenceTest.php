<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Ranetrace\Laravel\Ranetrace;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;

beforeEach(function (): void {
    Config::set('ranetrace.batch.cache_driver', 'array');
    Cache::store('array')->flush();
    Http::fake();
});

test('a key that is missing or only whitespace captures nothing, so nothing is buffered or sent', function (?string $key): void {
    Config::set('ranetrace.key', $key);
    Config::set('ranetrace.errors.queue', false);
    Config::set('ranetrace.events.queue', false);

    $ranetrace = new Ranetrace;
    $ranetrace->report(new RuntimeException('boom'));
    $ranetrace->trackEvent('button_clicked');

    $buffer = app(RanetraceBatchBuffer::class);

    expect($buffer->count('errors'))->toBe(0)
        ->and($buffer->count('events'))->toBe(0);

    Http::assertNothingSent();
})->with([
    'null' => [null],
    'empty' => [''],
    'a space' => [' '],
    'whitespace' => ["\t\n "],
]);

test('a real key still captures', function (): void {
    Config::set('ranetrace.key', 'ingest-key');
    Config::set('ranetrace.errors.queue', false);
    Config::set('ranetrace.events.queue', false);

    $ranetrace = new Ranetrace;
    $ranetrace->report(new RuntimeException('boom'));
    $ranetrace->trackEvent('button_clicked');

    $buffer = app(RanetraceBatchBuffer::class);

    expect($buffer->count('errors'))->toBe(1)
        ->and($buffer->count('events'))->toBe(1);
});

test('ranetrace:test fails on a key of only whitespace', function (): void {
    Config::set('ranetrace.key', '   ');

    $this->artisan('ranetrace:test')
        ->expectsOutputToContain('Ranetrace ingest API key is not set.')
        ->assertFailed();
});

test('the feature test commands report a key of only whitespace as not set', function (string $command): void {
    Queue::fake();
    Config::set('ranetrace.key', '   ');

    Artisan::call($command);

    expect(Artisan::output())->toMatch('/API key set\s*\|\s*No\s*\|/');
})->with([
    'errors' => ['ranetrace:test-errors'],
    'events' => ['ranetrace:test-events'],
    'logging' => ['ranetrace:test-logging'],
]);
