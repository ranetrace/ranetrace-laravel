---
name: ranetrace-javascript-errors
description: Capture client-side JavaScript errors with breadcrumbs, deduplication, and sampling using Ranetrace's Blade directive.
---

# Ranetrace JavaScript Error Tracking

## When to use this skill

Use this skill when setting up client-side JavaScript error tracking, configuring error filtering, or using the manual JavaScript error capture API.

## Setup

### 1. Enable JavaScript error tracking

```env
RANETRACE_JAVASCRIPT_ERRORS_ENABLED=true
```

### 2. Add the Blade directive

Place `@ranetraceErrorTracking` in your layout, just before the closing `</body>` tag:

```blade
<!DOCTYPE html>
<html>
<head>...</head>
<body>
    {{ $slot }}

    @ranetraceErrorTracking
</body>
</html>
```

The directive injects a self-contained script that automatically captures errors. No npm packages or build steps required.

## What Gets Captured Automatically

- **Global errors** via `window.onerror`
- **Unhandled promise rejections**
- **Console errors** (optional, off by default)
- **Breadcrumbs** for debugging context:
  - Page navigation
  - User clicks (tag, id, class, text)
  - Form submissions
  - XHR and fetch requests (method, URL, status)

Each error report includes browser info (screen size, viewport, device memory, connection type) and the current URL.

## Manual Error Capture

The script exposes a global API for manual error tracking:

```javascript
// Capture a caught error
try {
    riskyOperation();
} catch (error) {
    window.Ranetrace.captureError(error, {
        component: 'PaymentForm',
        action: 'submit',
    });
}

// Add a custom breadcrumb
window.Ranetrace.addBreadcrumb('custom', 'User selected plan', {
    plan: 'premium',
    price: 29.99,
});
```

## Configuration

```php
// config/ranetrace.php
'javascript_errors' => [
    'enabled' => env('RANETRACE_JAVASCRIPT_ERRORS_ENABLED', false),
    'queue' => env('RANETRACE_JAVASCRIPT_ERRORS_QUEUE', true),
    'queue_name' => env('RANETRACE_JAVASCRIPT_ERRORS_QUEUE_NAME', 'default'),
    'timeout' => env('RANETRACE_JAVASCRIPT_ERRORS_TIMEOUT', 10),
    'sample_rate' => env('RANETRACE_JAVASCRIPT_ERRORS_SAMPLE_RATE', 1.0),
    'capture_console_errors' => env('RANETRACE_JAVASCRIPT_CAPTURE_CONSOLE_ERRORS', false),
    'max_breadcrumbs' => env('RANETRACE_JAVASCRIPT_MAX_BREADCRUMBS', 20),
    'ignored_errors' => [
        'ResizeObserver loop limit exceeded',
        'ResizeObserver loop completed with undelivered notifications',
        'Script error.',
        'Script error',
        'Failed to fetch',
        'NetworkError when attempting to fetch resource',
        'Network request failed',
        'Load failed',
        'Loading chunk',
        'ChunkLoadError',
        'cancelled',
        'canceled',
        'The operation was aborted',
        'AbortError',
        'Illegal invocation',
    ],
],
```

### Key Options

- **`sample_rate`** — `1.0` captures 100% of errors, `0.1` captures 10%. Useful for high-traffic sites.
- **`capture_console_errors`** — When `true`, intercepts `console.error()` calls and reports them.
- **`max_breadcrumbs`** — Maximum number of breadcrumbs stored per error (default: 20).
- **`ignored_errors`** — Error messages containing any of these strings are silently dropped. Add your own patterns as needed.

## Endpoint & Throttling

Errors are sent to `POST /ranetrace/js-errors`, which is rate-limited to 60 requests per minute per IP. The route is auto-registered with `web` middleware when JS error tracking is enabled.

Client-side deduplication prevents the same error (same message + file + line + column) from being sent more than once per page session.

## Testing

```bash
php artisan ranetrace:test-javascript-errors
```
