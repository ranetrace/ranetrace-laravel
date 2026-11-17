<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Dashboard;

use Carbon\Carbon;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Ranetrace\Laravel\Analytics\Middleware\TrackPageVisit;
use Ranetrace\Laravel\Dashboard\Checks\ApiKeyCheck;
use Ranetrace\Laravel\Dashboard\Checks\BufferCapacityCheck;
use Ranetrace\Laravel\Dashboard\Checks\CacheDriverCheck;
use Ranetrace\Laravel\Dashboard\Checks\Check;
use Ranetrace\Laravel\Dashboard\Checks\CheckResult;
use Ranetrace\Laravel\Dashboard\Checks\DrainStalledCheck;
use Ranetrace\Laravel\Dashboard\Checks\InternalLoggingCheck;
use Ranetrace\Laravel\Dashboard\Checks\QueueWorkerCheck;
use Ranetrace\Laravel\Jobs\SendBatchToRanetraceJob;
use Ranetrace\Laravel\Jobs\SendHeartbeatToRanetraceJob;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;
use Ranetrace\Laravel\Services\RanetracePauseManager;
use Ranetrace\Laravel\Support\BatchConfig;
use Ranetrace\Laravel\Support\CoreConfig;
use Ranetrace\Laravel\Support\InternalLogger;
use Ranetrace\Php\Http\ResponsePolicy;
use Throwable;

/**
 * Shared source of truth for Ranetrace installation health and diagnostics.
 *
 * Both the `ranetrace:status` command and the in-app dashboard consume this
 * service, so the CLI and the web page can never disagree. Every cache/database
 * read degrades gracefully: this is a diagnostic surface and must never throw,
 * even when the cache store or database is unavailable.
 */
class DashboardData
{
    /**
     * A buffer is considered "drain-stalled" when it holds items but has not
     * had a successful batch send within this many seconds.
     */
    public const int DRAIN_STALE_SECONDS = 600;

    /**
     * The feature pause reasons the API asks for in its answer, which the
     * batch job takes at once. Only such a pause excuses a buffer from the
     * stalled check, and only for PAUSED_DRAIN_GRACE_SECONDS. Every other
     * reason (`network`, a server status such as `500`, `exception`) is set
     * after the job's own retries have already spent 21 minutes, so it
     * excuses nothing.
     *
     * @var list<string>
     */
    public const array API_REQUESTED_PAUSE_REASONS = ['403', '413', '422', '429'];

    /**
     * How long a pause the API asked for excuses a buffer: two default pauses.
     * A 403 sets the pause again on every attempt for as long as its cause
     * lasts, so an item that has waited longer than this, with no batch of its
     * type sent in that time, is waiting on a cause someone has to fix.
     */
    public const int PAUSED_DRAIN_GRACE_SECONDS = 2 * ResponsePolicy::PAUSE_SECONDS;

    /**
     * Fraction of a buffer's max size at which it is considered "near capacity".
     * Drives both the overall health flag and the recommendations output.
     */
    public const float NEAR_CAPACITY_RATIO = 0.8;

    /**
     * The seed misconfiguration checks. Pluggable: a host can override the list
     * via `config('ranetrace.dashboard.checks')` to add or replace checks
     * without touching the panel. Each entry must implement the Check contract.
     *
     * @var array<int, class-string<Check>>
     */
    public const array DEFAULT_CHECKS = [
        ApiKeyCheck::class,
        CacheDriverCheck::class,
        DrainStalledCheck::class,
        BufferCapacityCheck::class,
        QueueWorkerCheck::class,
        InternalLoggingCheck::class,
    ];

    /**
     * Number of recent internal-log entries (warning+) surfaced in the feed.
     */
    protected const int LOG_TAIL_LIMIT = 15;

    public function __construct(
        protected RanetraceBatchBuffer $buffer,
        protected RanetracePauseManager $pauseManager,
    ) {}

    /**
     * Collect all status information.
     *
     * All cache/database reads degrade gracefully: the status surface is a
     * diagnostic tool and must never throw, even when subsystems are down.
     *
     * In `config`, `queue_name` is the batch queue as configured, null when
     * unset. `queue_landing` and `queue_connection` are where the batch job
     * really lands once the host's queue routes and forwards apply:
     * `queue_landing` is the queue it is pushed to, null for the default
     * queue of `queue_connection`, and `queue_connection` is always a
     * connection name.
     *
     * In `drain`, `stalled` lists the features whose items wait too long while
     * they are not paused, a sign of the schedule or the queue worker, and
     * `paused_stalled` the paused features whose pause does not, or no longer,
     * explains the wait. Either makes the installation unhealthy.
     *
     * `heartbeat.last_sent` is when the API last accepted a heartbeat, as a
     * Unix timestamp, or null when none was. It is information only and plays
     * no part in `healthy`.
     *
     * @return array<string, mixed>
     */
    public function collectStatus(): array
    {
        $features = RanetraceBatchBuffer::TYPES;

        // Check global pause
        try {
            $globalPause = $this->pauseManager->getGlobalPause();
            $isGloballyPaused = $this->pauseManager->isGloballyPaused();
        } catch (Throwable) {
            $globalPause = null;
            $isGloballyPaused = false;
        }

        // Check feature pauses
        $featurePauses = [];
        foreach ($features as $feature) {
            try {
                $pauseData = $this->pauseManager->getFeaturePause($feature);
                $featurePauses[$feature] = $pauseData ? [
                    'paused' => $this->pauseManager->isFeaturePaused($feature),
                    'paused_until' => $pauseData['paused_until'],
                    'reason' => $pauseData['reason'],
                    'time_remaining_seconds' => $this->remainingSecondsUntil($pauseData['paused_until']),
                ] : null;
            } catch (Throwable) {
                $featurePauses[$feature] = null;
            }
        }

        // Get buffer sizes, last-drain timestamps, and oldest buffered-item ages
        $buffers = [];
        $lastBatch = [];
        $oldestItem = [];
        $totalBuffered = 0;
        foreach ($features as $feature) {
            try {
                $count = $this->buffer->count($feature);
            } catch (Throwable) {
                $count = 0;
            }
            $buffers[$feature] = $count;
            $totalBuffered += $count;
            $lastBatch[$feature] = $this->getLastBatchTimestamp($feature);
            $oldestItem[$feature] = $this->getOldestBufferedTimestamp($feature);
        }

        $maxPerFeature = config('ranetrace.batch.max_buffer_size', 5000);

        // Detect drain-stalled buffers. A buffer is stalled only when it holds an
        // item that has genuinely waited too long: the oldest item is older than
        // the window AND no successful drain has happened within it. Items
        // simply waiting for the next scheduled ranetrace:work run (and
        // brand-new buffers that have never drained yet) are NOT stalled.
        // A paused feature goes to its own list, because its cause is the
        // pause rather than the schedule or the queue worker. Under a global
        // pause nothing is listed: that pause makes the installation unhealthy
        // on its own.
        $now = Carbon::now()->timestamp;
        $stalledFeatures = [];
        $pausedStalledFeatures = [];
        foreach ($features as $feature) {
            if ($buffers[$feature] <= 0 || $isGloballyPaused) {
                continue;
            }

            $pause = $featurePauses[$feature];
            $isPaused = $pause['paused'] ?? false;
            $window = $isPaused && in_array((string) $pause['reason'], self::API_REQUESTED_PAUSE_REASONS, true)
                ? self::PAUSED_DRAIN_GRACE_SECONDS
                : self::DRAIN_STALE_SECONDS;

            if (! $this->hasWaitedTooLong($oldestItem[$feature], $lastBatch[$feature], $now, $window)) {
                continue;
            }

            if ($isPaused) {
                $pausedStalledFeatures[] = $feature;
            } else {
                $stalledFeatures[] = $feature;
            }
        }

        // Get failed jobs count (last 24 hours)
        $failedJobsCount = $this->getFailedJobsCount();

        $isEnabled = (bool) config('ranetrace.enabled', true);
        $isApiKeyConfigured = CoreConfig::hasIngestKey();

        // Overall health: an enabled installation has an ingest key (without one
        // nothing is captured or sent), no single buffer is near
        // its own capacity, no drain is stalled, it is not globally paused, and
        // few jobs failed. A disabled installation needs no key, since nothing
        // is meant to be sent, but a stalled drain counts there too: the worker
        // still sends what was captured before it was switched off.
        $anyBufferNearCapacity = array_any(
            $buffers,
            fn (int $count): bool => $count >= $maxPerFeature * self::NEAR_CAPACITY_RATIO
        );

        $batchJob = BatchConfig::jobAsDispatched('ranetrace.batch');

        $healthy = (! $isEnabled || $isApiKeyConfigured)
            && ! $isGloballyPaused
            && ! $anyBufferNearCapacity
            && $stalledFeatures === []
            && $pausedStalledFeatures === []
            && $failedJobsCount < 10;

        return [
            'healthy' => $healthy,
            'timestamp' => Carbon::now()->toIso8601String(),
            'pauses' => [
                'global' => $globalPause ? [
                    'paused' => $isGloballyPaused,
                    'paused_until' => $globalPause['paused_until'],
                    'reason' => $globalPause['reason'],
                    'time_remaining_seconds' => $this->remainingSecondsUntil($globalPause['paused_until']),
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
                'paused_stalled' => $pausedStalledFeatures,
            ],
            'heartbeat' => [
                'last_sent' => $this->getLastHeartbeatTimestamp(),
            ],
            'failed_jobs_last_24h' => $failedJobsCount,
            'config' => [
                'enabled' => $isEnabled,
                // Only whether the ingest key is set is reported, never the
                // value. The MCP tools use an OAuth connection, a different
                // credential entirely, held by the MCP client, not by this application.
                'api_key_configured' => $isApiKeyConfigured,
                'cache_driver' => BatchConfig::cacheStoreName(),
                'cache_driver_is_app_default' => BatchConfig::cacheStoreIsAppDefault(),
                'queue_name' => BatchConfig::queueName(),
                'queue_landing' => BatchConfig::jobLandingQueueName($batchJob),
                'queue_connection' => BatchConfig::jobConnectionName($batchJob),
            ],
        ];
    }

    /**
     * The full dashboard payload: the canonical status plus dashboard-only
     * extras (checks, registered surfaces, internal-log tail, environment).
     *
     * `collectStatus()` is computed once and shared with the checks so the cache
     * isn't read twice. No outbound API calls: everything is local.
     *
     * @return array{
     *     status: array<string, mixed>,
     *     checks: array<int, CheckResult>,
     *     surfaces: array<int, array{label: string, ok: bool, note: ?string}>,
     *     logs: array<int, array{time: string, level: string, message: string}>,
     *     environment: array<string, ?string>,
     * }
     */
    public function collect(): array
    {
        $status = $this->collectStatus();

        return [
            'status' => $status,
            'checks' => $this->runChecks($status),
            'surfaces' => $this->registeredSurfaces(),
            'logs' => $this->internalLogTail(),
            'environment' => $this->environment(),
        ];
    }

    /**
     * Run every configured misconfiguration check against the status array.
     * Each check is isolated: one that throws is skipped, never breaking the page.
     *
     * @param  array<string, mixed>  $status
     * @return array<int, CheckResult>
     */
    public function runChecks(array $status): array
    {
        $checks = config('ranetrace.dashboard.checks', self::DEFAULT_CHECKS);
        $results = [];

        foreach ($checks as $checkClass) {
            try {
                $check = app($checkClass);
                if ($check instanceof Check) {
                    $results[] = $check->run($status);
                }
            } catch (Throwable) {
                // A broken check must never break the dashboard, so skip it.
            }
        }

        return $results;
    }

    /**
     * What the service provider actually wired up given the current config, the
     * "installation truth" view (B2). Read-only introspection; degrades to
     * an empty list rather than throwing.
     *
     * @return array<int, array{label: string, ok: bool, note: ?string}>
     */
    public function registeredSurfaces(): array
    {
        try {
            $webGroup = app('router')->getMiddlewareGroups()['web'] ?? [];
            $pageVisitActive = in_array(TrackPageVisit::class, $webGroup, true);

            $ranetraceChannel = config()->has('logging.channels.ranetrace');

            return [
                $this->surface('JavaScript-error ingest route', Route::has('ranetrace.javascript-errors.store'), 'Active only when javascript_errors.enabled'),
                $this->surface('Dashboard route', Route::has('ranetrace.dashboard')),
                $this->surface('Page-visit middleware (web group)', $pageVisitActive, 'Active only when website_analytics.enabled'),
                $this->surface('@ranetraceErrorTracking Blade directive', isset(Blade::getCustomDirectives()['ranetraceErrorTracking'])),
                $this->surface('Internal log channel', config()->has('logging.channels.ranetrace_internal')),
                $this->surface('Ranetrace log channel', $ranetraceChannel),
            ];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Most-recent internal-log entries at warning level or above. Reads the
     * latest daily file written by the `ranetrace_internal` channel; an
     * absent/unreadable file yields an empty feed rather than an error.
     *
     * The directory and basename come from that channel's own configured path
     * ({@see InternalLogger::channelPath()}), so the reader follows the writer.
     * Both calling storage_path() would make them agree only by coincidence:
     * point the channel elsewhere and this panel would keep reading the old
     * directory.
     *
     * @return array<int, array{time: string, level: string, message: string}>
     */
    public function internalLogTail(int $limit = self::LOG_TAIL_LIMIT): array
    {
        try {
            $path = InternalLogger::channelPath();

            $files = glob(dirname($path).'/'.basename($path, '.log').'-*.log') ?: [];

            if ($files === []) {
                $files = is_file($path) ? [$path] : [];
            }

            if ($files === []) {
                return [];
            }

            sort($files); // daily filenames sort chronologically
            $contents = @file_get_contents((string) end($files));

            return $contents === false ? [] : $this->parseLogTail($contents, $limit);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Environment and version facts (H).
     *
     * @return array<string, ?string>
     */
    public function environment(): array
    {
        return [
            'package' => $this->packageVersion(),
            'laravel' => app()->version(),
            'php' => PHP_VERSION,
            'env' => app()->environment(),
            'queue' => (string) config('queue.default', 'sync'),
            'cache' => (string) config('cache.default', 'file'),
        ];
    }

    /**
     * @return array{label: string, ok: bool, note: ?string}
     */
    protected function surface(string $label, bool $ok, ?string $note = null): array
    {
        return ['label' => $label, 'ok' => $ok, 'note' => $ok ? null : $note];
    }

    /**
     * Extract warning+ entries from raw log contents, keeping the most recent
     * $limit in chronological order. Continuation lines (stack traces) are
     * ignored: only lines that start a log entry are parsed.
     *
     * @return array<int, array{time: string, level: string, message: string}>
     */
    protected function parseLogTail(string $contents, int $limit): array
    {
        $surfaced = ['WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];
        $entries = [];

        foreach (explode("\n", $contents) as $line) {
            if (! preg_match('/^\[(?<time>[^\]]+)\]\s+\S+\.(?<level>[A-Z]+):\s*(?<msg>.*)$/', $line, $m)) {
                continue;
            }

            if (! in_array($m['level'], $surfaced, true)) {
                continue;
            }

            $entries[] = [
                'time' => $m['time'],
                'level' => $m['level'],
                'message' => mb_substr(mb_trim($m['msg']), 0, 200),
            ];
        }

        return array_slice($entries, -$limit);
    }

    /**
     * Installed package version, or null if it cannot be resolved (e.g. running
     * from a path repository without Composer metadata).
     */
    protected function packageVersion(): ?string
    {
        try {
            return InstalledVersions::getPrettyVersion('ranetrace/ranetrace-laravel');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whole seconds remaining until a pause's ISO-8601 expiry timestamp, floored
     * at 0. Carbon 3's diffInSeconds() returns a float, so the result is cast to
     * int: both the CLI's formatDuration() and the JSON `time_remaining_seconds`
     * field require an integer (a float would TypeError under strict_types).
     */
    protected function remainingSecondsUntil(string $until): int
    {
        return (int) max(0, round(Carbon::now()->diffInSeconds(Carbon::parse($until), false)));
    }

    /**
     * Whether the oldest buffered item is older than $window seconds with no
     * successful batch of its type inside that window. An unreadable oldest
     * item has not waited too long.
     */
    protected function hasWaitedTooLong(?int $oldest, ?int $lastBatch, int $now, int $window): bool
    {
        if ($oldest === null || ($now - $oldest) <= $window) {
            return false;
        }

        return $lastBatch === null || ($now - $lastBatch) > $window;
    }

    /**
     * Get the timestamp of the last successful batch send for a feature.
     *
     * Numeric strings are accepted as well as ints: the Redis and Memcached
     * cache stores keep bare numbers un-serialized (to support atomic
     * increments) and hand them back as numeric strings. An `is_int()` check
     * would discard a perfectly valid timestamp from those stores and report
     * the buffer as never drained, the root cause of false "drain stalled"
     * alarms on a Redis-backed cache.
     */
    protected function getLastBatchTimestamp(string $feature): ?int
    {
        try {
            $value = BatchConfig::cacheStore()->get(SendBatchToRanetraceJob::LAST_BATCH_PREFIX.$feature);

            return is_numeric($value) ? (int) $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * When the API last accepted a heartbeat, read the way
     * {@see getLastBatchTimestamp()} reads a batch's.
     */
    protected function getLastHeartbeatTimestamp(): ?int
    {
        try {
            $value = BatchConfig::cacheStore()->get(SendHeartbeatToRanetraceJob::LAST_SENT_KEY);

            return is_numeric($value) ? (int) $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Get the timestamp of the oldest item currently buffered for a feature,
     * or null when the buffer is empty or unreadable. Drives stalled detection:
     * a buffer is only stalled once its oldest item has waited past the drain
     * window, never the instant an item lands.
     */
    protected function getOldestBufferedTimestamp(string $feature): ?int
    {
        try {
            return $this->buffer->oldestTimestamp($feature);
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
