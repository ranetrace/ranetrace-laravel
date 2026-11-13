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
        'path',
        'user_agent',
        'user_agent_hash',
        'referrer',
        'device_type',
        'browser_name',
        'session_id_hash',
        'timestamp',
    ]);

    expect($data['path'])->toBe('/test-page');
    expect($data['referrer'])->toBe('https://google.com');
    expect($data['utm_source'])->toBe('google');
});

test('it reports the page by its path and sends no url', function (): void {
    $request = Request::create('https://example.com/pricing?plan=team', 'GET');
    $request->headers->set('User-Agent', 'Mozilla/5.0');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $data = VisitDataCollector::collect($request);

    expect($data)->not->toHaveKey('url')
        ->and($data['path'])->toBe('/pricing')
        ->and(json_encode($data))->not->toContain('example.com')
        ->and(json_encode($data))->not->toContain('plan=team');
});

/**
 * The device type the collector reports for a request carrying this User-Agent header.
 */
function deviceTypeFor(string $userAgent): ?string
{
    $request = Request::create('/', 'GET');
    $request->headers->set('User-Agent', $userAgent);
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    return VisitDataCollector::collect($request)['device_type'];
}

test('it reports the device type the user agent names', function (string $userAgent, string $expectedDeviceType): void {
    expect(deviceTypeFor($userAgent))->toBe($expectedDeviceType);
})->with([
    'iPhone Safari' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.6 Mobile/15E148 Safari/604.1', 'mobile'],
    'Chrome on an Android phone' => ['Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36', 'mobile'],
    'iPad on iPadOS 12' => ['Mozilla/5.0 (iPad; CPU OS 12_5_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/12.1.2 Mobile/15E148 Safari/604.1', 'tablet'],
    'Chrome on an Android tablet' => ['Mozilla/5.0 (Linux; Android 14; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', 'tablet'],
    'Windows Chrome' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', 'desktop'],
    'Linux Firefox' => ['Mozilla/5.0 (X11; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0', 'desktop'],
    'PlayStation 5' => ['Mozilla/5.0 (PlayStation; PlayStation 5/2.26) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/13.0 Safari/605.1.15', 'console'],
]);

test('an iPad on iPadOS 13 or later is reported as a desktop because it sends a Mac user agent', function (): void {
    expect(deviceTypeFor('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Safari/605.1.15'))
        ->toBe('desktop');
});

test('a user agent of "0" is a desktop with no browser the parser knows', function (): void {
    $request = Request::create('/', 'GET');
    $request->headers->set('User-Agent', '0');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $data = VisitDataCollector::collect($request);

    expect($data['device_type'])->toBe('desktop')
        ->and($data['browser_name'])->toBe('Other');
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
    // schema and gets the visit refused.
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

test('it cuts a path over the 1,024 characters the endpoint takes, and sends one at the limit whole', function (): void {
    // The API refuses a visit whose path is longer, and a refused visit is
    // lost. Characters, not bytes, as the endpoint counts them.
    $atLimit = '/'.str_repeat('é', 1_023);

    $whole = VisitDataCollector::collect(Request::create('https://example.com'.$atLimit, 'GET'));
    $cut = VisitDataCollector::collect(Request::create('https://example.com'.$atLimit.'x/more', 'GET'));

    expect($whole['path'])->toBe($atLimit)
        ->and($cut['path'])->toBe($atLimit);
});

test('it cuts a referrer over the 2,048 characters the endpoint takes, and sends one at the limit whole', function (): void {
    $atLimit = 'https://search.test/?q='.str_repeat('é', 2_048 - 23);

    $request = Request::create('https://example.com/page', 'GET');
    $request->headers->set('Referer', $atLimit);
    $whole = VisitDataCollector::collect($request);

    $request->headers->set('Referer', $atLimit.'x&more=1');
    $cut = VisitDataCollector::collect($request);

    expect($whole['referrer'])->toBe($atLimit)
        ->and($cut['referrer'])->toBe($atLimit);
});

test('it cuts a referrer that only passes its limit once its secrets are redacted', function (): void {
    // `token=x` becomes `token=[REDACTED]`, so the scrub lengthens the value.
    $referrer = 'https://app.test/callback?'.implode('&', array_fill(0, 250, 'token=x'));

    $request = Request::create('https://example.com/page', 'GET');
    $request->headers->set('Referer', $referrer);
    $data = VisitDataCollector::collect($request);

    expect(mb_strlen($referrer))->toBeLessThan(2_048)
        ->and(mb_strlen($data['referrer']))->toBe(2_048)
        ->and($data['referrer'])->toStartWith('https://app.test/callback?token=[REDACTED]&token=[REDACTED]')
        ->and($data['referrer'])->not->toContain('token=x');
});

test('it includes timestamp in ISO format', function (): void {
    $request = Request::create('/', 'GET');
    $request->headers->set('User-Agent', 'Test Browser');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $data = VisitDataCollector::collect($request);

    expect($data['timestamp'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/');
});

test('it scrubs sensitive query params from the referrer, and no query secret reaches the visit', function (): void {
    $request = Request::create('https://example.com/reset?token=live-query-secret&utm_source=google', 'GET');
    $request->headers->set('User-Agent', 'Mozilla/5.0');
    $request->headers->set('Referer', 'https://example.com/login?api_key=zzz');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $data = VisitDataCollector::collect($request);

    expect(json_encode($data))->not->toContain('live-query-secret')
        ->and($data['referrer'])->toBe('https://example.com/login?api_key=[REDACTED]')
        ->and($data['utm_source'])->toBe('google');
});

test('it scrubs sensitive route parameter values from the path', function (): void {
    $request = requestWithRoute(
        'https://example.com/invitations/secret-token-123?page=2',
        'invitations/{token}'
    );

    $data = VisitDataCollector::collect($request);

    expect($data['path'])->toBe('/invitations/[REDACTED]')
        ->and(json_encode($data))->not->toContain('secret-token-123');
});

test('it scrubs a verification hash segment but keeps non-sensitive segments', function (): void {
    $request = requestWithRoute(
        'https://example.com/verify/42/deadbeefhash',
        'verify/{id}/{hash}'
    );

    $data = VisitDataCollector::collect($request);

    expect($data['path'])->toBe('/verify/42/[REDACTED]');
});

test('it leaves non-sensitive route parameters untouched', function (): void {
    $request = requestWithRoute('https://example.com/articles/my-post', 'articles/{slug}');

    $data = VisitDataCollector::collect($request);

    expect($data['path'])->toBe('/articles/my-post');
});

test('a token in both the path and the query reaches no field of the visit', function (): void {
    $request = requestWithRoute(
        'https://example.com/invitations/tok-abc?token=tok-abc&utm_source=mail',
        'invitations/{token}'
    );

    $data = VisitDataCollector::collect($request);

    expect($data['path'])->toBe('/invitations/[REDACTED]')
        ->and(json_encode($data))->not->toContain('tok-abc');
});

test('a JWT under an innocently named query parameter reaches no field of the visit', function (): void {
    $jwt = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U';
    $request = requestWithRoute("https://example.com/magic?t={$jwt}&page=2", 'magic');

    $data = VisitDataCollector::collect($request);

    expect($data['path'])->toBe('/magic')
        ->and(json_encode($data))->not->toContain($jwt);
});

test('it redacts a credential-prefixed token in a path segment the route does not name sensitive', function (): void {
    $request = requestWithRoute('https://example.com/keys/sk_live_51HxYzAbCdEfGhIjKlMn/edit', 'keys/{slug}/edit');

    $data = VisitDataCollector::collect($request);

    expect($data['path'])->toBe('/keys/[REDACTED]/edit');
});

test('it redacts a percent-encoded credential-prefixed token in the decoded path', function (): void {
    $request = requestWithRoute('https://example.com/keys/sk%5Flive%5F51HxYzAbCdEfGhIjKlMn', 'keys/{slug}');

    $data = VisitDataCollector::collect($request);

    expect($data['path'])->toBe('/keys/[REDACTED]');
});

test('it redacts the password of a url in the referrer, keeping scheme, user and host', function (): void {
    $request = Request::create('https://example.com/articles', 'GET');
    $request->headers->set('User-Agent', 'Mozilla/5.0');
    $request->headers->set('Referer', 'https://deploy:hunter2-pass@ci.example.org/builds/42?page=2');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $data = VisitDataCollector::collect($request);

    expect($data['referrer'])->toBe('https://deploy:[REDACTED]@ci.example.org/builds/42?page=2');
});

test('it keeps a path and referrer that only resemble a token byte for byte', function (): void {
    $request = requestWithRoute('https://example.com/docs/sk_live_/eyJ-intro?ref=AKIA-guide', 'docs/{a}/{b}');
    $request->headers->set('Referer', 'https://example.com/docs/ghp_short?user=app@example.com');

    $data = VisitDataCollector::collect($request);

    expect($data['path'])->toBe('/docs/sk_live_/eyJ-intro')
        ->and($data['referrer'])->toBe('https://example.com/docs/ghp_short?user=app@example.com');
});

test('a route token in the path and a credential in the query reach no field of the visit', function (): void {
    $request = requestWithRoute(
        'https://example.com/invitations/live-invite-token?ref=sk_live_51HxYzAbCdEfGhIjKlMn',
        'invitations/{token}'
    );

    $data = VisitDataCollector::collect($request);

    expect($data['path'])->toBe('/invitations/[REDACTED]')
        ->and(json_encode($data))->not->toContain('live-invite-token')
        ->and(json_encode($data))->not->toContain('sk_live_51HxYzAbCdEfGhIjKlMn');
});

test('it redacts no path segment for its route when no route is resolved', function (): void {
    $request = Request::create('https://example.com/invitations/secret-token-123', 'GET');
    $request->headers->set('User-Agent', 'Mozilla/5.0');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $data = VisitDataCollector::collect($request);

    expect($data['path'])->toBe('/invitations/secret-token-123');
});

test('it handles a parameterless route on the root path', function (): void {
    $request = requestWithRoute('https://example.com/', '/');

    $data = VisitDataCollector::collect($request);

    expect($data['path'])->toBe('/');
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

test('it replaces invalid UTF-8 from the request with U+FFFD, so the visit encodes as JSON', function (): void {
    // `/caf%E9` is a latin1-encoded path: decoding it for the report yields a
    // byte that is not UTF-8, and one such byte fails the JSON encode of the
    // whole batch the visit is sent in.
    $request = Request::create('https://example.com/caf%E9?utm_source=%B1', 'GET');
    $request->headers->set('User-Agent', 'Mozilla/5.0');
    $request->headers->set('Referer', "https://example.com/caf\xE9");
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $data = VisitDataCollector::collect($request);

    expect($data['path'])->toBe("/caf\u{FFFD}")
        ->and($data['utm_source'])->toBe("\u{FFFD}")
        ->and($data['referrer'])->toBe("https://example.com/caf\u{FFFD}")
        ->and(json_encode($data))->toBeString();
});

test('it caps a campaign parameter of invalid bytes inside the cap', function (): void {
    $request = Request::create('/', 'GET', ['utm_campaign' => str_repeat("\xB1", 300)]);
    $request->headers->set('User-Agent', 'Test Browser');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $campaign = VisitDataCollector::collect($request)['utm_campaign'];

    expect(mb_strlen($campaign))->toBe(255)
        ->and($campaign)->toBe(str_repeat("\u{FFFD}", 255));
});
