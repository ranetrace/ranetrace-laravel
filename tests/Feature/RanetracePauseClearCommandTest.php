<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Ranetrace\Laravel\Services\RanetracePauseManager;

beforeEach(function (): void {
    Config::set('ranetrace.batch.cache_driver', 'array');
    Cache::store('array')->flush();
});

test('it fails when no option is provided', function (): void {
    $this->artisan('ranetrace:pause-clear')
        ->expectsOutputToContain('You must specify at least one option')
        ->assertFailed();
});

test('--all reports when no pauses are active', function (): void {
    $this->artisan('ranetrace:pause-clear', ['--all' => true])
        ->expectsOutputToContain('No pauses were active.')
        ->assertSuccessful();
});

test('--all clears active global and feature pauses', function (): void {
    $pauseManager = app(RanetracePauseManager::class);
    $pauseManager->setGlobalPause(900, '429');
    $pauseManager->setFeaturePause('errors', 900, '500');

    $this->artisan('ranetrace:pause-clear', ['--all' => true])
        ->expectsOutputToContain('Successfully cleared')
        ->assertSuccessful();

    expect($pauseManager->getGlobalPause())->toBeNull()
        ->and($pauseManager->getFeaturePause('errors'))->toBeNull();
});

test('--global with no active pause is a no-op success', function (): void {
    $this->artisan('ranetrace:pause-clear', ['--global' => true])
        ->expectsOutputToContain('Global pause is not set.')
        ->assertSuccessful();
});

test('--global clears an active global pause after confirmation', function (): void {
    app(RanetracePauseManager::class)->setGlobalPause(900, '429');

    $this->artisan('ranetrace:pause-clear', ['--global' => true])
        ->expectsConfirmation('Clear global pause and resume all processing?', 'yes')
        ->expectsOutputToContain('Global pause cleared successfully')
        ->assertSuccessful();

    expect(app(RanetracePauseManager::class)->getGlobalPause())->toBeNull();
});

test('--feature rejects an invalid feature name', function (): void {
    $this->artisan('ranetrace:pause-clear', ['--feature' => 'not-a-feature'])
        ->expectsOutputToContain('Invalid feature: not-a-feature')
        ->assertFailed();
});

test('--feature clears an active feature pause after confirmation', function (): void {
    app(RanetracePauseManager::class)->setFeaturePause('errors', 900, '429');

    $this->artisan('ranetrace:pause-clear', ['--feature' => 'errors'])
        ->expectsConfirmation("Clear pause for 'errors' and resume processing?", 'yes')
        ->expectsOutputToContain("Pause cleared for 'errors'")
        ->assertSuccessful();

    expect(app(RanetracePauseManager::class)->getFeaturePause('errors'))->toBeNull();
});
