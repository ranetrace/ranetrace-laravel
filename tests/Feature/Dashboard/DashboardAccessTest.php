<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Ranetrace\Laravel\Http\Middleware\Authorize;

/**
 * Access control for the diagnostics dashboard. The `viewRanetrace` gate is the
 * only guard; it must default-deny everywhere except `local`. The environment is
 * faked at request time — the default gate closure reads it lazily — while
 * route-registration variations (path/enabled) are decided at boot, so they set
 * $this->configOverrides and reloadApplication().
 */
test('the dashboard is reachable in the local environment by default', function (): void {
    $this->app['env'] = 'local';

    $this->get('/ranetrace')->assertOk();
});

test('SECURITY: the dashboard is forbidden in production with no gate override', function (): void {
    // The regression that would silently expose everything: a faked production
    // env with nobody having redefined the gate must 403.
    $this->app['env'] = 'production';

    $this->get('/ranetrace')->assertForbidden();
});

test('a host gate override grants access even in production', function (): void {
    $this->app['env'] = 'production';

    // Defining the gate resolves the Gate, which fires the package's
    // callAfterResolving default first; this definition then overrides it —
    // proving a host AppServiceProvider override always wins.
    Gate::define('viewRanetrace', fn ($user = null): bool => true);

    $this->get('/ranetrace')->assertOk();
});

test('a host gate override can deny access in local', function (): void {
    $this->app['env'] = 'local';

    Gate::define('viewRanetrace', fn ($user = null): bool => false);

    $this->get('/ranetrace')->assertForbidden();
});

test('the default gate allows in local and denies in production', function (): void {
    // Force the package default to be defined (no host override present).
    expect(Gate::has('viewRanetrace'))->toBeTrue();

    $this->app['env'] = 'local';
    expect(Gate::check('viewRanetrace'))->toBeTrue();

    $this->app['env'] = 'production';
    expect(Gate::check('viewRanetrace'))->toBeFalse();
});

test('a custom dashboard path moves the route', function (): void {
    $this->configOverrides = ['ranetrace.dashboard.path' => 'telemetry'];
    $this->reloadApplication();
    $this->app['env'] = 'local';

    $this->get('/telemetry')->assertOk();
    $this->get('/ranetrace')->assertNotFound();
});

test('disabling the dashboard removes its routes entirely', function (): void {
    $this->configOverrides = ['ranetrace.dashboard.enabled' => false];
    $this->reloadApplication();
    $this->app['env'] = 'local';

    $this->get('/ranetrace')->assertNotFound();
});

test('the package appends its own Authorize middleware to the dashboard route', function (): void {
    $route = collect(app('router')->getRoutes())->first(
        fn ($route): bool => $route->getName() === 'ranetrace.dashboard'
    );

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain(Authorize::class)
        ->and($route->gatherMiddleware())->toContain('web');
});

test('the dashboard route does not swallow the JavaScript error ingest route', function (): void {
    // Distinct verbs/paths (GET ranetrace vs POST ranetrace/javascript-errors/store)
    // — no Horizon-style catch-all — so both routes coexist.
    $names = collect(app('router')->getRoutes())->map(fn ($route): ?string => $route->getName());

    expect($names)->toContain('ranetrace.dashboard')
        ->and($names)->toContain('ranetrace.javascript-errors.store');
});
