<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Dashboard\Checks;

use Ranetrace\Laravel\Support\BatchConfig;

/**
 * The buffer and pause state live in the cache. A store on a volatile driver
 * (array/null) loses them between requests: critical in production, only a
 * warning locally. The store is judged by the driver `cache.stores` gives it,
 * because its name says nothing about how it keeps its values.
 */
class CacheDriverCheck implements Check
{
    /**
     * @var array<int, string>
     */
    protected const array VOLATILE_DRIVERS = ['array', 'null'];

    public function run(array $status): CheckResult
    {
        $store = $status['config']['cache_driver'];
        $isAppDefault = $status['config']['cache_driver_is_app_default'];
        $driver = config("cache.stores.{$store}.driver");

        if (! is_string($driver) || $driver === '') {
            return CheckResult::fail(
                'cache_driver',
                'Cache driver '.BatchConfig::describeCacheStore("\"{$store}\"", $isAppDefault).' is not a configured cache store',
                'Nothing can be buffered or paused until it is. Define it under stores in config/cache.php, or point ranetrace.batch.cache_driver at a store that is defined there.'
            );
        }

        $onDriver = $driver === $store ? '' : " on the \"{$driver}\" driver";
        $named = BatchConfig::describeCacheStore("\"{$store}\"{$onDriver}", $isAppDefault);

        if (! in_array($driver, self::VOLATILE_DRIVERS, true)) {
            return CheckResult::pass('cache_driver', "Cache driver {$named} persists between requests");
        }

        if (app()->environment('production')) {
            return CheckResult::fail(
                'cache_driver',
                "Volatile cache driver {$named} in production",
                'Buffers and pauses are lost between requests. Point ranetrace.batch.cache_driver at redis, file, or database.'
            );
        }

        return CheckResult::warn(
            'cache_driver',
            "Volatile cache driver {$named}",
            'Fine locally, but buffers/pauses will not survive in production. Use a durable store there.'
        );
    }
}
