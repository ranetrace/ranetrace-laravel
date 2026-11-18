<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * The rate limit on a route the package mounts in the host application.
 *
 * Each route gets a named limiter of its own rather than Laravel's
 * `throttle:60,1`, for two reasons. That form keys its counter on the user or
 * the IP and nothing else, so every route limited that way shares one count,
 * the host application's own routes included: a page that posts a beacon on
 * each view would spend the limit of the host's `throttle:6,1` routes. And it
 * asks the auth guard for the user with nothing around the call, so a guard
 * that throws fails the request before the controller runs.
 */
class RouteRateLimit
{
    public const string JAVASCRIPT_ERRORS = 'ranetrace-javascript-errors';

    public const string ANALYTICS_BEACON = 'ranetrace-analytics-beacon';

    /**
     * Register the named limiter and return the middleware that applies it.
     *
     * The limit is read from the config on each request, in Laravel's
     * `requests,minutes` form. The minutes may be left out and are then 1. Any
     * other value, such as the name of a limiter of the host's own, gives the
     * default: a route that anyone can post to is never left without a limit.
     */
    public static function middleware(string $name, string $configKey, int $defaultRequests): string
    {
        RateLimiter::for($name, static function (Request $request) use ($configKey, $defaultRequests): Limit {
            [$requests, $minutes] = self::parse(config($configKey), $defaultRequests);

            return Limit::perMinutes($minutes, $requests)->by(self::key($request));
        });

        return 'throttle:'.$name;
    }

    /**
     * @return array{0: int, 1: int} The requests allowed, and the minutes they are counted over.
     */
    protected static function parse(mixed $value, int $defaultRequests): array
    {
        if (! is_string($value) && ! is_int($value)) {
            return [$defaultRequests, 1];
        }

        if (preg_match('/^\s*(\d+)\s*(?:,\s*(\d+)\s*)?$/', (string) $value, $matches) !== 1) {
            return [$defaultRequests, 1];
        }

        $requests = (int) $matches[1];
        $minutes = (int) ($matches[2] ?? 1);

        return $requests > 0 && $minutes > 0 ? [$requests, $minutes] : [$defaultRequests, 1];
    }

    /**
     * Who the request is counted against: the signed-in user, or the IP.
     *
     * A guard that throws counts the request by IP. Nothing is logged here.
     * On the JavaScript errors route the relay asks the guard again for the
     * item's user and reports the failure there, once. The analytics beacon
     * never asks for a user, so on that route the failure is logged nowhere.
     */
    protected static function key(Request $request): string
    {
        try {
            $id = $request->user()?->getAuthIdentifier();
        } catch (Throwable) {
            $id = null;
        }

        return is_int($id) || is_string($id) ? 'user:'.$id : 'ip:'.$request->ip();
    }
}
