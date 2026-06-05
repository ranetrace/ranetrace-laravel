<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Utilities;

/**
 * Redacts values stored under sensitive keys before telemetry leaves the host.
 *
 * Applied to log context/extra (and, from the error path, exception context) so
 * that secrets a developer accidentally logs — e.g. `Log::error('x', ['api_key'
 * => $key])` — never reach the Ranetrace backend. Matching is a case-insensitive
 * substring test on the key name. The built-in fragment list is always applied
 * and can be extended (never shrunk) via `ranetrace.scrubbing.extra_keys`.
 */
class SecretScrubber
{
    public const string REDACTION = '[REDACTED]';

    /**
     * Built-in sensitive key fragments (case-insensitive substring match).
     *
     * @var array<int, string>
     */
    private const array DEFAULT_KEYS = [
        'password',
        'passwd',
        'secret',
        'token',
        'api_key',
        'apikey',
        'api-key',
        'authorization',
        'credential',
        'private_key',
        'access_key',
        'signature',
    ];

    /**
     * Recursively redact array values whose key matches a sensitive fragment.
     *
     * Non-array input is returned untouched, so this composes directly with the
     * `mixed` return of {@see DataSanitizer::sanitizeForSerialization()}.
     */
    public static function scrub(mixed $data): mixed
    {
        if (! is_array($data)) {
            return $data;
        }

        return self::scrubArray($data, self::sensitiveFragments());
    }

    /**
     * Like {@see scrub()} (key-based redaction), but ALSO scrubs sensitive
     * query-string params inside URL-shaped string VALUES — catching a secret
     * in an innocuously-keyed URL (e.g. a breadcrumb `data.endpoint` of
     * `https://api/x?token=…`) that key-based scrubbing alone would miss.
     *
     * Intended for free-form, untrusted breadcrumb/context data. Composes with
     * the `mixed` return of {@see DataSanitizer::sanitizeForSerialization()},
     * which has already bounded the recursion depth.
     */
    public static function scrubDeep(mixed $data): mixed
    {
        return self::scrubUrlValues(self::scrub($data));
    }

    /**
     * Redact sensitive query-string parameters within a URL, preserving the
     * scheme, host, path and fragment. Non-sensitive params keep their exact
     * encoding; the URL is returned untouched when it has no query string or no
     * sensitive params. Use for `url`/`referrer` analytics fields, which can
     * otherwise carry reset tokens, signed-URL signatures, `?api_key=`, etc.
     */
    public static function scrubUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return $url;
        }

        $query = parse_url($url, PHP_URL_QUERY);
        if (! is_string($query) || $query === '') {
            return $url;
        }

        $scrubbed = self::scrubQuery($query, self::sensitiveFragments());
        if ($scrubbed === $query) {
            return $url;
        }

        $queryStart = mb_strpos($url, '?');
        if ($queryStart === false) {
            return $url;
        }

        $fragmentStart = mb_strpos($url, '#', $queryStart);
        $fragment = $fragmentStart !== false ? mb_substr($url, $fragmentStart) : '';

        return mb_substr($url, 0, $queryStart).'?'.$scrubbed.$fragment;
    }

    /**
     * Redact `key=value` / `key: value` / `key => value` pairs in a free-form
     * string when the key contains a sensitive fragment. Partial, best-effort
     * defense-in-depth for strings we cannot structure (e.g. exception stack
     * traces): it catches query-string-like and key/value leakage, but not
     * positional secret arguments that carry no key.
     */
    public static function scrubString(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        $alternation = implode('|', array_map(
            static fn (string $fragment): string => preg_quote($fragment, '/'),
            self::sensitiveFragments()
        ));

        // key-token (containing a sensitive fragment) + separator (= : =>) + value.
        $pattern = '/(["\']?[\w.\-]*(?:'.$alternation.')[\w.\-]*["\']?\s*(?:=>|[:=])\s*)(["\']?)([^"\'\s,;&)}]+)\2/i';

        $result = preg_replace_callback($pattern, static function (array $matches): string {
            return $matches[1].$matches[2].self::REDACTION.$matches[2];
        }, $value);

        return $result ?? $value;
    }

    /**
     * Redact the values of sensitive keys in a raw query string, leaving every
     * other pair byte-for-byte intact.
     *
     * @param  array<int, string>  $fragments
     */
    private static function scrubQuery(string $query, array $fragments): string
    {
        $pairs = explode('&', $query);

        foreach ($pairs as $index => $pair) {
            if ($pair === '') {
                continue;
            }

            $equals = mb_strpos($pair, '=');
            $rawKey = $equals === false ? $pair : mb_substr($pair, 0, $equals);

            if (self::isSensitive(urldecode($rawKey), $fragments)) {
                $pairs[$index] = $rawKey.'='.self::REDACTION;
            }
        }

        return implode('&', $pairs);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  array<int, string>  $fragments
     * @return array<array-key, mixed>
     */
    private static function scrubArray(array $data, array $fragments): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitive($key, $fragments)) {
                $data[$key] = self::REDACTION;

                continue;
            }

            if (is_array($value)) {
                $data[$key] = self::scrubArray($value, $fragments);
            }
        }

        return $data;
    }

    /**
     * Recursively apply {@see scrubUrl()} to every string value that looks like
     * an absolute http(s) URL, leaving all other values untouched. Operates on
     * the already-depth-bounded output of {@see DataSanitizer}.
     */
    private static function scrubUrlValues(mixed $data): mixed
    {
        if (is_string($data)) {
            return str_starts_with($data, 'http://') || str_starts_with($data, 'https://')
                ? (self::scrubUrl($data) ?? $data)
                : $data;
        }

        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = self::scrubUrlValues($value);
            }
        }

        return $data;
    }

    /**
     * @param  array<int, string>  $fragments
     */
    private static function isSensitive(string $key, array $fragments): bool
    {
        $haystack = mb_strtolower($key);

        foreach ($fragments as $fragment) {
            if (str_contains($haystack, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Built-in fragments merged with the user-configured extensions.
     *
     * @return array<int, string>
     */
    private static function sensitiveFragments(): array
    {
        $extra = config('ranetrace.scrubbing.extra_keys', []);

        if (! is_array($extra)) {
            $extra = [];
        }

        $extra = array_map(
            static fn (mixed $fragment): string => mb_strtolower((string) $fragment),
            $extra
        );

        return array_values(array_unique([...self::DEFAULT_KEYS, ...$extra]));
    }
}
