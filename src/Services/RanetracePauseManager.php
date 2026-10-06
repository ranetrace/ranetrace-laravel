<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Services;

use Carbon\Carbon;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Ranetrace\Laravel\Support\BatchConfig;

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
        $pauseData = $this->store()->get(self::GLOBAL_PAUSE_KEY);

        if (! $pauseData) {
            return false;
        }

        $pausedUntil = Carbon::parse($pauseData['paused_until']);

        return Carbon::now()->lessThan($pausedUntil);
    }

    /**
     * Check if a specific feature is paused.
     */
    public function isFeaturePaused(string $feature): bool
    {
        $pauseData = $this->store()->get($this->getFeaturePauseKey($feature));

        if (! $pauseData) {
            return false;
        }

        $pausedUntil = Carbon::parse($pauseData['paused_until']);

        return Carbon::now()->lessThan($pausedUntil);
    }

    /**
     * Get global pause data.
     *
     * @return array{paused_until: string, reason: string}|null
     */
    public function getGlobalPause(): ?array
    {
        return $this->store()->get(self::GLOBAL_PAUSE_KEY);
    }

    /**
     * Get feature pause data.
     *
     * @return array{paused_until: string, reason: string}|null
     */
    public function getFeaturePause(string $feature): ?array
    {
        return $this->store()->get($this->getFeaturePauseKey($feature));
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
