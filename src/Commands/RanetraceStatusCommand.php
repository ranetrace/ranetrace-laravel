<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Ranetrace\Laravel\Dashboard\DashboardData;
use Ranetrace\Laravel\Support\BatchConfig;
use Ranetrace\Laravel\Support\WorkerSchedule;
use Throwable;

/**
 * @phpstan-import-type ScheduleEntry from WorkerSchedule
 * @phpstan-import-type ScheduleAnswer from WorkerSchedule
 */
class RanetraceStatusCommand extends Command
{
    protected $signature = 'ranetrace:status
                            {--json : Output as JSON instead of formatted text}';

    protected $description = 'Display Ranetrace health status including pauses, buffers, and recent activity';

    /**
     * The status is `DashboardData::collectStatus()` plus a `schedule` key,
     * see {@see WorkerSchedule::inspect()}.
     */
    public function handle(DashboardData $data, WorkerSchedule $workerSchedule): int
    {
        $status = $data->collectStatus();
        $status['schedule'] = $workerSchedule->inspect($status);

        if ($this->option('json')) {
            $this->line(json_encode($status, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->displayStatus($status);

        return self::SUCCESS;
    }

    /**
     * Display formatted status output.
     *
     * @param  array<string, mixed>  $status
     */
    protected function displayStatus(array $status): void
    {
        // Header
        $this->newLine();
        $this->line('╔═══════════════════════════════════════════════════════════════╗');
        $this->line('║                    Ranetrace health status                    ║');
        $this->line('╚═══════════════════════════════════════════════════════════════╝');
        $this->newLine();

        // Overall health
        if ($status['healthy']) {
            $this->info('✓ Overall status: healthy');
        } else {
            $this->error('✗ Overall status: issues detected');
        }

        $this->newLine();

        // Configuration
        $this->line('<fg=cyan>Configuration</>');
        $this->line('─────────────────────────────────────────────────────────────');
        $this->line('Enabled: '.($status['config']['enabled'] ? '<fg=green>Yes</>' : '<fg=red>No</>'));
        if (! $status['config']['enabled']) {
            $this->line(str_repeat(' ', 2).'→ Nothing is captured or sent. Set RANETRACE_ENABLED=true to turn it on');
        }
        $this->line('Ingest API key: '.($status['config']['api_key_configured'] ? '<fg=green>Configured</>' : '<fg=red>Not configured</>'));
        $this->line('Cache driver: '.BatchConfig::describeCacheStore($status['config']['cache_driver'], $status['config']['cache_driver_is_app_default']));
        $this->line('Queue name: '.BatchConfig::describeQueue($status['config']['queue_landing'], $status['config']['queue_connection'], $status['config']['queue_name']));
        $this->newLine();

        // Global pause
        $this->line('<fg=cyan>Global pause status</>');
        $this->line('─────────────────────────────────────────────────────────────');
        if ($status['pauses']['global']) {
            $pause = $status['pauses']['global'];
            if ($pause['paused']) {
                $this->error('✗ Paused');
                $this->line('  Reason: '.$pause['reason']);
                $this->line('  Until: '.$pause['paused_until']);
                $this->line('  Remaining: '.$this->formatDuration($pause['time_remaining_seconds']));
            } else {
                $this->warn('○ Pause expired (cleaning up)');
            }
        } else {
            $this->info('✓ Not paused');
        }
        $this->newLine();

        // Feature pauses
        $this->line('<fg=cyan>Feature pause status</>');
        $this->line('─────────────────────────────────────────────────────────────');
        foreach ($status['pauses']['features'] as $feature => $pause) {
            if ($pause) {
                if ($pause['paused']) {
                    $this->line(sprintf(
                        '  <fg=red>✗</> %-20s <fg=red>Paused</> (reason: %s, remaining: %s)',
                        $feature,
                        $pause['reason'],
                        $this->formatDuration($pause['time_remaining_seconds'])
                    ));

                    $guidance = $this->pauseGuidance($pause['reason']);
                    if ($guidance !== null) {
                        $this->line(str_repeat(' ', 25).'→ '.$guidance);
                    }
                } else {
                    $this->line(sprintf(
                        '  <fg=yellow>○</> %-20s <fg=yellow>Pause expired</>',
                        $feature
                    ));
                }
            } else {
                $this->line(sprintf(
                    '  <fg=green>✓</> %-20s Active',
                    $feature
                ));
            }
        }
        $this->newLine();

        // Buffers
        $this->line('<fg=cyan>Buffer status</>');
        $this->line('─────────────────────────────────────────────────────────────');
        $this->line('Total items: '.$status['buffers']['total']);
        $this->line('Max per feature: '.$status['buffers']['max_per_feature']);
        $this->newLine();

        foreach ($status['buffers']['features'] as $feature => $count) {
            $percentage = $status['buffers']['max_per_feature'] > 0
                ? ($count / $status['buffers']['max_per_feature']) * 100
                : 0;

            $bar = $this->createProgressBar($percentage);

            if ($percentage >= 80) {
                $color = 'red';
                $icon = '✗';
            } elseif ($percentage >= 50) {
                $color = 'yellow';
                $icon = '!';
            } else {
                $color = 'green';
                $icon = '✓';
            }

            $this->line(sprintf(
                '  <fg=%s>%s</> %-20s %6d items [%s] %3.0f%%',
                $color,
                $icon,
                $feature,
                $count,
                $bar,
                $percentage
            ));
        }
        $this->newLine();

        // Drain warning: buffers holding items with no recent batch send
        if (! empty($status['drain']['stalled'])) {
            $this->warn('! No recent batch drain for: '.implode(', ', $status['drain']['stalled']));
            $this->line('  Buffered items are not being sent to Ranetrace.');
            $this->line('  '.$this->stalledDrainCause($status['schedule']));
            $this->newLine();
        }

        if (! empty($status['drain']['paused_stalled'])) {
            $this->warn('! Paused and not sending: '.implode(', ', $status['drain']['paused_stalled']));
            $this->line('  Buffered items are not being sent to Ranetrace, and have waited longer than one pause explains.');
            $this->line('  The reason is on each feature\'s row under "Feature pause status" above.');
            $this->newLine();
        }

        if ($status['schedule'] !== null) {
            $this->displaySchedule($status['schedule']);
        }

        $this->displayHeartbeat($status['heartbeat']['last_sent']);

        // Failed jobs
        $this->line('<fg=cyan>Failed jobs (last 24h)</>');
        $this->line('─────────────────────────────────────────────────────────────');
        if ($status['failed_jobs_last_24h'] === 0) {
            $this->info('✓ No failed jobs');
        } elseif ($status['failed_jobs_last_24h'] < 10) {
            $this->warn('! '.$status['failed_jobs_last_24h'].' failed job(s): review queue:failed');
        } else {
            $this->error('✗ '.$status['failed_jobs_last_24h'].' failed job(s): investigate now');
        }
        $this->newLine();

        $scheduleWarnings = $status['schedule']['warnings'] ?? [];

        // Recommendations
        if (! $status['healthy'] || $scheduleWarnings !== []) {
            $this->line('<fg=cyan>Recommendations</>');
            $this->line('─────────────────────────────────────────────────────────────');

            if ($status['config']['enabled'] && ! $status['config']['api_key_configured']) {
                $this->line('• Set RANETRACE_KEY in the environment that reports to Ranetrace (on a server, in its .env). It is the ingest key; the MCP tools use an OAuth connection, held by your MCP client');
            }

            if ($status['pauses']['global']) {
                $this->line('• Check API credentials (401 indicates invalid/revoked key)');
                $this->line('• Run: php artisan ranetrace:pause-clear --global');
            }

            $nearCapacity = array_keys(array_filter(
                $status['buffers']['features'],
                fn (int $count): bool => $count >= $status['buffers']['max_per_feature'] * DashboardData::NEAR_CAPACITY_RATIO
            ));
            if (! empty($nearCapacity)) {
                $this->line('• Buffers approaching capacity, data may be dropped: '.implode(', ', $nearCapacity));

                $isAnsweredByDrainLine = ! empty($status['drain']['stalled'])
                    || array_diff($nearCapacity, $status['drain']['paused_stalled']) === [];

                $advice = $this->nearCapacityAdvice($status['schedule'], $isAnsweredByDrainLine);
                if ($advice !== null) {
                    $this->line('• '.$advice);
                }
            }

            if (! empty($status['drain']['stalled'])) {
                $this->line('• No recent drain for: '.implode(', ', $status['drain']['stalled']));

                $advice = $this->stalledDrainAdvice($status['schedule']);
                if ($advice !== null) {
                    $this->line('• '.$advice);
                }
            }

            if (! empty($status['drain']['paused_stalled'])) {
                $this->line('• Paused and not sending: '.implode(', ', $status['drain']['paused_stalled']).'. Fix the reason shown under "Feature pause status". The pause ends by itself, or clear it with: php artisan ranetrace:pause-clear --feature=<type>');
            }

            foreach ($this->scheduleRecommendations($status['schedule']) as $recommendation) {
                $this->line('• '.$recommendation);
            }

            if ($status['failed_jobs_last_24h'] >= 10) {
                $this->line('• High failed job count: check the ranetrace_internal logs');
                $this->line('• Review failed_jobs table for details');
            }

            $this->newLine();
        }

        $this->line('Last checked: '.$status['timestamp']);
        $this->newLine();

        $this->displayDashboardHint();
    }

    /**
     * The Schedule section: one line per scheduled ranetrace:work, followed by
     * what each warning on it costs and the fix.
     *
     * @param  ScheduleAnswer  $schedule
     */
    protected function displaySchedule(array $schedule): void
    {
        $this->line('<fg=cyan>Schedule</>');
        $this->line('─────────────────────────────────────────────────────────────');

        if (! $schedule['scheduled']) {
            if (in_array(WorkerSchedule::WARNING_NOT_SCHEDULED, $schedule['warnings'], true)) {
                $this->error('✗ '.WorkerSchedule::COMMAND.' was not found in the schedule');
                $this->line('  Nothing is sent to Ranetrace until it runs every minute.');
                $this->line('  → Add this line to routes/console.php:');
                $this->line('    '.WorkerSchedule::DOCUMENTED_LINE);
            } else {
                $this->info(sprintf(
                    '✓ %s was not found in the schedule, but a batch was sent in the last %d minutes, so it runs some other way',
                    WorkerSchedule::COMMAND,
                    $this->staleMinutes(),
                ));
            }

            $this->newLine();

            return;
        }

        foreach ($schedule['entries'] as $entry) {
            $summary = $entry['command'].' '.$this->describeEntry($entry);

            if (in_array(WorkerSchedule::WARNING_LOCK_STUCK, $entry['warnings'], true)) {
                $this->error('✗ '.$summary);
                $this->line(sprintf(
                    '  The overlap lock is held while buffered items have waited over %d minutes with no batch sent, so it looks stuck.',
                    $this->staleMinutes(),
                ));
                $this->line('  → Release it with php artisan schedule:clear-cache');
            } elseif ($entry['warnings'] !== []) {
                $this->warn('! '.$summary);
            } else {
                $this->info('✓ '.$summary);
            }

            if (in_array(WorkerSchedule::WARNING_DEFAULT_LOCK_EXPIRY, $entry['warnings'], true)) {
                $this->line('  1440 minutes is Laravel\'s 24 hour default. A run that is killed before it finishes (a deploy, an out-of-memory kill)');
                $this->line('  leaves the lock behind, and every run is skipped until it expires, so nothing is sent for up to 24 hours.');
                $this->line('  → Change the line in routes/console.php to ->withoutOverlapping(5)');
            }
        }

        $this->newLine();
    }

    /**
     * The Heartbeat section: when the API last accepted one. Information only,
     * so it carries no colour and no recommendation.
     */
    protected function displayHeartbeat(?int $lastSent): void
    {
        $this->line('<fg=cyan>Heartbeat</>');
        $this->line('─────────────────────────────────────────────────────────────');

        if ($lastSent === null) {
            $this->line('No heartbeat sent yet');
        } else {
            $age = now()->timestamp - $lastSent;

            $this->line('Last sent: '.($age <= 0 ? 'just now' : $this->formatDuration($age).' ago'));
        }

        $this->newLine();
    }

    /**
     * How an entry runs, such as "runs every minute, overlap lock expires
     * after 5 minutes".
     *
     * @param  ScheduleEntry  $entry
     */
    protected function describeEntry(array $entry): string
    {
        $runs = WorkerSchedule::runsEveryMinute($entry['expression'])
            ? 'runs every minute'
            : 'runs on the cron expression '.$entry['expression'];

        if (! $entry['without_overlapping']) {
            return $runs.', without an overlap lock';
        }

        $minutes = $entry['lock_expires_after_minutes'];

        return sprintf('%s, overlap lock expires after %d %s', $runs, $minutes, $minutes === 1 ? 'minute' : 'minutes');
    }

    /**
     * The line under the stalled-drain warning that points at the likely
     * cause. Only an unread schedule leaves the question of whether the worker
     * is scheduled open.
     *
     * @param  ScheduleAnswer|null  $schedule
     */
    protected function stalledDrainCause(?array $schedule): string
    {
        if ($schedule === null) {
            return 'Is the <fg=cyan>ranetrace:work</> command scheduled (every minute)?';
        }

        if (! $schedule['scheduled']) {
            return '<fg=cyan>ranetrace:work</> was not found in the schedule (see Schedule below).';
        }

        if (in_array(WorkerSchedule::WARNING_LOCK_STUCK, $schedule['warnings'], true)) {
            return '<fg=cyan>ranetrace:work</> is scheduled, and its overlap lock looks stuck (see Schedule below).';
        }

        return '<fg=cyan>ranetrace:work</> is scheduled. Check that the scheduler\'s own cron entry runs schedule:run every minute, and that a queue worker processes the batch jobs.';
    }

    /**
     * The Recommendations line under a stalled drain, or null when a schedule
     * warning already says what to do.
     *
     * @param  ScheduleAnswer|null  $schedule
     */
    protected function stalledDrainAdvice(?array $schedule): ?string
    {
        if ($schedule === null) {
            return 'Ensure ranetrace:work is scheduled every minute (see documentation)';
        }

        $warnings = $schedule['warnings'];

        if (in_array(WorkerSchedule::WARNING_NOT_SCHEDULED, $warnings, true) || in_array(WorkerSchedule::WARNING_LOCK_STUCK, $warnings, true)) {
            return null;
        }

        if (! $schedule['scheduled']) {
            return 'Check that ranetrace:work runs every minute and that a queue worker processes the batch jobs';
        }

        return 'Check that the scheduler\'s own cron entry runs schedule:run every minute, and that a queue worker processes the batch jobs';
    }

    /**
     * The Recommendations line under a buffer near capacity, or null when
     * another line already says what to do: the stalled-drain advice, the
     * paused-and-not-sending line when it covers every buffer near capacity,
     * or the recommendation of a schedule warning.
     *
     * @param  ScheduleAnswer|null  $schedule
     */
    protected function nearCapacityAdvice(?array $schedule, bool $isAnsweredByDrainLine): ?string
    {
        if ($schedule === null) {
            return 'Check if ranetrace:work command is running on schedule';
        }

        $warnings = $schedule['warnings'];

        if ($isAnsweredByDrainLine || in_array(WorkerSchedule::WARNING_NOT_SCHEDULED, $warnings, true) || in_array(WorkerSchedule::WARNING_LOCK_STUCK, $warnings, true)) {
            return null;
        }

        if (! $schedule['scheduled']) {
            return 'ranetrace:work is not in the schedule. Check that whatever runs it does so every minute';
        }

        $runsEveryMinute = array_any(
            $schedule['entries'],
            fn (array $entry): bool => WorkerSchedule::runsEveryMinute($entry['expression']),
        );

        if (! $runsEveryMinute) {
            return 'ranetrace:work is scheduled, but not every minute. A run sends at most 1000 items per feature, so schedule it every minute';
        }

        return 'ranetrace:work is scheduled every minute. Check for a paused feature above, and that a queue worker processes the batch jobs: a run sends at most 1000 items per feature';
    }

    /**
     * One Recommendations line per schedule warning, naming the entry it is
     * about.
     *
     * @param  ScheduleAnswer|null  $schedule
     * @return list<string>
     */
    protected function scheduleRecommendations(?array $schedule): array
    {
        if ($schedule === null) {
            return [];
        }

        $recommendations = [];

        if (in_array(WorkerSchedule::WARNING_NOT_SCHEDULED, $schedule['warnings'], true)) {
            $recommendations[] = 'Schedule ranetrace:work every minute in routes/console.php (the line is under Schedule above)';
        }

        foreach ($schedule['entries'] as $entry) {
            if (in_array(WorkerSchedule::WARNING_LOCK_STUCK, $entry['warnings'], true)) {
                $recommendations[] = "Release the stuck overlap lock of {$entry['command']}: php artisan schedule:clear-cache";
            }
        }

        foreach ($schedule['entries'] as $entry) {
            if (in_array(WorkerSchedule::WARNING_DEFAULT_LOCK_EXPIRY, $entry['warnings'], true)) {
                $recommendations[] = "Give the overlap lock of {$entry['command']} an expiry: change its line in routes/console.php to ->withoutOverlapping(5)";
            }
        }

        return $recommendations;
    }

    /**
     * The stalled-drain window in whole minutes, the one definition the
     * schedule messages quote.
     */
    protected function staleMinutes(): int
    {
        return intdiv(DashboardData::DRAIN_STALE_SECONDS, 60);
    }

    /**
     * What a feature pause for this reason means, or null when the reason has
     * no known explanation. It prints under the paused feature's row, aligned
     * with the row's text column, so it shows even when nothing else is
     * unhealthy enough for Recommendations to print.
     */
    protected function pauseGuidance(string $reason): ?string
    {
        return match ($reason) {
            '429' => 'Rate limited, auto-resumes',
            '413' => 'Payload too large: client bug, investigate',
            '422' => 'Request body rejected as malformed: client bug, investigate',
            '500' => 'Ranetrace backend error: check backend health',
            '403' => 'Subscription or permission issue: check your subscription and permissions',
            default => null,
        };
    }

    /**
     * Print a one-line pointer to the in-app diagnostics dashboard.
     *
     * Skipped when the dashboard is disabled or its route isn't registered, and
     * defensive against URL-generation failures: a status command must never
     * throw. (Text output only; the --json payload is intentionally untouched.)
     */
    protected function displayDashboardHint(): void
    {
        if (! config('ranetrace.dashboard.enabled', true)) {
            return;
        }

        try {
            if (! Route::has('ranetrace.dashboard')) {
                return;
            }

            $this->line('<fg=cyan>Dashboard:</> '.route('ranetrace.dashboard'));
            $this->newLine();
        } catch (Throwable) {
            // URL generation failed, so skip the hint rather than break the command.
        }
    }

    /**
     * Create a simple progress bar.
     */
    protected function createProgressBar(float $percentage): string
    {
        $barWidth = 20;
        $filled = (int) round(($percentage / 100) * $barWidth);
        $empty = $barWidth - $filled;

        return str_repeat('█', $filled).str_repeat('░', $empty);
    }

    /**
     * Format duration in seconds to human-readable format.
     */
    protected function formatDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return 'expired';
        }

        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds % 60;

        if ($minutes > 0) {
            return "{$minutes}m {$remainingSeconds}s";
        }

        return "{$seconds}s";
    }
}
