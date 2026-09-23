<?php

declare(strict_types=1);

use Ranetrace\Laravel\Analytics\HumanProbabilityScorer;

test('it scores typical browser request as human', function (): void {
    $request = Illuminate\Http\Request::create('/', 'GET');
    $request->headers->set('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
    $request->headers->set('Accept', 'text/html,application/xhtml+xml');
    $request->headers->set('Accept-Language', 'en-US,en;q=0.9');
    $request->headers->set('Accept-Encoding', 'gzip, deflate, br');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $result = HumanProbabilityScorer::score($request);

    expect($result)->toHaveKeys(['score', 'classification', 'reasons']);
    expect($result['score'])->toBeGreaterThanOrEqual(50);
    expect($result['classification'])->toBeIn(['likely_human', 'possibly_human']);
});

test('it scores suspicious user agents as bot', function (): void {
    $suspiciousAgents = [
        'curl/7.68.0',
        'python-requests/2.25.1',
        'Postman Runtime/7.26.8',
        'bot/1.0',
        'crawler',
    ];

    foreach ($suspiciousAgents as $agent) {
        $request = Illuminate\Http\Request::create('/', 'GET');
        $request->headers->set('User-Agent', $agent);
        $request->server->set('REMOTE_ADDR', '127.0.0.1');

        $result = HumanProbabilityScorer::score($request);

        expect($result['score'])->toBeLessThan(50);
    }
});

test('it penalizes missing user agent', function (): void {
    $request = Illuminate\Http\Request::create('/', 'GET');
    $request->headers->remove('User-Agent');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $result = HumanProbabilityScorer::score($request);

    expect($result['score'])->toBeLessThan(50);
    expect($result['reasons'])->toContain('Missing user agent');
});

test('it rewards valid referrer', function (): void {
    $request = Illuminate\Http\Request::create('/', 'GET');
    $request->headers->set('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/91.0');
    $request->headers->set('Referer', 'https://google.com');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $result = HumanProbabilityScorer::score($request);

    expect($result['reasons'])->toContain('Request includes a referrer');
});

test('it rewards common browser headers', function (): void {
    $request = Illuminate\Http\Request::create('/', 'GET');
    $request->headers->set('User-Agent', 'Mozilla/5.0');
    $request->headers->set('Accept', 'text/html');
    $request->headers->set('Accept-Language', 'en-US');
    $request->headers->set('Accept-Encoding', 'gzip');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $result = HumanProbabilityScorer::score($request);

    expect($result['reasons'])->toContain('Request contains typical browser headers');
});

test('it rewards cookies', function (): void {
    $request = Illuminate\Http\Request::create('/', 'GET');
    $request->headers->set('User-Agent', 'Mozilla/5.0');
    $request->headers->set('Cookie', 'session=abc123');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $result = HumanProbabilityScorer::score($request);

    expect($result['reasons'])->toContain('Request includes cookies');
});

test('it rewards User-Agent Client Hints', function (): void {
    $withoutHints = Illuminate\Http\Request::create('/', 'GET');
    $withoutHints->headers->set('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0');
    $withoutHints->server->set('REMOTE_ADDR', '127.0.0.1');

    $request = Illuminate\Http\Request::create('/', 'GET');
    $request->headers->set('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0');
    $request->headers->set('Sec-CH-UA', '"Chromium";v="120", "Google Chrome";v="120"');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $result = HumanProbabilityScorer::score($request);

    expect($result['reasons'])->toContain('User-Agent Client Hints (Sec-CH-UA) present');

    // The reward has to move the number too: the middleware gates on the score,
    // not on the reason list.
    expect($result['score'])->toBeGreaterThan(HumanProbabilityScorer::score($withoutHints)['score']);
});

test('score is always between 0 and 100', function (): void {
    $userAgents = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/91.0',
        'curl/7.68.0',
        'bot crawler spider',
        '',
        str_repeat('a', 1000),
    ];

    foreach ($userAgents as $ua) {
        $request = Illuminate\Http\Request::create('/', 'GET');
        if ($ua) {
            $request->headers->set('User-Agent', $ua);
        }
        $request->server->set('REMOTE_ADDR', '127.0.0.1');

        $result = HumanProbabilityScorer::score($request);

        expect($result['score'])->toBeGreaterThanOrEqual(0);
        expect($result['score'])->toBeLessThanOrEqual(100);
    }
});

test('it classifies scores correctly', function (): void {
    // This test indirectly verifies classification by checking score ranges
    $request = Illuminate\Http\Request::create('/', 'GET');
    $request->headers->set('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/91.0');
    $request->headers->set('Accept', 'text/html');
    $request->headers->set('Accept-Language', 'en');
    $request->headers->set('Accept-Encoding', 'gzip');
    $request->headers->set('Cookie', 'test=1');
    $request->headers->set('Referer', 'https://google.com');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $result = HumanProbabilityScorer::score($request);

    // With all positive signals, should be likely_human
    expect($result['classification'])->toBe('likely_human');
});

test('it penalizes very short user agents', function (): void {
    $request = Illuminate\Http\Request::create('/', 'GET');
    $request->headers->set('User-Agent', 'abc'); // Very short
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $result = HumanProbabilityScorer::score($request);

    expect($result['reasons'])->toContain('User agent suspiciously short');
});

test('it penalizes very long user agents', function (): void {
    $request = Illuminate\Http\Request::create('/', 'GET');
    $request->headers->set('User-Agent', str_repeat('a', 600));
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    $result = HumanProbabilityScorer::score($request);

    expect($result['reasons'])->toContain('User agent suspiciously long');
});

test('it never writes to the host session', function (): void {
    $session = new Illuminate\Session\Store('test', new Illuminate\Session\ArraySessionHandler(120));

    $request = Illuminate\Http\Request::create('/', 'GET');
    $request->setLaravelSession($session);
    $request->headers->set('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/91.0');
    $request->server->set('REMOTE_ADDR', '127.0.0.1');

    HumanProbabilityScorer::score($request);

    expect($session->all())->toBe([]);
});

/*
 * The request-frequency penalty is meant for more than 10 requests from one IP
 * within one minute. Every request used to rewrite the counter with a fresh
 * one-minute expiry, so the count only reset after a full quiet minute: an IP
 * that kept coming back at least once a minute took the penalty on every
 * request after its eleventh, which drops real visitors on a shared IP.
 */

/**
 * Score one request from the given IP and say whether it took the penalty.
 */
function tookFrequencyPenalty(string $ip): bool
{
    $request = Illuminate\Http\Request::create('/', 'GET');
    $request->headers->set('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
    $request->server->set('REMOTE_ADDR', $ip);

    return in_array('High request frequency detected', HumanProbabilityScorer::score($request)['reasons'], true);
}

test('an IP that keeps returning within a minute is not penalised once its window has passed', function (): void {
    Illuminate\Support\Facades\Cache::flush();
    $this->travelTo(Illuminate\Support\Carbon::create(2026, 1, 1, 10, 0, 0));

    // Thirty requests ten seconds apart: each within a minute of the previous,
    // never more than six inside any one minute.
    $penalised = [];

    for ($request = 1; $request <= 30; $request++) {
        if (tookFrequencyPenalty('198.51.100.20')) {
            $penalised[] = $request;
        }

        $this->travel(10)->seconds();
    }

    expect($penalised)->toBe([]);

    $this->travelBack();
});

test('more than eleven requests inside one minute are still penalised from the twelfth', function (): void {
    Illuminate\Support\Facades\Cache::flush();
    $this->travelTo(Illuminate\Support\Carbon::create(2026, 1, 1, 10, 0, 0));

    $penalised = [];

    for ($request = 1; $request <= 13; $request++) {
        if (tookFrequencyPenalty('198.51.100.21')) {
            $penalised[] = $request;
        }

        $this->travel(2)->seconds();
    }

    expect($penalised)->toBe([12, 13]);

    // The window is fixed from its first request, so the next one opens fresh.
    $this->travelTo(Illuminate\Support\Carbon::create(2026, 1, 1, 10, 1, 1));

    expect(tookFrequencyPenalty('198.51.100.21'))->toBeFalse();

    $this->travelBack();
});

test('a counter that lost its expiry is given one back rather than counting forever', function (): void {
    Illuminate\Support\Facades\Cache::flush();
    $this->travelTo(Illuminate\Support\Carbon::create(2026, 1, 1, 10, 0, 0));

    // What an increment leaves behind when the window expired between the
    // add() and the increment(): the stores recreate the key with no expiry.
    $store = Illuminate\Support\Facades\Cache::store(config('ranetrace.batch.cache_driver'));
    $store->forget('ranetrace:request_frequency:198.51.100.22');
    $store->increment('ranetrace:request_frequency:198.51.100.22', 0);

    tookFrequencyPenalty('198.51.100.22');

    $this->travel(61)->seconds();

    expect($store->has('ranetrace:request_frequency:198.51.100.22'))->toBeFalse();

    $this->travelBack();
});
