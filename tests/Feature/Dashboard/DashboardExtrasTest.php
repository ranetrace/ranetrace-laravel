<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Ranetrace\Laravel\Dashboard\DashboardData;

beforeEach(function (): void {
    Config::set('ranetrace.batch.cache_driver', 'array');
    Cache::store('array')->flush();
});

test('registered surfaces report the wired-up installation truth', function (): void {
    $surfaces = collect(app(DashboardData::class)->registeredSurfaces())->keyBy('label');

    // Dashboard route is registered (we are testing it), internal log channel always is.
    expect($surfaces->get('Dashboard route')['ok'])->toBeTrue()
        ->and($surfaces->get('Internal log channel')['ok'])->toBeTrue()
        ->and($surfaces->get('@ranetraceErrorTracking Blade directive')['ok'])->toBeTrue();
});

test('environment exposes runtime versions and connections', function (): void {
    $env = app(DashboardData::class)->environment();

    expect($env)->toHaveKeys(['package', 'laravel', 'php', 'env', 'queue', 'cache'])
        ->and($env['php'])->toBe(PHP_VERSION)
        ->and($env['laravel'])->toBe(app()->version());
});

test('the internal log tail always returns an array and never throws', function (): void {
    expect(app(DashboardData::class)->internalLogTail())->toBeArray();
});

test('the internal log tail reads warning+ entries and skips info and stack traces', function (): void {
    // Far-future date so this file is always the most-recent daily log, whatever
    // else the shared Workbench storage already contains.
    $dir = storage_path('logs');
    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $file = $dir.'/ranetrace-internal-2099-01-01.log';
    file_put_contents($file, implode("\n", [
        '[2099-01-01 10:00:00] testing.INFO: a routine note',
        '[2099-01-01 10:01:00] testing.WARNING: buffer overflow — oldest items dropped',
        '[2099-01-01 10:02:00] testing.ERROR: batch send failed (500)',
        '    #0 /some/stack/trace/line that should be ignored',
    ])."\n");

    try {
        $tail = app(DashboardData::class)->internalLogTail();

        expect($tail)->toHaveCount(2)
            ->and($tail[0]['level'])->toBe('WARNING')
            ->and($tail[1]['level'])->toBe('ERROR')
            ->and($tail[1]['message'])->toContain('batch send failed');
    } finally {
        @unlink($file);
    }
});

test('the internal log tail yields no entries when only info-level lines exist', function (): void {
    $dir = storage_path('logs');
    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $file = $dir.'/ranetrace-internal-2099-01-02.log';
    file_put_contents($file, "[2099-01-02 10:00:00] testing.INFO: nothing worth surfacing\n");

    try {
        expect(app(DashboardData::class)->internalLogTail())->toBe([]);
    } finally {
        @unlink($file);
    }
});

test('collect() bundles status plus all dashboard extras', function (): void {
    $payload = app(DashboardData::class)->collect();

    expect($payload)->toHaveKeys(['status', 'checks', 'surfaces', 'logs', 'environment'])
        ->and($payload['status'])->toHaveKey('healthy')
        ->and($payload['checks'])->not->toBeEmpty();
});
