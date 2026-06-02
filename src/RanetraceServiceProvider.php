<?php

declare(strict_types=1);

namespace Ranetrace\Laravel;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;
use Ranetrace\Laravel\Analytics\Middleware\TrackPageVisit;
use Ranetrace\Laravel\Commands\RanetraceAnalyticsTestCommand;
use Ranetrace\Laravel\Commands\RanetraceErrorTestCommand;
use Ranetrace\Laravel\Commands\RanetraceEventTestCommand;
use Ranetrace\Laravel\Commands\RanetraceJavaScriptErrorTestCommand;
use Ranetrace\Laravel\Commands\RanetraceLogTestCommand;
use Ranetrace\Laravel\Commands\RanetracePauseClearCommand;
use Ranetrace\Laravel\Commands\RanetraceStatusCommand;
use Ranetrace\Laravel\Commands\RanetraceTestCommand;
use Ranetrace\Laravel\Commands\RanetraceWorkCommand;
use Ranetrace\Laravel\Events\EventTracker;
use Ranetrace\Laravel\Http\Controllers\JavaScriptErrorController;
use Ranetrace\Laravel\Logging\RanetraceLogDriver;
use Ranetrace\Laravel\Mcp\RanetraceServer;

class RanetraceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Merge package config
        $this->mergeConfigFrom(
            __DIR__.'/../config/ranetrace.php',
            'ranetrace'
        );

        $this->registerLogChannels();

        // Register Ranetrace as singleton
        $this->app->singleton(Ranetrace::class);

        // Register EventTracker as singleton
        $this->app->singleton(EventTracker::class);

        // Register custom log driver
        $this->app['log']->extend('ranetrace', function ($app, $config) {
            return (new RanetraceLogDriver)($config);
        });
    }

    public function boot(): void
    {
        // Publish config
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/ranetrace.php' => config_path('ranetrace.php'),
            ], 'ranetrace-laravel-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/ranetrace'),
            ], 'ranetrace-laravel-views');

            // Register commands
            $this->commands([
                RanetraceTestCommand::class,
                RanetraceAnalyticsTestCommand::class,
                RanetraceErrorTestCommand::class,
                RanetraceEventTestCommand::class,
                RanetraceJavaScriptErrorTestCommand::class,
                RanetraceLogTestCommand::class,
                RanetraceWorkCommand::class,
                RanetraceStatusCommand::class,
                RanetracePauseClearCommand::class,
            ]);
        }

        // Load package views
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'ranetrace');

        // Add middleware to web group
        if (config('ranetrace.enabled', true) && config('ranetrace.website_analytics.enabled')) {
            $this->app['router']->pushMiddlewareToGroup('web', TrackPageVisit::class);
        }

        // Register JavaScript error tracking route
        if (config('ranetrace.enabled', true) && config('ranetrace.javascript_errors.enabled')) {
            $this->registerJavaScriptErrorRoute();
        }

        // Register Blade directive for error tracking script
        $this->registerBladeDirectives();

        // Register MCP server
        $this->registerMcpServer();
    }

    /**
     * Auto-register the package's log channels so the host application gets
     * zero-config diagnostics and centralized logging.
     *
     * The user-facing `ranetrace` channel is never overwritten: if the host
     * defines its own `ranetrace` channel, that definition always wins. The
     * internal `ranetrace_internal` channel is package-owned and is always
     * (re)set from `ranetrace.internal_logging.*`, so it cannot be overridden
     * via `config/logging.php` — tune it through those config keys instead.
     */
    protected function registerLogChannels(): void
    {
        $this->app['config']->set('logging.channels.ranetrace_internal', [
            'driver' => 'daily',
            'path' => storage_path('logs/ranetrace-internal.log'),
            'level' => config('ranetrace.internal_logging.level', 'debug'),
            'days' => config('ranetrace.internal_logging.days', 14),
        ]);

        if (config('ranetrace.logging.enabled', false)
            && ! $this->app['config']->has('logging.channels.ranetrace')) {
            $this->app['config']->set('logging.channels.ranetrace', [
                'driver' => 'ranetrace',
                'level' => config('ranetrace.logging.level', 'notice'),
            ]);
        }
    }

    protected function registerJavaScriptErrorRoute(): void
    {
        $throttle = config('ranetrace.javascript_errors.throttle', '60,1');

        $this->app['router']
            ->post('ranetrace/javascript-errors/store', [JavaScriptErrorController::class, 'store'])
            ->middleware(['web', "throttle:{$throttle}"])
            ->name('ranetrace.javascript-errors.store');
    }

    protected function registerBladeDirectives(): void
    {
        Blade::directive('ranetraceErrorTracking', function () {
            return "<?php echo view('ranetrace::error-tracker')->render(); ?>";
        });
    }

    protected function registerMcpServer(): void
    {
        if (! class_exists(Mcp::class)) {
            return;
        }

        if (! config('ranetrace.mcp.enabled', true)) {
            return;
        }

        if (empty(config('ranetrace.key'))) {
            return;
        }

        Mcp::local('ranetrace', RanetraceServer::class);
    }
}
