<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Services;

use Carbon\Carbon;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Ranetrace\Laravel\Support\BatchConfig;
use Throwable;

class RanetracePauseManager
{
    protected const string FEATURE_PAUSE_PREFIX = 'ranetrace.feature.';

    protected const string GLOBAL_PAUSE_KEY = 'ranetrace.global.pause';

    protected string $cacheDriver;

    public function __construct()
    {
        $this->cacheDriver = BatchConfig::cacheStoreName();
    }

    /**
     * Set a global pause (applies to all features).
     */
    public function setGlobalPause(int $seconds, string $reason): void
    {
        $pausedUntil = Carbon::now()->addSeconds($seconds);

        $this->store()->put(
            self::GLOBAL_PAUSE_KEY,
            [
                'paused_until' => $pausedUntil->toIso8601String(),
                'reason' => $reason,
            ],
            $pausedUntil
        );
    }

    /**
     * Set a pause for a specific feature.
     */
    public function setFeaturePause(string $feature, int $seconds, string $reason): void
    {
        $pausedUntil = Carbon::now()->addSeconds($seconds);

        $this->store()->put(
            $this->getFeaturePauseKey($feature),
            [
                'paused_until' => $pausedUntil->toIso8601String(),
                'reason' => $reason,
            ],
            $pausedUntil
        );
    }

    /**
     * Check if globally paused.
     */
    public function isGloballyPaused(): bool
    {
        return $this->isActive($this->getGlobalPause());
    }

    /**
     * Check if a specific feature is paused.
     */
    public function isFeaturePaused(string $feature): bool
    {
        return $this->isActive($this->getFeaturePause($feature));
    }

    /**
     * Get global pause data.
     *
     * @return array{paused_until: string, reason: string}|null
     */
    public function getGlobalPause(): ?array
    {
        return $this->read(self::GLOBAL_PAUSE_KEY);
    }

    /**
     * Get feature pause data.
     *
     * @return array{paused_until: string, reason: string}|null
     */
    public function getFeaturePause(string $feature): ?array
    {
        return $this->read($this->getFeaturePauseKey($feature));
    }

    /**
     * Clear global pause.
     */
    public function clearGlobalPause(): void
    {
        $this->store()->forget(self::GLOBAL_PAUSE_KEY);
    }

    /**
     * Clear feature pause.
     */
    public function clearFeaturePause(string $feature): void
    {
        $this->store()->forget($this->getFeaturePauseKey($feature));
    }

    /**
     * A stored pause, in the shape every reader counts on. This class only
     * writes that shape, but the entry sits in a cache other writers can
     * reach. An entry that does not say when it ends reads as no pause: held
     * as one, it would stop every send until someone cleared it by hand.
     * Every reader prints the reason or matches on it, so a reason that is
     * not a scalar reads as `''`, the reason nothing is known about.
     *
     * @return array{paused_until: string, reason: string}|null
     */
    protected function read(string $key): ?array
    {
        $pause = $this->store()->get($key);

        if (! is_array($pause) || $this->endOf($pause['paused_until'] ?? null) === null) {
            return null;
        }

        return [
            ...$pause,
            'reason' => is_scalar($pause['reason'] ?? null) ? (string) $pause['reason'] : '',
        ];
    }

    /**
     * @param  array{paused_until: string, reason: string}|null  $pause
     */
    protected function isActive(?array $pause): bool
    {
        return $pause !== null && Carbon::now()->lessThan($this->endOf($pause['paused_until']));
    }

    /**
     * The moment a stored `paused_until` names, or null when it names none.
     * Carbon reads an empty string as now, which is no end either.
     */
    protected function endOf(mixed $pausedUntil): ?Carbon
    {
        if (! is_string($pausedUntil) || mb_trim($pausedUntil) === '') {
            return null;
        }

        try {
            return Carbon::parse($pausedUntil);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The batch cache store, resolved on each use rather than in the
     * constructor so a store that cannot be resolved fails the call that
     * needs it, not every class this one is injected into.
     */
    protected function store(): Repository
    {
        return Cache::store($this->cacheDriver);
    }

    /**
     * Get the cache key for a feature pause.
     */
    protected function getFeaturePauseKey(string $feature): string
    {
        return self::FEATURE_PAUSE_PREFIX.$feature.'.pause';
    }
}
