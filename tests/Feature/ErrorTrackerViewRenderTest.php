<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Vite;

beforeEach(function (): void {
    config([
        'ranetrace.javascript_errors.enabled' => true,
    ]);
});

test('error tracker view renders to valid output', function (): void {
    // Regression guard: `view(...)` alone does not compile the Blade template;
    // only render() does. A directive/PHP syntax error in the view (e.g. the
    // un-compiled `<script@if` nonce conditional) surfaces here as a
    // ViewException and would 500 every host page using the snippet.
    $html = view('ranetrace::error-tracker')->render();

    expect($html)
        ->toContain('Ranetrace JavaScript Error Tracking')
        ->toContain(route('ranetrace.javascript-errors.store'))
        ->toContain("window.addEventListener('error'")
        ->toContain("window.addEventListener('unhandledrejection'")
        ->not->toContain('@if')
        ->not->toContain('@endif');
});

test('ranetraceErrorTracking directive renders the snippet into a host page', function (): void {
    $html = Blade::render('<html><head>@ranetraceErrorTracking</head><body></body></html>');

    expect($html)
        ->toContain('Ranetrace JavaScript Error Tracking')
        ->toContain('<script');
});

test('error tracker view renders nothing when feature is disabled', function (): void {
    config(['ranetrace.javascript_errors.enabled' => false]);

    expect(mb_trim(view('ranetrace::error-tracker')->render()))->toBe('');
});

test('error tracker script tag includes nonce when a CSP nonce is set', function (): void {
    Vite::useCspNonce('test-nonce-value');

    $html = view('ranetrace::error-tracker')->render();

    expect($html)->toContain('nonce="test-nonce-value"');
});
