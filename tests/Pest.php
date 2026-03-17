<?php

declare(strict_types=1);

use Ranetrace\Laravel\RanetraceServiceProvider;
use Ranetrace\Laravel\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Test Case Setup
|--------------------------------------------------------------------------
|
| Configure the test case to properly load the Ranetrace service provider
| and set up the testing environment.
|
*/

// Define the getPackageProviders method for all tests
uses()->beforeEach(function (): void {
    // No additional setup needed for now
})->in('Feature', 'Unit');

// Helper to get package providers
function getPackageProviders($app): array
{
    return [
        RanetraceServiceProvider::class,
    ];
}

// Helper to define environment setup
function getEnvironmentSetUp($app): void
{
    // Configure cache to use array driver for testing
    $app['config']->set('cache.default', 'array');
    $app['config']->set('queue.default', 'sync');

    // Set test API key
    $app['config']->set('ranetrace.key', 'test-api-key-12345');

    // Enable features for testing
    $app['config']->set('ranetrace.events.enabled', true);
    $app['config']->set('ranetrace.events.queue', true);
    $app['config']->set('ranetrace.events.queue_name', 'default');

    $app['config']->set('ranetrace.logging.enabled', true);
    $app['config']->set('ranetrace.logging.queue', true);
    $app['config']->set('ranetrace.logging.queue_name', 'default');

    $app['config']->set('ranetrace.javascript_errors.enabled', true);
    $app['config']->set('ranetrace.javascript_errors.queue', true);
    $app['config']->set('ranetrace.javascript_errors.sample_rate', 1.0);
    $app['config']->set('ranetrace.javascript_errors.queue_name', 'default');

    $app['config']->set('ranetrace.website_analytics.enabled', true);
    $app['config']->set('ranetrace.website_analytics.queue', 'default');
}
