<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

beforeEach(function (): void {
    Config::set('ranetrace.batch.cache_driver', 'array');
    Cache::store('array')->flush();
    $this->app['env'] = 'local';
});

test('the panels fragment renders the panels without the shell', function (): void {
    $response = $this->get('/ranetrace/panels');

    $response->assertOk();
    expect($response->getContent())
        ->toContain('Checks')
        ->toContain('Pipeline buffers')
        ->toContain('Configuration')
        // fragment only — no surrounding HTML document
        ->not->toContain('<!DOCTYPE')
        ->not->toContain('<html')
        ->not->toContain('<body');
});

test('the panels fragment stays behind the gate (403 in production)', function (): void {
    $this->app['env'] = 'production';

    $this->get('/ranetrace/panels')->assertForbidden();
});

test('the panels fragment degrades to 200 (never 500) when the cache is unavailable', function (): void {
    Config::set('ranetrace.batch.cache_driver', 'does-not-exist');

    $this->get('/ranetrace/panels')
        ->assertOk()
        ->assertSee('Checks');
});

test('the shell wires the poller attributes for the fragment', function (): void {
    $html = $this->get('/ranetrace')->getContent();

    expect($html)
        ->toContain('id="rt-app"')
        ->toContain('data-refresh="')
        ->toContain('data-panels-url="')
        ->toContain(route('ranetrace.dashboard.panels'))
        ->toContain('id="rt-panels"');
});

test('the served JS is the auto-refresh poller and stays CSP-clean (external file)', function (): void {
    $js = $this->get('/ranetrace/ranetrace.js');

    $js->assertOk();
    expect($js->getContent())
        ->toContain('data-panels-url')
        ->toContain('rt-panels')
        ->toContain('fetch(');
});
