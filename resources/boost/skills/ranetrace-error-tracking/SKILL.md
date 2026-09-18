---
name: ranetrace-error-tracking
description: Track, investigate, and manage application errors with Ranetrace, and work with the hosted Ranetrace MCP server, including connecting an MCP client over OAuth and fixing a connection that fails, the tools for AI-assisted debugging, the search_tools and execute_tools flow for state changes, the monitored website's verdicts (uptime, performance, Lighthouse, certificate, domain, broken links) and the notification rules.
---

# Ranetrace Error Tracking

## When to use this skill

Use this skill when working with error tracking, exception reporting, error investigation, or managing error states in a Ranetrace-monitored Laravel application. It is also the reference for the hosted MCP server: connecting a client, what a read-only or a write connection can do, the monitor tools and the notification rules.

## Reporting Errors

Capturing unhandled exceptions is **required wiring: it is NOT automatic.** Register Ranetrace on Laravel's exception handler in `bootstrap/app.php` with the package's one-liner:

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
- HTTP request data (URL with sensitive query params redacted, method, allowlisted headers only; the client IP / `x-forwarded-for` is masked, not captured)
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

Ranetrace hosts an MCP server covering error investigation, investigation notes, error state management, the monitored website's verdicts (see *Monitor tools* at the end) and the owner's notification rules. It runs on Ranetrace, so there is nothing to install in the application and nothing to keep running.

The tables below name each tool by its class. On the wire the name is kebab-cased: `SearchErrorsTool` is `search-errors-tool`, `GetMonitorStatusTool` is `get-monitor-status-tool`.

### Connecting: the client asks, the user approves

Any MCP client that supports OAuth connects with no pre-shared secret. Give it the server URL; it registers itself, opens the user's browser at Ranetrace's approval screen, and comes back with a credential of its own:

```bash
claude mcp add --transport http ranetrace https://api.ranetrace.com/mcp
```

On claude.ai the same URL is added as a custom connector. Clients configured from a file, Cursor and VS Code among them, read the same server as an `mcpServers` entry, with no `Authorization` header because their own client runs the approval:

```json
{
  "mcpServers": {
    "ranetrace": {
      "type": "http",
      "url": "https://api.ranetrace.com/mcp"
    }
  }
}
```

### What the approval decides

Two things, both chosen by the user and neither of them changeable from the agent's side:

- **One website.** The connection reaches that site and nothing else in the account.
- **Read or write.** Writes are off by default. A read-only connection lists the read tools and nothing else: no write tools, and no meta tools either, so there is no catalog for it to search and nothing to refuse at call time. That is why a tool below may simply not be there. Allowing write actions adds `search_tools` and `execute_tools` next to the reads, and the error state tools, the bulk tools, the note create/update/delete tools and the notification-rule update all run through those two.

Connections are listed and revoked by the user on the agent connections page in their Ranetrace account, at `/user/profile/connections`, which also carries the connect instructions. A machine with no browser can use the device authorization grant instead, entering the code it displays at `https://app.ranetrace.com/oauth/device`.

### Changing state: search, then execute

The read tools are listed directly in `tools/list`. The tools that change something are not listed at all. They sit in a tool catalog reached through two meta tools, which a write-enabled connection lists next to the reads:

- `search_tools` takes a `query` and an optional `limit` of 1 to 50. An empty query browses the whole catalog. It answers `{"ok":true,"tools":[{"name":...,"description":...,"inputSchema":...}],"hasMore":false}`.
- `execute_tools` takes `calls`, a list of `{"name":"<exact name from search_tools>","arguments":{...}}`, at most 10 per call. They run in order and stop at the first error. It answers `{"ok":true,"results":[{"name":...,"content":[...],"isError":false}]}`.

The catalogued names are kebab-cased the same way: the `ResolveErrorTool` in the tables below is `resolve-error-tool`. A direct `tools/call` of a catalogued name answers not found, so search first rather than guessing at a name. `execute_tools` re-checks the write permission, so it is not a way around a read-only connection either.

Resolving an error, end to end:

```json
// 1. search_tools arguments
{"query": "resolve error"}

// 2. what search_tools answers, shortened to the one match and its schema
{"ok":true,"tools":[{"name":"resolve-error-tool","description":"Mark an error as resolved. This is an idempotent operation - resolving an already resolved error succeeds silently.","inputSchema":{"type":"object","properties":{"error_id":{"type":"string","description":"The error ID (with or without err_ prefix)."},"type":{"type":"string","enum":["php","javascript","js"]}},"required":["error_id","type"]}}],"hasMore":false}

// 3. execute_tools arguments, using that exact name
{"calls":[{"name":"resolve-error-tool","arguments":{"error_id":"err_123","type":"php"}}]}
```

### MCP tokens are retired

Before connections, the tools authenticated with a static MCP token sent as a bearer header. Those tokens are no longer accepted: a client still sending one gets a 401 with `error_code: MCP_OAUTH_REQUIRED` and instructions to remove the header and reconnect over OAuth, as above.

The MCP credential is never `RANETRACE_KEY` and never lives in `.env`. The key writes captured telemetry in and lives on every server; the MCP credential reads data back out and belongs on the machine running the MCP client. An ingest key, or an old MCP token, sent to an MCP endpoint returns that same 401 with `error_code: MCP_OAUTH_REQUIRED`, and every tool surfaces it as instructions rather than a generic failure.

An application with `RANETRACE_MCP_TOKEN` in `.env` is on a retired setup: there is no local MCP server to run. Point the client at the hosted URL above, approve the connection in the browser, and delete the variable from `.env`.

### Retrieving Errors

Listed directly, so call these by name.

| Tool | Description |
|---|---|
| `LatestErrorsTool` | Fetch the most recent errors |
| `SearchErrorsTool` | Search errors with advanced filtering, and by free text: `query` matches a phrase in the message, a class name or a file path |
| `GetErrorTool` | Get full details of a specific error |
| `ErrorStatsTool` | Get error statistics and trends |
| `GetErrorActivityTool` | View the activity timeline for an error |

To find errors that mention a phrase, or that come from one class or file, pass it as `query` to `SearchErrorsTool` instead of paging through results and filtering them yourself. The match is a case-insensitive substring, at most 200 characters, and `%` and `_` are literal. For a PHP error the message is the one of its latest occurrence; for a JavaScript error the page URL is matched too. `query` combines with every other filter, so `query` plus `sort=last_occurred` also answers "the latest errors that mention this".

### Managing Error States

In the catalog, so find them with `search_tools` and run them with `execute_tools`.

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

In the catalog too, same route in.

| Tool | Description |
|---|---|
| `BulkResolveErrorsTool` | Resolve multiple errors at once |
| `BulkReopenErrorsTool` | Reopen multiple errors |
| `BulkIgnoreErrorsTool` | Ignore multiple errors |
| `BulkDeleteErrorsTool` | Delete multiple errors |
| `BulkRestoreErrorsTool` | Restore multiple deleted errors |

### Investigation Notes

Reading notes is listed directly; writing them is in the catalog.

| Tool | Description | Where |
|---|---|---|
| `ListNotesTool` | List all notes on an error | Listed |
| `GetNoteTool` | Get a specific note | Listed |
| `CreateNoteTool` | Add a note to an error | Catalog |
| `CreateNotesTool` | Add multiple notes at once | Catalog |
| `UpdateNoteTool` | Update a note | Catalog |
| `DeleteNoteTool` | Delete a note | Catalog |

## Monitor Tools

The same MCP server also answers for the website being monitored, not only the application's errors. These are reads, so they are all listed directly.

| Tool | Description |
|---|---|
| `GetMonitorStatusTool` | Which of my monitors needs a look: every enabled monitor with its verdict |
| `GetUptimeStatusTool` | Up or down, 24h uptime, and the recent outages |
| `GetPerformanceStatsTool` | 24h average response time and where that time goes |
| `GetLighthouseAuditTool` | Latest Lighthouse scores, metrics, trend, and ranked opportunities |
| `GetCertificateStatusTool` | HTTPS, issuer, validity window, days until expiry |
| `GetDomainStatusTool` | Registrar, expiry, DNSSEC, registrar locks |
| `GetBrokenLinksTool` | Broken links from the latest site audit, with the page each was found on; filterable and paged |

The connection already scopes every call to one website, so none of them takes parameters, with one exception. A list of broken links can be longer than one answer should be, so `GetBrokenLinksTool` lists 100 links per call and takes three optional parameters: `status_code` (only links that answered with this HTTP status, 0 for a link that gave no HTTP answer), `source_page` (only links found on this page URL) and `cursor` (the next-cursor value of the previous answer, to walk the rest of the list with the same filters). The filters narrow the list only: the verdict and the counts always describe the whole audit.

Each answers **verdict first**: what we found, why it matters, what to do, the same guidance a human reads on the dashboard, with the raw measurements following as its evidence. Read the verdict before the numbers, and pass its wording on rather than re-deriving your own conclusion from the data. A monitor that is switched off answers 409 `MONITOR_DISABLED` instead of returning stale figures, and the tool surfaces that message as-is.

## Notification rules

| Tool | Description | Where |
|---|---|---|
| `GetNotificationRulesTool` | The owner's notification rules, verdict first: it flags when a key alert such as website down is switched off | Listed |
| `UpdateNotificationRulesTool` | Change those rules; they are account-wide, so a change made through one website's connection affects every website the owner has | Catalog |

## Testing

```bash
php artisan ranetrace:test-errors
```
