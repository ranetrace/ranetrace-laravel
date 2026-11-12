---
name: ranetrace-worker
description: >-
  Set up, schedule and troubleshoot the Ranetrace worker (ranetrace:work) that
  flushes buffered errors, events, logs, analytics and JavaScript errors to the
  API. Use when buffers grow without draining, features show as paused, or
  nothing reaches Ranetrace.
---

# Ranetrace worker

## When to use this skill

Use this skill when setting up, scheduling, or troubleshooting the Ranetrace worker (`ranetrace:work`). This is the command that flushes all buffered data (errors, events, logs, page visits, JavaScript errors) to the Ranetrace API. Without it running on a schedule, no data leaves the application.

## Running the worker

```bash
# Process all feature types
php artisan ranetrace:work

# Process only a specific type
php artisan ranetrace:work --type=errors
php artisan ranetrace:work --type=events
php artisan ranetrace:work --type=logs
php artisan ranetrace:work --type=page_visits
php artisan ranetrace:work --type=javascript_errors
```

## Scheduling

The command must be scheduled in the application's `routes/console.php`. A one-minute interval is recommended:

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('ranetrace:work')->everyMinute()->withoutOverlapping(5)->runInBackground();
```

The `5` is the lock's expiry in minutes, and it matters. The scheduler removes the lock when a run finishes, but a run that is hard-killed (a deploy that stops the container, an out-of-memory kill, a lost machine) cannot remove it. Without an expiry the lock stays for Laravel's 24 hour default and every run in that time is skipped. The command only dispatches jobs and takes about a second, so five minutes is long enough.

A queue worker must also be running to process the dispatched batch jobs. [Laravel Horizon](https://laravel.com/docs/horizon) is recommended for managing queue workers in production:

```bash
php artisan horizon
```

Alternatively, use the built-in queue worker:

```bash
php artisan queue:work
```

Ranetrace's jobs go to the connection's default queue unless a queue name is set, so a plain worker drains them. If a custom queue name is configured via `RANETRACE_BATCH_QUEUE_NAME` (or a feature's own `RANETRACE_*_QUEUE_NAME`), a worker must listen on it:

```bash
php artisan queue:work --queue=ranetrace
```

## Configuration

```php
// config/ranetrace.php
'batch' => [
    'queue_name' => env('RANETRACE_BATCH_QUEUE_NAME', null), // unset: the connection's default queue
    // Defaults to your app's cache store (CACHE_STORE / CACHE_DRIVER, → file).
    // For production / multi-worker setups point this at a shared, lock-capable
    // store (redis, memcached, or database), never `array` (per-process).
    'cache_driver' => env('RANETRACE_BATCH_CACHE_DRIVER', env('CACHE_STORE', env('CACHE_DRIVER', 'file'))),
    'max_buffer_size' => env('RANETRACE_BATCH_MAX_BUFFER_SIZE', 5000),
],
```

| Env var | Description | Default |
|---|---|---|
| `RANETRACE_BATCH_QUEUE_NAME` | Queue name for batch jobs | unset: the connection's default queue |
| `RANETRACE_BATCH_CACHE_DRIVER` | Cache store for the buffer (use a shared, lock-capable store in production) | app cache store (`CACHE_STORE` → `file`) |
| `RANETRACE_BATCH_MAX_BUFFER_SIZE` | Max items per feature buffer before oldest are dropped | `5000` |

## Monitoring

```bash
# Check health status (buffer sizes, pauses, failed jobs, the schedule entry)
php artisan ranetrace:status
php artisan ranetrace:status --json

# Clear pauses (set automatically on API errors)
php artisan ranetrace:pause-clear --global
php artisan ranetrace:pause-clear --feature=errors
php artisan ranetrace:pause-clear --all
```

`healthy` in the `--json` output is false when an enabled installation has no ingest key, when everything is paused, when a buffer is at 80 percent of its capacity, when a drain is stalled (a buffer holds an item older than ten minutes and no batch of that type was sent in that time), when a paused feature has waited longer than its pause explains, or when ten or more Ranetrace jobs failed in the last 24 hours. A pause the API asked for, after a 403, 413, 422 or 429, excuses its feature for 30 minutes, measured the same way. A pause set after the batch job's own retries, after a network failure or a server error, excuses nothing beyond the ten minutes. In `--json`, `drain.stalled` lists the stalled features that are not paused, and `drain.paused_stalled` the paused ones that have waited too long. A stalled drain means captured data is not leaving the application.

`ranetrace:status` reads the schedule and lists each `ranetrace:work` entry with its overlap lock. The `--json` output carries it under `schedule`, whose `warnings` name what it found: `not_scheduled` (no entry, and no batch in the last ten minutes), `default_lock_expiry` (a lock with Laravel's 24 hour default) and `lock_stuck` (a lock held while the drain is stalled). Only the command can read the schedule; the web dashboard cannot.

## Troubleshooting

**Buffers growing but not draining:**
- Verify `ranetrace:work` is scheduled in `routes/console.php`: `ranetrace:status` says whether it found it in the schedule
- Verify a queue worker is running: `php artisan queue:work`
- Check for pauses: `php artisan ranetrace:status`
- Check the key when `ranetrace:work` fails with "RANETRACE_KEY is not set, so nothing is sent": without a key it dispatches nothing and lists what waits in the buffer. This happens when the key is removed, or missing from a cached config, after items were captured. Set `RANETRACE_KEY` and the next run sends them
- Check for a stuck scheduler lock when data stopped after a deploy, a restart or a crash: a schedule line whose `withoutOverlapping()` has no expiry keeps its lock for 24 hours after a hard-killed run. Release it with `php artisan schedule:clear-cache`, then change the line to `withoutOverlapping(5)`. `ranetrace:status` warns about both: a lock with the 24 hour default, and a lock that is held while buffered items wait unsent
- Nothing is lost by time alone: items wait in the buffer until they are sent, up to `RANETRACE_BATCH_MAX_BUFFER_SIZE` per feature, after which the oldest are dropped first. A cache store that evicts keys under memory pressure (Redis with an eviction `maxmemory-policy`, Memcached) can still drop a buffer, so use a lock-capable store with room to spare

**Features paused:**
- Check the reason in `ranetrace:status` output
- 401: verify `RANETRACE_KEY` in `.env` is valid
- 403: check subscription status and feature access
- 429: wait for auto-resume or reduce schedule frequency
- Clear manually: `php artisan ranetrace:pause-clear --feature=<type>`
- A paused feature whose buffer has waited longer than its pause explains (30 minutes after a 403, 413, 422 or 429, ten minutes after a pause that followed the retries) makes `healthy` false: `ranetrace:status` prints "Paused and not sending" and `--json` lists it under `drain.paused_stalled`. Fix the reason on its row, then let the pause end or clear it

**Cache driver not available:**
- The batch buffer uses the cache store set by `RANETRACE_BATCH_CACHE_DRIVER` (defaults to your app's cache store: `CACHE_STORE`/`CACHE_DRIVER`, i.e. `file` unless you've changed it)
- Set to null or left blank (`RANETRACE_BATCH_CACHE_DRIVER=`), it means the store `cache.default` names; `ranetrace:status` shows that store's name followed by "(the app's default store)"
- For production / multi-worker setups, point it at a shared, lock-capable store (`redis`, `memcached`, or `database`) and make sure that store is configured in `config/cache.php`
