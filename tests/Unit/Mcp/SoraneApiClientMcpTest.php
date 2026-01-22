<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Sorane\Laravel\Services\SoraneApiClient;

test('getLatestErrors sends GET request to errors endpoint', function (): void {
    Http::fake();
    $client = new SoraneApiClient('test-key');

    $client->getLatestErrors();

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.sorane.io/v1/errors'
            && $request->method() === 'GET';
    });
});

test('getLatestErrors passes query parameters', function (): void {
    Http::fake();
    $client = new SoraneApiClient('test-key');

    $client->getLatestErrors([
        'limit' => 20,
        'environment' => 'production',
        'type' => 'exception',
    ]);

    Http::assertSent(function ($request): bool {
        return str_contains($request->url(), 'limit=20')
            && str_contains($request->url(), 'environment=production')
            && str_contains($request->url(), 'type=exception');
    });
});

test('getLatestErrors returns success response format', function (): void {
    Http::fake([
        '*' => Http::response([
            'errors' => [
                ['id' => 'err-1', 'message' => 'Test error'],
            ],
        ], 200),
    ]);

    $client = new SoraneApiClient('test-key');
    $result = $client->getLatestErrors();

    expect($result['success'])->toBeTrue()
        ->and($result['status'])->toBe(200)
        ->and($result['data']['errors'])->toHaveCount(1);
});

test('getError sends GET request with error id', function (): void {
    Http::fake();
    $client = new SoraneApiClient('test-key');

    $client->getError('err-abc-123');

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.sorane.io/v1/errors/err-abc-123'
            && $request->method() === 'GET';
    });
});

test('getError returns success response format', function (): void {
    Http::fake([
        '*' => Http::response([
            'error' => [
                'id' => 'err-123',
                'message' => 'Test error',
                'stack_trace' => 'Error at line 1',
            ],
        ], 200),
    ]);

    $client = new SoraneApiClient('test-key');
    $result = $client->getError('err-123');

    expect($result['success'])->toBeTrue()
        ->and($result['status'])->toBe(200)
        ->and($result['data']['error']['id'])->toBe('err-123');
});

test('getError returns 404 status for not found', function (): void {
    Http::fake([
        '*' => Http::response(['error' => 'Not found'], 404),
    ]);

    $client = new SoraneApiClient('test-key');
    $result = $client->getError('nonexistent');

    expect($result['success'])->toBeFalse()
        ->and($result['status'])->toBe(404);
});

test('getErrorStats sends GET request to stats endpoint', function (): void {
    Http::fake();
    $client = new SoraneApiClient('test-key');

    $client->getErrorStats();

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.sorane.io/v1/errors/stats'
            && $request->method() === 'GET';
    });
});

test('getErrorStats passes period parameter', function (): void {
    Http::fake();
    $client = new SoraneApiClient('test-key');

    $client->getErrorStats(['period' => '7d']);

    Http::assertSent(function ($request): bool {
        return str_contains($request->url(), 'period=7d');
    });
});

test('getErrorStats returns success response format', function (): void {
    Http::fake([
        '*' => Http::response([
            'stats' => [
                'total_errors' => 100,
                'unique_errors' => 25,
                'resolved_errors' => 10,
            ],
        ], 200),
    ]);

    $client = new SoraneApiClient('test-key');
    $result = $client->getErrorStats(['period' => '24h']);

    expect($result['success'])->toBeTrue()
        ->and($result['status'])->toBe(200)
        ->and($result['data']['stats']['total_errors'])->toBe(100);
});

test('MCP methods include authorization header', function (string $method, array $args): void {
    Http::fake();
    $client = new SoraneApiClient('test-key-auth');

    $client->{$method}(...$args);

    Http::assertSent(function ($request): bool {
        return $request->hasHeader('Authorization', 'Bearer test-key-auth');
    });
})->with([
    'getLatestErrors' => ['getLatestErrors', []],
    'getError' => ['getError', ['err-123']],
    'getErrorStats' => ['getErrorStats', []],
]);

test('MCP methods send correct user agent header', function (string $method, array $args): void {
    Http::fake();
    $client = new SoraneApiClient('test-key');

    $client->{$method}(...$args);

    Http::assertSent(function ($request): bool {
        return $request->hasHeader('User-Agent', 'Sorane-Laravel/MCP/1.0');
    });
})->with([
    'getLatestErrors' => ['getLatestErrors', []],
    'getError' => ['getError', ['err-123']],
    'getErrorStats' => ['getErrorStats', []],
]);

test('MCP methods return error when api key is missing', function (string $method, array $args): void {
    Http::fake();
    config(['sorane.key' => null]);
    $client = new SoraneApiClient(null);

    $result = $client->{$method}(...$args);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toBe('API key not configured');
    Http::assertNothingSent();
})->with([
    'getLatestErrors' => ['getLatestErrors', []],
    'getError' => ['getError', ['err-123']],
    'getErrorStats' => ['getErrorStats', []],
]);

test('MCP methods handle network exceptions', function (string $method, array $args): void {
    Http::fake(function (): void {
        throw new Exception('Network failure');
    });

    $client = new SoraneApiClient('test-key');
    $result = $client->{$method}(...$args);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('Network failure');
})->with([
    'getLatestErrors' => ['getLatestErrors', []],
    'getError' => ['getError', ['err-123']],
    'getErrorStats' => ['getErrorStats', []],
]);
