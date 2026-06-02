<?php

declare(strict_types=1);

use Ranetrace\Laravel\Utilities\SecretScrubber;

test('it redacts values under sensitive keys', function (): void {
    $result = SecretScrubber::scrub([
        'password' => 'hunter2',
        'api_key' => 'sk_live_123',
        'token' => 'abc',
        'authorization' => 'Bearer xyz',
        'username' => 'alice',
    ]);

    expect($result['password'])->toBe('[REDACTED]')
        ->and($result['api_key'])->toBe('[REDACTED]')
        ->and($result['token'])->toBe('[REDACTED]')
        ->and($result['authorization'])->toBe('[REDACTED]')
        ->and($result['username'])->toBe('alice');
});

test('it matches sensitive keys case-insensitively and as substrings', function (): void {
    $result = SecretScrubber::scrub([
        'API_KEY' => 'x',
        'Stripe_Secret' => 'y',
        'csrf_token' => 'z',
        'safe' => 'keep',
    ]);

    expect($result['API_KEY'])->toBe('[REDACTED]')
        ->and($result['Stripe_Secret'])->toBe('[REDACTED]')
        ->and($result['csrf_token'])->toBe('[REDACTED]')
        ->and($result['safe'])->toBe('keep');
});

test('it redacts nested sensitive keys and the whole sensitive subtree', function (): void {
    $result = SecretScrubber::scrub([
        'user' => [
            'name' => 'bob',
            'credentials' => ['password' => 'p', 'pin' => '1234'],
        ],
        'authorization' => ['scheme' => 'Bearer', 'value' => 'tok'],
    ]);

    expect($result['user']['name'])->toBe('bob')
        ->and($result['user']['credentials'])->toBe('[REDACTED]')
        ->and($result['authorization'])->toBe('[REDACTED]');
});

test('it does not over-match unrelated keys', function (): void {
    $result = SecretScrubber::scrub([
        'author' => 'jane',
        'description' => 'a token of appreciation',
        'count' => 3,
    ]);

    // 'author' must not match 'authorization'; values (not keys) are never inspected.
    expect($result['author'])->toBe('jane')
        ->and($result['description'])->toBe('a token of appreciation')
        ->and($result['count'])->toBe(3);
});

test('it returns non-array input untouched', function (): void {
    expect(SecretScrubber::scrub('plain'))->toBe('plain')
        ->and(SecretScrubber::scrub(123))->toBe(123)
        ->and(SecretScrubber::scrub(null))->toBeNull();
});

test('it honors user-configured extra keys', function (): void {
    config(['ranetrace.scrubbing.extra_keys' => ['x_signature']]);

    $result = SecretScrubber::scrub([
        'x_signature' => 'deadbeef',
        'keep' => 'ok',
    ]);

    expect($result['x_signature'])->toBe('[REDACTED]')
        ->and($result['keep'])->toBe('ok');
});

test('it preserves list/numeric-keyed arrays while scrubbing nested secrets', function (): void {
    $result = SecretScrubber::scrub([
        'headers' => [
            ['name' => 'Accept', 'value' => 'application/json'],
            ['name' => 'X-Api-Key', 'api_key' => 'secret-value'],
        ],
    ]);

    expect($result['headers'][0]['value'])->toBe('application/json')
        ->and($result['headers'][1]['api_key'])->toBe('[REDACTED]')
        ->and($result['headers'][1]['name'])->toBe('X-Api-Key');
});

test('scrubUrl redacts sensitive query params and preserves the rest', function (): void {
    expect(SecretScrubber::scrubUrl('https://example.com/reset?token=abc123&utm_source=google&page=2'))
        ->toBe('https://example.com/reset?token=[REDACTED]&utm_source=google&page=2');
});

test('scrubUrl redacts laravel signed-url signatures', function (): void {
    expect(SecretScrubber::scrubUrl('https://example.com/invite?expires=1700000000&signature=deadbeef'))
        ->toBe('https://example.com/invite?expires=1700000000&signature=[REDACTED]');
});

test('scrubUrl preserves the fragment', function (): void {
    expect(SecretScrubber::scrubUrl('https://example.com/p?api_key=secret#section'))
        ->toBe('https://example.com/p?api_key=[REDACTED]#section');
});

test('scrubUrl leaves urls without sensitive params untouched', function (): void {
    expect(SecretScrubber::scrubUrl('https://example.com/list?page=2&sort=name'))
        ->toBe('https://example.com/list?page=2&sort=name')
        ->and(SecretScrubber::scrubUrl('https://example.com/plain'))
        ->toBe('https://example.com/plain')
        ->and(SecretScrubber::scrubUrl(null))->toBeNull();
});

test('scrubString redacts key=value secrets in free-form strings', function (): void {
    expect(SecretScrubber::scrubString('error with password=hunter2 in config'))
        ->toBe('error with password=[REDACTED] in config');
});

test('scrubString redacts json-style and arrow-style secrets', function (): void {
    expect(SecretScrubber::scrubString('"api_key":"sk_live_abc"'))->toBe('"api_key":"[REDACTED]"')
        ->and(SecretScrubber::scrubString("token => 'abc123'"))->toBe("token => '[REDACTED]'");
});

test('scrubString redacts query-string secrets while keeping the rest', function (): void {
    $scrubbed = SecretScrubber::scrubString('GET https://api.test/v1?api_key=secret&page=2');

    expect($scrubbed)->toContain('api_key=[REDACTED]')->and($scrubbed)->toContain('page=2');
});

test('scrubString leaves strings without sensitive keys untouched', function (): void {
    expect(SecretScrubber::scrubString('just a normal message, id=42'))->toBe('just a normal message, id=42')
        ->and(SecretScrubber::scrubString(''))->toBe('');
});
