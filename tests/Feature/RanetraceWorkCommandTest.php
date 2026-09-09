<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
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
        $mock->shouldReceive('isGloballyPaused')->andThrow(new RuntimeException('cache backend unavailable'));
    });

    // The command must not let the raw exception escape: it logs and exits non-zero.
    $this->artisan('ranetrace:work')->assertFailed();
});

test('the backend-unavailable error line speaks in two sentences, with no em-dash', function (): void {
    // The house writing rule keeps the dash out of anything the package says,
    // and this line is what an operator reads on a failed scheduled run.
    $this->mock(RanetracePauseManager::class, function ($mock): void {
        $mock->shouldReceive('isGloballyPaused')->andThrow(new RuntimeException('cache backend unavailable'));
    });

    $this->artisan('ranetrace:work')
        ->expectsOutputToContain('Ranetrace: ranetrace:work could not run. Is the cache/queue backend available? See the ranetrace_internal log.')
        ->doesntExpectOutputToContain("\u{2014}")
        ->assertFailed();
});
