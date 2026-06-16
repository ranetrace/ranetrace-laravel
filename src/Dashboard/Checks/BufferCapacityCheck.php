<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Dashboard\Checks;

use Illuminate\Support\Facades\Cache;
use Ranetrace\Laravel\Dashboard\DashboardData;
use Throwable;

/**
 * Buffers at/over capacity, or with an active overflow flag, mean data is being
 * dropped (oldest items first). Overflow is active data loss; near-capacity is a
 * warning that loss is imminent.
 */
class BufferCapacityCheck implements Check
{
    /**
     * Buffer cache-key prefix and the per-feature overflow flag suffix, mirroring
     * RanetraceBatchBuffer's own keys.
     */
    protected const string BUFFER_PREFIX = 'ranetrace:buffer:';

    public function run(array $status): CheckResult
    {
        $buffers = $status['buffers']['features'] ?? [];
        $max = (int) ($status['buffers']['max_per_feature'] ?? 0);

        $overflowing = $this->overflowingFeatures(array_keys($buffers));
        if (! empty($overflowing)) {
            return CheckResult::fail(
                'buffer_capacity',
                'Buffer overflow: '.implode(', ', $overflowing),
                'Oldest items are being dropped (active data loss). Speed up draining: check `ranetrace:work` and queue throughput.'
            );
        }

        $near = [];
        foreach ($buffers as $feature => $count) {
            if ($max > 0 && (int) $count >= $max * DashboardData::NEAR_CAPACITY_RATIO) {
                $near[] = $feature;
            }
        }

        if (! empty($near)) {
            return CheckResult::warn(
                'buffer_capacity',
                'Buffers near capacity: '.implode(', ', $near),
                'Data loss is imminent once a buffer fills. Confirm the worker is keeping up.'
            );
        }

        return CheckResult::pass('buffer_capacity', 'Buffers have headroom');
    }

    /**
     * Features whose overflow flag is currently set. Reads are defensive — a
     * cache failure yields an empty list rather than throwing.
     *
     * @param  array<int, string>  $features
     * @return array<int, string>
     */
    protected function overflowingFeatures(array $features): array
    {
        try {
            $driver = config('ranetrace.batch.cache_driver', 'file');
            $store = Cache::store($driver);

            return array_values(array_filter(
                $features,
                fn (string $feature): bool => (bool) $store->get(self::BUFFER_PREFIX.$feature.':overflow', false)
            ));
        } catch (Throwable) {
            return [];
        }
    }
}
