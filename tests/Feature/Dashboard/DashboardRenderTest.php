<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;
use Ranetrace\Laravel\Services\RanetracePauseManager;

beforeEach(function (): void {
    Config::set('ranetrace.batch.cache_driver', 'array');
    Cache::store('array')->flush();
    // The dashboard's default gate allows the local environment.
    $this->app['env'] = 'local';
});

test('the dashboard renders the shell and every panel', function (): void {
    $response = $this->get('/ranetrace');

    $response->assertOk();
    expect($response->getContent())
        ->toContain('Ranetrace')
        ->toContain('Checks')
        ->toContain('Configuration')
        ->toContain('Pipeline buffers')
        ->toContain('Pauses')
        ->toContain('Registered surfaces')
        ->toContain('Failed jobs (24h)')
        ->toContain('Environment')
        ->toContain('Internal log')
        // health badge — empty buffers + key configured in tests => healthy
        ->toContain('Healthy')
        // links to the externally-served, version-busted assets (CSP-clean)
        ->toContain('ranetrace.css?v=')
        ->toContain('ranetrace.js?v=');
});

test('the dashboard links out to the hosted Ranetrace dashboard', function (): void {
    Config::set('ranetrace.dashboard.hosted_url', 'https://example.test/captured');

    expect($this->get('/ranetrace')->getContent())
        ->toContain('https://example.test/captured')
        ->toContain('View captured data');
});

test('the checks panel renders remediation text for a failing check', function (): void {
    Config::set('ranetrace.key', null);

    expect($this->get('/ranetrace')->getContent())
        ->toContain('API key is missing')
        ->toContain('Set RANETRACE_KEY');
});

test('the dashboard surfaces live state: a pause and a stalled buffer', function (): void {
    app(RanetracePauseManager::class)->setFeaturePause('errors', 900, '429');
    app(RanetraceBatchBuffer::class)->addItem('events', ['event_name' => 'e1']);

    // Age the buffered item past the drain window so it reads as stalled.
    $this->travel(601)->seconds();

    $response = $this->get('/ranetrace');

    $response->assertOk();
    expect($response->getContent())
        ->toContain('Rate limited') // 429 reason explanation
        ->toContain('Drain stalled'); // buffered item overdue, never drained
});

test('a freshly buffered item is shown as waiting, not stalled', function (): void {
    // The exact false-positive reported in production: an item placed in the
    // buffer and waiting for the next ranetrace:work run must not read as stalled.
    app(RanetraceBatchBuffer::class)->addItem('events', ['event_name' => 'e1']);

    $response = $this->get('/ranetrace');

    $response->assertOk();
    expect($response->getContent())->not->toContain('Drain stalled');
});

test('the dashboard degrades to 200 (never 500) when the cache store is unavailable', function (): void {
    // A bogus store name makes every cache read throw; DashboardData catches
    // each and degrades to a safe default, so the page must still render.
    Config::set('ranetrace.batch.cache_driver', 'does-not-exist');

    $this->get('/ranetrace')
        ->assertOk()
        ->assertSee('Ranetrace');
});

test('a fresh install (no API key) renders without 500-ing', function (): void {
    Config::set('ranetrace.key', null);

    $response = $this->get('/ranetrace');

    $response->assertOk();
    expect($response->getContent())->toContain('Missing'); // API key pill
});

test('the dashboard is forbidden in production but its assets are not gated', function (): void {
    $this->app['env'] = 'production';

    // Data route stays behind the gate.
    $this->get('/ranetrace')->assertForbidden();

    // Assets serve publicly (no secrets) so the page stays CSP-clean.
    $css = $this->get('/ranetrace/ranetrace.css');
    $css->assertOk();
    expect($css->headers->get('Content-Type'))->toContain('text/css');
    expect($css->headers->get('Cache-Control'))->toContain('max-age=31536000');

    $js = $this->get('/ranetrace/ranetrace.js');
    $js->assertOk();
    expect($js->headers->get('Content-Type'))->toContain('javascript');
    expect($js->headers->get('Cache-Control'))->toContain('immutable');
});

test('the rendered page carries no inline style or script (CSP-clean)', function (): void {
    $html = $this->get('/ranetrace')->getContent();

    expect($html)
        ->not->toContain('<style')
        ->not->toContain('style=')
        ->not->toMatch('/<script(?![^>]*\bsrc=)/'); // only external <script src=...>
});
