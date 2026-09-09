<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Tests;

use Illuminate\Filesystem\Filesystem;
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

    /**
     * This test's own storage directory, created in getEnvironmentSetUp() and
     * removed in tearDown(). Null until the application is built.
     */
    protected ?string $temporaryStoragePath = null;

    protected function tearDown(): void
    {
        parent::tearDown();

        if ($this->temporaryStoragePath !== null) {
            (new Filesystem)->deleteDirectory($this->temporaryStoragePath);
            $this->temporaryStoragePath = null;
        }
    }

    protected function getPackageProviders($app): array
    {
        return [
            RanetraceServiceProvider::class,
        ];
    }

    /**
     * Testbench runs this after the config is loaded, which is what makes the
     * storage redirect below safe, and after the package provider's register(),
     * which is why the internal log channel has to be re-pointed by hand.
     *
     * Every framework path derived from storage (view.compiled, session.files,
     * the file cache store, the app's single/daily log channels) was computed
     * from the Testbench skeleton's storage path while the config loaded, so
     * useStoragePath() here only redirects the storage_path() calls made later:
     * the dashboard's glob for the internal log's daily files, and the reader's
     * fallback. The skeleton's storage is shared by every run and is never
     * reset, so without this a log file an earlier run left behind would decide
     * what the dashboard's log panel shows on this machine.
     *
     * The `ranetrace_internal` channel is the other half: the provider computed
     * its path from the skeleton's storage before this method ran, so the value
     * is re-set here (config set after register() wins) and the writer lands in
     * the same private directory the reader looks in. That also keeps the suite
     * from dropping `ranetrace-internal-*.log` files into the shared skeleton.
     */
    protected function getEnvironmentSetUp($app): void
    {
        // Unique per test, so a parallel run has nothing to coordinate. Kept
        // across a reloadApplication() so the rebuilt app reads the same files.
        if ($this->temporaryStoragePath === null) {
            $this->temporaryStoragePath = sys_get_temp_dir().'/ranetrace-laravel-tests-'.uniqid('', true);
            mkdir($this->temporaryStoragePath.'/logs', 0755, true);
        }

        $app->useStoragePath($this->temporaryStoragePath);
        $app['config']->set('logging.channels.ranetrace_internal.path', $app->storagePath('logs/ranetrace-internal.log'));

        // Configure cache to use array driver for testing
        $app['config']->set('cache.default', 'array');

        // Ranetrace's buffer/pause/throttle/frequency caches use this store; pin
        // it to the (default) array store so it's isolated per test and cleared
        // by Cache::flush(). Otherwise it would resolve to the on-disk file store.
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
