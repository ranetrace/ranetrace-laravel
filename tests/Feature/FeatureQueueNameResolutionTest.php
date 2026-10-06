<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Ranetrace\Laravel\Dashboard\Checks\CheckLevel;
use Ranetrace\Laravel\Dashboard\Checks\QueueWorkerCheck;
use Ranetrace\Laravel\Dashboard\DashboardData;
use Ranetrace\Laravel\Facades\Ranetrace;
use Ranetrace\Laravel\Jobs\HandleErrorJob;
use Ranetrace\Laravel\Jobs\HandleEventJob;
use Ranetrace\Laravel\Jobs\HandleJavaScriptErrorJob;
use Ranetrace\Laravel\Jobs\HandleLogJob;
use Ranetrace\Laravel\Jobs\HandlePageVisitJob;
use Ranetrace\Laravel\Jobs\SendBatchToRanetraceJob;

/**
 * `RANETRACE_EVENTS_QUEUE_NAME=null` arrives as null and
 * `RANETRACE_EVENTS_QUEUE_NAME=` as an empty string: both name no queue, and
 * so does a value of nothing but spaces.
 */
dataset('unset feature queue names', [
    'null' => [null],
    'blank' => [''],
    'spaces' => ['  '],
]);

dataset('capture jobs', [
    'errors' => [HandleErrorJob::class, 'ranetrace.errors'],
    'events' => [HandleEventJob::class, 'ranetrace.events'],
    'logging' => [HandleLogJob::class, 'ranetrace.logging'],
    'javascript errors' => [HandleJavaScriptErrorJob::class, 'ranetrace.javascript_errors'],
    'website analytics' => [HandlePageVisitJob::class, 'ranetrace.website_analytics'],
]);

test('a captured event with an unset queue name is pushed to the connection default queue', function (?string $unsetValue): void {
    Config::set('ranetrace.events.queue_name', $unsetValue);
    Queue::fake();

    Ranetrace::trackEvent('user_registered');

    Queue::assertPushed(HandleEventJob::class, fn (HandleEventJob $job): bool => $job->queue === null);
})->with('unset feature queue names');

test('a captured event with a queue name set is pushed to that queue', function (): void {
    Config::set('ranetrace.events.queue_name', 'ranetrace');
    Queue::fake();

    Ranetrace::trackEvent('user_registered');

    Queue::assertPushed(HandleEventJob::class, fn (HandleEventJob $job): bool => $job->queue === 'ranetrace');
});

test('every capture job with an unset queue name goes to the connection default queue', function (string $jobClass, string $configPath, ?string $unsetValue): void {
    Config::set("{$configPath}.queue_name", $unsetValue);

    expect((new $jobClass([]))->queue)->toBeNull();
})->with('capture jobs')->with('unset feature queue names');

test('every capture job uses its feature queue name as it is set', function (string $jobClass, string $configPath): void {
    Config::set("{$configPath}.queue_name", 'ranetrace');

    expect((new $jobClass([]))->queue)->toBe('ranetrace');
})->with('capture jobs');

test('a captured event with no queue name configured is pushed to the connection default queue', function (): void {
    Queue::fake();

    Ranetrace::trackEvent('user_registered');

    Queue::assertPushed(HandleEventJob::class, fn (HandleEventJob $job): bool => $job->queue === null);
});

test('every capture job with no queue name configured goes to the connection default queue', function (string $jobClass): void {
    expect((new $jobClass([]))->queue)->toBeNull();
})->with('capture jobs');

test('a batch job with no queue name configured goes to the connection default queue', function (): void {
    expect((new SendBatchToRanetraceJob('events'))->queue)->toBeNull();
});

test('ranetrace:test names the connection default queue for an unset feature queue name', function (?string $unsetValue): void {
    Config::set('ranetrace.errors.queue_name', $unsetValue);

    $this->artisan('ranetrace:test')
        ->expectsTable(
            ['Setting', 'Value'],
            [
                ['Timeout', '10 seconds'],
                ['Queue Name', "the connection's default queue"],
                ['Capture User Email', 'No'],
            ],
        )
        ->assertSuccessful();
})->with('unset feature queue names');

test('ranetrace:test-javascript-errors names the connection default queue for an unset queue name', function (?string $unsetValue): void {
    Bus::fake();
    Config::set('ranetrace.javascript_errors.queue_name', $unsetValue);

    $this->artisan('ranetrace:test-javascript-errors')
        ->expectsOutputToContain("the connection's default queue")
        ->assertSuccessful();
})->with('unset feature queue names');

test('the queue worker check does not count an unset feature queue name as a queue', function (?string $unsetValue): void {
    Config::set('queue.default', 'database');
    Config::set('queue.connections.database.queue', 'jobs');
    Config::set('ranetrace.batch.queue_name', 'jobs');
    Config::set('ranetrace.errors.queue_name', 'jobs');
    Config::set('ranetrace.events.queue_name', 'jobs');
    Config::set('ranetrace.logging.queue_name', 'jobs');
    Config::set('ranetrace.javascript_errors.queue_name', 'jobs');
    Config::set('ranetrace.website_analytics.queue_name', $unsetValue);

    $result = (new QueueWorkerCheck)->run(app(DashboardData::class)->collectStatus());

    expect($result->level)->toBe(CheckLevel::Pass);
})->with('unset feature queue names');
