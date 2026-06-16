<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Ranetrace\Laravel\Analytics\Middleware\TrackPageVisit;
use Ranetrace\Laravel\RanetraceServiceProvider;

class TestCase extends Orchestra
{
    /**
     * Per-test config overrides applied last in getEnvironmentSetUp(), so they
     * win over the defaults below. Set these then call reloadApplication() to
     * exercise behavior that the service provider decides at boot time (e.g.
     * which routes it registers based on config).
     *
     * @var array<string, mixed>
     */
    public array $configOverrides = [];

    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [
            RanetraceServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Configure cache to use array driver for testing
        $app['config']->set('cache.default', 'array');

        // Ranetrace's buffer/pause/throttle/frequency caches use this store; pin
        // it to the (default) array store so it's isolated per test and cleared
        // by Cache::flush() — otherwise it would resolve to the on-disk file store.
        $app['config']->set('ranetrace.batch.cache_driver', 'array');

        // Set encryption key for session handling
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        // Set test API key
        $app['config']->set('ranetrace.key', 'test-api-key-12345');

        // Enable Ranetrace globally
        $app['config']->set('ranetrace.enabled', true);

        // Enable features for testing
        $app['config']->set('ranetrace.events.enabled', true);
        $app['config']->set('ranetrace.events.queue', true); // Enable queue for testing Queue::fake()
        $app['config']->set('ranetrace.events.queue_name', 'default');
        $app['config']->set('ranetrace.logging.enabled', true);
        $app['config']->set('ranetrace.logging.queue', true);
        $app['config']->set('ranetrace.logging.queue_name', 'default');
        $app['config']->set('ranetrace.javascript_errors.enabled', true);
        $app['config']->set('ranetrace.javascript_errors.queue', true);
        $app['config']->set('ranetrace.javascript_errors.queue_name', 'default');
        $app['config']->set('ranetrace.javascript_errors.sample_rate', 1.0);
        $app['config']->set('ranetrace.website_analytics.enabled', true);
        $app['config']->set('ranetrace.website_analytics.queue', 'default');

        // Apply per-test overrides last so they take precedence.
        foreach ($this->configOverrides as $key => $value) {
            $app['config']->set($key, $value);
        }
    }

    protected function defineRoutes($router): void
    {
        // Define test routes for middleware testing
        $router->get('/', function () {
            return response('OK');
        })->middleware(['web', TrackPageVisit::class]);

        $router->get('/test-page', function () {
            return response('Test Page');
        })->middleware(['web', TrackPageVisit::class]);

        $router->get('/products', function () {
            return response('Products Page');
        })->middleware(['web', TrackPageVisit::class]);
    }
}
