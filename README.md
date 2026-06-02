# Ranetrace: Web Application Monitoring for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/ranetrace/ranetrace-laravel.svg?style=flat-square)](https://packagist.org/packages/ranetrace/ranetrace-laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/ranetrace/ranetrace-laravel.svg?style=flat-square)](https://packagist.org/packages/ranetrace/ranetrace-laravel)

Ranetrace is an all-in-one tool for **Error Tracking**, **Website Analytics**, and **Website Monitoring** for Laravel applications.

- Alerts you about errors and provides the context you need to fix them
- Privacy-first, fully server-side website analytics — no cookies, no fingerprinting, no intrusive scripts
- Monitors uptime, performance, SSL certificates, domain and DNS status, Lighthouse scores, and broken links

Check out the [Ranetrace website](https://ranetrace.com) for more information.

## Installation

Install the package via Composer:

```bash
composer require ranetrace/ranetrace-laravel
```

Add your Ranetrace key to `.env`:

```env
RANETRACE_KEY=your-key-here
```

Optionally publish the config file:

```bash
php artisan vendor:publish --tag="ranetrace-laravel-config"
```

### Schedule the work command

Captured items (errors, events, logs, page visits, JS errors) are buffered locally and sent to Ranetrace in batches by the `ranetrace:work` artisan command. Add it to your scheduler:

```php
// In your scheduler (routes/console.php on Laravel 11+, or app/Console/Kernel.php on Laravel 10)
Schedule::command('ranetrace:work')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
```

> **Don't skip this step.** Without it, buffered telemetry never reaches Ranetrace. Run `php artisan ranetrace:status` at any time to verify health and see whether buffers are draining.

## Usage

### Error Tracking

Wire Ranetrace into Laravel's exception handling in `bootstrap/app.php`:

```php
use Illuminate\Foundation\Configuration\Exceptions;
use Ranetrace\Laravel\Facades\Ranetrace;

return Application::configure(basePath: dirname(__DIR__))
    // ...
    ->withExceptions(function (Exceptions $exceptions) {
        Ranetrace::handles($exceptions);
    })
    ->create();
```

That's it — every unhandled exception is now reported to your Ranetrace dashboard (alongside Laravel's normal logging). You can also capture exceptions in-flow:

```php
use Ranetrace\Laravel\Facades\Ranetrace;

try {
    // ...
} catch (Throwable $e) {
    Ranetrace::report($e);
    throw $e;
}
```

Test your setup with:

```bash
php artisan ranetrace:test-errors
```

### JavaScript Error Tracking

1. Enable it in your `.env`:

```env
RANETRACE_JAVASCRIPT_ERRORS_ENABLED=true
```

2. Add the Blade directive to your layout:

```blade
<body>
    @yield('content')

    @ranetraceErrorTracking
</body>
```

The directive injects a small script that captures `window.onerror`, unhandled promise rejections, and (optionally) `console.error` calls. It also collects breadcrumbs for clicks, form submissions, and XHR/fetch activity to give you context around each error.

You can also capture errors manually:

```javascript
window.Ranetrace.captureError(error, { payment_amount: amount });
```

### Event Tracking

Track custom events with a privacy-first approach — no IP addresses are stored, user agents are hashed, and session IDs rotate daily.

```php
use Ranetrace\Laravel\Facades\Ranetrace;

Ranetrace::trackEvent('button_clicked', [
    'button_id' => 'header-cta',
    'page' => 'homepage'
]);
```

E-commerce helpers are available via the `RanetraceEvents` facade:

```php
use Ranetrace\Laravel\Facades\RanetraceEvents;

RanetraceEvents::sale(
    orderId: 'ORDER-456',
    totalAmount: 89.97,
    products: [['id' => 'PROD-123', 'name' => 'Widget', 'price' => 29.99, 'quantity' => 3]],
    currency: 'USD'
);
```

Test your setup with:

```bash
php artisan ranetrace:test-events
```

### Centralized Logging

Enable it in your `.env`:

```env
RANETRACE_LOGGING_ENABLED=true
```

The package auto-registers a `ranetrace` log channel — no `config/logging.php` edit is required. Add it to your existing log stack so application logs are routed to both your normal destination AND Ranetrace:

```php
// config/logging.php — example stacked channel
'channels' => [
    'production' => [
        'driver' => 'stack',
        'channels' => array_merge(explode(',', env('LOG_STACK', 'single')), ['ranetrace']),
        'ignore_exceptions' => false,
    ],
],
```

Then point Laravel at it:

```env
LOG_CHANNEL=production
```

By default the package captures `notice` and above. Tune via `RANETRACE_LOGGING_LEVEL`.

Test your setup with:

```bash
php artisan ranetrace:test-logging
```

### Website Analytics

Enable it in your `.env`:

```env
RANETRACE_WEBSITE_ANALYTICS_ENABLED=true
```

The `TrackPageVisit` middleware is automatically added to the `web` middleware group. It applies extensive bot and crawler filtering before sending visits to your Ranetrace dashboard. No code changes needed.

See the [Ranetrace website](https://ranetrace.com) for dashboard setup and configuration details.

## Health Check

At any time, see what the package is doing:

```bash
php artisan ranetrace:status
```

Reports overall health, configured features, buffer sizes, pause states (if the API has rate-limited you), and recent failed jobs — both as formatted output and via `--json` for monitoring integrations.

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Ranetrace](https://github.com/ranetrace)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
