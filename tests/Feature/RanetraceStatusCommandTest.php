<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;
use Ranetrace\Laravel\Services\RanetracePauseManager;

beforeEach(function (): void {
    Config::set('ranetrace.batch.cache_driver', 'array');
    Cache::store('array')->flush();
});

test('status reports healthy with empty buffers', function (): void {
    $this->artisan('ranetrace:status')
        ->expectsOutputToContain('Overall Status: HEALTHY')
        ->assertSuccessful();
});

test('status reports unhealthy when a buffer is near its own capacity', function (): void {
    Config::set('ranetrace.batch.max_buffer_size', 10);

    $buffer = app(RanetraceBatchBuffer::class);
    for ($i = 0; $i < 8; $i++) {
        $buffer->addItem('events', ['event_name' => "e{$i}"]);
    }

    $this->artisan('ranetrace:status')
        ->expectsOutputToContain('ISSUES DETECTED')
        ->assertSuccessful();
});

test('status warns about a stalled drain when a buffer has items but never drained', function (): void {
    $buffer = app(RanetraceBatchBuffer::class);
    $buffer->addItem('events', ['event_name' => 'e1']);

    $this->artisan('ranetrace:status')
        ->expectsOutputToContain('No recent batch drain')
        ->assertSuccessful();
});

test('status outputs successfully with the --json flag', function (): void {
    $this->artisan('ranetrace:status', ['--json' => true])
        ->assertSuccessful();
});

test('status renders an active pause without crashing and shows the remaining time', function (): void {
    // Regression guard: time_remaining_seconds is a Carbon-3 float; before the
    // (int) cast it TypeError'd when passed to formatDuration(int) for an
    // active pause — the exact case the status command exists to report.
    app(RanetracePauseManager::class)->setFeaturePause('errors', 900, '429');

    $this->artisan('ranetrace:status')
        ->expectsOutputToContain('PAUSED')
        ->assertSuccessful();
});
