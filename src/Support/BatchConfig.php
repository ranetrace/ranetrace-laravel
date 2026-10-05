<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * The batch pipeline's cache store and queue, read in one place.
 *
 * `ranetrace.batch.cache_driver` names the store the buffers, pauses, drain
 * timestamps, capture throttle and beacon marks all live in, so every reader
 * has to land on the same store. A value nobody set arrives as null
 * (`RANETRACE_BATCH_CACHE_DRIVER=null`) or as an empty string
 * (`RANETRACE_BATCH_CACHE_DRIVER=`), and `Cache::store('')` throws where
 * `Cache::store(null)` means the default, so both are resolved here to the
 * app's default store by its real name.
 */
final class BatchConfig
{
    /**
     * What the status output, the checks and the dashboard add after a store
     * name that came from `cache.default` rather than from Ranetrace's config.
     */
    public const string APP_DEFAULT_STORE_NOTE = "the app's default store";

    /**
     * How a batch queue nobody named is described: the job is dispatched with
     * no queue, so it lands on its connection's default.
     */
    public const string CONNECTION_DEFAULT_QUEUE = "the connection's default queue";

    /**
     * The name of the cache store the batch pipeline uses.
     */
    public static function cacheStoreName(): string
    {
        $configured = config('ranetrace.batch.cache_driver');

        return filled($configured) ? (string) $configured : (string) config('cache.default');
    }

    /**
     * Whether the store came from `cache.default` because none was configured.
     */
    public static function cacheStoreIsAppDefault(): bool
    {
        return blank(config('ranetrace.batch.cache_driver'));
    }

    public static function cacheStore(): Repository
    {
        return Cache::store(self::cacheStoreName());
    }

    /**
     * A store name as the status output and the dashboard print it.
     */
    public static function describeCacheStore(string $name, bool $isAppDefault): string
    {
        return $isAppDefault ? $name.' ('.self::APP_DEFAULT_STORE_NOTE.')' : $name;
    }

    /**
     * The queue batch jobs are dispatched to, or null for the connection's
     * default queue.
     */
    public static function queueName(): ?string
    {
        $configured = config('ranetrace.batch.queue_name');

        return filled($configured) ? (string) $configured : null;
    }

    /**
     * A queue name as the status output and the dashboard print it.
     */
    public static function describeQueue(?string $name): string
    {
        return $name ?? self::CONNECTION_DEFAULT_QUEUE;
    }
}
