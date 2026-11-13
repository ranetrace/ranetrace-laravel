<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Ranetrace\Laravel\Dashboard\DashboardData;
use Ranetrace\Laravel\Jobs\SendBatchToRanetraceJob;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;
use Ranetrace\Laravel\Services\RanetracePauseManager;

beforeEach(function (): void {
    Config::set('ranetrace.batch.cache_driver', 'array');
    Cache::store('array')->flush();
});

test('collectStatus returns the canonical status structure', function (): void {
    $status = app(DashboardData::class)->collectStatus();

    expect($status)
        ->toHaveKeys(['healthy', 'timestamp', 'pauses', 'buffers', 'drain', 'failed_jobs_last_24h', 'config'])
        ->and($status['healthy'])->toBeTrue()
        ->and($status['pauses'])->toHaveKeys(['global', 'features'])
        ->and($status['buffers'])->toHaveKeys(['total', 'max_per_feature', 'features'])
        ->and($status['buffers']['features'])->toHaveKeys(RanetraceBatchBuffer::TYPES)
        ->and($status['drain'])->toHaveKeys(['last_batch', 'stalled', 'paused_stalled'])
        ->and($status['config'])->toHaveKeys(['enabled', 'api_key_configured', 'cache_driver', 'queue_name', 'queue_landing', 'queue_connection'])
        ->and($status['config']['api_key_configured'])->toBeTrue();
});

test('the ingest key is reported as present without exposing its value', function (): void {
    Config::set('ranetrace.key', 'ingest-key');

    $config = app(DashboardData::class)->collectStatus()['config'];

    expect($config['api_key_configured'])->toBeTrue()
        ->and($config)->not->toContain('ingest-key');
});

test('no MCP surface is reported now the local server is gone', function (): void {
    // The MCP server is hosted by Ranetrace and its OAuth connection lives with
    // the MCP client, so this application registers nothing for it to introspect.
    $labels = collect(app(DashboardData::class)->registeredSurfaces())->pluck('label');

    expect($labels)->not->toContain('MCP server');
});

test('a numeric-string last-batch timestamp (Redis-style) counts as a recent drain', function (): void {
    // Redis and Memcached return bare numbers as numeric strings, not ints. A
    // buffer holding an overdue item must NOT be reported as stalled when such a
    // timestamp shows a recent successful drain, the production false-positive.
    $buffer = app(RanetraceBatchBuffer::class);
    $buffer->addItem('events', ['event_name' => 'e1']);

    // Age the item past the drain window, then record a *string* drain timestamp
    // for "now", exactly the shape a Redis store hands back.
    $this->travel(DashboardData::DRAIN_STALE_SECONDS + 1)->seconds();
    $now = now()->timestamp;
    Cache::store('array')->put(SendBatchToRanetraceJob::LAST_BATCH_PREFIX.'events', (string) $now, 3600);

    $status = app(DashboardData::class)->collectStatus();

    expect($status['drain']['last_batch']['events'])->toBe($now)
        ->and($status['drain']['stalled'])->not->toContain('events');
});

test('a buffer left alone for hours is still reported as stalled, with its items dated when they were captured', function (): void {
    // The worker stopped right after its last drain: nothing drains the buffer
    // and nothing writes to it, which is when a buffer that expires would vanish
    // and take the stalled signal with it.
    $this->freezeTime();
    $capturedAt = now()->timestamp;
    $buffer = app(RanetraceBatchBuffer::class);
    $buffer->addItems('events', [['event_name' => 'e1'], ['event_name' => 'e2']]);
    Cache::store('array')->put(SendBatchToRanetraceJob::LAST_BATCH_PREFIX.'events', $capturedAt, 3600);

    $this->travel(2)->hours();

    $status = app(DashboardData::class)->collectStatus();

    expect($status['drain']['stalled'])->toBe(['events'])
        ->and($status['buffers']['features']['events'])->toBe(2)
        ->and(now()->timestamp - $buffer->oldestTimestamp('events'))->toBe(2 * 3600);
});

test('the Ranetrace log channel surface reports wired even when logging is disabled', function (): void {
    // The channel is registered unconditionally (so a committed stack that
    // references `ranetrace` stays valid everywhere); the handler short-circuits
    // when disabled. The installation-truth surface must therefore report it as
    // wired regardless of the logging.enabled flag. `note` stays null because
    // `surface()` only surfaces a note when a check is NOT ok.
    $this->configOverrides = ['ranetrace.logging.enabled' => false];
    $this->reloadApplication();

    $surface = collect(app(DashboardData::class)->registeredSurfaces())
        ->firstWhere('label', 'Ranetrace log channel');

    expect($surface)->not->toBeNull()
        ->and($surface['ok'])->toBeTrue()
        ->and($surface['note'])->toBeNull();
});

test('ranetrace:status --json output is unchanged after the DashboardData extraction', function (): void {
    // Populate state across panels (a pause, a buffered item) so parity is
    // checked against a populated structure, not just an empty baseline.
    app(RanetracePauseManager::class)->setFeaturePause('errors', 900, '429');
    app(RanetraceBatchBuffer::class)->addItem('events', ['event_name' => 'e1']);

    // Freeze time so the command's `timestamp` and `time_remaining_seconds`
    // (both derived from now()) are identical to the direct service call below.
    $this->freezeTime();

    Artisan::call('ranetrace:status', ['--json' => true]);
    $commandJson = json_decode(mb_trim(Artisan::output()), true);

    $serviceData = app(DashboardData::class)->collectStatus();

    // The command adds the schedule, which only a console process can read.
    expect($commandJson)->toHaveKey('schedule');
    unset($commandJson['schedule']);

    expect($commandJson)->toEqual($serviceData)
        ->and($commandJson['drain'])->toHaveKeys(['last_batch', 'stalled', 'paused_stalled']);
});

test('an enabled installation with no ingest key is unhealthy', function (): void {
    Config::set('ranetrace.key', null);

    $status = app(DashboardData::class)->collectStatus();

    expect($status['healthy'])->toBeFalse()
        ->and($status['config']['enabled'])->toBeTrue()
        ->and($status['config']['api_key_configured'])->toBeFalse();
});

test('a disabled installation is healthy with or without an ingest key', function (?string $key): void {
    Config::set('ranetrace.enabled', false);
    Config::set('ranetrace.key', $key);

    $status = app(DashboardData::class)->collectStatus();

    expect($status['healthy'])->toBeTrue()
        ->and($status['config']['enabled'])->toBeFalse();
})->with([
    'without a key' => [null],
    'with a key' => ['ingest-key'],
]);

test('a missing ingest key does not hide the other reasons a disabled installation is unhealthy', function (): void {
    Config::set('ranetrace.enabled', false);
    Config::set('ranetrace.key', null);
    app(RanetracePauseManager::class)->setGlobalPause(900, '401');

    expect(app(DashboardData::class)->collectStatus()['healthy'])->toBeFalse();
});

test('a stalled drain makes the installation unhealthy, from the second the drain window has passed', function (): void {
    app(RanetraceBatchBuffer::class)->addItem('events', ['event_name' => 'e1']);

    $this->travel(DashboardData::DRAIN_STALE_SECONDS)->seconds();

    expect(app(DashboardData::class)->collectStatus()['healthy'])->toBeTrue();

    $this->travel(1)->seconds();
    $status = app(DashboardData::class)->collectStatus();

    expect($status['drain']['stalled'])->toBe(['events'])
        ->and($status['healthy'])->toBeFalse();
});

test('a stalled drain makes a disabled installation unhealthy too, since the worker still sends what was captured', function (): void {
    app(RanetraceBatchBuffer::class)->addItem('events', ['event_name' => 'e1']);
    Config::set('ranetrace.enabled', false);

    $this->travel(DashboardData::DRAIN_STALE_SECONDS + 1)->seconds();

    expect(app(DashboardData::class)->collectStatus()['healthy'])->toBeFalse();
});

test('a feature the API paused holding items past the drain window but inside two pauses is not stalled and stays healthy', function (): void {
    app(RanetracePauseManager::class)->setFeaturePause('events', 900, '429');
    app(RanetraceBatchBuffer::class)->addItem('events', ['event_name' => 'e1']);

    $this->travel(DashboardData::DRAIN_STALE_SECONDS + 1)->seconds();
    $status = app(DashboardData::class)->collectStatus();

    expect($status['drain']['stalled'])->toBe([])
        ->and($status['drain']['paused_stalled'])->toBe([])
        ->and($status['healthy'])->toBeTrue();
});

/**
 * Buffer one events item, let $seconds pass, then pause events for $reason, so
 * the pause is in force when the status is read, as it is when the API sets it
 * again on every attempt. With $lastBatchSecondsAgo, a successful events batch
 * is recorded that long before the read.
 */
function pauseEventsWithItemWaiting(string $reason, int $seconds, ?int $lastBatchSecondsAgo = null): void
{
    test()->freezeTime();
    app(RanetraceBatchBuffer::class)->addItem('events', ['event_name' => 'e1']);

    test()->travel($seconds)->seconds();
    app(RanetracePauseManager::class)->setFeaturePause('events', 900, $reason);

    if ($lastBatchSecondsAgo !== null) {
        Cache::store('array')->forever(SendBatchToRanetraceJob::LAST_BATCH_PREFIX.'events', now()->subSeconds($lastBatchSecondsAgo)->timestamp);
    }
}

dataset('pause reasons the API asks for', ['403', '413', '422', '429']);

dataset('pause reasons that follow the retries', [
    'network' => ['network'],
    'a server error' => ['500'],
    'an exception' => ['exception'],
    'an unknown reason' => ['418'],
]);

test('a pause the API asked for excuses its buffer until the oldest item is older than two pauses', function (string $reason): void {
    pauseEventsWithItemWaiting($reason, DashboardData::PAUSED_DRAIN_GRACE_SECONDS);

    $status = app(DashboardData::class)->collectStatus();

    expect($status['pauses']['features']['events']['paused'])->toBeTrue()
        ->and($status['drain']['paused_stalled'])->toBe([])
        ->and($status['healthy'])->toBeTrue();
})->with('pause reasons the API asks for');

test('a pause the API asked for no longer excuses a buffer whose oldest item is older than two pauses with no batch in that time', function (string $reason): void {
    pauseEventsWithItemWaiting($reason, DashboardData::PAUSED_DRAIN_GRACE_SECONDS + 1, lastBatchSecondsAgo: DashboardData::PAUSED_DRAIN_GRACE_SECONDS + 1);

    $status = app(DashboardData::class)->collectStatus();

    expect($status['drain']['paused_stalled'])->toBe(['events'])
        ->and($status['drain']['stalled'])->toBe([])
        ->and($status['healthy'])->toBeFalse();
})->with('pause reasons the API asks for');

test('a pause the API asked for still excuses old items while a batch was sent inside two pauses', function (string $reason): void {
    pauseEventsWithItemWaiting($reason, DashboardData::PAUSED_DRAIN_GRACE_SECONDS + 1, lastBatchSecondsAgo: DashboardData::PAUSED_DRAIN_GRACE_SECONDS);

    $status = app(DashboardData::class)->collectStatus();

    expect($status['drain']['paused_stalled'])->toBe([])
        ->and($status['healthy'])->toBeTrue();
})->with('pause reasons the API asks for');

test('a pause that follows the retries excuses nothing past the drain window', function (string $reason): void {
    pauseEventsWithItemWaiting($reason, DashboardData::DRAIN_STALE_SECONDS + 1);

    $status = app(DashboardData::class)->collectStatus();

    expect($status['pauses']['features']['events']['paused'])->toBeTrue()
        ->and($status['drain']['paused_stalled'])->toBe(['events'])
        ->and($status['drain']['stalled'])->toBe([])
        ->and($status['healthy'])->toBeFalse();
})->with('pause reasons that follow the retries');

test('a pause that follows the retries leaves the installation healthy inside the drain window', function (string $reason): void {
    pauseEventsWithItemWaiting($reason, DashboardData::DRAIN_STALE_SECONDS);

    $status = app(DashboardData::class)->collectStatus();

    expect($status['drain']['paused_stalled'])->toBe([])
        ->and($status['healthy'])->toBeTrue();
})->with('pause reasons that follow the retries');

test('a pause that follows the retries leaves old items healthy while a batch was sent inside the drain window', function (string $reason): void {
    pauseEventsWithItemWaiting($reason, DashboardData::DRAIN_STALE_SECONDS + 1, lastBatchSecondsAgo: DashboardData::DRAIN_STALE_SECONDS);

    $status = app(DashboardData::class)->collectStatus();

    expect($status['drain']['paused_stalled'])->toBe([])
        ->and($status['healthy'])->toBeTrue();
})->with('pause reasons that follow the retries');

test('an empty buffer under a feature pause of any reason is healthy', function (string $reason): void {
    $this->freezeTime();
    $this->travel(2)->hours();
    app(RanetracePauseManager::class)->setFeaturePause('events', 900, $reason);

    $status = app(DashboardData::class)->collectStatus();

    expect($status['drain']['paused_stalled'])->toBe([])
        ->and($status['drain']['stalled'])->toBe([])
        ->and($status['healthy'])->toBeTrue();
})->with(['403', '413', '422', '429', 'network', '500', 'exception', '418']);

test('under a global pause no feature is reported as stalled, paused or not, and the installation is unhealthy', function (string $reason): void {
    $this->freezeTime();
    $buffer = app(RanetraceBatchBuffer::class);
    $buffer->addItem('events', ['event_name' => 'e1']);
    $buffer->addItem('errors', ['message' => 'a']);

    $this->travel(2)->hours();
    $pauseManager = app(RanetracePauseManager::class);
    $pauseManager->setFeaturePause('events', 900, $reason);
    $pauseManager->setGlobalPause(900, '401');

    $status = app(DashboardData::class)->collectStatus();

    expect($status['drain']['stalled'])->toBe([])
        ->and($status['drain']['paused_stalled'])->toBe([])
        ->and($status['healthy'])->toBeFalse();
})->with(['403', 'network']);

test('a paused feature its pause no longer excuses makes a disabled installation unhealthy too', function (string $reason): void {
    Config::set('ranetrace.enabled', false);
    pauseEventsWithItemWaiting($reason, DashboardData::PAUSED_DRAIN_GRACE_SECONDS + 1);

    $status = app(DashboardData::class)->collectStatus();

    expect($status['drain']['paused_stalled'])->toBe(['events'])
        ->and($status['healthy'])->toBeFalse();
})->with(['403', 'network']);

test('a feature whose pause expired falls under the normal rule and counts as stalled, not paused', function (): void {
    $this->freezeTime();
    app(RanetraceBatchBuffer::class)->addItem('events', ['event_name' => 'e1']);

    $this->travel(DashboardData::DRAIN_STALE_SECONDS + 1)->seconds();
    Cache::store('array')->forever('ranetrace.feature.events.pause', [
        'paused_until' => now()->subMinute()->toIso8601String(),
        'reason' => '429',
    ]);
    $status = app(DashboardData::class)->collectStatus();

    expect($status['pauses']['features']['events']['paused'])->toBeFalse()
        ->and($status['drain']['stalled'])->toBe(['events'])
        ->and($status['drain']['paused_stalled'])->toBe([])
        ->and($status['healthy'])->toBeFalse();
});

test('a paused feature and a stalled one are each reported in their own list', function (): void {
    $this->freezeTime();
    $buffer = app(RanetraceBatchBuffer::class);
    $buffer->addItem('events', ['event_name' => 'e1']);
    $buffer->addItem('errors', ['message' => 'a']);

    $this->travel(DashboardData::DRAIN_STALE_SECONDS + 1)->seconds();
    app(RanetracePauseManager::class)->setFeaturePause('events', 900, 'network');

    $status = app(DashboardData::class)->collectStatus();

    expect($status['drain']['stalled'])->toBe(['errors'])
        ->and($status['drain']['paused_stalled'])->toBe(['events']);
});

test('a pause the API asked for is judged on the reason it carries, whatever the remaining time', function (): void {
    // A 429 whose Retry-After outlasts two pauses is still the API asking: the
    // grace is measured from the oldest item, not from the pause.
    $this->freezeTime();
    app(RanetraceBatchBuffer::class)->addItem('events', ['event_name' => 'e1']);
    app(RanetracePauseManager::class)->setFeaturePause('events', 3 * 3600, '429');

    $this->travel(DashboardData::PAUSED_DRAIN_GRACE_SECONDS + 1)->seconds();
    $status = app(DashboardData::class)->collectStatus();

    expect($status['pauses']['features']['events']['paused'])->toBeTrue()
        ->and($status['drain']['paused_stalled'])->toBe(['events'])
        ->and($status['healthy'])->toBeFalse();
});

test('overdue items with a batch sent inside the drain window leave the installation healthy', function (): void {
    app(RanetraceBatchBuffer::class)->addItem('events', ['event_name' => 'e1']);

    $this->travel(DashboardData::DRAIN_STALE_SECONDS + 1)->seconds();
    Cache::store('array')->forever(SendBatchToRanetraceJob::LAST_BATCH_PREFIX.'events', now()->subSeconds(DashboardData::DRAIN_STALE_SECONDS)->timestamp);

    expect(app(DashboardData::class)->collectStatus()['healthy'])->toBeTrue();
});
