<?php

declare(strict_types=1);

namespace Ranetrace\Laravel;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Support\Facades\Blade;
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
use Ranetrace\Laravel\Http\Controllers\AnalyticsBeaconController;
use Ranetrace\Laravel\Http\Controllers\AssetController;
use Ranetrace\Laravel\Http\Controllers\JavaScriptErrorController;
use Ranetrace\Laravel\Http\Middleware\Authorize;
use Ranetrace\Laravel\Logging\RanetraceLogDriver;

class RanetraceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergePublishedConfigOverDefaults();

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
            $this->registerAnalyticsBeaconRoute();
        }

        // Register JavaScript error tracking route. The error-tracker view
        // renders its script on the same answer, since that script names this
        // route.
        if (JavaScriptErrorController::isMounted()) {
            $this->registerJavaScriptErrorRoute();
        }

        // Register Blade directive for error tracking script
        $this->registerBladeDirectives();

        // Register the in-app diagnostics dashboard
        $this->registerDashboard();
    }

    /**
     * Register the diagnostics dashboard route group + its authorization gate.
     *
     * Gated on `ranetrace.dashboard.enabled` independently of the master
     * `ranetrace.enabled` capture switch, so an admin can still open the
     * dashboard to see *why* capture is disabled or misconfigured. Disabling it
     * removes the routes entirely (zero attack surface).
     */
    protected function registerDashboard(): void
    {
        if (! config('ranetrace.dashboard.enabled', true)) {
            return;
        }

        $this->registerDashboardGate();

        // Data routes, behind the gate.
        $this->app['router']->group($this->dashboardRouteConfiguration(), function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/dashboard.php');
        });

        // Asset routes, same path/domain but NOT gated (no secrets; keeps the
        // page CSP-clean). Long-cached, content-hash busted by the shell.
        $this->app['router']->group([
            'domain' => config('ranetrace.dashboard.domain'),
            'prefix' => config('ranetrace.dashboard.path', 'ranetrace'),
        ], function ($router): void {
            $router->get('ranetrace.css', [AssetController::class, 'css'])->name('ranetrace.assets.css');
            $router->get('ranetrace.js', [AssetController::class, 'js'])->name('ranetrace.assets.js');
        });
    }

    /**
     * Define the default `viewRanetrace` gate (local-only) unless the host has
     * already defined one.
     *
     * Mirrors Pulse: registered after the Gate resolves so a host override in
     * AppServiceProvider::boot() always wins regardless of provider boot order.
     * The closure takes a nullable user and explicitly denies in every
     * non-local environment: it must never fall through to allow.
     */
    protected function registerDashboardGate(): void
    {
        $this->callAfterResolving(Gate::class, function (Gate $gate): void {
            if (! $gate->has('viewRanetrace')) {
                $gate->define(
                    'viewRanetrace',
                    fn (?Authenticatable $user = null): bool => $this->app->environment('local')
                );
            }
        });
    }

    /**
     * Build the dashboard route group configuration from config, always
     * appending the package's own Authorize middleware (never trusting the
     * host's `web` group to include it).
     *
     * @return array<string, mixed>
     */
    protected function dashboardRouteConfiguration(): array
    {
        return [
            'domain' => config('ranetrace.dashboard.domain'),
            'prefix' => config('ranetrace.dashboard.path', 'ranetrace'),
            'middleware' => array_merge(
                (array) config('ranetrace.dashboard.middleware', ['web']),
                [Authorize::class]
            ),
        ];
    }

    /**
     * Lay the package's config underneath the host's published copy at every
     * depth, so a key added after the host published `config/ranetrace.php`
     * still reads its env var. Laravel's own `mergeConfigFrom()` merges the top
     * level only, so a published block would hide every key added inside it.
     *
     * Only associative arrays are merged. A list (`excluded_paths`,
     * `ignored_errors`, `dashboard.middleware`) is taken whole from the host:
     * merged by index, as `replaceConfigRecursivelyFrom()` does, it would keep
     * every package entry past the host's last one, and a host could never
     * remove a default.
     */
    protected function mergePublishedConfigOverDefaults(): void
    {
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return;
        }

        $config = $this->app->make('config');

        $config->set('ranetrace', self::mergeOverDefaults(
            require __DIR__.'/../config/ranetrace.php',
            (array) $config->get('ranetrace', []),
        ));
    }

    /**
     * Auto-register the package's log channels so the host application gets
     * zero-config diagnostics and centralized logging.
     *
     * The user-facing `ranetrace` channel is never overwritten: if the host
     * defines its own `ranetrace` channel, that definition always wins. The
     * internal `ranetrace_internal` channel is package-owned and is always
     * (re)set from `ranetrace.internal_logging.*`, so it cannot be overridden
     * via `config/logging.php`. Tune it through those config keys instead.
     */
    protected function registerLogChannels(): void
    {
        $this->app['config']->set('logging.channels.ranetrace_internal', [
            'driver' => 'daily',
            'path' => storage_path('logs/ranetrace-internal.log'),
            'level' => config('ranetrace.internal_logging.level', 'debug'),
            'days' => config('ranetrace.internal_logging.days', 14),
        ]);

        // Define the user-facing `ranetrace` channel unconditionally. The
        // `ranetrace` log driver is always extended, and the handler
        // short-circuits when logging is disabled, so a defined-but-inactive
        // channel is inert. Registering it regardless of the enabled flag keeps
        // a committed `config/logging.php` stack that references `ranetrace`
        // valid in every environment, including local or dev where logging is
        // turned off, instead of throwing "Log [ranetrace] is not defined".
        if (! $this->app['config']->has('logging.channels.ranetrace')) {
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

    /**
     * Mount the human-verification beacon endpoint.
     *
     * Registered whenever website analytics is on, not only when the beacon is,
     * so a beacon fired from a page rendered before the flag was turned off is
     * answered with a clean 403 by the controller rather than a 404 that reads
     * as a broken install.
     */
    protected function registerAnalyticsBeaconRoute(): void
    {
        $throttle = config('ranetrace.website_analytics.beacon.throttle', '120,1');

        $this->app['router']
            ->post('ranetrace/analytics/verify', [AnalyticsBeaconController::class, 'verify'])
            ->middleware(['web', "throttle:{$throttle}"])
            ->name('ranetrace.analytics.verify');
    }

    protected function registerBladeDirectives(): void
    {
        Blade::directive('ranetraceErrorTracking', function () {
            return "<?php echo view('ranetrace::error-tracker')->render(); ?>";
        });
    }

    /**
     * @param  array<array-key, mixed>  $defaults
     * @param  array<array-key, mixed>  $published
     * @return array<array-key, mixed>
     */
    private static function mergeOverDefaults(array $defaults, array $published): array
    {
        foreach ($published as $key => $value) {
            $default = $defaults[$key] ?? null;

            $defaults[$key] = is_array($value) && is_array($default) && ! array_is_list($default)
                ? self::mergeOverDefaults($default, $value)
                : $value;
        }

        return $defaults;
    }
}
