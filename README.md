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

## Usage

### Error Tracking

Error tracking is enabled by default. Once installed, unhandled exceptions are automatically reported to your Ranetrace dashboard.

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

Send your application logs to Ranetrace by adding the driver to `config/logging.php`:

```php
'channels' => [
    'ranetrace' => [
        'driver' => 'ranetrace',
        'level' => 'error',
    ],

    'production' => [
        'driver' => 'stack',
        'channels' => array_merge(explode(',', env('LOG_STACK', 'single')), ['ranetrace']),
        'ignore_exceptions' => false,
    ],
],
```

Then enable it in your `.env`:

```env
LOG_CHANNEL=production
RANETRACE_LOGGING_ENABLED=true
```

Test your setup with:

```bash
php artisan ranetrace:test-logging
```

### Website Analytics

Refer to the [Ranetrace website](https://ranetrace.com) for setup instructions.

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
