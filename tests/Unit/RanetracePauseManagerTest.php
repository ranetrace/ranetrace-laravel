<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Ranetrace\Laravel\Services\RanetracePauseManager;

beforeEach(function (): void {
    Config::set('ranetrace.batch.cache_driver', 'array');
    Cache::store('array')->flush();
});

dataset('damaged_pause_entries', [
    'a string' => ['paused'],
    'a number' => [1_731_500_000],
    'an array without paused_until' => [['reason' => '429']],
    'a paused_until that is not a string' => [['paused_until' => ['2099-01-01T00:00:00+00:00'], 'reason' => '429']],
    'a paused_until that is no date' => [['paused_until' => 'not a date', 'reason' => '429']],
    'an empty paused_until' => [['paused_until' => '', 'reason' => '429']],
]);

test('a pause entry that does not say when it ends reads as no pause', function (mixed $entry): void {
    // The pause store only writes well-formed entries, so this takes one
    // another writer or a damaged cache left behind.
    Cache::store('array')->forever('ranetrace.global.pause', $entry);
    Cache::store('array')->forever('ranetrace.feature.errors.pause', $entry);

    $pauses = app(RanetracePauseManager::class);

    expect($pauses->isGloballyPaused())->toBeFalse()
        ->and($pauses->isFeaturePaused('errors'))->toBeFalse()
        ->and($pauses->getGlobalPause())->toBeNull()
        ->and($pauses->getFeaturePause('errors'))->toBeNull();
})->with('damaged_pause_entries');

test('a well-formed pause is active until it ends', function (): void {
    $pauses = app(RanetracePauseManager::class);
    $pauses->setGlobalPause(900, '401');
    $pauses->setFeaturePause('errors', 900, '429');

    expect($pauses->isGloballyPaused())->toBeTrue()
        ->and($pauses->isFeaturePaused('errors'))->toBeTrue()
        ->and($pauses->isFeaturePaused('events'))->toBeFalse();

    Cache::store('array')->forever('ranetrace.feature.logs.pause', [
        'paused_until' => now()->subMinute()->toIso8601String(),
        'reason' => '429',
    ]);

    expect($pauses->isFeaturePaused('logs'))->toBeFalse();
});
