<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Analytics;

use Illuminate\Http\Request;

class FingerprintGenerator
{
    /**
     * Generate a session ID hash for linking visits and events
     * This uses the same logic as VisitDataCollector for consistency
     */
    public static function generateSessionIdHash(?Request $request = null): string
    {
        $request ??= request();

        // Rotate daily, non-persistent session hash
        $raw = $request->ip().'|'.mb_substr($request->userAgent() ?? '', 0, 100).'|'.now()->format('Y-m-d');

        return self::hash($raw);
    }

    /**
     * Generate a user agent hash
     */
    public static function generateUserAgentHash(?Request $request = null): string
    {
        $request ??= request();
        $userAgent = $request->userAgent();

        return $userAgent ? self::hash($userAgent) : '';
    }

    /**
     * HMAC-SHA256 an arbitrary value with the per-install fingerprint salt.
     * Use to pseudonymise an identifier (e.g. the raw session id) before it
     * leaves the host.
     */
    public static function hash(string $value): string
    {
        return hash_hmac('sha256', $value, self::salt());
    }

    /**
     * Per-install salt for fingerprint HMACs. Defaults to the application key so
     * hashes are non-reversible out of the box; set `ranetrace.fingerprint_salt`
     * to rotate fingerprints independently of APP_KEY.
     */
    private static function salt(): string
    {
        return (string) (config('ranetrace.fingerprint_salt') ?: config('app.key'));
    }
}
