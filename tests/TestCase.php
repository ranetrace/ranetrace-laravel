<?php

namespace Ranetrace\Laravel\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Ranetrace\Laravel\Analytics\Middleware\TrackPageVisit;
use Ranetrace\Laravel\RanetraceServiceProvider;

class TestCase extends Orchestra
{
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
