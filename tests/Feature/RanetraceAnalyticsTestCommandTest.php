<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Jaybizzle\CrawlerDetect\CrawlerDetect;
use Ranetrace\Laravel\Analytics\BotSignals;
use Ranetrace\Laravel\Analytics\VisitDataCollector;
use Ranetrace\Laravel\Jobs\HandlePageVisitJob;

/**
 * @return array<string, mixed>
 */
function dispatchedTestVisit(): array
{
    $visitData = null;

    Bus::assertDispatched(HandlePageVisitJob::class, function (HandlePageVisitJob $job) use (&$visitData): bool {
        $visitData = $job->getVisitData();

        return true;
    });

    return $visitData;
}

beforeEach(function (): void {
    Bus::fake();
    Config::set('ranetrace.website_analytics.enabled', true);
    Config::set('ranetrace.key', 'test-key');
});

test('ranetrace:test-analytics dispatches a visit with the keys the middleware builds, in the same order', function (): void {
    $this->artisan('ranetrace:test-analytics')->assertSuccessful();

    $collectorKeys = array_keys(VisitDataCollector::collect(Request::create('/')));

    expect(array_keys(dispatchedTestVisit()))
        ->toBe([...$collectorKeys, 'human_probability_score', 'human_probability_reasons']);
});

test('ranetrace:test-analytics names the browser and device a real desktop Chrome visit names', function (): void {
    Config::set('app.url', 'https://example.com');

    $this->artisan('ranetrace:test-analytics')->assertSuccessful();

    $visitData = dispatchedTestVisit();

    expect($visitData['browser_name'])->toBe('Chrome')
        ->and($visitData['device_type'])->toBe('desktop')
        ->and($visitData['path'])->toBe('/ranetrace-test-analytics')
        ->and($visitData['url'])->toBe('https://example.com/ranetrace-test-analytics');
});

test('ranetrace:test-analytics dispatches a visit whose human score clears min_human_score', function (): void {
    $this->artisan('ranetrace:test-analytics')->assertSuccessful();

    $visitData = dispatchedTestVisit();

    expect($visitData['human_probability_score'])
        ->toBeGreaterThanOrEqual((int) config('ranetrace.website_analytics.min_human_score'))
        ->and($visitData['human_probability_reasons'])->toContain(
            'Modern browser security headers present',
            'User-Agent Client Hints (Sec-CH-UA) present',
        );
});

test('ranetrace:test-analytics still clears min_human_score once repeated runs trip the request frequency penalty', function (): void {
    foreach (range(1, 12) as $run) {
        $this->artisan('ranetrace:test-analytics')->assertSuccessful();
    }

    $scores = [];

    Bus::assertDispatched(HandlePageVisitJob::class, function (HandlePageVisitJob $job) use (&$scores): bool {
        $scores[] = $job->getVisitData();

        return true;
    });

    $lastVisit = end($scores);

    expect($scores)->toHaveCount(12)
        ->and($lastVisit['human_probability_reasons'])->toContain('High request frequency detected')
        ->and($lastVisit['human_probability_score'])
        ->toBeGreaterThanOrEqual((int) config('ranetrace.website_analytics.min_human_score'));
});

test('ranetrace:test-analytics sends a user agent the middleware would not filter as a bot', function (): void {
    $this->artisan('ranetrace:test-analytics')->assertSuccessful();

    $userAgent = dispatchedTestVisit()['user_agent'];
    $matchedPatterns = array_filter(
        BotSignals::SUSPICIOUS_USER_AGENT_PATTERNS,
        static fn (string $pattern): bool => mb_stripos($userAgent, $pattern) !== false,
    );

    expect((new CrawlerDetect)->isCrawler($userAgent))->toBeFalse()
        ->and($matchedPatterns)->toBe([]);
});

test('ranetrace:test-analytics dispatches no beacon handles even when the beacon is enabled', function (): void {
    Config::set('ranetrace.website_analytics.beacon.enabled', true);

    $this->artisan('ranetrace:test-analytics')->assertSuccessful();

    expect(dispatchedTestVisit())->not->toHaveKeys(['view_token', 'held_until', 'verified_human']);
});

test('ranetrace:test-analytics dispatches a visit on every run, since the middleware throttle does not apply', function (): void {
    $this->artisan('ranetrace:test-analytics')->assertSuccessful();
    $this->artisan('ranetrace:test-analytics')->assertSuccessful();

    Bus::assertDispatchedTimes(HandlePageVisitJob::class, 2);
});

test('ranetrace:test-analytics keeps the test path when app.url names no host', function (string $appUrl): void {
    Config::set('app.url', $appUrl);

    $this->artisan('ranetrace:test-analytics')->assertSuccessful();

    $visitData = dispatchedTestVisit();

    expect($visitData['path'])->toBe('/ranetrace-test-analytics')
        ->and($visitData['url'])->toBe('http://localhost/ranetrace-test-analytics');
})->with([
    'empty' => '',
    'without a scheme' => 'example.com',
]);

test('ranetrace:test-analytics keeps the test path when app.url carries a port, a trailing slash or a sub-path', function (string $appUrl, string $expectedUrl): void {
    Config::set('app.url', $appUrl);

    $this->artisan('ranetrace:test-analytics')->assertSuccessful();

    $visitData = dispatchedTestVisit();

    expect($visitData['path'])->toBe('/ranetrace-test-analytics')
        ->and($visitData['url'])->toBe($expectedUrl);
})->with([
    'port' => ['http://example.test:8080', 'http://example.test:8080/ranetrace-test-analytics'],
    'trailing slash' => ['https://example.com/', 'https://example.com/ranetrace-test-analytics'],
    'sub-path' => ['https://example.com/app', 'https://example.com/ranetrace-test-analytics'],
]);

test('ranetrace:test-analytics is a no-op when analytics is disabled', function (): void {
    Config::set('ranetrace.website_analytics.enabled', false);

    $this->artisan('ranetrace:test-analytics')->assertSuccessful();

    Bus::assertNotDispatched(HandlePageVisitJob::class);
});

test('ranetrace:test-analytics dispatches nothing and fails when the key is missing', function (): void {
    Config::set('ranetrace.key', null);

    $this->artisan('ranetrace:test-analytics')->assertFailed();

    Bus::assertNotDispatched(HandlePageVisitJob::class);
});
