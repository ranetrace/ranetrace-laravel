## Ranetrace

Ranetrace is an all-in-one monitoring package for Laravel providing error tracking, website analytics, event tracking, centralized logging, and JavaScript error tracking.

### Setup

- Config: `config/ranetrace.php` (publish with `php artisan vendor:publish --tag=ranetrace-laravel-config`)
- All env vars are prefixed with `RANETRACE_`
- Required: set `RANETRACE_KEY` and `RANETRACE_ENABLED=true` in `.env`
- Each feature has its own `enabled` toggle and can run via queue or synchronously

### Features & Env Vars

| Feature | Enable Env Var | Default |
|---|---|---|
| Error Tracking | `RANETRACE_ERRORS_ENABLED` | `true` |
| Event Tracking | `RANETRACE_EVENTS_ENABLED` | `true` |
| Centralized Logging | `RANETRACE_LOGGING_ENABLED` | `false` |
| Website Analytics | `RANETRACE_WEBSITE_ANALYTICS_ENABLED` | `false` |
| JavaScript Errors | `RANETRACE_JAVASCRIPT_ERRORS_ENABLED` | `false` |

### Error Tracking

Capturing unhandled exceptions is **required wiring — it is NOT automatic.** Register Ranetrace on Laravel's exception handler in `bootstrap/app.php`:

@verbatim
<code-snippet name="Wire Ranetrace into the exception handler" lang="php">
use Illuminate\Foundation\Configuration\Exceptions;
use Ranetrace\Laravel\Facades\Ranetrace;

->withExceptions(function (Exceptions $exceptions) {
    Ranetrace::handles($exceptions);
})
</code-snippet>
@endverbatim

Without this line, unhandled exceptions are NOT captured (though `Ranetrace::report($exception)` still works for in-flow calls). `Ranetrace::handles()` preserves Laravel's own default logging.

### Key Facades

- `Ranetrace` — error reporting (`Ranetrace::report($exception)`) and event tracking (`Ranetrace::trackEvent('event_name', $properties)`)
- `RanetraceEvents` — convenience methods for common events (sales, user registration, etc.)

### Middleware

The `TrackPageVisit` middleware is auto-registered on the `web` middleware group when analytics is enabled. Analytics is privacy-first: no cookies and no client-side scripts. Visitors are identified only by salted, one-way HMAC hashes (a user-agent hash and a daily-rotating session-id hash) — never raw identifiers, and never across sites.

### Blade Directive

Add `@ranetraceErrorTracking` before `</body>` to enable client-side JavaScript error tracking.

### Queue & Batch Processing

All features use queue-based processing by default. Captured items are buffered locally and sent to the API in batches by the batch worker:

@verbatim
<code-snippet name="Run the Ranetrace batch worker" lang="bash">
php artisan ranetrace:work
</code-snippet>
@endverbatim

### Logging Channel

The package **auto-registers** a `ranetrace` log channel when `RANETRACE_LOGGING_ENABLED=true` — no `config/logging.php` edit is required (a user-defined `ranetrace` channel always wins). Route application logs to Ranetrace by adding `'ranetrace'` to your log stack, or use it directly via `Log::channel('ranetrace')`. The default minimum level is `notice` (tune via `RANETRACE_LOGGING_LEVEL`).

### MCP Server

Ranetrace includes an MCP server (`ranetrace`) with 24 tools for error investigation, note management, and error state management. It is auto-registered when `laravel/mcp` is installed and `RANETRACE_KEY` is set.

### Testing

@verbatim
<code-snippet name="Test all Ranetrace features" lang="bash">
php artisan ranetrace:test
php artisan ranetrace:status
</code-snippet>
@endverbatim

Individual test commands: `ranetrace:test-errors`, `ranetrace:test-events`, `ranetrace:test-logging`, `ranetrace:test-analytics`, `ranetrace:test-javascript-errors`.

### Common Pitfalls

- Both `RANETRACE_ENABLED=true` and the feature-specific env var must be set for any feature to work.
- Error tracking requires the `Ranetrace::handles($exceptions)` wiring in `bootstrap/app.php` (see *Error Tracking* above) — without it, unhandled exceptions are not captured.
- Batch buffering uses your app's cache store by default (`RANETRACE_BATCH_CACHE_DRIVER`, falling back to `CACHE_STORE`/`CACHE_DRIVER` → `file`). For production / multi-worker setups, point it at a shared, lock-capable store (`redis`, `memcached`, or `database`) — avoid `array` (per-process).
- The logging channel name `ranetrace_internal` is reserved for internal diagnostics — do not use it in your application. Self-logging is handled internally (the package writes its own diagnostics to that separate channel), so you do NOT need to add anything to `excluded_channels` to prevent loops.
