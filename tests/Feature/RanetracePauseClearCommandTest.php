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

test('--feature ends with the troubleshooting tip for the pause reason', function (string $reason, string $tip): void {
    app(RanetracePauseManager::class)->setFeaturePause('errors', 900, $reason);

    $this->artisan('ranetrace:pause-clear', ['--feature' => 'errors'])
        ->expectsConfirmation("Clear pause for 'errors' and resume processing?", 'yes')
        ->expectsOutput('  • '.$tip)
        ->assertSuccessful();
})->with([
    '401' => ['401', 'Check that RANETRACE_KEY in .env is valid and not revoked'],
    '403' => ['403', 'Verify subscription is active, email is verified, and feature access is enabled'],
    '413' => ['413', 'Payload too large, which indicates a client bug. Investigate the batch sizes'],
    '422' => ['422', 'The request body was rejected as malformed, which indicates a client bug. The wrapper key is missing, its value is not a list, or the list is empty. Check recent changes to how batches are sent'],
    '429' => ['429', 'Rate limited: reduce the batch frequency or raise the rate limit with the API provider'],
    '500' => ['500', "Ranetrace backend error: check the backend API's health and logs"],
    'an unknown reason' => ['418', 'Check ranetrace_internal logs for more details'],
]);
