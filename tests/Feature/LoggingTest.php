<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Ranetrace\Laravel\Jobs\HandleLogJob;

beforeEach(function (): void {
    Bus::fake();
    config([
        'ranetrace.logging.enabled' => true,
        'ranetrace.logging.queue' => true,
        'ranetrace.logging.queue_name' => 'default',
        'logging.channels.ranetrace' => [
            'driver' => 'ranetrace',
            'level' => 'debug',
        ],
    ]);
});

test('it sends logs to ranetrace channel', function (): void {
    Log::channel('ranetrace')->error('Test error message', [
        'context' => 'test',
    ]);

    Bus::assertDispatched(HandleLogJob::class, function ($job): bool {
        return $job->getLogData()['level'] === 'error'
            && $job->getLogData()['message'] === 'Test error message'
            && $job->getLogData()['context']['context'] === 'test';
    });
});

test('it includes environment information', function (): void {
    Log::channel('ranetrace')->error('Test');

    Bus::assertDispatched(HandleLogJob::class, function ($job): bool {
        return isset($job->getLogData()['extra']['environment'])
            && isset($job->getLogData()['extra']['laravel_version'])
            && isset($job->getLogData()['extra']['php_version']);
    });
});

test('it respects enabled configuration', function (): void {
    config(['ranetrace.logging.enabled' => false]);

    Log::channel('ranetrace')->error('Test');

    Bus::assertNotDispatched(HandleLogJob::class);
});

test('it respects excluded channels', function (): void {
    config(['ranetrace.logging.excluded_channels' => ['test-channel']]);

    // Create a custom logger for testing
    $logger = Log::build([
        'driver' => 'ranetrace',
        'channel' => 'test-channel',
    ]);

    $logger->error('Test');

    Bus::assertNotDispatched(HandleLogJob::class);
});

test('it handles different log levels', function (): void {
    $levels = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

    foreach ($levels as $level) {
        Log::channel('ranetrace')->{$level}("Test {$level} message");
    }

    Bus::assertDispatchedTimes(HandleLogJob::class, count($levels));
});

test('it sanitizes context with closures', function (): void {
    Log::channel('ranetrace')->error('Test', [
        'closure' => fn () => 'test',
        'safe' => 'value',
    ]);

    Bus::assertDispatched(HandleLogJob::class, function ($job): bool {
        return $job->getLogData()['context']['closure'] === '[Closure]'
            && $job->getLogData()['context']['safe'] === 'value';
    });
});

test('it formats timestamp correctly', function (): void {
    Log::channel('ranetrace')->error('Test');

    Bus::assertDispatched(HandleLogJob::class, function ($job): bool {
        // ISO 8601 format
        return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $job->getLogData()['timestamp']) === 1;
    });
});

test('it includes channel name', function (): void {
    Log::channel('ranetrace')->error('Test');

    Bus::assertDispatched(HandleLogJob::class, function ($job): bool {
        return $job->getLogData()['channel'] === 'ranetrace';
    });
});
