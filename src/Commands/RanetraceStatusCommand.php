<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Ranetrace\Laravel\Dashboard\DashboardData;
use Ranetrace\Laravel\Support\BatchConfig;
use Throwable;

class RanetraceStatusCommand extends Command
{
    protected $signature = 'ranetrace:status
                            {--json : Output as JSON instead of formatted text}';

    protected $description = 'Display Ranetrace health status including pauses, buffers, and recent activity';

    public function handle(DashboardData $data): int
    {
        $status = $data->collectStatus();

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
            $this->line('  Is the <fg=cyan>ranetrace:work</> command scheduled (every minute)?');
            $this->newLine();
        }

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

        // Recommendations
        if (! $status['healthy'] || ! empty($status['drain']['stalled'])) {
            $this->line('<fg=cyan>Recommendations</>');
            $this->line('─────────────────────────────────────────────────────────────');

            if ($status['config']['enabled'] && ! $status['config']['api_key_configured']) {
                $this->line('• Configure RANETRACE_KEY in .env (the ingest key; the MCP tools use an OAuth connection, held by your MCP client)');
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
                $this->line('• Check if ranetrace:work command is running on schedule');
            }

            if (! empty($status['drain']['stalled'])) {
                $this->line('• No recent drain for: '.implode(', ', $status['drain']['stalled']));
                $this->line('• Ensure ranetrace:work is scheduled every minute (see documentation)');
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
