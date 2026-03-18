---
name: ranetrace-logging
description: Send application logs to Ranetrace by configuring the centralized logging channel and integrating with Laravel's logging stack.
---

# Ranetrace Centralized Logging

## When to use this skill

Use this skill when setting up centralized logging to Ranetrace, configuring the Ranetrace log channel, or integrating it with Laravel's logging stack.

## Setup

### 1. Enable logging

```env
RANETRACE_LOGGING_ENABLED=true
```

### 2. Add the channel to `config/logging.php`

```php
'channels' => [
    // ... existing channels

    'ranetrace' => [
        'driver' => 'ranetrace',
        'level' => 'error',  // Minimum log level to send
    ],
],
```

### 3. Use the channel

```php
use Illuminate\Support\Facades\Log;

// Use directly
Log::channel('ranetrace')->error('Payment processing failed', [
    'order_id' => $orderId,
    'error' => $exception->getMessage(),
]);

// Or add to a stack channel
'channels' => [
    'stack' => [
        'driver' => 'stack',
        'channels' => ['daily', 'ranetrace'],
    ],
],
```

## Configuration

```php
// config/ranetrace.php
'logging' => [
    'enabled' => env('RANETRACE_LOGGING_ENABLED', false),
    'queue' => env('RANETRACE_LOGGING_QUEUE', true),
    'queue_name' => env('RANETRACE_LOGGING_QUEUE_NAME', 'default'),
    'timeout' => env('RANETRACE_LOGGING_TIMEOUT', 10),
    'excluded_channels' => [
        // Channels that should never be forwarded to Ranetrace
    ],
],
```

## Preventing Infinite Loops

When adding the Ranetrace channel to a stack, add the stack's name to `excluded_channels` to prevent log entries from recursively forwarding:

```php
'logging' => [
    'excluded_channels' => [
        'ranetrace',           // Prevent self-referencing
        'ranetrace_internal',  // Reserved for Ranetrace diagnostics
    ],
],
```

The channel name `ranetrace_internal` is reserved by Ranetrace for its own internal diagnostics and should never be used in application code.

## What Gets Sent

Each log entry includes:
- Log level (emergency, alert, critical, error, warning, notice, info, debug)
- Message (truncated to 50,000 characters)
- Context data (truncated to 50KB)
- Channel name
- ISO 8601 timestamp
- Extra metadata: environment, Laravel version, PHP version

## Log Driver Details

The `ranetrace` driver uses a custom Monolog handler (`RanetraceLogHandler`) that:
- Respects the `level` config to filter which log levels are sent
- Supports the `bubble` config for Monolog handler chaining
- Sanitizes context data to handle non-serializable objects (closures, resources)
- Dispatches logs via queue by default for zero-impact on request performance

## Testing

```bash
php artisan ranetrace:test-logging
```
