<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Dashboard\Checks;

/**
 * The buffer and pause state live in the cache. A volatile store (array/null)
 * loses them between requests — critical in production, only a warning locally.
 */
class CacheDriverCheck implements Check
{
    /**
     * @var array<int, string>
     */
    protected const array VOLATILE_DRIVERS = ['array', 'null'];

    public function run(array $status): CheckResult
    {
        $driver = (string) ($status['config']['cache_driver'] ?? 'file');

        if (! in_array($driver, self::VOLATILE_DRIVERS, true)) {
            return CheckResult::pass('cache_driver', "Cache driver \"{$driver}\" persists between requests");
        }

        if (app()->environment('production')) {
            return CheckResult::fail(
                'cache_driver',
                "Volatile cache driver \"{$driver}\" in production",
                'Buffers and pauses are lost between requests. Point ranetrace.batch.cache_driver at redis, file, or database.'
            );
        }

        return CheckResult::warn(
            'cache_driver',
            "Volatile cache driver \"{$driver}\"",
            'Fine locally, but buffers/pauses will not survive in production — use a durable store there.'
        );
    }
}
