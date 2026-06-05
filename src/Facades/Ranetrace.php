<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Facades;

use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Support\Facades\Facade;
use Throwable;

/**
 * @see \Ranetrace\Laravel\Ranetrace
 *
 * @method static void trackEvent(string $eventName, array $properties = [], int|string|null $userId = null, bool $validate = true)
 */
class Ranetrace extends Facade
{
    public static function handles(Exceptions $exceptions): void
    {
        $exceptions->reportable(static function (Throwable $exception): bool {
            app(\Ranetrace\Laravel\Ranetrace::class)->report($exception);

            // Additive capture: returning non-false lets Laravel's default
            // logging stack continue (returning false would suppress it).
            return true;
        });
    }

    protected static function getFacadeAccessor(): string
    {
        return \Ranetrace\Laravel\Ranetrace::class;
    }
}
