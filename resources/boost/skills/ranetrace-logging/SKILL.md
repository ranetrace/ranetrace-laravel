---
name: ranetrace-logging
description: Send application logs to Ranetrace via the auto-registered logging channel, and integrate it with Laravel's logging stack.
---

# Ranetrace Centralized Logging

## When to use this skill

Use this skill when setting up centralized logging to Ranetrace, configuring the Ranetrace log channel, or integrating it with Laravel's logging stack.

## Setup

Setup splits into two parts: **wiring in your code** (it deploys with your app)
and a **switch you flip in production** (where logging actually runs). An AI
agent or a local dev can do the wiring; the production switch is a deployment
step a human sets in the host's environment.

### 1. Wire the channel into your log stack

Route your whole application log to Ranetrace by adding the `ranetrace` channel
to your stack in `config/logging.php`. This is code, so it deploys to every
environment:

```php
// config/logging.php
'channels' => [
    'stack' => [
        'driver' => 'stack',
        'channels' => ['single', 'ranetrace'],
        'ignore_exceptions' => false,
    ],
],
```

The package registers the `ranetrace` channel for you, so this stack entry is
valid everywhere. Where logging is turned off the channel is inert: records
short-circuit at the handler and nothing is sent, so the stack is safe in every
environment even though it is only active in production. If you have defined your own
`ranetrace` channel, that definition always wins (see *Overriding the channel*).

To forward only specific records instead of your whole log, skip the stack and
write to the channel directly:

```php
use Illuminate\Support\Facades\Log;

Log::channel('ranetrace')->error('Payment processing failed', [
    'order_id' => $orderId,
    'error' => $exception->getMessage(),
]);
```

### 2. Turn it on in production

Logging only does something once it is enabled, and it belongs in production, not
local development. Set these in your **production** environment, not just a local
`.env`:

```env
RANETRACE_ENABLED=true
RANETRACE_LOGGING_ENABLED=true
```

Also set `RANETRACE_KEY` from your Ranetrace dashboard if it is not already
configured. Records at or above `notice` are forwarded; tune the threshold with
`RANETRACE_LOGGING_LEVEL`.

To verify the wiring before deploying, set the same flags in your local `.env`.
That ships your development logs to Ranetrace, so treat it as a one-off check
rather than a permanent local setting.

## Configuration

```php
// config/ranetrace.php
'logging' => [
    'enabled' => env('RANETRACE_LOGGING_ENABLED', false),
    'queue' => env('RANETRACE_LOGGING_QUEUE', true),
    'queue_name' => env('RANETRACE_LOGGING_QUEUE_NAME', 'default'),
    'timeout' => env('RANETRACE_LOGGING_TIMEOUT', 10),
    'level' => env('RANETRACE_LOGGING_LEVEL', 'notice'),
    'excluded_channels' => [
        // Record channels that should never be forwarded to Ranetrace
    ],
],
```

- `level` — minimum level the auto-registered channel captures (default `notice`).
- `excluded_channels` — record channel names to skip (matched against
  `$record->channel`). Use it to drop noisy or sensitive channels.

## Overriding the channel

The channel is registered for you only when you have not defined one yourself.
To customize it (for example a different minimum level), define `ranetrace` in
`config/logging.php` and your definition wins:

```php
'channels' => [
    'ranetrace' => [
        'driver' => 'ranetrace',
        'level' => 'warning',
    ],
],
```

## Self-logging is handled for you

Ranetrace writes its own diagnostics to a separate, package-owned
`ranetrace_internal` channel — never back through the `ranetrace` channel — so
capturing logs cannot create a feedback loop. You do **not** need to add anything
to `excluded_channels` to prevent self-referencing. (`ranetrace_internal` is
reserved for Ranetrace; do not use it in application code.)

## What gets sent

Each log entry includes:

- Log level (emergency, alert, critical, error, warning, notice, info, debug)
- Message (secrets redacted, then truncated to 50,000 characters)
- Context data (secrets redacted, then capped at 50KB)
- Channel name
- ISO 8601 timestamp
- Extra metadata: environment, Laravel version, PHP version

Values stored under sensitive keys (`password`, `token`, `api_key`, `secret`,
`authorization`, …) — and `key=value` secrets written into the message string —
are redacted to `[REDACTED]` before the entry is sent. Extend the sensitive-key
list via `ranetrace.scrubbing.extra_keys`. Scrubbing is defense-in-depth; avoid
deliberately logging secrets regardless.

## Log driver details

The `ranetrace` driver uses a custom Monolog handler (`RanetraceLogHandler`) that:

- Respects the `level` config to filter which log levels are sent
- Supports the `bubble` config for Monolog handler chaining
- Sanitizes context data to handle non-serializable objects (closures, resources)
- Is failure-isolated — it never throws back into your `Log::*()` call
- Dispatches logs to the queue by default, so transmission to the API happens off
  the request path

## Testing

```bash
php artisan ranetrace:test-logging
```
