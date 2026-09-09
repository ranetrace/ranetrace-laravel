<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

use function Orchestra\Testbench\default_skeleton_path;

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
    // Each test gets its own storage directory (see TestCase), so this is the
    // only daily file the tail can find, and tearDown removes it afterwards.
    file_put_contents(storage_path('logs/ranetrace-internal-2026-01-01.log'), implode("\n", [
        '[2026-01-01 10:00:00] testing.INFO: a routine note',
        '[2026-01-01 10:01:00] testing.WARNING: buffer overflow, oldest items dropped',
        '[2026-01-01 10:02:00] testing.ERROR: batch send failed (500)',
        '    #0 /some/stack/trace/line that should be ignored',
    ])."\n");

    $tail = app(DashboardData::class)->internalLogTail();

    expect($tail)->toHaveCount(2)
        ->and($tail[0]['level'])->toBe('WARNING')
        ->and($tail[1]['level'])->toBe('ERROR')
        ->and($tail[1]['message'])->toContain('batch send failed');
});

test('the internal log tail yields no entries when only info-level lines exist', function (): void {
    file_put_contents(
        storage_path('logs/ranetrace-internal-2026-01-02.log'),
        "[2026-01-02 10:00:00] testing.INFO: nothing worth surfacing\n"
    );

    expect(app(DashboardData::class)->internalLogTail())->toBe([]);
});

test('the internal log tail reads the directory the channel is configured to write to', function (): void {
    // The reader derives its directory and basename from the channel's own
    // path, so moving the channel moves the panel with it. The new directory
    // sits inside this test's storage, which tearDown clears.
    $directory = storage_path('logs/elsewhere');
    mkdir($directory, 0755, true);
    Config::set('logging.channels.ranetrace_internal.path', $directory.'/ranetrace-internal.log');
    file_put_contents(
        $directory.'/ranetrace-internal-2026-01-03.log',
        "[2026-01-03 10:00:00] testing.WARNING: written where the channel points\n"
    );

    $tail = app(DashboardData::class)->internalLogTail();

    expect($tail)->toHaveCount(1)
        ->and($tail[0]['message'])->toContain('written where the channel points');
});

test('a log file left in the shared workbench storage never reaches the tail', function (): void {
    // The Testbench skeleton's storage is shared by every run and is never
    // reset, so what an earlier run left there must not decide what the panel
    // shows. A far-future date is the file a reader globbing that directory
    // would always pick as the newest, so this row fails the moment the tail
    // reads the skeleton's logs again.
    $file = default_skeleton_path('storage/logs').'/ranetrace-internal-2099-12-31.log';
    file_put_contents($file, "[2099-12-31 10:00:00] testing.WARNING: left behind by an earlier run\n");

    try {
        // Guard the arrangement: a fixture that never landed would satisfy the
        // assertion below for the wrong reason.
        expect($file)->toBeFile();

        expect(app(DashboardData::class)->internalLogTail())->toBe([]);
    } finally {
        // The one row that writes outside the isolated directory, so the one
        // row that has to clean up after itself.
        @unlink($file);
    }
});

test('collect() bundles status plus all dashboard extras', function (): void {
    $payload = app(DashboardData::class)->collect();

    expect($payload)->toHaveKeys(['status', 'checks', 'surfaces', 'logs', 'environment'])
        ->and($payload['status'])->toHaveKey('healthy')
        ->and($payload['checks'])->not->toBeEmpty();
});
