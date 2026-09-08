<?php

declare(strict_types=1);

return [
    'enabled' => env('RANETRACE_ENABLED', true),
    'key' => env('RANETRACE_KEY'),

    /*
     * Salt for the one-way analytics fingerprints (the visitor and session
     * hashes). Left unset it falls back to the API key above, which every
     * install has and which never travels inside a payload. Set it to rotate
     * fingerprints without rotating the key.
     */
    'fingerprint_salt' => env('RANETRACE_FINGERPRINT_SALT'),

    'errors' => [
        'enabled' => env('RANETRACE_ERRORS_ENABLED', true),
        'queue' => env('RANETRACE_ERRORS_QUEUE', true),
        'queue_name' => env('RANETRACE_ERRORS_QUEUE_NAME', 'default'),
        'timeout' => env('RANETRACE_ERRORS_TIMEOUT', 10),
        'capture_user_email' => env('RANETRACE_ERRORS_CAPTURE_USER_EMAIL', false),
    ],

    'events' => [
        'enabled' => env('RANETRACE_EVENTS_ENABLED', true),
        'queue' => env('RANETRACE_EVENTS_QUEUE', true),
        'queue_name' => env('RANETRACE_EVENTS_QUEUE_NAME', 'default'),
        'timeout' => env('RANETRACE_EVENTS_TIMEOUT', 10),
    ],

    'logging' => [
        'enabled' => env('RANETRACE_LOGGING_ENABLED', false),
        'queue' => env('RANETRACE_LOGGING_QUEUE', true),
        'queue_name' => env('RANETRACE_LOGGING_QUEUE_NAME', 'default'),
        'timeout' => env('RANETRACE_LOGGING_TIMEOUT', 10),
        'level' => env('RANETRACE_LOGGING_LEVEL', 'notice'),
        'excluded_channels' => [
            // Add channels here that should never be sent to Ranetrace
        ],
    ],

    'website_analytics' => [
        'enabled' => env('RANETRACE_WEBSITE_ANALYTICS_ENABLED', false),
        'queue' => env('RANETRACE_WEBSITE_ANALYTICS_QUEUE', true),
        'queue_name' => env('RANETRACE_WEBSITE_ANALYTICS_QUEUE_NAME', 'default'),
        'timeout' => env('RANETRACE_WEBSITE_ANALYTICS_TIMEOUT', 10),
        'excluded_paths' => [
            'horizon',
            'nova',
            'telescope',
            'admin',
            'filament',
            'api',
            'debugbar',
            'storage',
            'livewire',
            '_debugbar',
            'up',
            'sanctum',
            '_ignition',
        ],
        'request_filter' => null,
        'user_agent' => [
            'min_length' => env('RANETRACE_WEBSITE_ANALYTICS_UA_MIN_LENGTH', 10),
            'max_length' => env('RANETRACE_WEBSITE_ANALYTICS_UA_MAX_LENGTH', 1000),
        ],
        'throttle_seconds' => env('RANETRACE_WEBSITE_ANALYTICS_THROTTLE_SECONDS', 30),

        // Minimum human-probability score (0-100) a request must reach to be
        // captured. Higher = stricter: 70 keeps only "likely human" traffic,
        // 50 also keeps the borderline "possibly human" band. Lower this if you
        // notice legitimate visitors (uncommon browsers, privacy proxies that
        // strip headers) being dropped from your analytics.
        'min_human_score' => env('RANETRACE_WEBSITE_ANALYTICS_MIN_HUMAN_SCORE', 70),

        // Consistency check for spoofed user agents. Modern Chromium browsers
        // (Chrome/Edge) always send fetch-metadata (Sec-Fetch-*) and User-Agent
        // Client Hint (Sec-CH-UA) headers. A request whose user agent claims a
        // recent Chromium build but carries none of them is almost always an
        // HTTP client wearing a fake browser user agent, so it is rejected.
        // `modern_browser_min_version` is the Chrome/Edge major version at or
        // above which these headers are required; set `require_client_hints` to
        // false to disable the check entirely.
        'bot_detection' => [
            'require_client_hints' => env('RANETRACE_WEBSITE_ANALYTICS_REQUIRE_CLIENT_HINTS', true),
            'modern_browser_min_version' => env('RANETRACE_WEBSITE_ANALYTICS_MODERN_BROWSER_MIN_VERSION', 100),
        ],

        // Human-verification beacon. Opt in, off by default. With it on, the
        // visit job waits `wait_seconds` for a tiny script on the page to post
        // one opaque token back, then reports the visit as verified or not.
        // The beacon rides the existing `@ranetraceErrorTracking` directive, so
        // a layout that already has it needs only the env var.
        //
        // It requires a real queue connection: a `sync` queue ignores the
        // delay, so with `QUEUE_CONNECTION=sync` visits go out without the flag
        // rather than late.
        //
        // The token is printed into the HTML, so a full-page cache in front of
        // the app (Cloudflare cache-everything, a static export) serves one
        // token to many visitors and the beacon must stay off there.
        //
        // `delay_ms` is how long the browser waits before posting, which
        // filters instant bounces and prerenders. `throttle` is the rate limit
        // on the beacon route, in Laravel's `requests,minutes` form.
        'beacon' => [
            'enabled' => env('RANETRACE_WEBSITE_ANALYTICS_BEACON_ENABLED', false),
            'wait_seconds' => env('RANETRACE_WEBSITE_ANALYTICS_BEACON_WAIT_SECONDS', 15),
            'delay_ms' => env('RANETRACE_WEBSITE_ANALYTICS_BEACON_DELAY_MS', 1500),
            'throttle' => env('RANETRACE_WEBSITE_ANALYTICS_BEACON_THROTTLE', '120,1'),
        ],

        'extra_bot_user_agents' => [
            // 'YourCustomMonitor/',
        ],
        'debug' => [
            'preserve_user_agent' => env('RANETRACE_WEBSITE_ANALYTICS_DEBUG_PRESERVE_UA', false),
        ],
    ],

    'javascript_errors' => [
        'enabled' => env('RANETRACE_JAVASCRIPT_ERRORS_ENABLED', false),
        'queue' => env('RANETRACE_JAVASCRIPT_ERRORS_QUEUE', true),
        'queue_name' => env('RANETRACE_JAVASCRIPT_ERRORS_QUEUE_NAME', 'default'),
        'timeout' => env('RANETRACE_JAVASCRIPT_ERRORS_TIMEOUT', 10),
        'throttle' => env('RANETRACE_JAVASCRIPT_ERRORS_THROTTLE', '60,1'),
        'sample_rate' => env('RANETRACE_JAVASCRIPT_ERRORS_SAMPLE_RATE', 1.0), // 1.0 = 100%, 0.1 = 10%
        'ignored_errors' => [
            // Browser quirks and unfixable issues
            'ResizeObserver loop limit exceeded',
            'ResizeObserver loop completed with undelivered notifications',

            // Cross-origin errors (no useful information due to CORS)
            'Script error.',
            'Script error',

            // Network errors (usually user connection issues, not bugs)
            'Failed to fetch',
            'NetworkError when attempting to fetch resource',
            'Network request failed',
            'Load failed',

            // Webpack/Vite chunk loading (usually navigation/stale deployments)
            'Loading chunk',
            'ChunkLoadError',

            // User-cancelled operations
            'cancelled',
            'canceled',
            'The operation was aborted',
            'AbortError',

            // Browser extension interference
            'Illegal invocation',

            // Add your own patterns here as needed
        ],
        'capture_console_errors' => env('RANETRACE_JAVASCRIPT_ERRORS_CAPTURE_CONSOLE_ERRORS', false),
        'max_breadcrumbs' => env('RANETRACE_JAVASCRIPT_ERRORS_MAX_BREADCRUMBS', 20),
    ],

    'batch' => [
        'queue_name' => env('RANETRACE_BATCH_QUEUE_NAME', 'default'),
        'cache_driver' => env('RANETRACE_BATCH_CACHE_DRIVER', env('CACHE_STORE', env('CACHE_DRIVER', 'file'))),
        'buffer_ttl' => env('RANETRACE_BATCH_BUFFER_TTL', 3600), // 1 hour
        'max_buffer_size' => env('RANETRACE_BATCH_MAX_BUFFER_SIZE', 5000),
        'lock_wait' => env('RANETRACE_BATCH_LOCK_WAIT', 1), // seconds to wait for a buffer lock (0 = non-blocking)
    ],

    'scrubbing' => [
        'extra_keys' => [
            // 'x_internal_signature',
        ],
    ],

    'internal_logging' => [
        'enabled' => env('RANETRACE_INTERNAL_LOGGING_ENABLED', true),
        'level' => env('RANETRACE_INTERNAL_LOGGING_LEVEL', 'debug'),
        'days' => env('RANETRACE_INTERNAL_LOGGING_DAYS', 14),
        'stderr_fallback' => env('RANETRACE_INTERNAL_STDERR_FALLBACK', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Diagnostics Dashboard
    |--------------------------------------------------------------------------
    |
    | In-app health/diagnostics surface for this Ranetrace installation, in the
    | spirit of Horizon/Pulse. Registered independently of the master `enabled`
    | switch above so an admin can still open it to see *why* capture is off.
    |
    | Access is guarded solely by the `viewRanetrace` gate, which defaults to
    | local-only (see RanetraceServiceProvider). Define the gate in your
    | AppServiceProvider::boot() to grant access in other environments.
    |
    */
    'dashboard' => [
        'enabled' => env('RANETRACE_DASHBOARD_ENABLED', true),
        'path' => env('RANETRACE_DASHBOARD_PATH', 'ranetrace'),
        'domain' => env('RANETRACE_DASHBOARD_DOMAIN'), // null = current domain
        'middleware' => ['web'], // Authorize is always appended in code
        'refresh' => env('RANETRACE_DASHBOARD_REFRESH', 10), // auto-refresh seconds (0 = off)
        'hosted_url' => env('RANETRACE_DASHBOARD_HOSTED_URL', 'https://ranetrace.com'), // link out to captured data
    ],
];
