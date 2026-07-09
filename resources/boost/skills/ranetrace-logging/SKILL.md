---
name: ranetrace-logging
description: Send application logs to Ranetrace via the auto-registered logging channel, and integrate it with Laravel's logging stack.
---

# Ranetrace Centralized Logging

## When to use this skill

Use this skill when setting up centralized logging to Ranetrace, configuring the Ranetrace log channel, or integrating it with Laravel's logging stack.

## Setup

### 1. Enable logging

```env
RANETRACE_LOGGING_ENABLED=true
```

That is all the wiring required. When logging is enabled the package
**auto-registers** a `ranetrace` log channel into your application's
`logging.channels` config at boot — you do **not** need to edit
`config/logging.php` to add a channel definition. The auto-registered channel is
equivalent to:

```php
'ranetrace' => [
    'driver' => 'ranetrace',
    'level' => 'notice', // from RANETRACE_LOGGING_LEVEL
],
```

If you have already defined your own `ranetrace` channel, that definition always
wins (see *Overriding the channel* below).

### 2. Send your logs to Ranetrace

The recommended setup is to route your whole application log to Ranetrace by
adding the `ranetrace` channel to your log stack. Everything your app already
logs is centralized, with no extra logging calls to write.

On a default Laravel install the stack is env-driven, so this is a one-line
change. Keep the channels you already stack and add `ranetrace`:

```env
LOG_STACK=single,ranetrace
```

If your app hard-codes its stack in `config/logging.php`, add `ranetrace` to
that channel's list instead:

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

To send only specific records instead of your whole log, write to the
`ranetrace` channel directly from anywhere in your app:

```php
use Illuminate\Support\Facades\Log;

Log::channel('ranetrace')->error('Payment processing failed', [
    'order_id' => $orderId,
    'error' => $exception->getMessage(),
]);
```

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

The channel is auto-registered only when you have not defined one yourself. To
customize it — e.g. a different minimum level — define `ranetrace` in
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
