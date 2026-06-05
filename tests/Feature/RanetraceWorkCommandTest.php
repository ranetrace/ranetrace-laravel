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

    // The command must not let the raw exception escape — it logs and exits non-zero.
    $this->artisan('ranetrace:work')->assertFailed();
});
