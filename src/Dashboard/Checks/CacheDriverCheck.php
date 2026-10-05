<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Dashboard\Checks;

use Ranetrace\Laravel\Support\BatchConfig;

/**
 * The buffer and pause state live in the cache. A volatile store (array/null)
 * loses them between requests: critical in production, only a warning locally.
 */
class CacheDriverCheck implements Check
{
    /**
     * @var array<int, string>
     */
    protected const array VOLATILE_DRIVERS = ['array', 'null'];

    public function run(array $status): CheckResult
    {
        $driver = $status['config']['cache_driver'];
        $named = "\"{$driver}\"".($status['config']['cache_driver_is_app_default'] ? ' ('.BatchConfig::APP_DEFAULT_STORE_NOTE.')' : '');

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
