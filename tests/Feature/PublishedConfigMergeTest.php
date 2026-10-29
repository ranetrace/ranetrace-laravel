<?php

declare(strict_types=1);

use Illuminate\Support\Arr;

afterEach(function (): void {
    unset($_SERVER['RANETRACE_WEBSITE_ANALYTICS_BEACON_ENABLED']);
});

/**
 * Every list in the package's config, by dot path, found by walking the file
 * so a list added later is covered the day it lands.
 *
 * @return array<string, array{string}>
 */
function packageConfigListKeys(): array
{
    $walk = static function (array $values, string $prefix) use (&$walk): array {
        $found = [];

        foreach ($values as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            $path = $prefix.$key;

            $found += array_is_list($value) ? [$path => [$path]] : $walk($value, $path.'.');
        }

        return $found;
    };

    return $walk(require dirname(__DIR__, 2).'/config/ranetrace.php', '');
}

test('a published config that predates the beacon still reads its env var', function (): void {
    $_SERVER['RANETRACE_WEBSITE_ANALYTICS_BEACON_ENABLED'] = 'true';

    registerWithPublishedConfig([
        'website_analytics' => [
            'enabled' => true,
            'excluded_paths' => ['horizon'],
        ],
    ]);

    expect(config('ranetrace.website_analytics.beacon.enabled'))->toBeTrue()
        ->and(config('ranetrace.website_analytics.min_human_score'))->toBe(70)
        ->and(config('ranetrace.website_analytics.bot_detection.require_client_hints'))->toBeTrue();
});

test('a published value beats the env var the package would read', function (): void {
    $_SERVER['RANETRACE_WEBSITE_ANALYTICS_BEACON_ENABLED'] = 'true';

    registerWithPublishedConfig([
        'website_analytics' => ['beacon' => ['enabled' => false]],
    ]);

    expect(config('ranetrace.website_analytics.beacon.enabled'))->toBeFalse()
        ->and(config('ranetrace.website_analytics.beacon.wait_seconds'))->toBe(15);
});

test('a published list replaces the package list whole', function (string $key): void {
    registerWithPublishedConfig(Arr::undot([$key => ['only-this']]));

    expect(config("ranetrace.{$key}"))->toBe(['only-this']);
})->with(packageConfigListKeys());

test('an empty published list removes every package default', function (): void {
    registerWithPublishedConfig([
        'website_analytics' => ['excluded_paths' => []],
        'javascript_errors' => ['ignored_errors' => []],
    ]);

    expect(config('ranetrace.website_analytics.excluded_paths'))->toBe([])
        ->and(config('ranetrace.javascript_errors.ignored_errors'))->toBe([]);
});

test('a nested published value wins while its siblings keep their defaults', function (): void {
    registerWithPublishedConfig([
        'website_analytics' => [
            'bot_detection' => ['require_client_hints' => false],
            'user_agent' => ['min_length' => 3],
            'queue_name' => null,
        ],
    ]);

    expect(config('ranetrace.website_analytics.bot_detection'))->toBe([
        'require_client_hints' => false,
        'modern_browser_min_version' => 100,
    ])
        ->and(config('ranetrace.website_analytics.user_agent'))->toBe(['min_length' => 3, 'max_length' => 1000])
        ->and(config()->has('ranetrace.website_analytics.queue_name'))->toBeTrue()
        ->and(config('ranetrace.website_analytics.queue_name'))->toBeNull()
        ->and(config('ranetrace.errors.enabled'))->toBeTrue();
});

test('a published request filter callable is kept as written', function (): void {
    registerWithPublishedConfig([
        'website_analytics' => ['request_filter' => ['App\\Analytics\\Filter', 'handle']],
    ]);

    expect(config('ranetrace.website_analytics.request_filter'))->toBe(['App\\Analytics\\Filter', 'handle']);
});

test('keys the package does not know survive the merge', function (): void {
    registerWithPublishedConfig([
        'legacy_flag' => true,
        'website_analytics' => ['host_note' => 'kept'],
    ]);

    expect(config('ranetrace.legacy_flag'))->toBeTrue()
        ->and(config('ranetrace.website_analytics.host_note'))->toBe('kept');
});

test('a cached config is left exactly as it was cached', function (): void {
    $_SERVER['RANETRACE_WEBSITE_ANALYTICS_BEACON_ENABLED'] = 'true';
    app()->instance('config_loaded_from_cache', true);

    $cached = ['website_analytics' => ['enabled' => true, 'excluded_paths' => ['horizon']]];

    registerWithPublishedConfig($cached);

    expect(config('ranetrace'))->toBe($cached);
});
