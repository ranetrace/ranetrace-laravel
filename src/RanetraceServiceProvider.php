<?php

declare(strict_types=1);

namespace Ranetrace\Laravel;

use Illuminate\Support\ServiceProvider;
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

        // Auto-register ranetrace_internal log channel
        // This ensures zero-config setup for internal diagnostics
        $this->app['config']->set('logging.channels.ranetrace_internal', [
            'driver' => 'daily',
            'path' => storage_path('logs/ranetrace-internal.log'),
            'level' => config('ranetrace.internal_logging.level', 'debug'),
            'days' => config('ranetrace.internal_logging.days', 14),
        ]);

        // Register Ranetrace as singleton
        $this->app->singleton(Ranetrace::class, function () {
            return new Ranetrace;
        });

        // Register EventTracker as singleton
        $this->app->singleton(EventTracker::class, function () {
            return new EventTracker;
        });

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
            ], 'ranetrace-config');

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
        if (config('ranetrace.enabled', false) && config('ranetrace.website_analytics.enabled')) {
            $this->app['router']->pushMiddlewareToGroup('web', TrackPageVisit::class);
        }

        // Register JavaScript error tracking route
        if (config('ranetrace.enabled', false) && config('ranetrace.javascript_errors.enabled')) {
            $this->registerJavaScriptErrorRoute();
        }

        // Register Blade directive for error tracking script
        $this->registerBladeDirectives();

        // Register MCP server
        $this->registerMcpServer();
    }

    protected function registerJavaScriptErrorRoute(): void
    {
        $this->app['router']
            ->post('ranetrace/js-errors', [Http\Controllers\JavaScriptErrorController::class, 'store'])
            ->middleware(['web', 'throttle:60,1'])
            ->name('ranetrace.javascript-errors.store');
    }

    protected function registerBladeDirectives(): void
    {
        \Illuminate\Support\Facades\Blade::directive('ranetraceErrorTracking', function () {
            return "<?php echo view('ranetrace::error-tracker')->render(); ?>";
        });
    }

    protected function registerMcpServer(): void
    {
        if (! class_exists(\Laravel\Mcp\Facades\Mcp::class)) {
            return;
        }

        if (! config('ranetrace.mcp.enabled', true)) {
            return;
        }

        if (empty(config('ranetrace.key'))) {
            return;
        }

        \Laravel\Mcp\Facades\Mcp::local('ranetrace', RanetraceServer::class);
    }
}
