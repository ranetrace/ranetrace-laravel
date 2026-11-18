<?php

declare(strict_types=1);

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Ranetrace\Laravel\Facades\Ranetrace as RanetraceFacade;
use Ranetrace\Laravel\Jobs\HandleErrorJob;
use Ranetrace\Laravel\Jobs\HandleEventJob;
use Ranetrace\Laravel\Jobs\HandleLogJob;
use Ranetrace\Laravel\Ranetrace;
use Ranetrace\Laravel\Services\CountingBatchBuffer;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;
use Ranetrace\Laravel\Support\InternalLogger;

/**
 * Every buffered item, of every type.
 */
function featureTestBufferedCount(): int
{
    $buffer = app(RanetraceBatchBuffer::class);

    return array_sum(array_map($buffer->count(...), RanetraceBatchBuffer::TYPES));
}

/**
 * The cell a summary table row shows beside a feature.
 */
function featureTestSummaryResult(string $output, string $feature): string
{
    preg_match('/\|\s*'.preg_quote($feature, '/').'\s*\|\s*([^|]+?)\s*\|/', $output, $matches);

    return $matches[1] ?? '';
}

dataset('capturing feature test commands', [
    'errors' => ['ranetrace:test-errors', 'errors', 'errors', HandleErrorJob::class, '4 test errors', 4],
    'events' => ['ranetrace:test-events', 'events', 'events', HandleEventJob::class, '8 test events', 8],
    'logging' => ['ranetrace:test-logging', 'logging', 'logs', HandleLogJob::class, 'Test logs', null],
]);

test('a feature test command with a blank key says so, captures nothing and fails', function (string $command, string $feature, string $type, string $job, string $items, ?int $count, ?string $key): void {
    Queue::fake();
    Config::set('ranetrace.key', $key);
    Config::set("ranetrace.{$feature}.queue", false);

    [$exitCode, $output] = artisanRun($command);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain("❌ Ranetrace API key is not set!\n💡 Set your API key in the environment that reports to Ranetrace (on a server, in its .env):\n   RANETRACE_KEY=your-api-key-here\n")
        ->and($output)->not->toContain('for Ranetrace.')
        ->and(featureTestBufferedCount())->toBe(0);

    Queue::assertNothingPushed();
})->with('capturing feature test commands')->with([
    'null' => [null],
    'empty' => [''],
    'whitespace' => ['   '],
]);

test('every feature test command stops without capturing while the master switch is off', function (string $command): void {
    Queue::fake();
    Config::set('ranetrace.enabled', false);

    [$exitCode, $output] = artisanRun($command);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('⚠ Ranetrace is disabled (RANETRACE_ENABLED is false), so nothing is captured.')
        ->and(featureTestBufferedCount())->toBe(0);

    Queue::assertNothingPushed();
})->with([
    'errors' => ['ranetrace:test-errors'],
    'events' => ['ranetrace:test-events'],
    'logging' => ['ranetrace:test-logging'],
    'javascript errors' => ['ranetrace:test-javascript-errors'],
    'analytics' => ['ranetrace:test-analytics'],
]);

test('a feature test command whose feature is off says so and captures nothing', function (string $command, string $feature, string $type, string $job, string $items, ?int $count): void {
    Queue::fake();
    Config::set("ranetrace.{$feature}.enabled", false);
    $variable = 'RANETRACE_'.mb_strtoupper($feature).'_ENABLED';

    [$exitCode, $output] = artisanRun($command);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain("is disabled. Set {$variable}=true in the environment that reports to Ranetrace (on a server, in its .env)\nConfiguration check completed.\n")
        ->and(featureTestBufferedCount())->toBe(0);

    Queue::assertNothingPushed();
})->with('capturing feature test commands');

test('with the queue on a feature test command says its items are queued, not sent, and points at ranetrace:test', function (string $command, string $feature, string $type, string $job, string $items, ?int $count): void {
    Queue::fake();
    Config::set("ranetrace.{$feature}.queue", true);

    [$exitCode, $output] = artisanRun($command);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain(
            "✅ {$items} queued for Ranetrace.\n"
            ."Once a queue worker runs them, they will be sent the next time the ranetrace:work command runs.\n"
            ."To send them immediately after that, run: php artisan ranetrace:work\n"
            ."To see whether the API accepts your key, run: php artisan ranetrace:test\n",
        )
        ->and(featureTestBufferedCount())->toBe(0);

    $count === null ? Queue::assertPushed($job) : Queue::assertPushed($job, $count);
})->with('capturing feature test commands');

test('with the queue off a feature test command says its items are buffered, as many as the buffer holds', function (string $command, string $feature, string $type, string $job, string $items, ?int $count): void {
    Config::set("ranetrace.{$feature}.queue", false);

    [$exitCode, $output] = artisanRun($command);

    // A sync dispatch goes through the queue's sync connection, which a
    // faked queue would swallow, so this test runs the jobs for real.
    $buffered = app(RanetraceBatchBuffer::class)->count($type);

    $count === null
        ? expect($buffered)->toBeGreaterThan(0)
        : expect($buffered)->toBe($count);

    // The log test has no fixed count, so it prints the one it counted.
    $counted = $count === null ? "{$buffered} test logs" : $items;

    expect($exitCode)->toBe(0)
        ->and($output)->toContain(
            "✅ {$counted} buffered for Ranetrace.\n"
            ."To send them now, run: php artisan ranetrace:work\n"
            ."To see whether the API accepts your key, run: php artisan ranetrace:test\n",
        )
        ->and($output)->not->toContain('have been sent to Ranetrace');
})->with('capturing feature test commands');

test('the logging test says what it writes, not that it sends it', function (): void {
    Queue::fake();
    [, $output] = artisanRun('ranetrace:test-logging');

    expect($output)->toContain("1. Writing an emergency and an error through Log::channel('ranetrace')\n")
        ->toContain("5. Writing three records in a row\n")
        ->not->toContain('log sent');
});

test('the error test says what each step reports', function (): void {
    Queue::fake();
    [, $output] = artisanRun('ranetrace:test-errors');

    expect($output)->toContain(
        "1. Reporting a simple exception\n"
        ."2. Reporting a RuntimeException\n"
        ."3. Reporting an exception with a deeper stack trace\n"
        ."4. Reporting an exception with a custom message\n",
    );
});

test('the events test says what each step tracks, not that it sends it', function (): void {
    Queue::fake();
    [, $output] = artisanRun('ranetrace:test-events');

    expect($output)->toContain(
        "1. Tracking an event with a valid name (user_registered)\n"
        ."2. Tracking an event by a predefined constant (EventTracker::PRODUCT_ADDED_TO_CART)\n"
        ."3. Tracking an event with an invalid name, which is refused before it is captured\n"
        .'   Expected validation error: ',
    )->toContain(
        "4. Tracking a basic custom event\n"
        ."5. Tracking a product added to cart event\n"
        ."6. Tracking a sale event\n"
        ."7. Tracking a user registration event\n"
        ."8. Tracking a page view event\n"
        ."9. Tracking a custom event through the validated method\n",
    )->not->toContain('Sending');
});

/**
 * A stack of the host's own would add a record on one host and not on another,
 * and the closing line counts what was buffered.
 */
test('the logging test writes its nine records whatever log channels the host defines', function (array $channels): void {
    Config::set('ranetrace.logging.queue', false);

    foreach ($channels as $channel) {
        Config::set("logging.channels.{$channel}", ['driver' => 'single', 'path' => storage_path('logs/host.log')]);
    }

    artisanRun('ranetrace:test-logging');

    expect(app(RanetraceBatchBuffer::class)->count('logs'))->toBe(9);
})->with([
    'the channels Laravel ships' => [[]],
    'a production and a development channel' => [['production', 'development']],
]);

test('a command that prints a feature flag on a line of its own says where it goes in the same words as for the key', function (string $command, array $switchedOff, string $hint): void {
    Queue::fake();

    foreach ($switchedOff as $feature) {
        Config::set("ranetrace.{$feature}.enabled", false);
    }

    [, $output] = artisanRun($command);

    expect($output)->toContain($hint);
})->with([
    'analytics' => ['ranetrace:test-analytics', ['website_analytics'], "💡 To enable it, set in the environment that reports to Ranetrace (on a server, in its .env):\n   RANETRACE_WEBSITE_ANALYTICS_ENABLED=true\n"],
    'javascript errors' => ['ranetrace:test-javascript-errors', ['javascript_errors'], "💡 To enable it, set in the environment that reports to Ranetrace (on a server, in its .env):\n   RANETRACE_JAVASCRIPT_ERRORS_ENABLED=true\n"],
    'the summary, with every feature off' => ['ranetrace:test', ['errors', 'events', 'logging', 'website_analytics', 'javascript_errors'], "💡 Enable features in the environment that reports to Ranetrace (on a server, in its .env):\n   RANETRACE_ERRORS_ENABLED=true\n"],
]);

test('the summary shows a switched-off feature as skipped and does not fail on it', function (string $off, string $row): void {
    Queue::fake();
    Config::set("ranetrace.{$off}.enabled", false);

    [$exitCode, $output] = artisanRun('ranetrace:test', ['--all' => true]);

    expect($exitCode)->toBe(0)
        ->and(featureTestSummaryResult($output, $row))->toBe('○ Skipped: disabled')
        ->and(featureTestSummaryResult($output, $row === 'Errors' ? 'Events' : 'Errors'))->toBe('✓ Passed');
})->with([
    'errors' => ['errors', 'Errors'],
    'events' => ['events', 'Events'],
    'logging' => ['logging', 'Logging'],
    'javascript errors' => ['javascript_errors', 'Javascript errors'],
    'analytics' => ['website_analytics', 'Analytics'],
]);

test('the summary fails every enabled feature on a blank key', function (): void {
    Queue::fake();
    Config::set('ranetrace.key', '');

    [$exitCode, $output] = artisanRun('ranetrace:test', ['--all' => true]);

    expect($exitCode)->toBe(1);

    foreach (['Errors', 'Events', 'Logging', 'Javascript errors', 'Analytics'] as $row) {
        expect(featureTestSummaryResult($output, $row))->toBe('✗ Failed');
    }
});

test('the summary skips every feature while the master switch is off', function (): void {
    Queue::fake();
    Config::set('ranetrace.enabled', false);

    [$exitCode, $output] = artisanRun('ranetrace:test', ['--feature' => 'all']);

    expect($exitCode)->toBe(0);

    foreach (['Errors', 'Events', 'Logging', 'Javascript errors', 'Analytics'] as $row) {
        expect(featureTestSummaryResult($output, $row))->toBe('○ Skipped: disabled');
    }
});

test('a list of features gets the same summary, a switched-off one skipped', function (): void {
    Queue::fake();
    Config::set('ranetrace.logging.enabled', false);

    [$exitCode, $output] = artisanRun('ranetrace:test', ['--feature' => 'errors,logging']);

    expect($exitCode)->toBe(0)
        ->and(featureTestSummaryResult($output, 'Errors'))->toBe('✓ Passed')
        ->and(featureTestSummaryResult($output, 'Logging'))->toBe('○ Skipped: disabled');
});

test('an unknown feature in a list fails the summary', function (): void {
    Queue::fake();
    [$exitCode, $output] = artisanRun('ranetrace:test', ['--feature' => 'errors,nonsense']);

    expect($exitCode)->toBe(1)
        ->and(featureTestSummaryResult($output, 'Nonsense'))->toBe('✗ Failed');
});

/**
 * Hold the buffer lock of a type, so nothing can be added to its buffer: the
 * one way a capture fails that needs no test double.
 */
function featureTestHoldBufferLock(string $type): void
{
    Config::set('ranetrace.batch.lock_wait', 0);

    Cache::store('array')->lock("ranetrace:buffer:{$type}:lock", 60)->get();
}

dataset('every feature test command', [
    'errors' => ['ranetrace:test-errors', 'errors', 'errors', '❌ No test errors were buffered: the capture failed.'],
    'events' => ['ranetrace:test-events', 'events', 'events', '❌ No test events were buffered: the capture failed.'],
    'logging' => ['ranetrace:test-logging', 'logging', 'logs', '❌ No test logs were buffered: the capture failed.'],
    'javascript errors' => ['ranetrace:test-javascript-errors', 'javascript_errors', 'javascript_errors', '❌ The test JavaScript error was not buffered: the capture failed.'],
    'analytics' => ['ranetrace:test-analytics', 'website_analytics', 'page_visits', '❌ The test page visit was not buffered: the capture failed.'],
]);

test('a feature test command whose items never reach the buffer says so, names the internal log and fails', function (string $command, string $feature, string $type, string $line): void {
    Config::set("ranetrace.{$feature}.enabled", true);
    Config::set("ranetrace.{$feature}.queue", false);
    featureTestHoldBufferLock($type);

    [$exitCode, $output] = artisanRun($command);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain($line.' The reason is in '.InternalLogger::todaysFile()."\n")
        ->and($output)->not->toContain('for Ranetrace.')
        ->and($output)->not->toContain('php artisan ranetrace:test')
        ->and(file_get_contents(InternalLogger::todaysFile()))->toContain('Could not acquire cache lock to add items to buffer')
        ->and(featureTestBufferedCount())->toBe(0);
})->with('every feature test command');

test('a feature test command that buffers nothing claims no step a success, and only its closing line gives the verdict', function (string $command, string $feature, string $type, string $line, string $claim): void {
    Config::set("ranetrace.{$feature}.queue", false);
    featureTestHoldBufferLock($type);

    [$exitCode, $output] = artisanRun($command);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain($line)
        ->and($output)->not->toContain('✓')
        ->and($output)->not->toContain($claim);
})->with([
    'errors' => ['ranetrace:test-errors', 'errors', 'errors', '❌ No test errors were buffered: the capture failed.', 'reported'],
    'events' => ['ranetrace:test-events', 'events', 'events', '❌ No test events were buffered: the capture failed.', 'Valid event name'],
    'logging' => ['ranetrace:test-logging', 'logging', 'logs', '❌ No test logs were buffered: the capture failed.', 'written'],
]);

test('a failed capture with internal logging off says to turn it on', function (): void {
    Config::set('ranetrace.errors.queue', false);
    Config::set('ranetrace.internal_logging.enabled', false);
    featureTestHoldBufferLock('errors');

    [$exitCode, $output] = artisanRun('ranetrace:test-errors');

    expect($exitCode)->toBe(1)
        ->and($output)->toContain("❌ No test errors were buffered: the capture failed. Turn internal logging on to see why\n");
});

test('a feature test command that buffered some of its items says how many, and still succeeds', function (): void {
    Config::set('ranetrace.errors.queue', false);

    // The third report finds the buffer lock held, the others find it free.
    RanetraceFacade::swap(new class extends Ranetrace
    {
        private int $reports = 0;

        public function report(Throwable $exception): void
        {
            $lock = Cache::store('array')->lock('ranetrace:buffer:errors:lock', 60);
            $isThird = ++$this->reports === 3;

            if ($isThird) {
                Config::set('ranetrace.batch.lock_wait', 0);
                $lock->get();
            }

            parent::report($exception);

            if ($isThird) {
                $lock->forceRelease();
            }
        }
    });

    [$exitCode, $output] = artisanRun('ranetrace:test-errors');

    expect($exitCode)->toBe(0)
        ->and($output)->toContain(
            '⚠ 3 of 4 test errors buffered for Ranetrace. The others were not captured. The reason is in '.InternalLogger::todaysFile()."\n"
            ."To send them now, run: php artisan ranetrace:work\n"
            ."To see whether the API accepts your key, run: php artisan ranetrace:test\n",
        )
        ->and(app(RanetraceBatchBuffer::class)->count('errors'))->toBe(3);
});

test('with the queue on and a connection that runs its jobs at once, a feature test command counts its items and says buffered', function (string $command, string $feature, string $type, string $job, string $items, ?int $count): void {
    Config::set('queue.default', 'sync');
    Config::set("ranetrace.{$feature}.queue", true);

    [$exitCode, $output] = artisanRun($command);

    $buffered = app(RanetraceBatchBuffer::class)->count($type);
    $counted = $count === null ? "{$buffered} test logs" : $items;

    expect($exitCode)->toBe(0)
        ->and($buffered)->toBeGreaterThan(0)
        ->and($output)->toContain("✅ {$counted} buffered for Ranetrace.\nTo send them now, run: php artisan ranetrace:work\n")
        ->and($output)->not->toContain('queue worker');
})->with('capturing feature test commands');

test('on a connection that runs its jobs after the command, a feature test command says buffered without a count', function (string $command, string $feature, string $type, string $job, string $items, ?int $count): void {
    Config::set('queue.default', 'deferred');
    Config::set("ranetrace.{$feature}.queue", true);

    [$exitCode, $output] = artisanRun($command);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain("✅ {$items} buffered for Ranetrace.\nTo send them now, run: php artisan ranetrace:work\n")
        ->and($output)->not->toContain('queue worker');
})->with('capturing feature test commands');

test('the single-item test commands say buffered, not queued, on a connection that needs no worker', function (string $command, string $feature, string $line): void {
    Config::set("ranetrace.{$feature}.enabled", true);
    Config::set("ranetrace.{$feature}.queue", true);
    Config::set('queue.default', 'sync');

    [$exitCode, $output] = artisanRun($command);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain($line)
        ->and($output)->not->toContain('queue worker');
})->with([
    'javascript errors' => ['ranetrace:test-javascript-errors', 'javascript_errors', "✅ Test JavaScript error buffered for Ranetrace.\nTo send it now, run: php artisan ranetrace:work\nTo see whether the API accepts your key, run: php artisan ranetrace:test\n"],
    'analytics' => ['ranetrace:test-analytics', 'website_analytics', "✅ Test page visit buffered for Ranetrace.\nTo send it now, run: php artisan ranetrace:work\nTo see whether the API accepts your key, run: php artisan ranetrace:test\n"],
]);

test('with the queue on the single-item test commands say their item is queued, not sent, and point at ranetrace:test', function (string $command, string $feature, string $item): void {
    Queue::fake();
    Config::set("ranetrace.{$feature}.enabled", true);
    Config::set("ranetrace.{$feature}.queue", true);

    [$exitCode, $output] = artisanRun($command);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain(
            "✅ {$item} queued for Ranetrace.\n"
            ."Once a queue worker runs it, it will be sent the next time the ranetrace:work command runs.\n"
            ."To send it immediately after that, run: php artisan ranetrace:work\n"
            ."To see whether the API accepts your key, run: php artisan ranetrace:test\n",
        )
        ->and(featureTestBufferedCount())->toBe(0);
})->with([
    'javascript errors' => ['ranetrace:test-javascript-errors', 'javascript_errors', 'Test JavaScript error'],
    'analytics' => ['ranetrace:test-analytics', 'website_analytics', 'Test page visit'],
]);

test('a feature test command leaves the buffer binding as it found it', function (): void {
    Config::set('ranetrace.errors.queue', false);

    artisanRun('ranetrace:test-errors');

    expect(app()->bound(RanetraceBatchBuffer::class))->toBeFalse()
        ->and(app(RanetraceBatchBuffer::class))->not->toBeInstanceOf(CountingBatchBuffer::class);
});

test('a host that binds its own buffer is not counted, and its buffer is the one used', function (): void {
    Config::set('ranetrace.errors.queue', false);
    $own = new RanetraceBatchBuffer;
    app()->instance(RanetraceBatchBuffer::class, $own);

    [$exitCode, $output] = artisanRun('ranetrace:test-errors');

    expect($exitCode)->toBe(0)
        ->and($output)->toContain("✅ 4 test errors buffered for Ranetrace.\n")
        ->and(app(RanetraceBatchBuffer::class))->toBe($own)
        ->and($own->count('errors'))->toBe(4);
});

test('a feature test command reads a queue flag the host config leaves out as capture does', function (string $command, string $feature): void {
    Queue::fake();
    Config::set("ranetrace.{$feature}.enabled", true);
    Config::set("ranetrace.{$feature}", Arr::except(config("ranetrace.{$feature}"), 'queue'));

    [$exitCode, $output] = artisanRun($command);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('queued for Ranetrace.')
        ->and($output)->toMatch('/\|\s*Queue enabled\s*\|\s*(✅ )?Yes\s*\|/u');
})->with([
    'events' => ['ranetrace:test-events', 'events'],
    'logging' => ['ranetrace:test-logging', 'logging'],
    'javascript errors' => ['ranetrace:test-javascript-errors', 'javascript_errors'],
    'analytics' => ['ranetrace:test-analytics', 'website_analytics'],
]);
