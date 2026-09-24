## Ranetrace

Ranetrace is an all-in-one monitoring package for Laravel providing error tracking, website analytics, event tracking, centralized logging, and JavaScript error tracking.

### Setup

- Config: `config/ranetrace.php` (publish with `php artisan vendor:publish --tag=ranetrace-laravel-config`)
- All env vars are prefixed with `RANETRACE_`
- Required: set `RANETRACE_KEY` in `.env`; without it nothing is captured. `RANETRACE_ENABLED` is the master switch over every feature and defaults to `true`
- Each feature has its own `enabled` toggle and can run via queue or synchronously
- **`RANETRACE_KEY` is the ingest key and nothing else.** It writes captured telemetry in and belongs on every server, in `.env`. Reading data back out is the MCP server's job, and it uses its own credential, held by the MCP client rather than by the application: an OAuth connection the user approves in the browser. Sending the ingest key to an MCP endpoint returns a 401 with `error_code: MCP_OAUTH_REQUIRED`. Never put an MCP credential in `.env`.

### Features & Env Vars

| Feature | Enable Env Var | Default |
|---|---|---|
| Error Tracking | `RANETRACE_ERRORS_ENABLED` | `true` |
| Event Tracking | `RANETRACE_EVENTS_ENABLED` | `true` |
| Centralized Logging | `RANETRACE_LOGGING_ENABLED` | `false` |
| Website Analytics | `RANETRACE_WEBSITE_ANALYTICS_ENABLED` | `false` |
| JavaScript Errors | `RANETRACE_JAVASCRIPT_ERRORS_ENABLED` | `false` |

### Error Tracking

Capturing unhandled exceptions is **required wiring: it is NOT automatic.** Register Ranetrace on Laravel's exception handler in `bootstrap/app.php`:

@verbatim
<code-snippet name="Wire Ranetrace into the exception handler" lang="php">
use Illuminate\Foundation\Configuration\Exceptions;
use Ranetrace\Laravel\Facades\Ranetrace;

->withExceptions(function (Exceptions $exceptions) {
    Ranetrace::handles($exceptions);
})
</code-snippet>
@endverbatim

`Ranetrace::report($exception)` works for in-flow calls without this line, and `Ranetrace::handles()` preserves Laravel's own default logging.

### Key Facades

- `Ranetrace`: error reporting (`Ranetrace::report($exception)`) and event tracking (`Ranetrace::trackEvent('event_name', $properties)`)
- `RanetraceEvents`: convenience methods for common events (sales, user registration, etc.)

### Middleware

The `TrackPageVisit` middleware is auto-registered on the `web` middleware group when analytics is enabled. Analytics is privacy-first: no cookies, no fingerprinting, no consent banner, and an optional beacon that sends one opaque token and nothing else. Visitors are identified only by salted, one-way HMAC hashes, never raw identifiers and never across sites.

The human-verification beacon is opt-in (`RANETRACE_WEBSITE_ANALYTICS_BEACON_ENABLED=true`). It requires a real queue connection, because the visit is held for a few seconds to wait for the beacon and a connection that runs jobs at once (`sync`, `deferred`, `background`) cannot hold it, and it must stay off behind a full-page cache, which would serve one token to many visitors. For how the beacon works, bot detection and request filters, activate the `ranetrace-analytics` skill.

### Blade Directive

Add `@ranetraceErrorTracking` before `</body>`. It enables client-side JavaScript error tracking and, when the analytics beacon is on, renders the human-verification beacon as well, so installing both stays one line.

### Queue & Batch Processing

All features use queue-based processing by default. Captured items are buffered in the cache store and sent to the API in batches by `ranetrace:work`, which the package does not schedule for you: until it runs on the scheduler, with a queue worker processing the jobs it dispatches, nothing leaves the application. Schedule it every minute in `routes/console.php`:

@verbatim
<code-snippet name="Schedule the Ranetrace batch worker" lang="php">
Schedule::command('ranetrace:work')->everyMinute()->withoutOverlapping()->runInBackground();
</code-snippet>
@endverbatim

For queue names, pauses and a buffer that does not drain, activate the `ranetrace-worker` skill.

### Logging Channel

The package **always registers** a `ranetrace` log channel, regardless of the feature flag, so no `config/logging.php` edit is required to define it (a user-defined `ranetrace` channel still wins). The channel is inert while logging is disabled: records short-circuit at the handler, so adding `'ranetrace'` to a committed log stack is safe in every environment. Route application logs to Ranetrace by adding `'ranetrace'` to your log stack, or use it directly via `Log::channel('ranetrace')`. Whether records are actually sent is what `RANETRACE_LOGGING_ENABLED` controls. The default minimum level is `notice` (tune via `RANETRACE_LOGGING_LEVEL`).

### MCP Server

Ranetrace hosts an MCP server for errors, investigation notes, error state, the monitored website's monitor verdicts and the owner's notification rules. It runs on Ranetrace, so there is nothing to install or run in the application. Connect an MCP client that supports OAuth to `https://api.ranetrace.com/mcp`. At the approval screen the user picks one website and whether the agent may write. No token goes in `.env`: an application with `RANETRACE_MCP_TOKEN` there is on a retired setup, so delete the variable. For the tools, the `search_tools` and `execute_tools` flow for state changes, and connection problems, activate the `ranetrace-error-tracking` skill.

### Testing

@verbatim
<code-snippet name="Test all Ranetrace features" lang="bash">
php artisan ranetrace:test
php artisan ranetrace:status
</code-snippet>
@endverbatim

Individual test commands: `ranetrace:test-errors`, `ranetrace:test-events`, `ranetrace:test-logging`, `ranetrace:test-analytics`, `ranetrace:test-javascript-errors`.

### Common Pitfalls

- A feature runs only while the master switch `RANETRACE_ENABLED` and its own flag are both on. Errors and events are on by default; logging, analytics and JavaScript errors stay off until their flag is set to `true`.
- Error tracking requires the `Ranetrace::handles($exceptions)` wiring in `bootstrap/app.php` (see *Error Tracking* above). Without it, unhandled exceptions are not captured.
- Batch buffering uses your app's cache store by default (`RANETRACE_BATCH_CACHE_DRIVER`, falling back to `CACHE_STORE`/`CACHE_DRIVER` → `file`). For production / multi-worker setups, point it at a shared, lock-capable store (`redis`, `memcached`, or `database`), never `array` (per-process).
- The logging channel name `ranetrace_internal` is reserved for internal diagnostics: do not use it in your application. Self-logging is handled internally (the package writes its own diagnostics to that separate channel), so you do NOT need to add anything to `excluded_channels` to prevent loops.
