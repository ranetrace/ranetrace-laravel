<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Analytics;

use Illuminate\Http\Request;
use Ranetrace\Laravel\Utilities\SecretScrubber;

class VisitDataCollector
{
    public static function collect(Request $request): array
    {
        $userAgent = $request->userAgent();
        $url = $request->fullUrl();

        return [
            'url' => SecretScrubber::scrubUrl($url),
            'path' => parse_url($url, PHP_URL_PATH) ?? '/',
            'ip' => $request->ip(), // Only used internally to resolve geo
            'user_agent' => $userAgent,
            'user_agent_hash' => FingerprintGenerator::generateUserAgentHash($request),

            'referrer' => SecretScrubber::scrubUrl($request->headers->get('referer')),

            'device_type' => self::detectDeviceType($userAgent),
            'browser_name' => self::detectBrowser($userAgent),

            'utm_source' => $request->query('utm_source'),
            'utm_medium' => $request->query('utm_medium'),
            'utm_campaign' => $request->query('utm_campaign'),
            'utm_content' => $request->query('utm_content'),
            'utm_term' => $request->query('utm_term'),

            'country_code' => self::resolveCountryFromIp($request->ip()),

            'session_id_hash' => FingerprintGenerator::generateSessionIdHash($request),

            'timestamp' => now()->toIso8601String(),
        ];
    }

    protected static function detectDeviceType(?string $userAgent): ?string
    {
        if (! $userAgent) {
            return null;
        }

        $ua = mb_strtolower($userAgent);

        // Check for tablets first (more specific)
        if (
            preg_match('/(ipad|tablet|playbook|silk)/i', $ua) ||
            (preg_match('/android/i', $ua) && ! preg_match('/mobile/i', $ua))
        ) {
            return 'tablet';
        }

        // Check for mobile devices with more comprehensive patterns
        if (preg_match('/(android|iphone|ipod|blackberry|iemobile|opera mini|opera mobi|webos|mobile safari|samsung.+mobile)/i', $ua) ||
            (str_contains($ua, 'mobile') && ! str_contains($ua, 'ipad'))
        ) {
            return 'mobile';
        }

        // Check for gaming consoles
        if (preg_match('/(nintendo|playstation|xbox)/i', $ua)) {
            return 'console';
        }

        // Default to desktop
        return 'desktop';
    }

    protected static function detectBrowser(?string $userAgent): ?string
    {
        if (! $userAgent) {
            return null;
        }

        // Define browser patterns in order of precedence
        $patterns = [
            'Edge' => '/Edge?\//i',
            'Opera' => '/(Opera|OPR)\//i',
            'Samsung' => '/SamsungBrowser\//i',
            'Firefox' => '/Firefox\//i',
            'Chrome' => '/Chrome\//i',
            'Safari' => '/Version\/.*Safari/i',
            'IE' => '/(MSIE |Trident\/.*rv:)/i',
            'UCBrowser' => '/UCBrowser\//i',
        ];

        foreach ($patterns as $browser => $pattern) {
            if (preg_match($pattern, $userAgent)) {
                // Return browser name only
                return $browser;
            }
        }

        return 'Other';
    }

    protected static function resolveCountryFromIp(?string $ip): ?string
    {
        // Country resolution not implemented yet
        // Future implementation would go here to resolve country from IP address
        return null;
    }
}
