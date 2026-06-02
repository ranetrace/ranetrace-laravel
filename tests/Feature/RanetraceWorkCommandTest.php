<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

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
