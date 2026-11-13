<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Ranetrace\Laravel\Services\RanetraceApiClient;
use Ranetrace\Php\Http\ResponsePolicy;

test('it sends error batch to correct endpoint', function (): void {
    Http::fake();
    $client = new RanetraceApiClient('test-key');

    $client->sendErrorBatch([['message' => 'Test error']]);

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.ranetrace.com/v1/errors/store'
            && isset($request->data()['errors']);
    });
});

test('it sends javascript error batch to correct endpoint', function (): void {
    Http::fake();
    $client = new RanetraceApiClient('test-key');

    $client->sendJavaScriptErrorBatch([['message' => 'JS error']]);

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.ranetrace.com/v1/javascript-errors/store'
            && isset($request->data()['javascript_errors']);
    });
});

test('it includes api key in authorization header', function (): void {
    Http::fake();
    $client = new RanetraceApiClient('test-key-123');

    $client->sendErrorBatch([['message' => 'Test']]);

    Http::assertSent(function ($request): bool {
        return $request->hasHeader('Authorization', 'Bearer test-key-123');
    });
});

test('it sends correct user agent header for errors', function (): void {
    Http::fake();
    $client = new RanetraceApiClient('test-key');

    $client->sendErrorBatch([['message' => 'Test']]);

    Http::assertSent(function ($request): bool {
        return $request->hasHeader('User-Agent', 'Ranetrace-Laravel/Errors/1.0');
    });
});

test('it sends correct user agent header for javascript errors', function (): void {
    Http::fake();
    $client = new RanetraceApiClient('test-key');

    $client->sendJavaScriptErrorBatch([['message' => 'Test']]);

    Http::assertSent(function ($request): bool {
        return $request->hasHeader('User-Agent', 'Ranetrace-Laravel/JavaScriptErrors/1.0');
    });
});

test('it sends correct user agent header for logs', function (): void {
    Http::fake();
    $client = new RanetraceApiClient('test-key');

    $client->sendLogBatch([['message' => 'Test']]);

    Http::assertSent(function ($request): bool {
        return $request->hasHeader('User-Agent', 'Ranetrace-Laravel/Logs/1.0');
    });
});

test('it sends event batch to events endpoint', function (): void {
    Http::fake();
    $client = new RanetraceApiClient('test-key');

    $client->sendEventBatch([['event_name' => 'test_event']]);

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.ranetrace.com/v1/events/store'
            && isset($request->data()['events']);
    });
});

test('it sends page visit batch to analytics endpoint', function (): void {
    Http::fake();
    $client = new RanetraceApiClient('test-key');

    $client->sendPageVisitBatch([['url' => 'https://example.com']]);

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.ranetrace.com/v1/page-visits/store'
            && isset($request->data()['page_visits']);
    });
});

test('it returns success on successful response', function (): void {
    Http::fake([
        '*' => Http::response(['success' => true, 'received' => 1, 'processed' => 1], 200),
    ]);

    $client = new RanetraceApiClient('test-key');
    $result = $client->sendErrorBatch([['message' => 'Test']]);

    expect($result['success'])->toBeTrue();
    expect($result['data']['received'])->toBe(1);
    expect($result['data']['processed'])->toBe(1);
});

test('it returns error when api key is missing', function (): void {
    Http::fake();
    config(['ranetrace.key' => null]);
    $client = new RanetraceApiClient(null);

    $result = $client->sendErrorBatch([['message' => 'Test']]);

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toBe('API key not configured');
    Http::assertNothingSent();
});

test('it sends a configured key padded with whitespace as the trimmed bearer token', function (): void {
    Http::fake();
    config(['ranetrace.key' => " real-key\t"]);

    (new RanetraceApiClient)->sendErrorBatch([['message' => 'Test']]);

    Http::assertSent(fn ($request): bool => $request->header('Authorization') === ['Bearer real-key']);
});

test('it refuses to send with a configured key of nothing but whitespace', function (): void {
    Http::fake();
    config(['ranetrace.key' => "\t\n "]);

    $result = (new RanetraceApiClient)->sendErrorBatch([['message' => 'Test']]);

    expect($result['error'])->toBe('API key not configured');
    Http::assertNothingSent();
});

test('it says whether it holds a key by the key it would send', function (?string $key, bool $hasKey): void {
    config(['ranetrace.key' => $key]);

    expect((new RanetraceApiClient)->hasKey())->toBe($hasKey);
})->with([
    'null' => [null, false],
    'empty' => ['', false],
    'whitespace' => ["\t\n ", false],
    'a key' => ['ingest-key', true],
    'a key padded with whitespace' => [' ingest-key ', true],
]);

test('it passes a refused request on with the body the api sent, where the shared reader finds the message', function (): void {
    Http::fake([
        '*' => Http::response(['success' => false, 'error' => ['message' => 'Invalid API key.']], 401),
    ]);

    $client = new RanetraceApiClient('test-key');
    $result = $client->sendErrorBatch([['message' => 'Test']]);

    expect($result['success'])->toBeFalse()
        ->and($result['status'])->toBe(401)
        ->and(ResponsePolicy::errorMessage($result['data'], 'Unauthorized'))->toBe('Invalid API key.')
        ->and($result)->not->toHaveKey('error');
});

test('it handles network exceptions gracefully', function (): void {
    Http::fake(function (): void {
        throw new Exception('Network error');
    });

    $client = new RanetraceApiClient('test-key');
    $result = $client->sendErrorBatch([['message' => 'Test']]);

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toContain('Network error');
});

test('a request that got no answer carries the cause alone, as the shared core words it', function (string $message, string $cause): void {
    Http::fake(function () use ($message): never {
        throw new ConnectionException($message);
    });

    $result = (new RanetraceApiClient('test-key'))->sendErrorBatch([['message' => 'Test']]);

    expect($result['status'])->toBe(0)
        ->and($result['error'])->toBe($cause);
})->with([
    'a refused connection' => [
        'cURL error 7: Failed to connect to 127.0.0.1:9 after 0 ms: Could not connect to server (see https://curl.se/libcurl/c/libcurl-errors.html) for http://127.0.0.1:9/v1/errors/store',
        'Failed to connect to 127.0.0.1:9 after 0 ms: Could not connect to server',
    ],
    'an older libcurl reference' => [
        'cURL error 28: Operation timed out after 5001 milliseconds with 0 bytes received (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://api.ranetrace.com/v1/errors/store',
        'Operation timed out after 5001 milliseconds with 0 bytes received',
    ],
    'a message with no cURL parts' => ['Connection refused', 'Connection refused'],
    'a message that only mentions a url' => ['No route for https://api.ranetrace.com/v1 in this sandbox', 'No route for https://api.ranetrace.com/v1 in this sandbox'],
    'the missing key message' => ['API key not configured', 'API key not configured'],
]);

test('it returns error for empty batch', function (): void {
    Http::fake();
    $client = new RanetraceApiClient('test-key');

    $result = $client->sendErrorBatch([]);

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toBe('Empty batch provided');
    Http::assertNothingSent();
});

test('it sends batches to the configured base url', function (): void {
    Http::fake();
    config(['ranetrace.base_url' => 'https://ranetrace.test/v1']);
    $client = new RanetraceApiClient('test-key');

    $client->sendErrorBatch([['message' => 'Test']]);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://ranetrace.test/v1/errors/store');
});

test('it trims a trailing slash from the configured base url', function (): void {
    Http::fake();
    config(['ranetrace.base_url' => 'https://ranetrace.test/v1/']);
    $client = new RanetraceApiClient('test-key');

    $client->sendErrorBatch([['message' => 'Test']]);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://ranetrace.test/v1/errors/store');
});

test('it falls back to the default base url when the configured value is empty', function (): void {
    Http::fake();
    config(['ranetrace.base_url' => '']);
    $client = new RanetraceApiClient('test-key');

    $client->sendErrorBatch([['message' => 'Test']]);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.ranetrace.com/v1/errors/store');
});

test('it uses timeout from config', function (): void {
    Http::fake();
    config(['ranetrace.errors.timeout' => 15]);
    $client = new RanetraceApiClient('test-key');

    $client->sendErrorBatch([['message' => 'Test']]);

    // Note: Can't directly test timeout value, but it's being set from config
    Http::assertSent(fn ($req) => str_contains($req->url(), 'errors'));
});
