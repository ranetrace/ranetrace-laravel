<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Support;

use Carbon\Carbon;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Container\Container;
use Ranetrace\Laravel\Dashboard\DashboardData;
use Throwable;

/**
 * What the application's schedule says about `ranetrace:work`, for
 * `ranetrace:status`.
 *
 * Console only: Laravel defines the schedule (`routes/console.php`,
 * `withSchedule()`) when Artisan starts, so in a web request it is empty and
 * every installation would read as not scheduled. That is why the web
 * dashboard has no check built on this, and why it is not part of
 * `DashboardData::collectStatus()`, which the dashboard shares.
 *
 * Like the rest of the status surface it never throws: a schedule that cannot
 * be read is reported as null, and a lock that cannot be read costs that one
 * answer.
 *
 * @phpstan-type ScheduleEntry array{
 *     command: string,
 *     expression: string,
 *     without_overlapping: bool,
 *     lock_expires_after_minutes: ?int,
 *     lock_held: ?bool,
 *     warnings: list<string>,
 * }
 * @phpstan-type ScheduleAnswer array{
 *     scheduled: bool,
 *     entries: list<ScheduleEntry>,
 *     warnings: list<string>,
 * }
 */
class WorkerSchedule
{
    public const string COMMAND = 'ranetrace:work';

    /**
     * The schedule line the README and the Boost resources document, printed
     * when the worker is not scheduled. WorkerScheduleLineTest holds it to the
     * documented copies.
     */
    public const string DOCUMENTED_LINE = "Schedule::command('ranetrace:work')->everyMinute()->withoutOverlapping(5)->runInBackground();";

    /**
     * Laravel's default for `withoutOverlapping()`: 24 hours.
     */
    public const int DEFAULT_LOCK_EXPIRY_MINUTES = 1440;

    public const string WARNING_NOT_SCHEDULED = 'not_scheduled';

    public const string WARNING_LOCK_STUCK = 'lock_stuck';

    public const string WARNING_DEFAULT_LOCK_EXPIRY = 'default_lock_expiry';

    protected const string EVERY_MINUTE = '* * * * *';

    public function __construct(protected Container $container) {}

    /**
     * Whether a cron expression runs every minute.
     */
    public static function runsEveryMinute(string $expression): bool
    {
        return preg_replace('/\s+/', ' ', mb_trim($expression)) === self::EVERY_MINUTE;
    }

    /**
     * Read the schedule against a `DashboardData::collectStatus()` array, or
     * null when the schedule cannot be read.
     *
     * `entries` holds one item per scheduled `ranetrace:work`: `command` from
     * `ranetrace:work` on, without the PHP binary and `artisan`; `expression`
     * the cron expression; `lock_expires_after_minutes` and `lock_held` null
     * when the entry has no overlap lock, and `lock_held` also null when the
     * lock could not be read. `warnings` lists the codes that apply, each
     * entry's own and the top-level union with `not_scheduled`:
     *
     * - `default_lock_expiry`: the overlap lock expires after Laravel's 24 hour
     *   default, which a killed run leaves behind.
     * - `lock_stuck`: the lock is held while `drain.stalled` is not empty.
     * - `not_scheduled`: no entry, and no feature had a successful batch within
     *   `DashboardData::DRAIN_STALE_SECONDS`. With such a batch the worker runs
     *   some other way, and `scheduled` is false without a warning.
     *
     * @param  array<string, mixed>  $status
     * @return ScheduleAnswer|null
     */
    public function inspect(array $status): ?array
    {
        try {
            return $this->inspectSchedule($this->container->make(Schedule::class), $status);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $status
     * @return ScheduleAnswer
     */
    protected function inspectSchedule(Schedule $schedule, array $status): array
    {
        $isDrainStalled = ! empty($status['drain']['stalled'] ?? []);

        $entries = [];
        foreach ($schedule->events() as $event) {
            $command = $this->workerCommand($event);

            if ($command === null) {
                continue;
            }

            $entries[] = $this->describe($event, $command, $isDrainStalled);
        }

        $warnings = [];

        if ($entries === [] && ! $this->hasRecentBatch($status)) {
            $warnings[] = self::WARNING_NOT_SCHEDULED;
        }

        foreach ([self::WARNING_LOCK_STUCK, self::WARNING_DEFAULT_LOCK_EXPIRY] as $code) {
            if (array_any($entries, fn (array $entry): bool => in_array($code, $entry['warnings'], true))) {
                $warnings[] = $code;
            }
        }

        return [
            'scheduled' => $entries !== [],
            'entries' => $entries,
            'warnings' => $warnings,
        ];
    }

    /**
     * The event's command from `ranetrace:work` on, or null when the event
     * does not run the worker. Closure and job events carry no command.
     */
    protected function workerCommand(Event $event): ?string
    {
        $command = $event->command ?? null;

        if (! is_string($command)) {
            return null;
        }

        if (preg_match('/(?<![\w:-])'.preg_quote(self::COMMAND, '/').'(?![\w:-]).*$/s', $command, $matches) !== 1) {
            return null;
        }

        return mb_scrub(mb_trim($matches[0]), 'UTF-8');
    }

    /**
     * @return ScheduleEntry
     */
    protected function describe(Event $event, string $command, bool $isDrainStalled): array
    {
        $withoutOverlapping = $event->withoutOverlapping;
        $expiresAfter = $withoutOverlapping ? (int) $event->expiresAt : null;
        $lockHeld = $withoutOverlapping ? $this->isLockHeld($event) : null;

        $warnings = [];

        if ($lockHeld === true && $isDrainStalled) {
            $warnings[] = self::WARNING_LOCK_STUCK;
        }

        if ($expiresAfter === self::DEFAULT_LOCK_EXPIRY_MINUTES) {
            $warnings[] = self::WARNING_DEFAULT_LOCK_EXPIRY;
        }

        return [
            'command' => $command,
            'expression' => mb_scrub($event->expression, 'UTF-8'),
            'without_overlapping' => $withoutOverlapping,
            'lock_expires_after_minutes' => $expiresAfter,
            'lock_held' => $lockHeld,
            'warnings' => $warnings,
        ];
    }

    /**
     * Whether the overlap lock is held right now, or null when the mutex
     * cannot be read, such as when its cache store is down.
     */
    protected function isLockHeld(Event $event): ?bool
    {
        try {
            return (bool) $event->mutex->exists($event);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whether any feature had a successful batch within the window that
     * defines a stalled drain.
     *
     * @param  array<string, mixed>  $status
     */
    protected function hasRecentBatch(array $status): bool
    {
        $now = Carbon::now()->timestamp;

        foreach ($status['drain']['last_batch'] ?? [] as $timestamp) {
            if (is_int($timestamp) && ($now - $timestamp) <= DashboardData::DRAIN_STALE_SECONDS) {
                return true;
            }
        }

        return false;
    }
}
