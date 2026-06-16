<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

beforeEach(function (): void {
    Config::set('ranetrace.batch.cache_driver', 'array');
    Cache::store('array')->flush();
});

test('ranetrace:status prints a dashboard hint with the URL', function (): void {
    Artisan::call('ranetrace:status');
    $output = Artisan::output();

    expect($output)
        ->toContain('Dashboard:')
        ->toContain(route('ranetrace.dashboard'));
});

test('the dashboard hint is omitted when the dashboard is disabled', function (): void {
    // The route stays registered for this process, but the hint guards on config.
    Config::set('ranetrace.dashboard.enabled', false);

    Artisan::call('ranetrace:status');

    expect(Artisan::output())->not->toContain('Dashboard:');
});

test('the dashboard hint never appears in the --json payload', function (): void {
    Artisan::call('ranetrace:status', ['--json' => true]);

    expect(Artisan::output())->not->toContain('Dashboard:');
});

test('the production 403 explains how to grant access (friendly, not bare)', function (): void {
    $this->app['env'] = 'production';

    $response = $this->get('/ranetrace');

    $response->assertForbidden();
    expect($response->getContent())
        ->toContain('access denied')
        ->toContain('viewRanetrace')
        ->toContain('AppServiceProvider')
        // CSP-clean: external stylesheet, no inline style/script
        ->toContain('ranetrace.css?v=')
        ->not->toContain('<style')
        ->not->toContain('style=')
        ->not->toMatch('/<script(?![^>]*\bsrc=)/');
});

test('the friendly 403 leaks no secrets', function (): void {
    $this->app['env'] = 'production';
    Config::set('ranetrace.key', 'super-secret-key-value');

    $content = $this->get('/ranetrace')->getContent();

    expect($content)->not->toContain('super-secret-key-value');
});
