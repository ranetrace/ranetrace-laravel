<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Ranetrace\Laravel\Jobs\BaseRanetraceJob;
use Ranetrace\Laravel\Jobs\SendBatchToRanetraceJob;

/**
 * The batch pipeline's cache store and the queues Ranetrace jobs go to, read
 * in one place.
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
     * How a queue nobody named is described: the job is dispatched with no
     * queue, so it lands on its connection's default.
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
        return self::featureQueueName('ranetrace.batch');
    }

    /**
     * The queue a feature's jobs are dispatched to, read from
     * `{$featureConfigPath}.queue_name` (for example `ranetrace.errors`), or
     * null for the connection's default queue when it is null or blank.
     */
    public static function featureQueueName(string $featureConfigPath): ?string
    {
        $configured = config($featureConfigPath.'.queue_name');

        return filled($configured) ? (string) $configured : null;
    }

    /**
     * The default queue of a queue connection, or null when that connection
     * names no queue.
     */
    public static function connectionDefaultQueueName(string $connectionName): ?string
    {
        $queue = config("queue.connections.{$connectionName}.queue");

        return filled($queue) ? (string) $queue : null;
    }

    /**
     * The connection a Ranetrace job is dispatched on, resolved the way the bus
     * dispatcher resolves it: the job's own connection (none of Ranetrace's
     * jobs sets one), else the connection a queue route registered for the
     * job's class, a parent, an interface or a trait names, or the connection
     * the job's queue is forwarded to, else `queue.default`.
     */
    public static function jobConnectionName(BaseRanetraceJob|SendBatchToRanetraceJob $job): string
    {
        return $job->connection
            ?? self::queueRoutes()?->getConnection($job)
            ?? (string) config('queue.default');
    }

    /**
     * The queue a Ranetrace job is pushed to, before forwards apply, or null
     * for its connection's default queue: the job's own queue, which its
     * constructor sets to the feature's queue name, else the queue a queue
     * route for the job names.
     */
    public static function jobQueueName(BaseRanetraceJob|SendBatchToRanetraceJob $job): ?string
    {
        return $job->queue ?? self::queueRoutes()?->getQueue($job);
    }

    /**
     * Whether a Ranetrace job lands on the queue that `queue:work` without
     * `--queue` drains on the job's connection. A connection forwards both
     * the queue a job is pushed to and the queue a worker pops from, so both
     * are compared after their forwards.
     */
    public static function jobLandsOnConnectionDefaultQueue(BaseRanetraceJob|SendBatchToRanetraceJob $job): bool
    {
        $connectionName = self::jobConnectionName($job);
        $defaultQueue = self::connectionDefaultQueueName($connectionName);

        return self::forwardedQueueName(self::jobQueueName($job) ?? $defaultQueue, $connectionName)
            === self::forwardedQueueName($defaultQueue, $connectionName);
    }

    /**
     * A queue name as the status output and the dashboard print it.
     */
    public static function describeQueue(?string $name): string
    {
        return $name ?? self::CONNECTION_DEFAULT_QUEUE;
    }

    /**
     * The queue a connection really uses for a queue name once queue forwards
     * apply.
     */
    private static function forwardedQueueName(?string $queue, string $connectionName): ?string
    {
        $routes = self::queueRoutes();

        if ($queue === null || $routes === null || ! method_exists($routes, 'forwardedQueue')) {
            return $queue;
        }

        return $routes->forwardedQueue($queue, $connectionName);
    }

    /**
     * The host's queue routes, or null where the framework has none (Laravel
     * 12). Untyped because `Illuminate\Queue\QueueRoutes` does not exist
     * there either.
     */
    private static function queueRoutes(): mixed
    {
        return app()->bound('queue.routes') ? app('queue.routes') : null;
    }
}
