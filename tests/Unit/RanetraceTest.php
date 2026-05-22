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

// --- header allowlist (R1-S1) ---

test('maskUnsafeHeaders masks every header not on the allowlist', function (): void {
    $ranetrace = new Ranetrace;
    $method = new ReflectionMethod($ranetrace, 'maskUnsafeHeaders');

    $masked = $method->invoke($ranetrace, [
        'user-agent' => ['Mozilla/5.0'],
        'accept' => ['text/html'],
        'authorization' => ['Bearer secret-token'],
        'cookie' => ['session=abc123'],
        'x-api-key' => ['super-secret-key'],
        'proxy-authorization' => ['Basic xyz'],
        'php-auth-pw' => ['hunter2'],
    ]);

    // Allowlisted headers are preserved
    expect($masked['user-agent'])->toBe(['Mozilla/5.0']);
    expect($masked['accept'])->toBe(['text/html']);

    // Everything else is masked
    expect($masked['authorization'])->toBe('***');
    expect($masked['cookie'])->toBe('***');
    expect($masked['x-api-key'])->toBe('***');
    expect($masked['proxy-authorization'])->toBe('***');
    expect($masked['php-auth-pw'])->toBe('***');
});
