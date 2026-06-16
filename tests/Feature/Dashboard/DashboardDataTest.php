<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Ranetrace\Laravel\Dashboard\DashboardData;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;
use Ranetrace\Laravel\Services\RanetracePauseManager;

beforeEach(function (): void {
    Config::set('ranetrace.batch.cache_driver', 'array');
    Cache::store('array')->flush();
});

test('collectStatus returns the canonical status structure', function (): void {
    $status = app(DashboardData::class)->collectStatus();

    expect($status)
        ->toHaveKeys(['healthy', 'timestamp', 'pauses', 'buffers', 'drain', 'failed_jobs_last_24h', 'config'])
        ->and($status['healthy'])->toBeTrue()
        ->and($status['pauses'])->toHaveKeys(['global', 'features'])
        ->and($status['buffers'])->toHaveKeys(['total', 'max_per_feature', 'features'])
        ->and($status['buffers']['features'])->toHaveKeys(RanetraceBatchBuffer::TYPES)
        ->and($status['drain'])->toHaveKeys(['last_batch', 'stalled'])
        ->and($status['config'])->toHaveKeys(['enabled', 'api_key_configured', 'cache_driver', 'queue_name'])
        ->and($status['config']['api_key_configured'])->toBeTrue();
});

test('ranetrace:status --json output is unchanged after the DashboardData extraction', function (): void {
    // Populate state across panels (a pause, a buffered item) so parity is
    // checked against a populated structure, not just an empty baseline.
    app(RanetracePauseManager::class)->setFeaturePause('errors', 900, '429');
    app(RanetraceBatchBuffer::class)->addItem('events', ['event_name' => 'e1']);

    // Freeze time so the command's `timestamp` and `time_remaining_seconds`
    // (both derived from now()) are identical to the direct service call below.
    $this->freezeTime();

    Artisan::call('ranetrace:status', ['--json' => true]);
    $commandJson = json_decode(mb_trim(Artisan::output()), true);

    $serviceData = app(DashboardData::class)->collectStatus();

    expect($commandJson)->toEqual($serviceData);
});
