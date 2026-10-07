<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Analytics;

use Illuminate\Http\Request;
use Ranetrace\Laravel\Support\Core;
use Ranetrace\Laravel\Utilities\RouteSecretResolver;
use Ranetrace\Php\Support\BrowserIdentity;
use Ranetrace\Php\Support\DeviceType;
use Ranetrace\Php\Support\Utf8;

/**
 * Every string a visit takes from the request goes through ranetrace-php's
 * `Utf8` before it is scrubbed or capped. A request is visitor-controlled, and
 * one invalid byte (`/%E9`, a latin1 `Referer`) fails the JSON encode of the
 * batch the visit is sent in.
 */
class VisitDataCollector
{
    /**
     * Maximum length kept for a visitor-supplied campaign parameter.
     */
    private const int MAX_UTM_LENGTH = 255;

    public static function collect(Request $request): array
    {
        $userAgent = $request->userAgent();
        $url = Utf8::repair($request->fullUrl());
        $parsedPath = parse_url($url, PHP_URL_PATH);
        $path = is_string($parsedPath) && $parsedPath !== '' ? $parsedPath : '/';

        // Secrets also live in the PATH (`password/reset/{token}`), where only
        // the resolved route can tell which segment is a secret. This middleware
        // runs in the `web` GROUP, so the route is already available here; when
        // there is none, the list is empty and both fields are left untouched.
        $sensitiveValues = RouteSecretResolver::forRequest($request);

        $scrubber = Core::scrubber();
        $fingerprints = Core::fingerprints();

        // The referrer describes a DIFFERENT request (the page the visitor came
        // from), so the current route says nothing about it. Same-origin
        // navigations send the full URL by default, which is exactly how a live
        // reset token reaches us one page after `/reset-password/{token}` was
        // itself redacted; that URL gets its own route lookup.
        $referrer = Utf8::repairNullable($request->headers->get('referer'));

        return [
            'url' => $scrubber->scrubUrlPath($scrubber->scrubUrl($url), $sensitiveValues),

            // Reported decoded, so every spelling of one page (`/login`,
            // `/%6Cogin`) is a single entry rather than an attacker-chosen
            // supply of distinct ones, since the router resolved them all to the
            // same route. Scrubbing runs FIRST and on the raw path: the values
            // resolved from the route are compared against rawurldecoded
            // segments, so decoding up front would stop a token that itself
            // contains a `%` from matching.
            'path' => Utf8::repair(rawurldecode($scrubber->scrubPathSegments($path, $sensitiveValues))),
            'ip' => $request->ip(), // Only used internally to resolve geo
            'user_agent' => Utf8::repairNullable($userAgent),
            'user_agent_hash' => $fingerprints->generateUserAgentHash($userAgent),

            'referrer' => $scrubber->scrubUrlPath(
                $scrubber->scrubUrl($referrer),
                RouteSecretResolver::forUrl($referrer)
            ),

            'device_type' => DeviceType::fromUserAgent($userAgent)?->value,
            'browser_name' => self::detectBrowser($userAgent),

            'utm_source' => self::campaignParameter($request, 'utm_source'),
            'utm_medium' => self::campaignParameter($request, 'utm_medium'),
            'utm_campaign' => self::campaignParameter($request, 'utm_campaign'),
            'utm_content' => self::campaignParameter($request, 'utm_content'),
            'utm_term' => self::campaignParameter($request, 'utm_term'),

            'country_code' => self::resolveCountryFromIp($request->ip()),

            // Carbon rather than the generator's own clock, so a host (or a
            // test) that freezes time sees the frozen day boundary.
            'session_id_hash' => $fingerprints->generateSessionIdHash(
                $request->ip(),
                $userAgent,
                now()->format('Y-m-d'),
            ),

            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Read a campaign parameter as a bounded string.
     *
     * Query parameters are visitor-controlled: `?utm_source[]=x` yields an
     * array and the value is otherwise unbounded, while the backend refuses
     * an item that violates the schema, losing the visit. Anything that is not
     * a string is dropped rather than shipped.
     */
    protected static function campaignParameter(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) ? mb_substr(Utf8::repair($value), 0, self::MAX_UTM_LENGTH) : null;
    }

    /**
     * A user agent that names no browser BrowserIdentity knows is 'Other', while no
     * user agent at all is null: the app keeps "a browser we do not name" apart from "unknown".
     */
    protected static function detectBrowser(?string $userAgent): ?string
    {
        if ($userAgent === null || $userAgent === '') {
            return null;
        }

        return BrowserIdentity::fromUserAgent($userAgent)->name ?? 'Other';
    }

    protected static function resolveCountryFromIp(?string $ip): ?string
    {
        // Country resolution not implemented yet
        // Future implementation would go here to resolve country from IP address
        return null;
    }
}
