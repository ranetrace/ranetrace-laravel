<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Facades;

use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Support\Facades\Facade;
use Throwable;

/**
 * @see \Ranetrace\Laravel\Ranetrace
 *
 * @method static void trackEvent(string $eventName, array $properties = [], ?int $userId = null, bool $validate = true)
 */
class Ranetrace extends Facade
{
    public static function handles(Exceptions $exceptions): void
    {
        $exceptions->reportable(static function (Throwable $exception): \Ranetrace\Laravel\Ranetrace {
            $ranetrace = app(\Ranetrace\Laravel\Ranetrace::class);

            $ranetrace->report($exception);

            return $ranetrace;
        });
    }

    protected static function getFacadeAccessor(): string
    {
        return \Ranetrace\Laravel\Ranetrace::class;
    }
}
