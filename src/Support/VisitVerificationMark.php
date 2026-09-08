<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * The one cache key the human-verification beacon writes and the delayed page
 * visit job reads.
 *
 * The beacon endpoint marks a view token as "a real, visible browser rendered
 * this page"; the job that was dispatched for that same view reads the mark
 * back a few seconds later and reports `verified_human` accordingly. Writer and
 * reader live in two classes, so the key format and the store name live here
 * rather than in both: a rename or a store change on one side would otherwise
 * be a silent, always-unverified mismatch that nothing throws on.
 *
 * The store is Ranetrace's own (`ranetrace.batch.cache_driver`), the same one
 * the buffer, the pause manager and the capture throttle use, and NOT the
 * host's default: the default may be `array`, which is per-process, and the
 * beacon's POST and the queued job are never the same process.
 */
final class VisitVerificationMark
{
    /**
     * Prefix of the cache key holding one view's mark.
     */
    private const string KEY_PREFIX = 'ranetrace:visit_verified:';

    /**
     * Seconds the mark outlives the job's own wait window.
     *
     * The job is delayed by `beacon.wait_seconds`, but a busy queue runs it a
     * little late. Without the margin, a beacon that arrived in time would be
     * read back as absent and the visit reported unverified.
     */
    private const int TTL_MARGIN_SECONDS = 60;

    /**
     * Mark a view token as verified.
     *
     * Idempotent: a second beacon for the same token simply rewrites the same
     * value, which is why the endpoint never has to know whether a token was
     * already marked (and so never reveals it).
     */
    public static function put(string $token): void
    {
        self::store()->put(
            self::key($token),
            true,
            now()->addSeconds(self::waitSeconds() + self::TTL_MARGIN_SECONDS)
        );
    }

    /**
     * Read a view token's mark and forget it in one call.
     *
     * The mark exists for exactly one job run, so pulling it keeps the store
     * from carrying a key per page view for the rest of its TTL.
     */
    public static function pull(string $token): bool
    {
        return (bool) self::store()->pull(self::key($token), false);
    }

    /**
     * The cache key one view token is marked under.
     */
    public static function key(string $token): string
    {
        return self::KEY_PREFIX.$token;
    }

    private static function store(): Repository
    {
        return Cache::store(config('ranetrace.batch.cache_driver', 'file'));
    }

    private static function waitSeconds(): int
    {
        return (int) config('ranetrace.website_analytics.beacon.wait_seconds', 15);
    }
}
