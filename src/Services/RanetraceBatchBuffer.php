<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Psr\SimpleCache\InvalidArgumentException;
use Ranetrace\Laravel\Support\InternalLogger;

class RanetraceBatchBuffer
{
    /**
     * Canonical list of all buffer types. Single source of truth — consumers
     * must derive from this rather than hardcoding their own lists.
     *
     * @var array<int, string>
     */
    public const array TYPES = ['errors', 'events', 'logs', 'page_visits', 'javascript_errors'];

    protected const string BUFFER_PREFIX = 'ranetrace:buffer:';

    protected string $cacheDriver;

    protected int $ttl;

    /**
     * Seconds to block waiting for a buffer's cache lock before giving up.
     * The critical sections are sub-millisecond, so a short wait lets concurrent
     * writers serialize instead of dropping their item on the first collision.
     */
    protected float $lockWait;

    public function __construct()
    {
        $this->cacheDriver = config('ranetrace.batch.cache_driver', 'file');
        $this->ttl = config('ranetrace.batch.buffer_ttl', 3600);
        $this->lockWait = (float) config('ranetrace.batch.lock_wait', 1);
    }

    /**
     * Add a single item to the buffer for a specific type.
     *
     * @return bool True when the item was buffered, false when the cache lock
     *              could not be acquired within the wait window (item not added).
     */
    public function addItem(string $type, array $data): bool
    {
        return $this->addItems($type, [$data]);
    }

    /**
     * Add multiple items to the buffer for a specific type in a single locked
     * cache operation. Used for both single adds and bulk failure re-queues so
     * a failed batch is re-buffered with one get/put instead of N.
     *
     * @param  array<int, array>  $dataItems
     * @return bool True when the items were buffered, false when the cache lock
     *              could not be acquired within the wait window (items not added).
     */
    public function addItems(string $type, array $dataItems): bool
    {
        if (empty($dataItems)) {
            return true;
        }

        $cacheKey = $this->getCacheKey($type);

        // Block briefly for the lock so concurrent writers serialize instead of
        // dropping their item on the first collision. The wait is short (see
        // $lockWait) because the critical section below is sub-millisecond.
        try {
            Cache::store($this->cacheDriver)->lock($cacheKey.':lock', 10)->block($this->lockWait, function () use ($type, $cacheKey, $dataItems) {
                $buffer = Cache::store($this->cacheDriver)->get($cacheKey, []);

                foreach ($dataItems as $data) {
                    $buffer[] = [
                        'id' => (string) Str::uuid(),
                        'data' => $data,
                        'timestamp' => now()->timestamp,
                    ];
                }

                // Check max buffer size
                $maxSize = $this->getMaxBufferSize();
                if (count($buffer) > $maxSize) {
                    $dropped = count($buffer) - $maxSize;

                    // Keep only the most recent items (FIFO — oldest dropped first)
                    $buffer = array_slice($buffer, -$maxSize);

                    // Buffer overflow drops data by design — log it so the loss is
                    // visible, but only once per overflow cycle (see logOverflowOnce).
                    $this->logOverflowOnce($type, $dropped, $maxSize);
                }

                Cache::store($this->cacheDriver)->put($cacheKey, $buffer, $this->ttl);
            });
        } catch (LockTimeoutException) {
            // Lock not acquired within the wait window. Callers re-queue rather
            // than drop (see BaseRanetraceJob::bufferOrRelease) — log so the rare
            // contention is still visible.
            InternalLogger::warning('Could not acquire cache lock to add items to buffer', [
                'type' => $type,
                'count' => count($dataItems),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Get items from the buffer for processing.
     * Items are atomically removed from the buffer to prevent duplicate processing.
     *
     * @return array<int, array{id: string, data: array, timestamp: int}>
     *
     * @throws InvalidArgumentException
     */
    public function getItems(string $type, int $limit): array
    {
        $cacheKey = $this->getCacheKey($type);

        try {
            $itemsToProcess = Cache::store($this->cacheDriver)->lock($cacheKey.':lock', 10)->block($this->lockWait, function () use ($cacheKey, $limit) {
                $buffer = Cache::store($this->cacheDriver)->get($cacheKey, []);

                // Get items to process
                $itemsToProcess = array_slice($buffer, 0, $limit);

                // Remove these items from the buffer atomically
                $buffer = array_slice($buffer, $limit);

                // A drain that brings the buffer below capacity ends the overflow
                // cycle — clear the flag so the next overflow is logged again.
                if (count($buffer) < $this->getMaxBufferSize()) {
                    Cache::store($this->cacheDriver)->forget($cacheKey.':overflow');
                }

                // Update cache
                if (empty($buffer)) {
                    Cache::store($this->cacheDriver)->forget($cacheKey);
                } else {
                    Cache::store($this->cacheDriver)->put($cacheKey, $buffer, $this->ttl);
                }

                return $itemsToProcess;
            });
        } catch (LockTimeoutException) {
            // Lock not acquired within the wait window. Harmless for a drain —
            // the items stay buffered and the next ranetrace:work run picks them
            // up. Log for monitoring and return nothing this cycle.
            InternalLogger::warning('Could not acquire cache lock to get items from buffer', ['type' => $type]);

            return [];
        }

        return $itemsToProcess;
    }

    /**
     * Get the count of items in the buffer for a specific type.
     */
    public function count(string $type): int
    {
        $cacheKey = $this->getCacheKey($type);
        $buffer = Cache::store($this->cacheDriver)->get($cacheKey, []);

        return count($buffer);
    }

    /**
     * Timestamp of the oldest item currently buffered for a type, or null when
     * the buffer is empty or unreadable. Items are appended in arrival order and
     * drained FIFO, so the first element is always the oldest. Lets the dashboard
     * tell a buffer that is simply waiting for its next drain apart from one that
     * is genuinely stalled.
     */
    public function oldestTimestamp(string $type): ?int
    {
        $cacheKey = $this->getCacheKey($type);
        $buffer = Cache::store($this->cacheDriver)->get($cacheKey, []);

        $timestamp = $buffer[0]['timestamp'] ?? null;

        return is_numeric($timestamp) ? (int) $timestamp : null;
    }

    /**
     * Clear all items from the buffer for a specific type.
     */
    public function clear(string $type): void
    {
        $cacheKey = $this->getCacheKey($type);
        Cache::store($this->cacheDriver)->forget($cacheKey);
    }

    /**
     * Get all available buffer types that have items.
     *
     * @return array<int, string>
     */
    public function getAvailableTypes(): array
    {
        return array_values(array_filter(
            self::TYPES,
            fn (string $type): bool => $this->count($type) > 0
        ));
    }

    /**
     * Get the cache key for a specific type.
     */
    protected function getCacheKey(string $type): string
    {
        return self::BUFFER_PREFIX.$type;
    }

    /**
     * Log a buffer-overflow drop at most once per overflow cycle. An overflow
     * flag is set on the first drop and cleared by getItems() once a drain
     * brings the buffer back below capacity — preventing log spam under load.
     */
    protected function logOverflowOnce(string $type, int $dropped, int $maxSize): void
    {
        $flagKey = $this->getCacheKey($type).':overflow';

        if (Cache::store($this->cacheDriver)->get($flagKey, false)) {
            return;
        }

        Cache::store($this->cacheDriver)->put($flagKey, true, $this->ttl);

        InternalLogger::warning('Ranetrace buffer overflow — oldest items dropped', [
            'type' => $type,
            'dropped' => $dropped,
            'max' => $maxSize,
        ]);
    }

    /**
     * Get the maximum buffer size to prevent memory issues.
     */
    protected function getMaxBufferSize(): int
    {
        return config('ranetrace.batch.max_buffer_size', 5000);
    }
}
