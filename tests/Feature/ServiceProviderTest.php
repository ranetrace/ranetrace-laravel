<?php

declare(strict_types=1);

use Ranetrace\Laravel\RanetraceServiceProvider;

test('service provider is registered', function (): void {
    $providers = $this->app->getLoadedProviders();

    expect($providers)->toHaveKey(RanetraceServiceProvider::class);
});

test('config is merged', function (): void {
    expect(config('ranetrace'))->toBeArray();
    expect(config('ranetrace.key'))->not->toBeNull();
});

test('event tracker is registered as singleton', function (): void {
    $instance1 = app(Ranetrace\Laravel\Events\EventTracker::class);
    $instance2 = app(Ranetrace\Laravel\Events\EventTracker::class);

    expect($instance1)->toBe($instance2);
});

test('ranetrace log driver is registered', function (): void {
    $channel = Log::channel('ranetrace');

    expect($channel)->toBeInstanceOf(Psr\Log\LoggerInterface::class);
});

test('blade directive is registered', function (): void {
    $directives = Illuminate\Support\Facades\Blade::getCustomDirectives();

    expect($directives)->toHaveKey('ranetraceErrorTracking');
});

test('javascript error route is registered when enabled', function (): void {
    $routes = collect(app('router')->getRoutes())->filter(function ($route): bool {
        return $route->getName() === 'ranetrace.javascript-errors.store';
    });

    expect($routes)->not->toBeEmpty();
});

test('middleware is registered when analytics enabled', function (): void {
    $middleware = app('router')->getMiddlewareGroups()['web'] ?? [];

    expect($middleware)->toContain(Ranetrace\Laravel\Analytics\Middleware\TrackPageVisit::class);
});

test('commands are registered', function (): void {
    $commands = Illuminate\Support\Facades\Artisan::all();

    expect($commands)->toHaveKeys([
        'ranetrace:test',
        'ranetrace:test-events',
        'ranetrace:test-logging',
        'ranetrace:test-javascript-errors',
    ]);
});

test('facades are accessible', function (): void {
    expect(class_exists(Ranetrace\Laravel\Facades\Ranetrace::class))->toBeTrue();
    expect(class_exists(Ranetrace\Laravel\Facades\RanetraceEvents::class))->toBeTrue();
});

test('package views are loadable', function (): void {
    $view = view('ranetrace::error-tracker');

    expect($view)->not->toBeNull();
});
