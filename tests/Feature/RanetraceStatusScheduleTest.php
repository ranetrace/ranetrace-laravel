<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Ranetrace\Laravel\Commands\RanetraceWorkCommand;
use Ranetrace\Laravel\Dashboard\DashboardData;
use Ranetrace\Laravel\Jobs\SendBatchToRanetraceJob;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;
use Ranetrace\Laravel\Services\RanetracePauseManager;
use Ranetrace\Laravel\Support\WorkerSchedule;

beforeEach(function (): void {
    Config::set('ranetrace.batch.cache_driver', 'array');
    Cache::store('array')->flush();
});

/**
 * The text output of ranetrace:status, without styling tags.
 */
function scheduleStatusText(): string
{
    expect(Artisan::call('ranetrace:status'))->toBe(0);

    return Artisan::output();
}

/**
 * The `schedule` key of `ranetrace:status --json`, after checking that the
 * whole output is valid JSON.
 *
 * @return array<string, mixed>|null
 */
function scheduleStatusJson(): ?array
{
    expect(Artisan::call('ranetrace:status', ['--json' => true]))->toBe(0);

    $status = json_decode(mb_trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

    expect($status)->toHaveKey('schedule');

    return $status['schedule'];
}

/**
 * Leave an events item in the buffer past the stalled-drain window, with no
 * batch sent.
 */
function stallTheEventsDrain(): void
{
    app(RanetraceBatchBuffer::class)->addItem('events', ['event_name' => 'e1']);

    test()->travel(DashboardData::DRAIN_STALE_SECONDS + 1)->seconds();
}

/**
 * Fill the events buffer to 80 percent of its capacity, where status counts
 * it as near capacity.
 */
function fillTheEventsBufferNearCapacity(): void
{
    Config::set('ranetrace.batch.max_buffer_size', 10);

    $buffer = app(RanetraceBatchBuffer::class);

    for ($i = 0; $i < 8; $i++) {
        $buffer->addItem('events', ['event_name' => "e{$i}"]);
    }
}

/**
 * Record a successful events batch this many seconds ago.
 */
function recordEventsBatchSecondsAgo(int $seconds): void
{
    Cache::store('array')->forever(SendBatchToRanetraceJob::LAST_BATCH_PREFIX.'events', now()->subSeconds($seconds)->timestamp);
}

test('a worker scheduled with a five minute lock gets one healthy line and no warning', function (): void {
    app(Schedule::class)->command('ranetrace:work')->everyMinute()->withoutOverlapping(5)->runInBackground();

    $output = scheduleStatusText();

    expect($output)->toContain("Schedule\n")
        ->and($output)->toContain('✓ ranetrace:work runs every minute, overlap lock expires after 5 minutes')
        ->and($output)->not->toContain('Recommendations')
        ->and(scheduleStatusJson())->toBe([
            'scheduled' => true,
            'entries' => [[
                'command' => 'ranetrace:work',
                'expression' => '* * * * *',
                'without_overlapping' => true,
                'lock_expires_after_minutes' => 5,
                'lock_held' => false,
                'warnings' => [],
            ]],
            'warnings' => [],
        ]);
});

test('a worker scheduled with the bare withoutOverlapping() is warned about the 24 hour default and given the fix', function (): void {
    app(Schedule::class)->command('ranetrace:work')->everyMinute()->withoutOverlapping()->runInBackground();

    $output = scheduleStatusText();
    $json = scheduleStatusJson();

    expect($output)->toContain('! ranetrace:work runs every minute, overlap lock expires after 1440 minutes')
        ->and($output)->toContain("  1440 minutes is Laravel's 24 hour default.")
        ->and($output)->toContain('so nothing is sent for up to 24 hours.')
        ->and($output)->toContain('  → Change the line in routes/console.php to ->withoutOverlapping(5)')
        ->and($output)->toContain('Recommendations')
        ->and($output)->toContain('• Give the overlap lock of ranetrace:work an expiry: change its line in routes/console.php to ->withoutOverlapping(5)')
        ->and($output)->toContain('✓ Overall status: healthy')
        ->and($json['warnings'])->toBe([WorkerSchedule::WARNING_DEFAULT_LOCK_EXPIRY])
        ->and($json['entries'][0]['warnings'])->toBe(['default_lock_expiry'])
        ->and($json['entries'][0]['lock_expires_after_minutes'])->toBe(1440);
});

test('an explicit lock expiry other than the default gets no warning', function (int $minutes): void {
    app(Schedule::class)->command('ranetrace:work')->everyMinute()->withoutOverlapping($minutes);

    expect(scheduleStatusJson()['warnings'])->toBe([])
        ->and(scheduleStatusText())->not->toContain('Recommendations');
})->with([1, 60, 1439, 1441]);

test('a worker scheduled without an overlap lock gets no warning', function (): void {
    app(Schedule::class)->command('ranetrace:work')->everyMinute();

    $json = scheduleStatusJson();

    expect(scheduleStatusText())->toContain('✓ ranetrace:work runs every minute, without an overlap lock')
        ->and($json['warnings'])->toBe([])
        ->and($json['entries'][0])->toMatchArray([
            'without_overlapping' => false,
            'lock_expires_after_minutes' => null,
            'lock_held' => null,
        ]);
});

test('a held lock while the drain is stalled is reported as stuck with the command that releases it', function (): void {
    stallTheEventsDrain();

    $event = app(Schedule::class)->command('ranetrace:work')->everyMinute()->withoutOverlapping(5);
    $event->mutex->create($event);

    $output = scheduleStatusText();
    $json = scheduleStatusJson();

    expect($output)->toContain('✗ ranetrace:work runs every minute, overlap lock expires after 5 minutes')
        ->and($output)->toContain('  The overlap lock is held while buffered items have waited over 10 minutes with no batch sent, so it looks stuck.')
        ->and($output)->toContain('  → Release it with php artisan schedule:clear-cache')
        ->and($output)->toContain('  ranetrace:work is scheduled, and its overlap lock looks stuck (see Schedule below).')
        ->and($output)->toContain('• Release the stuck overlap lock of ranetrace:work: php artisan schedule:clear-cache')
        ->and($output)->toContain('✗ Overall status: issues detected')
        ->and($output)->not->toContain('Is the ranetrace:work command scheduled')
        ->and($output)->not->toContain('Ensure ranetrace:work is scheduled')
        ->and($json['warnings'])->toBe([WorkerSchedule::WARNING_LOCK_STUCK])
        ->and($json['entries'][0]['lock_held'])->toBeTrue();
});

test('a held lock with nothing stalled is a run in progress and says nothing', function (): void {
    $event = app(Schedule::class)->command('ranetrace:work')->everyMinute()->withoutOverlapping(5);
    $event->mutex->create($event);

    $json = scheduleStatusJson();

    expect(scheduleStatusText())->toContain('✓ ranetrace:work runs every minute, overlap lock expires after 5 minutes')
        ->not->toContain('stuck')
        ->and($json['entries'][0]['lock_held'])->toBeTrue()
        ->and($json['warnings'])->toBe([]);
});

test('a held lock while only a paused feature waits too long is not called stuck', function (): void {
    // A paused feature is not dispatched, so the lock cannot be what holds it up.
    $this->freezeTime();
    app(RanetraceBatchBuffer::class)->addItem('events', ['event_name' => 'e1']);
    $this->travel(DashboardData::PAUSED_DRAIN_GRACE_SECONDS + 1)->seconds();
    app(RanetracePauseManager::class)->setFeaturePause('events', 900, '403');

    $event = app(Schedule::class)->command('ranetrace:work')->everyMinute()->withoutOverlapping(5);
    $event->mutex->create($event);

    $json = scheduleStatusJson();

    expect(scheduleStatusText())->toContain('! Paused and not sending: events')
        ->not->toContain('stuck')
        ->and($json['warnings'])->toBe([]);
});

test('a stalled drain with no held lock points past the schedule at the cron entry and the queue worker', function (): void {
    stallTheEventsDrain();
    app(Schedule::class)->command('ranetrace:work')->everyMinute()->withoutOverlapping(5);

    $output = scheduleStatusText();

    expect($output)->toContain("  ranetrace:work is scheduled. Check that the scheduler's own cron entry runs schedule:run every minute, and that a queue worker processes the batch jobs.")
        ->and($output)->toContain("• Check that the scheduler's own cron entry runs schedule:run every minute, and that a queue worker processes the batch jobs")
        ->and($output)->not->toContain('Is the ranetrace:work command scheduled')
        ->and($output)->not->toContain('Ensure ranetrace:work is scheduled')
        ->and(scheduleStatusJson()['warnings'])->toBe([]);
});

test('a killed run under the default lock gets both the stuck lock and the expiry warning', function (): void {
    stallTheEventsDrain();

    $event = app(Schedule::class)->command('ranetrace:work')->everyMinute()->withoutOverlapping();
    $event->mutex->create($event);

    $output = scheduleStatusText();

    expect($output)->toContain('✗ ranetrace:work runs every minute, overlap lock expires after 1440 minutes')
        ->and($output)->toContain('  → Release it with php artisan schedule:clear-cache')
        ->and($output)->toContain('  → Change the line in routes/console.php to ->withoutOverlapping(5)')
        ->and(scheduleStatusJson()['warnings'])->toBe(['lock_stuck', 'default_lock_expiry']);
});

test('a worker missing from the schedule with no recent batch is warned about, with the line to add', function (?int $batchSecondsAgo): void {
    if ($batchSecondsAgo !== null) {
        recordEventsBatchSecondsAgo($batchSecondsAgo);
    }

    $output = scheduleStatusText();
    $lines = explode("\n", $output);

    expect($output)->toContain('✗ ranetrace:work was not found in the schedule')
        ->and($output)->toContain('✓ Overall status: healthy')
        ->and($output)->toContain('  Nothing is sent to Ranetrace until it runs every minute.')
        ->and($output)->toContain('  → Add this line to routes/console.php:')
        ->and($lines)->toContain('    '.WorkerSchedule::DOCUMENTED_LINE)
        ->and($output)->toContain('• Schedule ranetrace:work every minute in routes/console.php (the line is under Schedule above)')
        ->and(scheduleStatusJson())->toBe([
            'scheduled' => false,
            'entries' => [],
            'warnings' => [WorkerSchedule::WARNING_NOT_SCHEDULED],
        ]);
})->with([
    'never sent a batch' => [null],
    'last batch past the stalled-drain window' => [601],
]);

test('a worker missing from the schedule that sent a batch recently runs some other way and gets no warning', function (): void {
    recordEventsBatchSecondsAgo(60);

    $output = scheduleStatusText();

    expect($output)->toContain('✓ ranetrace:work was not found in the schedule, but a batch was sent in the last 10 minutes, so it runs some other way')
        ->and($output)->not->toContain('Recommendations')
        ->and(scheduleStatusJson())->toBe([
            'scheduled' => false,
            'entries' => [],
            'warnings' => [],
        ]);
});

test('the worker is recognised however it is scheduled', function (string $command, array $parameters, string $expected): void {
    app(Schedule::class)->command($command, $parameters)->everyMinute()->withoutOverlapping(5);

    $json = scheduleStatusJson();

    expect($json['scheduled'])->toBeTrue()
        ->and($json['entries'])->toHaveCount(1)
        ->and($json['entries'][0]['command'])->toBe($expected)
        ->and($json['warnings'])->toBe([]);
})->with([
    'by name' => ['ranetrace:work', [], 'ranetrace:work'],
    'by class name' => [RanetraceWorkCommand::class, [], 'ranetrace:work'],
    'with a type in the string' => ['ranetrace:work --type=errors', [], 'ranetrace:work --type=errors'],
    'with a type as a parameter' => ['ranetrace:work', ['--type' => 'errors'], "ranetrace:work --type='errors'"],
]);

test('closure, job and other command entries are ignored without error', function (): void {
    $schedule = app(Schedule::class);
    $schedule->call(fn (): null => null)->everyMinute();
    $schedule->job(SendBatchToRanetraceJob::class)->everyMinute();
    $schedule->command('ranetrace:status')->everyMinute();
    $schedule->command('ranetrace:pause-clear --all')->daily();
    $schedule->exec('echo ranetrace:workers')->everyMinute();

    expect(scheduleStatusJson())->toBe([
        'scheduled' => false,
        'entries' => [],
        'warnings' => [WorkerSchedule::WARNING_NOT_SCHEDULED],
    ]);
});

test('an event whose command is null is ignored without error', function (): void {
    $schedule = app(Schedule::class);
    $schedule->exec('ranetrace:work')->command = null;
    $schedule->command('ranetrace:work')->everyMinute()->withoutOverlapping(5);

    expect(scheduleStatusJson()['entries'])->toHaveCount(1);
});

test('with two entries the expiry warning names the one that has the default', function (): void {
    $schedule = app(Schedule::class);
    $schedule->command('ranetrace:work --type=errors')->everyMinute()->withoutOverlapping(5);
    $schedule->command('ranetrace:work --type=logs')->everyFiveMinutes()->withoutOverlapping();

    $output = scheduleStatusText();
    $json = scheduleStatusJson();

    expect($output)->toContain('✓ ranetrace:work --type=errors runs every minute, overlap lock expires after 5 minutes')
        ->and($output)->toContain('! ranetrace:work --type=logs runs on the cron expression */5 * * * *, overlap lock expires after 1440 minutes')
        ->and($output)->toContain('• Give the overlap lock of ranetrace:work --type=logs an expiry')
        ->and($output)->not->toContain('• Give the overlap lock of ranetrace:work --type=errors')
        ->and(mb_substr_count($output, '→ Change the line in routes/console.php'))->toBe(1)
        ->and(array_column($json['entries'], 'warnings', 'command'))->toBe([
            'ranetrace:work --type=errors' => [],
            'ranetrace:work --type=logs' => ['default_lock_expiry'],
        ])
        ->and($json['warnings'])->toBe(['default_lock_expiry']);
});

test('a mutex that cannot be read costs the lock answer only', function (): void {
    stallTheEventsDrain();

    $event = app(Schedule::class)->command('ranetrace:work')->everyMinute()->withoutOverlapping(5);
    $event->mutex = new class implements EventMutex
    {
        public function create(Event $event): bool
        {
            return true;
        }

        public function exists(Event $event): bool
        {
            throw new RuntimeException('Cache store is down');
        }

        public function forget(Event $event): void {}
    };

    $json = scheduleStatusJson();

    expect(scheduleStatusText())->toContain('✓ ranetrace:work runs every minute, overlap lock expires after 5 minutes')
        ->and($json['entries'][0]['lock_held'])->toBeNull()
        ->and($json['warnings'])->toBe([]);
});

test('a schedule that cannot be resolved leaves the schedule out and keeps the old question', function (): void {
    stallTheEventsDrain();
    app()->bind(Schedule::class, fn (): never => throw new RuntimeException('No schedule here'));

    $output = scheduleStatusText();

    expect(explode("\n", $output))->not->toContain('Schedule')
        ->and($output)->toContain('  Is the ranetrace:work command scheduled (every minute)?')
        ->and($output)->toContain('• Ensure ranetrace:work is scheduled every minute (see documentation)')
        ->and(scheduleStatusJson())->toBeNull();
});

test('a buffer near capacity under a worker scheduled every minute points at pauses and the queue worker', function (): void {
    fillTheEventsBufferNearCapacity();
    app(Schedule::class)->command('ranetrace:work')->everyMinute()->withoutOverlapping(5);

    expect(scheduleStatusText())
        ->toContain('• Buffers approaching capacity, data may be dropped: events')
        ->toContain('• ranetrace:work is scheduled every minute. Check for a paused feature above, and that a queue worker processes the batch jobs: a run sends at most 1000 items per feature')
        ->not->toContain('running on schedule');
});

test('a buffer near capacity under a worker scheduled less often than every minute says to schedule it every minute', function (): void {
    fillTheEventsBufferNearCapacity();
    app(Schedule::class)->command('ranetrace:work')->everyFiveMinutes()->withoutOverlapping(5);

    expect(scheduleStatusText())
        ->toContain('• ranetrace:work is scheduled, but not every minute. A run sends at most 1000 items per feature, so schedule it every minute')
        ->not->toContain('running on schedule');
});

test('a buffer near capacity with the worker missing from the schedule leaves the advice to the schedule recommendation', function (): void {
    fillTheEventsBufferNearCapacity();

    $output = scheduleStatusText();

    expect($output)
        ->toContain('• Buffers approaching capacity, data may be dropped: events')
        ->toContain('• Schedule ranetrace:work every minute in routes/console.php (the line is under Schedule above)')
        ->not->toContain('running on schedule')
        ->and(mb_substr_count($output, '• '))->toBe(2);
});

test('a buffer near capacity under a worker that runs some other way says to check that it runs every minute', function (): void {
    fillTheEventsBufferNearCapacity();
    recordEventsBatchSecondsAgo(60);

    expect(scheduleStatusText())
        ->toContain('• ranetrace:work is not in the schedule. Check that whatever runs it does so every minute')
        ->not->toContain('running on schedule');
});

test('a buffer near capacity whose drain is also stalled gets the stalled advice once and no second one', function (): void {
    fillTheEventsBufferNearCapacity();
    $this->travel(DashboardData::DRAIN_STALE_SECONDS + 1)->seconds();
    app(Schedule::class)->command('ranetrace:work')->everyMinute()->withoutOverlapping(5);

    $output = scheduleStatusText();

    expect($output)
        ->toContain('• Buffers approaching capacity, data may be dropped: events')
        ->toContain("• Check that the scheduler's own cron entry runs schedule:run every minute, and that a queue worker processes the batch jobs")
        ->not->toContain('ranetrace:work is scheduled every minute. Check for a paused feature')
        ->not->toContain('running on schedule');
});

test('a buffer near capacity whose feature is paused and not sending leaves the advice to the paused line', function (): void {
    $this->freezeTime();
    fillTheEventsBufferNearCapacity();
    $this->travel(DashboardData::PAUSED_DRAIN_GRACE_SECONDS + 1)->seconds();
    app(RanetracePauseManager::class)->setFeaturePause('events', 900, '403');
    app(Schedule::class)->command('ranetrace:work')->everyMinute()->withoutOverlapping(5);

    $output = scheduleStatusText();

    expect($output)
        ->toContain('• Buffers approaching capacity, data may be dropped: events')
        ->toContain('• Paused and not sending: events.')
        ->not->toContain('ranetrace:work is scheduled every minute. Check for a paused feature')
        ->and(mb_substr_count($output, '• '))->toBe(2);
});

test('a buffer near capacity keeps its advice while only another feature is paused and not sending', function (): void {
    $this->freezeTime();
    app(RanetraceBatchBuffer::class)->addItem('errors', ['message' => 'a']);
    $this->travel(DashboardData::PAUSED_DRAIN_GRACE_SECONDS + 1)->seconds();
    app(RanetracePauseManager::class)->setFeaturePause('errors', 900, '403');
    fillTheEventsBufferNearCapacity();
    app(Schedule::class)->command('ranetrace:work')->everyMinute()->withoutOverlapping(5);

    expect(scheduleStatusText())
        ->toContain('• Paused and not sending: errors.')
        ->toContain('• ranetrace:work is scheduled every minute. Check for a paused feature above, and that a queue worker processes the batch jobs: a run sends at most 1000 items per feature');
});

test('a buffer near capacity with a schedule that cannot be read keeps the old question', function (): void {
    fillTheEventsBufferNearCapacity();
    app()->bind(Schedule::class, fn (): never => throw new RuntimeException('No schedule here'));

    expect(scheduleStatusText())->toContain('• Check if ranetrace:work command is running on schedule');
});

test('a disabled installation is still told when the worker is missing from the schedule', function (): void {
    // The schedule line lives in code that is the same in every environment,
    // so its absence is worth knowing where Ranetrace is switched off too.
    Config::set('ranetrace.enabled', false);

    expect(scheduleStatusText())
        ->toContain('✗ ranetrace:work was not found in the schedule')
        ->toContain('• Schedule ranetrace:work every minute in routes/console.php (the line is under Schedule above)');
});

test('a scheduled command that is not valid UTF-8 still gives valid JSON', function (): void {
    app(Schedule::class)->command("ranetrace:work --type=\xB1")->everyMinute()->withoutOverlapping(5);

    $json = scheduleStatusJson();

    expect($json['entries'][0]['command'])->toStartWith('ranetrace:work --type=')
        ->and(mb_check_encoding($json['entries'][0]['command'], 'UTF-8'))->toBeTrue();
});

test('the schedule output has no all-caps words besides acronyms', function (): void {
    stallTheEventsDrain();

    $event = app(Schedule::class)->command('ranetrace:work')->everyMinute()->withoutOverlapping();
    $event->mutex->create($event);
    $troubled = scheduleStatusText();

    app()->instance(Schedule::class, new Schedule);
    $missing = scheduleStatusText();

    foreach ([$troubled, $missing] as $output) {
        preg_match_all('/(?<!\w)[A-Z]{3,}(?!\w)/u', $output, $matches);

        expect(array_diff($matches[0], ['API', 'JSON', 'MCP']))->toBe([]);
    }
});
