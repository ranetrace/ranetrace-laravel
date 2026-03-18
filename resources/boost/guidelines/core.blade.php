## Ranetrace

Ranetrace is an all-in-one monitoring package for Laravel providing error tracking, website analytics, event tracking, centralized logging, and JavaScript error tracking.

### Setup

- Config: `config/ranetrace.php` (publish with `php artisan vendor:publish --tag=ranetrace-config`)
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

### Key Facades

- `Ranetrace` — error reporting (`Ranetrace::report($exception)`) and event tracking (`Ranetrace::trackEvent('event_name', $properties)`)
- `RanetraceEvents` — convenience methods for common events (sales, user registration, etc.)

### Middleware

The `TrackPageVisit` middleware is auto-registered on the `web` middleware group when analytics is enabled. It provides privacy-first analytics with no cookies or fingerprinting.

### Blade Directive

Add `@ranetraceErrorTracking` before `</body>` to enable client-side JavaScript error tracking.

### Queue & Batch Processing

All features use queue-based processing by default with Redis-backed batch buffering. Run the batch worker:

@verbatim
<code-snippet name="Run the Ranetrace batch worker" lang="bash">
php artisan ranetrace:work
</code-snippet>
@endverbatim

### Logging Channel

To send logs to Ranetrace, add a channel to `config/logging.php`:

@verbatim
<code-snippet name="Ranetrace logging channel configuration" lang="php">
'ranetrace' => [
    'driver' => 'ranetrace',
    'level' => 'error',
],
</code-snippet>
@endverbatim

Then add `'ranetrace'` to your stack channels or use it directly via `Log::channel('ranetrace')`.

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
- Batch processing requires Redis as the cache driver (`RANETRACE_BATCH_CACHE_DRIVER=redis`).
- The logging channel name `ranetrace_internal` is reserved for internal diagnostics — do not use it in your application.
- When adding the Ranetrace logging channel to a stack, add it to the `excluded_channels` config to prevent infinite loops.
