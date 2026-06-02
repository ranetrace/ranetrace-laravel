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

test('trackEvent redacts secrets in event properties', function (): void {
    (new Ranetrace)->trackEvent('checkout_completed', [
        'api_key' => 'sk_live_x',
        'order_id' => 'ORD-1',
    ]);

    Queue::assertPushed(HandleEventJob::class, function ($job): bool {
        $properties = $job->getEventData()['properties'];

        return $properties['api_key'] === '[REDACTED]'
            && $properties['order_id'] === 'ORD-1';
    });
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

test('error payload uses an ISO 8601 timestamp, not the legacy time field', function (): void {
    $payload = invokeBuildErrorPayload(new RuntimeException('boom'));

    expect($payload)->toHaveKey('timestamp')
        ->and($payload)->not->toHaveKey('time')
        ->and($payload['timestamp'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/');
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

// --- file-path relativization (R4-2) ---

test('relativizePath strips the application base path', function (): void {
    $ranetrace = new Ranetrace;
    $method = new ReflectionMethod($ranetrace, 'relativizePath');

    expect($method->invoke($ranetrace, base_path('app/Http/Controllers/UserController.php')))
        ->toBe('app/Http/Controllers/UserController.php');
});

test('relativizePath leaves paths outside base_path unchanged', function (): void {
    $ranetrace = new Ranetrace;
    $method = new ReflectionMethod($ranetrace, 'relativizePath');

    expect($method->invoke($ranetrace, '/usr/local/share/elsewhere/File.php'))
        ->toBe('/usr/local/share/elsewhere/File.php');
});

// --- per-line context cap (R6-2) ---

test('capContextLine truncates an over-long source line and preserves the newline', function (): void {
    $ranetrace = new Ranetrace;
    $method = new ReflectionMethod($ranetrace, 'capContextLine');

    $capped = $method->invoke($ranetrace, str_repeat('x', 5000)."\n");

    expect(mb_strlen($capped))->toBeLessThan(5000)
        ->and($capped)->toEndWith("... (truncated)\n");

    // A short line passes through unchanged.
    expect($method->invoke($ranetrace, "short line\n"))->toBe("short line\n");
});

// --- referer scrubbing in headers (R4-4) ---

test('maskAndBoundHeaders scrubs secrets from the referer query string', function (): void {
    $ranetrace = new Ranetrace;
    $method = new ReflectionMethod($ranetrace, 'maskAndBoundHeaders');

    $masked = $method->invoke($ranetrace, [
        'referer' => ['https://example.com/reset?token=abc123&page=2'],
    ]);

    expect($masked['referer'][0])->toBe('https://example.com/reset?token=[REDACTED]&page=2');
});

// --- user email is gated (R4-6) ---

test('user email is not captured by default', function (): void {
    Illuminate\Support\Facades\Auth::shouldReceive('user')->andReturn(makeAuthenticatableWithEmail(7, 'secret@example.com'));

    $payload = invokeBuildErrorPayload(new RuntimeException('boom'));

    expect($payload['user'])->toBe(['id' => 7, 'email' => null]);
});

test('user email is captured only when explicitly enabled', function (): void {
    Config::set('ranetrace.errors.capture_user_email', true);
    Illuminate\Support\Facades\Auth::shouldReceive('user')->andReturn(makeAuthenticatableWithEmail(7, 'secret@example.com'));

    $payload = invokeBuildErrorPayload(new RuntimeException('boom'));

    expect($payload['user'])->toBe(['id' => 7, 'email' => 'secret@example.com']);
});

/**
 * Helper: an Authenticatable whose `email` attribute is readable via getAttribute().
 */
function makeAuthenticatableWithEmail(int $id, ?string $email): Illuminate\Contracts\Auth\Authenticatable
{
    return new class($id, $email) implements Illuminate\Contracts\Auth\Authenticatable
    {
        public function __construct(private int $id, private ?string $email) {}

        public function getAttribute(string $key): mixed
        {
            return $key === 'email' ? $this->email : null;
        }

        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): int
        {
            return $this->id;
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
}

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
