<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Ranetrace\Laravel\Services\RanetracePauseManager;

beforeEach(function (): void {
    Config::set('ranetrace.batch.cache_driver', 'array');
    Cache::store('array')->flush();
});

test('a pause entry that does not say when it ends reads as no pause', function (mixed $entry): void {
    Cache::store('array')->forever('ranetrace.global.pause', $entry);
    Cache::store('array')->forever('ranetrace.feature.errors.pause', $entry);

    $pauses = app(RanetracePauseManager::class);

    expect($pauses->isGloballyPaused())->toBeFalse()
        ->and($pauses->isFeaturePaused('errors'))->toBeFalse()
        ->and($pauses->getGlobalPause())->toBeNull()
        ->and($pauses->getFeaturePause('errors'))->toBeNull();
})->with('damaged pause entries');

test('a well-formed pause is active until it ends', function (): void {
    $pauses = app(RanetracePauseManager::class);
    $pauses->setGlobalPause(900, '401');
    $pauses->setFeaturePause('errors', 900, '429');

    expect($pauses->isGloballyPaused())->toBeTrue()
        ->and($pauses->isFeaturePaused('errors'))->toBeTrue()
        ->and($pauses->isFeaturePaused('events'))->toBeFalse();

    storeExpiredFeaturePause('logs');

    expect($pauses->isFeaturePaused('logs'))->toBeFalse();
});
