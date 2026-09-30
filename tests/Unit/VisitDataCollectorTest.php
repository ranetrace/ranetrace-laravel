<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Ranetrace\Laravel\Analytics\VisitDataCollector;
use Ranetrace\Php\Support\BrowserIdentity;

/**
 * A request with a resolved route bound to it, mirroring what the collector
 * sees from `web` group middleware (the route is matched before it runs).
 */
function requestWithRoute(string $url, string $uri): Request
{
    $request = Request::create($url, 'GET');
    $request->headers->set('User-Agent', 'Mozilla/5.0');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $route = (new Route(['GET'], $uri, static fn (): string => 'ok'))->bind($request);
    $request->setRouteResolver(static fn (): Route => $route);

    return $request;
}

test('it collects basic visit data', function (): void {
    $request = Request::create('https://example.com/test-page?utm_source=google', 'GET');
    $request->headers->set('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/91.0.4472.124');
    $request->headers->set('Referer', 'https://google.com');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $data = VisitDataCollector::collect($request);

    expect($data)->toHaveKeys([
        'url',
        'path',
        'user_agent',
        'user_agent_hash',
        'referrer',
        'device_type',
        'browser_name',
        'session_id_hash',
        'timestamp',
    ]);

    expect($data['url'])->toBe('https://example.com/test-page?utm_source=google');
    expect($data['path'])->toBe('/test-page');
    expect($data['referrer'])->toBe('https://google.com');
    expect($data['utm_source'])->toBe('google');
});

test('it detects mobile devices correctly', function (): void {
    $userAgents = [
        'Mozilla/5.0 (iPhone; CPU iPhone OS 14_6 like Mac OS X) AppleWebKit/605.1.15',
        'Mozilla/5.0 (Linux; Android 11; SM-G991B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/90.0.4430.210 Mobile Safari/537.36',
        'Mozilla/5.0 (Linux; Android 10; Mobile) AppleWebKit/537.36',
    ];

    foreach ($userAgents as $ua) {
        $request = Request::create('/', 'GET');
        $request->headers->set('User-Agent', $ua);
        $request->server->set('REMOTE_ADDR', '127.0.0.1');

        $data = VisitDataCollector::collect($request);

        expect($data['device_type'])->toBe('mobile');
    }
});

test('it detects tablets correctly', function (): void {
    $userAgents = [
        'Mozilla/5.0 (iPad; CPU OS 14_6 like Mac OS X) AppleWebKit/605.1.15',
        'Mozilla/5.0 (Linux; Android 11; SM-T870) AppleWebKit/537.36', // Android tablet
    ];

    foreach ($userAgents as $ua) {
        $request = Request::create('/', 'GET');
        $request->headers->set('User-Agent', $ua);
        $request->server->set('REMOTE_ADDR', '127.0.0.1');

        $data = VisitDataCollector::collect($request);

        expect($data['device_type'])->toBe('tablet');
    }
});

test('it detects desktop devices correctly', function (): void {
    $userAgents = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/91.0.4472.124',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
    ];

    foreach ($userAgents as $ua) {
        $request = Request::create('/', 'GET');
        $request->headers->set('User-Agent', $ua);
        $request->server->set('REMOTE_ADDR', '127.0.0.1');

        $data = VisitDataCollector::collect($request);

        expect($data['device_type'])->toBe('desktop');
    }
});

/**
 * The browser name the collector reports for a request carrying this User-Agent
 * header, or none at all when null.
 */
function browserNameFor(?string $userAgent): ?string
{
    $request = Request::create('/', 'GET');
    $request->headers->remove('User-Agent');

    if ($userAgent !== null) {
        $request->headers->set('User-Agent', $userAgent);
    }

    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    return VisitDataCollector::collect($request)['browser_name'];
}

test('it reports the browser name the user agent names', function (string $userAgent, string $expectedBrowser): void {
    expect(browserNameFor($userAgent))->toBe($expectedBrowser);
})->with([
    'desktop Chrome' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36', 'Chrome'],
    'Chrome on iOS' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/128.0.6613.98 Mobile/15E148 Safari/604.1', 'Chrome'],
    'desktop Firefox' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) Gecko/20100101 Firefox/130.0', 'Firefox'],
    'Firefox on iOS' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) FxiOS/130.0 Mobile/15E148 Safari/605.1.15', 'Firefox'],
    'desktop Safari' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15', 'Safari'],
    'desktop Edge' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36 Edg/128.0.2739.54', 'Edge'],
    'Edge on Android' => ['Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Mobile Safari/537.36 EdgA/128.0.2739.60', 'Edge'],
    'Edge on iOS' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 EdgiOS/128.2739.60 Mobile/15E148 Safari/605.1.15', 'Edge'],
    'Opera' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36 OPR/113.0.0.0', 'Opera'],
    'Samsung Internet' => ['Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/25.0 Chrome/121.0.0.0 Mobile Safari/537.36', 'Samsung Internet'],
]);

test('it reports Other for a user agent that names no browser', function (string $userAgent): void {
    expect(browserNameFor($userAgent))->toBe('Other');
})->with([
    'Internet Explorer 11' => ['Mozilla/5.0 (Windows NT 10.0; WOW64; Trident/7.0; rv:11.0) like Gecko'],
    'unknown client' => ['Test Browser'],
    // Bots never reach the collector through the middleware; called directly,
    // a present user agent is still a visitor the app counts, not an unknown.
    'bot claiming Chrome' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html) Chrome/128.0.0.0 Safari/537.36'],
    'headless Chrome' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/128.0.0.0 Safari/537.36'],
    'whitespace only' => [' '],
]);

test('it reports no browser when there is no user agent', function (?string $userAgent): void {
    expect(browserNameFor($userAgent))->toBeNull();
})->with([
    'header absent' => [null],
    'header empty' => [''],
]);

test('it reads a browser token only within the first 1024 characters of the user agent', function (): void {
    $padding = str_repeat('x', BrowserIdentity::MAX_USER_AGENT_LENGTH);

    expect(browserNameFor('Mozilla/5.0 Chrome/128.0.0.0 '.$padding))->toBe('Chrome')
        ->and(browserNameFor('Mozilla/5.0 '.$padding.' Chrome/128.0.0.0'))->toBe('Other');
});

test('it collects utm parameters', function (): void {
    $request = Request::create('/', 'GET', [
        'utm_source' => 'google',
        'utm_medium' => 'cpc',
        'utm_campaign' => 'summer_sale',
        'utm_content' => 'banner',
        'utm_term' => 'laravel',
    ]);
    $request->headers->set('User-Agent', 'Test Browser');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $data = VisitDataCollector::collect($request);

    expect($data['utm_source'])->toBe('google');
    expect($data['utm_medium'])->toBe('cpc');
    expect($data['utm_campaign'])->toBe('summer_sale');
    expect($data['utm_content'])->toBe('banner');
    expect($data['utm_term'])->toBe('laravel');
});

test('it reports the path decoded so one page is one entry', function (): void {
    // The router rawurldecodes before matching, so these are all the same page;
    // reporting the raw request line would fragment it in analytics.
    $request = Request::create('https://example.com/%61dmin/us%65rs', 'GET');
    $request->headers->set('User-Agent', 'Mozilla/5.0');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $data = VisitDataCollector::collect($request);

    expect($data['path'])->toBe('/admin/users');
});

test('it still redacts a sensitive segment that itself contains a percent sign', function (): void {
    // Scrubbing runs before decoding for exactly this case: decoding first
    // would turn the segment into `tok%41` and stop it matching the route's
    // parameter value.
    $request = requestWithRoute('https://example.com/invitations/tok%2541', 'invitations/{token}');

    $data = VisitDataCollector::collect($request);

    expect($data['path'])->toBe('/invitations/[REDACTED]');
});

test('it drops a non-string campaign parameter', function (): void {
    // `?utm_source[]=x` yields an array, which violates the API's string|null
    // schema and gets the whole batch rejected.
    $request = Request::create('/', 'GET', ['utm_source' => ['x']]);
    $request->headers->set('User-Agent', 'Test Browser');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $data = VisitDataCollector::collect($request);

    expect($data['utm_source'])->toBeNull();
});

test('it caps an oversized campaign parameter', function (): void {
    $request = Request::create('/', 'GET', ['utm_campaign' => str_repeat('a', 500)]);
    $request->headers->set('User-Agent', 'Test Browser');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $data = VisitDataCollector::collect($request);

    expect($data['utm_campaign'])->toHaveLength(255);
});

test('it includes timestamp in ISO format', function (): void {
    $request = Request::create('/', 'GET');
    $request->headers->set('User-Agent', 'Test Browser');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $data = VisitDataCollector::collect($request);

    expect($data['timestamp'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/');
});

test('it scrubs sensitive query params from url and referrer', function (): void {
    $request = Request::create('https://example.com/reset?token=abc&utm_source=google', 'GET');
    $request->headers->set('User-Agent', 'Mozilla/5.0');
    $request->headers->set('Referer', 'https://example.com/login?api_key=zzz');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $data = VisitDataCollector::collect($request);

    expect($data['url'])->toContain('token=[REDACTED]')
        ->and($data['url'])->toContain('utm_source=google')
        ->and($data['url'])->not->toContain('token=abc')
        ->and($data['referrer'])->toBe('https://example.com/login?api_key=[REDACTED]')
        ->and($data['utm_source'])->toBe('google');
});

test('it scrubs sensitive route parameter values from the path and url', function (): void {
    $request = requestWithRoute(
        'https://example.com/invitations/secret-token-123?page=2',
        'invitations/{token}'
    );

    $data = VisitDataCollector::collect($request);

    expect($data['path'])->toBe('/invitations/[REDACTED]')
        ->and($data['url'])->toBe('https://example.com/invitations/[REDACTED]?page=2');
});

test('it scrubs a verification hash segment but keeps non-sensitive segments', function (): void {
    $request = requestWithRoute(
        'https://example.com/verify/42/deadbeefhash',
        'verify/{id}/{hash}'
    );

    $data = VisitDataCollector::collect($request);

    expect($data['path'])->toBe('/verify/42/[REDACTED]')
        ->and($data['url'])->toBe('https://example.com/verify/42/[REDACTED]');
});

test('it leaves non-sensitive route parameters untouched', function (): void {
    $request = requestWithRoute('https://example.com/articles/my-post', 'articles/{slug}');

    $data = VisitDataCollector::collect($request);

    expect($data['path'])->toBe('/articles/my-post')
        ->and($data['url'])->toBe('https://example.com/articles/my-post');
});

test('it scrubs both the path and the query when a token appears in each', function (): void {
    $request = requestWithRoute(
        'https://example.com/invitations/tok-abc?token=tok-abc&utm_source=mail',
        'invitations/{token}'
    );

    $data = VisitDataCollector::collect($request);

    expect($data['path'])->toBe('/invitations/[REDACTED]')
        ->and($data['url'])->toBe('https://example.com/invitations/[REDACTED]?token=[REDACTED]&utm_source=mail');
});

test('it behaves exactly as before when no route is resolved', function (): void {
    $request = Request::create('https://example.com/invitations/secret-token-123', 'GET');
    $request->headers->set('User-Agent', 'Mozilla/5.0');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $data = VisitDataCollector::collect($request);

    expect($data['path'])->toBe('/invitations/secret-token-123')
        ->and($data['url'])->toBe('https://example.com/invitations/secret-token-123');
});

test('it handles a parameterless route on the root path', function (): void {
    $request = requestWithRoute('https://example.com/', '/');

    $data = VisitDataCollector::collect($request);

    expect($data['path'])->toBe('/')
        ->and($data['url'])->toBe('https://example.com');
});

test('it hashes user agent', function (): void {
    $request = Request::create('/', 'GET');
    $request->headers->set('User-Agent', 'Test Browser');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $data = VisitDataCollector::collect($request);

    expect($data['user_agent_hash'])->not->toBeNull();
    expect($data['user_agent_hash'])->toHaveLength(64); // SHA256
    expect($data['user_agent_hash'])->not->toBe('Test Browser'); // Should be hashed
});
