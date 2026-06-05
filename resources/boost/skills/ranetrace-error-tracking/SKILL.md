---
name: ranetrace-error-tracking
description: Track, investigate, and manage application errors with Ranetrace, including MCP tools for AI-assisted debugging.
---

# Ranetrace Error Tracking

## When to use this skill

Use this skill when working with error tracking, exception reporting, error investigation, or managing error states in a Ranetrace-monitored Laravel application.

## Reporting Errors

Capturing unhandled exceptions is **required wiring — it is NOT automatic.** Register Ranetrace on Laravel's exception handler in `bootstrap/app.php` with the package's one-liner:

```php
use Illuminate\Foundation\Configuration\Exceptions;
use Ranetrace\Laravel\Facades\Ranetrace;

->withExceptions(function (Exceptions $exceptions) {
    Ranetrace::handles($exceptions);
})
```

`Ranetrace::handles()` registers a `reportable` callback and preserves Laravel's own default error logging. Without this line, unhandled exceptions are **not** captured (manual `Ranetrace::report()` calls still work).

Manual reporting, for in-flow capture:

```php
try {
    // risky operation
} catch (Throwable $e) {
    Ranetrace::report($e);
}
```

## What Gets Captured

Each error report includes:
- Exception message (key=value secrets redacted), type, file, and line number
- Stack trace (truncated to 5000 chars; key=value secrets redacted)
- Code snippet (5 lines before and after the error line; each line length-capped)
- HTTP request data (URL with sensitive query params redacted, method, allowlisted headers only — the client IP / `x-forwarded-for` is masked, not captured)
- Authenticated user ID (email only when `ranetrace.errors.capture_user_email` is enabled; off by default)
- PHP and Laravel versions
- Environment name
- Console command details (for CLI errors)

## Configuration

```php
// config/ranetrace.php
'errors' => [
    'enabled' => env('RANETRACE_ERRORS_ENABLED', true),
    'queue' => env('RANETRACE_ERRORS_QUEUE', true),       // async via queue
    'queue_name' => env('RANETRACE_ERRORS_QUEUE_NAME', 'default'),
    'timeout' => env('RANETRACE_ERRORS_TIMEOUT', 10),
],
```

## MCP Tools for Error Investigation

The Ranetrace MCP server provides 24 tools for AI-assisted error investigation. These are available when `laravel/mcp` is installed.

### Retrieving Errors

| Tool | Description |
|---|---|
| `LatestErrorsTool` | Fetch the most recent errors |
| `SearchErrorsTool` | Search errors with advanced filtering |
| `GetErrorTool` | Get full details of a specific error |
| `ErrorStatsTool` | Get error statistics and trends |
| `GetErrorActivityTool` | View the activity timeline for an error |

### Managing Error States

| Tool | Description |
|---|---|
| `ResolveErrorTool` | Mark an error as resolved |
| `ReopenErrorTool` | Reopen a previously resolved error |
| `IgnoreErrorTool` | Ignore an error (suppress future alerts) |
| `UnignoreErrorTool` | Stop ignoring an error |
| `SnoozeErrorTool` | Temporarily snooze an error |
| `UnsnoozeErrorTool` | Unsnooze a snoozed error |
| `DeleteErrorTool` | Soft-delete an error |
| `RestoreErrorTool` | Restore a deleted error |

### Bulk Operations

| Tool | Description |
|---|---|
| `BulkResolveErrorsTool` | Resolve multiple errors at once |
| `BulkReopenErrorsTool` | Reopen multiple errors |
| `BulkIgnoreErrorsTool` | Ignore multiple errors |
| `BulkDeleteErrorsTool` | Delete multiple errors |
| `BulkRestoreErrorsTool` | Restore multiple deleted errors |

### Investigation Notes

| Tool | Description |
|---|---|
| `CreateNoteTool` | Add a note to an error |
| `CreateNotesTool` | Add multiple notes at once |
| `ListNotesTool` | List all notes on an error |
| `GetNoteTool` | Get a specific note |
| `UpdateNoteTool` | Update a note |
| `DeleteNoteTool` | Delete a note |

## Testing

```bash
php artisan ranetrace:test-errors
```
