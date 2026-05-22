<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Ranetrace\Laravel\Jobs\SendBatchToRanetraceJob;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;
use Ranetrace\Laravel\Services\RanetracePauseManager;
use Throwable;

class RanetraceStatusCommand extends Command
{
    /**
     * A buffer is considered "drain-stalled" when it holds items but has not
     * had a successful batch send within this many seconds (and is not paused).
     */
    protected const int DRAIN_STALE_SECONDS = 600;

    /**
     * Fraction of a buffer's max size at which it is considered "near capacity"
     * — drives both the overall health flag and the recommendations output.
     */
    protected const float NEAR_CAPACITY_RATIO = 0.8;

    protected $signature = 'ranetrace:status
                            {--json : Output as JSON instead of formatted text}';

    protected $description = 'Display Ranetrace health status including pauses, buffers, and recent activity';

    public function handle(RanetraceBatchBuffer $buffer, RanetracePauseManager $pauseManager): int
    {
        $status = $this->collectStatus($buffer, $pauseManager);

        if ($this->option('json')) {
            $this->line(json_encode($status, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->displayStatus($status);

        return self::SUCCESS;
    }

    /**
     * Collect all status information.
     *
     * All cache/database reads degrade gracefully — the status command is a
     * diagnostic tool and must never throw, even when subsystems are down.
     *
     * @return array<string, mixed>
     */
    protected function collectStatus(RanetraceBatchBuffer $buffer, RanetracePauseManager $pauseManager): array
    {
        $features = RanetraceBatchBuffer::TYPES;

        // Check global pause
        try {
            $globalPause = $pauseManager->getGlobalPause();
            $isGloballyPaused = $pauseManager->isGloballyPaused();
        } catch (Throwable) {
            $globalPause = null;
            $isGloballyPaused = false;
        }

        // Check feature pauses
        $featurePauses = [];
        foreach ($features as $feature) {
            try {
                $pauseData = $pauseManager->getFeaturePause($feature);
                $featurePauses[$feature] = $pauseData ? [
                    'paused' => $pauseManager->isFeaturePaused($feature),
                    'paused_until' => $pauseData['paused_until'],
                    'reason' => $pauseData['reason'],
                    'time_remaining_seconds' => max(0, Carbon::now()->diffInSeconds(Carbon::parse($pauseData['paused_until']), false)),
                ] : null;
            } catch (Throwable) {
                $featurePauses[$feature] = null;
            }
        }

        // Get buffer sizes and last-drain timestamps
        $buffers = [];
        $lastBatch = [];
        $totalBuffered = 0;
        foreach ($features as $feature) {
            try {
                $count = $buffer->count($feature);
            } catch (Throwable) {
                $count = 0;
            }
            $buffers[$feature] = $count;
            $totalBuffered += $count;
            $lastBatch[$feature] = $this->getLastBatchTimestamp($feature);
        }

        $maxPerFeature = config('ranetrace.batch.max_buffer_size', 5000);

        // Detect drain-stalled buffers: items present, not paused, no recent drain.
        $stalledFeatures = [];
        foreach ($features as $feature) {
            if ($buffers[$feature] <= 0) {
                continue;
            }

            if ($isGloballyPaused || ($featurePauses[$feature]['paused'] ?? false)) {
                continue;
            }

            $last = $lastBatch[$feature];
            if ($last === null || (Carbon::now()->timestamp - $last) > self::DRAIN_STALE_SECONDS) {
                $stalledFeatures[] = $feature;
            }
        }

        // Get failed jobs count (last 24 hours)
        $failedJobsCount = $this->getFailedJobsCount();

        // Overall health: no single buffer near its own capacity, not globally
        // paused, and few failed jobs.
        $anyBufferNearCapacity = array_any(
            $buffers,
            fn (int $count): bool => $count >= $maxPerFeature * self::NEAR_CAPACITY_RATIO
        );

        $healthy = ! $isGloballyPaused
            && ! $anyBufferNearCapacity
            && $failedJobsCount < 10;

        return [
            'healthy' => $healthy,
            'timestamp' => Carbon::now()->toIso8601String(),
            'pauses' => [
                'global' => $globalPause ? [
                    'paused' => $isGloballyPaused,
                    'paused_until' => $globalPause['paused_until'],
                    'reason' => $globalPause['reason'],
                    'time_remaining_seconds' => max(0, Carbon::now()->diffInSeconds(Carbon::parse($globalPause['paused_until']), false)),
                ] : null,
                'features' => $featurePauses,
            ],
            'buffers' => [
                'total' => $totalBuffered,
                'max_per_feature' => $maxPerFeature,
                'features' => $buffers,
            ],
            'drain' => [
                'last_batch' => $lastBatch,
                'stalled' => $stalledFeatures,
            ],
            'failed_jobs_last_24h' => $failedJobsCount,
            'config' => [
                'enabled' => config('ranetrace.enabled', true),
                'api_key_configured' => ! empty(config('ranetrace.key')),
                'cache_driver' => config('ranetrace.batch.cache_driver', 'redis'),
                'queue_name' => config('ranetrace.batch.queue_name', 'default'),
            ],
        ];
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
        $this->line('║              RANETRACE HEALTH STATUS                             ║');
        $this->line('╚═══════════════════════════════════════════════════════════════╝');
        $this->newLine();

        // Overall health
        if ($status['healthy']) {
            $this->info('✓ Overall Status: HEALTHY');
        } else {
            $this->error('✗ Overall Status: ISSUES DETECTED');
        }

        $this->newLine();

        // Configuration
        $this->line('<fg=cyan>CONFIGURATION</>');
        $this->line('─────────────────────────────────────────────────────────────');
        $this->line('Enabled: '.($status['config']['enabled'] ? '<fg=green>Yes</>' : '<fg=red>No</>'));
        $this->line('API Key: '.($status['config']['api_key_configured'] ? '<fg=green>Configured</>' : '<fg=red>Not Configured</>'));
        $this->line('Cache Driver: '.$status['config']['cache_driver']);
        $this->line('Queue Name: '.$status['config']['queue_name']);
        $this->newLine();

        // Global pause
        $this->line('<fg=cyan>GLOBAL PAUSE STATUS</>');
        $this->line('─────────────────────────────────────────────────────────────');
        if ($status['pauses']['global']) {
            $pause = $status['pauses']['global'];
            if ($pause['paused']) {
                $this->error('✗ PAUSED');
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
        $this->line('<fg=cyan>FEATURE PAUSE STATUS</>');
        $this->line('─────────────────────────────────────────────────────────────');
        foreach ($status['pauses']['features'] as $feature => $pause) {
            if ($pause) {
                if ($pause['paused']) {
                    $this->line(sprintf(
                        '  <fg=red>✗</> %-20s <fg=red>PAUSED</> (reason: %s, remaining: %s)',
                        $feature,
                        $pause['reason'],
                        $this->formatDuration($pause['time_remaining_seconds'])
                    ));
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
        $this->line('<fg=cyan>BUFFER STATUS</>');
        $this->line('─────────────────────────────────────────────────────────────');
        $this->line('Total Items: '.$status['buffers']['total']);
        $this->line('Max Per Feature: '.$status['buffers']['max_per_feature']);
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

        // Drain warning — buffers holding items with no recent batch send
        if (! empty($status['drain']['stalled'])) {
            $this->warn('! No recent batch drain for: '.implode(', ', $status['drain']['stalled']));
            $this->line('  Buffered items are not being sent to Ranetrace.');
            $this->line('  Is the <fg=cyan>ranetrace:work</> command scheduled (every minute)?');
            $this->newLine();
        }

        // Failed jobs
        $this->line('<fg=cyan>FAILED JOBS (Last 24h)</>');
        $this->line('─────────────────────────────────────────────────────────────');
        if ($status['failed_jobs_last_24h'] === 0) {
            $this->info('✓ No failed jobs');
        } elseif ($status['failed_jobs_last_24h'] < 10) {
            $this->warn('! '.$status['failed_jobs_last_24h'].' failed job(s) - Review queue:failed');
        } else {
            $this->error('✗ '.$status['failed_jobs_last_24h'].' failed job(s) - INVESTIGATE IMMEDIATELY');
        }
        $this->newLine();

        // Recommendations
        if (! $status['healthy'] || ! empty($status['drain']['stalled'])) {
            $this->line('<fg=cyan>RECOMMENDATIONS</>');
            $this->line('─────────────────────────────────────────────────────────────');

            if (! $status['config']['enabled']) {
                $this->line('• Enable Ranetrace in config/ranetrace.php');
            }

            if (! $status['config']['api_key_configured']) {
                $this->line('• Configure RANETRACE_KEY in .env');
            }

            if ($status['pauses']['global']) {
                $this->line('• Check API credentials (401 indicates invalid/revoked key)');
                $this->line('• Run: php artisan ranetrace:pause-clear --global');
            }

            foreach ($status['pauses']['features'] as $feature => $pause) {
                if ($pause && $pause['paused']) {
                    $reason = $pause['reason'];
                    $this->line("• Feature '{$feature}' paused (reason: {$reason})");

                    if ($reason === '429') {
                        $this->line('  → Rate limit exceeded, wait for auto-resume');
                    } elseif ($reason === '413') {
                        $this->line('  → Batch too large - CLIENT BUG, investigate immediately');
                    } elseif ($reason === '422') {
                        $this->line('  → Validation failed - schema drift or malformed data');
                    } elseif ($reason === '500') {
                        $this->line('  → Server errors - check backend health');
                    } elseif ($reason === '403') {
                        $this->line('  → Access denied - check subscription/permissions');
                    }
                }
            }

            $nearCapacity = array_keys(array_filter(
                $status['buffers']['features'],
                fn (int $count): bool => $count >= $status['buffers']['max_per_feature'] * self::NEAR_CAPACITY_RATIO
            ));
            if (! empty($nearCapacity)) {
                $this->line('• Buffers approaching capacity: '.implode(', ', $nearCapacity).' - data may be dropped');
                $this->line('• Check if ranetrace:work command is running on schedule');
            }

            if (! empty($status['drain']['stalled'])) {
                $this->line('• No recent drain for: '.implode(', ', $status['drain']['stalled']));
                $this->line('• Ensure ranetrace:work is scheduled every minute (see documentation)');
            }

            if ($status['failed_jobs_last_24h'] >= 10) {
                $this->line('• High failed job count - check ranetrace_internal logs');
                $this->line('• Review failed_jobs table for details');
            }

            $this->newLine();
        }

        $this->line('Last checked: '.$status['timestamp']);
        $this->newLine();
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

    /**
     * Get the timestamp of the last successful batch send for a feature.
     */
    protected function getLastBatchTimestamp(string $feature): ?int
    {
        try {
            $cacheDriver = config('ranetrace.batch.cache_driver', 'redis');
            $value = Cache::store($cacheDriver)->get(SendBatchToRanetraceJob::LAST_BATCH_PREFIX.$feature);

            return is_int($value) ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Get count of failed Ranetrace jobs in the last 24 hours.
     */
    protected function getFailedJobsCount(): int
    {
        try {
            return DB::table('failed_jobs')
                ->where('payload', 'like', '%Ranetrace%')
                ->where('failed_at', '>=', Carbon::now()->subDay())
                ->count();
        } catch (Throwable) {
            // Table might not exist or database not available
            return 0;
        }
    }
}
