<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Ranetrace\Laravel\Jobs\HandleErrorJob;
use Ranetrace\Laravel\Jobs\HandleEventJob;
use Ranetrace\Laravel\Ranetrace;

beforeEach(function (): void {
    Queue::fake();
});

// --- report(): capture gating ---

test('report does nothing when the package is disabled', function (): void {
    Config::set('ranetrace.enabled', false);

    (new Ranetrace)->report(new RuntimeException('boom'));

    Queue::assertNothingPushed();
});

test('report does nothing when error tracking is disabled', function (): void {
    Config::set('ranetrace.errors.enabled', false);

    (new Ranetrace)->report(new RuntimeException('boom'));

    Queue::assertNothingPushed();
});

test('report does nothing when no API key is configured', function (): void {
    Config::set('ranetrace.key', null);

    (new Ranetrace)->report(new RuntimeException('boom'));

    Queue::assertNothingPushed();
});

test('report dispatches HandleErrorJob when enabled and configured', function (): void {
    (new Ranetrace)->report(new RuntimeException('boom'));

    Queue::assertPushed(HandleErrorJob::class);
});

// --- report(): failure isolation (Core Rule — never throw from the capture path) ---

test('report never throws', function (): void {
    expect(fn () => (new Ranetrace)->report(new RuntimeException('boom')))
        ->not->toThrow(Throwable::class);
});

// --- trackEvent(): capture gating ---

test('trackEvent does nothing when the package is disabled', function (): void {
    Config::set('ranetrace.enabled', false);

    (new Ranetrace)->trackEvent('button_clicked');

    Queue::assertNothingPushed();
});

test('trackEvent does nothing when no API key is configured', function (): void {
    Config::set('ranetrace.key', null);

    (new Ranetrace)->trackEvent('button_clicked');

    Queue::assertNothingPushed();
});

test('trackEvent dispatches HandleEventJob for a valid event', function (): void {
    (new Ranetrace)->trackEvent('button_clicked', ['page' => 'home']);

    Queue::assertPushed(HandleEventJob::class);
});

// --- trackEvent(): validation stays loud, the rest is isolated ---

test('trackEvent throws on an invalid event name when validation is enabled', function (): void {
    expect(fn () => (new Ranetrace)->trackEvent('Invalid Name!!'))
        ->toThrow(InvalidArgumentException::class);
});

test('trackEvent does not throw on an invalid event name when validation is disabled', function (): void {
    expect(fn () => (new Ranetrace)->trackEvent('Invalid Name!!', validate: false))
        ->not->toThrow(Throwable::class);
});

// --- header allowlist + bounded shape ---

test('maskAndBoundHeaders masks every header not on the allowlist and preserves nested array shape', function (): void {
    $ranetrace = new Ranetrace;
    $method = new ReflectionMethod($ranetrace, 'maskAndBoundHeaders');

    $masked = $method->invoke($ranetrace, [
        'user-agent' => ['Mozilla/5.0'],
        'accept' => ['text/html'],
        'authorization' => ['Bearer secret-token'],
        'cookie' => ['session=abc123'],
        'x-api-key' => ['super-secret-key'],
        'proxy-authorization' => ['Basic xyz'],
        'php-auth-pw' => ['hunter2'],
    ]);

    // Allowlisted headers are preserved as nested arrays
    expect($masked['user-agent'])->toBe(['Mozilla/5.0']);
    expect($masked['accept'])->toBe(['text/html']);

    // Non-allowlisted header values are masked; structure stays nested arrays
    expect($masked['authorization'])->toBe(['***']);
    expect($masked['cookie'])->toBe(['***']);
    expect($masked['x-api-key'])->toBe(['***']);
    expect($masked['proxy-authorization'])->toBe(['***']);
    expect($masked['php-auth-pw'])->toBe(['***']);
});

test('maskAndBoundHeaders truncates header values that exceed MAX_HEADER_VALUE_LENGTH', function (): void {
    $ranetrace = new Ranetrace;
    $method = new ReflectionMethod($ranetrace, 'maskAndBoundHeaders');

    $huge = str_repeat('a', 1000);
    $masked = $method->invoke($ranetrace, ['user-agent' => [$huge]]);

    // The value is truncated to <= 500 chars (MAX_HEADER_VALUE_LENGTH)
    expect(mb_strlen($masked['user-agent'][0]))->toBeLessThanOrEqual(500);
    expect($masked['user-agent'][0])->toEndWith('... (truncated)');
});

test('maskAndBoundHeaders caps header count at MAX_HEADER_COUNT', function (): void {
    $ranetrace = new Ranetrace;
    $method = new ReflectionMethod($ranetrace, 'maskAndBoundHeaders');

    $headers = [];
    for ($i = 0; $i < 80; $i++) {
        $headers["x-custom-{$i}"] = ['value'];
    }

    $masked = $method->invoke($ranetrace, $headers);

    expect(count($masked))->toBe(50);
});

// --- error payload shape ---

test('error payload no longer contains the legacy `for` field', function (): void {
    $payload = invokeBuildErrorPayload(new RuntimeException('boom'));

    expect($payload)->not->toHaveKey('for');
});

test('error payload no longer contains the always-null `console_options` field', function (): void {
    $payload = invokeBuildErrorPayload(new RuntimeException('boom'));

    expect($payload)->not->toHaveKey('console_options');
});

test('error payload has exactly 18 fields', function (): void {
    $payload = invokeBuildErrorPayload(new RuntimeException('boom'));

    expect(count($payload))->toBe(18);
});

test('console_arguments is sent as an array, not a JSON-encoded string', function (): void {
    $payload = invokeBuildErrorPayload(new RuntimeException('boom'));

    // In the test environment runningInConsole() is true, so console_arguments is populated.
    expect($payload['console_arguments'])->toBeArray();
});

test('user payload uses getAuthIdentifier() and is null-safe for missing email', function (): void {
    $ranetrace = new Ranetrace;

    // A custom Authenticatable that has no `email` attribute at all.
    $user = new class implements Illuminate\Contracts\Auth\Authenticatable
    {
        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): int
        {
            return 42;
        }

        public function getAuthPassword(): string
        {
            return '';
        }

        public function getAuthPasswordName(): string
        {
            return 'password';
        }

        public function getRememberToken(): string
        {
            return '';
        }

        public function setRememberToken($value): void {}

        public function getRememberTokenName(): string
        {
            return 'remember_token';
        }
    };

    Illuminate\Support\Facades\Auth::shouldReceive('user')->andReturn($user);

    $payload = invokeBuildErrorPayload(new RuntimeException('boom'));

    expect($payload['user'])->toBe(['id' => 42, 'email' => null]);
});

/**
 * Helper: invoke the private buildErrorPayload via reflection.
 *
 * @return array<string, mixed>
 */
function invokeBuildErrorPayload(Throwable $exception): array
{
    $ranetrace = new Ranetrace;
    $method = new ReflectionMethod($ranetrace, 'buildErrorPayload');

    return $method->invoke($ranetrace, $exception);
}
