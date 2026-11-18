<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Pest\Plugins\Tia\Recorder;
use Pest\Support\Container;
use Ranetrace\Laravel\Dashboard\DashboardData;
use Ranetrace\Laravel\Jobs\SendBatchToRanetraceJob;
use Ranetrace\Laravel\RanetraceServiceProvider;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;
use Ranetrace\Laravel\Services\RanetracePauseManager;
use Ranetrace\Laravel\Tests\TestCase;

uses(TestCase::class)->in('Browser', 'Contract', 'Feature', 'Unit');

const QUEUE_ROUTES_SKIP_REASON = 'Queue routes arrived in Laravel 13, so on Laravel 12 no host can route a job away from the default connection.';

const QUEUE_FORWARDS_SKIP_REASON = 'This Laravel version has no queue forwards.';

/**
 * Every file under resources/boost, as sorted absolute paths. Swept
 * recursively rather than as a list of the skills that exist today, so a skill
 * added later is covered the day it lands.
 *
 * TIA: the files are read as text, never executed, so the coverage driver
 * records no edge to them. Each one is linked by hand, or a TIA run replays
 * this sweep's cached pass while the prose it guards changes underneath it.
 * Outside a TIA run the recorder is inactive and the link is a no-op. The
 * Laravel 12 lane resolves Pest 4, which has no TIA plugin and so no recorder.
 *
 * @return list<string>
 */
function boostResourceFiles(): array
{
    $recorder = class_exists(Recorder::class)
        ? Container::getInstance()->get(Recorder::class)
        : null;

    $paths = [];

    foreach (File::allFiles(dirname(__DIR__).'/resources/boost') as $file) {
        $recorder?->linkSource($file->getPathname());

        $paths[] = $file->getPathname();
    }

    sort($paths);

    return $paths;
}

/**
 * Every comment that survived into rendered output, as the lines carrying them.
 *
 * The views under resources/views are what this package sends to a browser, and
 * the notes explaining them are written for whoever maintains them, not for the
 * visitor paying for the bytes on a phone. Blade comments keep those notes in the
 * source and out of the output, so anything found here is either a comment
 * written in the wrong syntax or a Blade comment whose braces were mangled on the
 * way in, which renders as page text.
 *
 * @return list<string>
 */
function commentsIn(string $rendered): array
{
    $found = [];

    foreach (explode("\n", $rendered) as $line) {
        $markers = array_filter(
            ['/*', '<!--', '{--', '--}'],
            static fn (string $marker): bool => str_contains($line, $marker),
        );

        // A bare "//" match would flag every https:// in the output, so a line only
        // counts as a line comment when the slashes start it.
        if ($markers !== [] || preg_match('#^\s*//#', $line) === 1) {
            $found[] = mb_trim($line);
        }
    }

    return $found;
}

/**
 * Make the default auth guard one whose user lookup throws $message, the way a
 * host's guard does when its session store or user provider is down. It is a
 * real guard behind `Auth::user()` and `$request->user()` alike, not a mocked
 * facade, so every lookup the package makes meets the throw.
 */
function useThrowingAuthGuard(string $message): void
{
    Auth::viaRequest('ranetrace-throwing', static fn (): never => throw new RuntimeException($message));
    Config::set('auth.guards.throwing', ['driver' => 'ranetrace-throwing']);
    Auth::shouldUse('throwing');
}

/**
 * Point the batch cache store at an array store whose `add()` throws
 * "cache backend unavailable", as the store of a cache backend that is down
 * does.
 */
function useBatchCacheStoreWhoseAddThrows(): void
{
    Cache::extend('ranetrace-add-throws', fn () => Cache::repository(new class extends ArrayStore
    {
        public function add($key, $value, $seconds): bool
        {
            throw new RuntimeException('cache backend unavailable');
        }
    }));
    Config::set('cache.stores.add-throws', ['driver' => 'ranetrace-add-throws']);
    Config::set('ranetrace.batch.cache_driver', 'add-throws');
}

/**
 * Fake the API so that every request gets the 200 the real API gives a batch
 * of one item it processed, which is how `ranetrace:test` counts a test item
 * as accepted.
 */
function fakeAcceptingApi(): void
{
    Http::fake(['*' => Http::response(['success' => true, 'items' => ['received' => 1, 'processed' => 1]])]);
}

/**
 * The default connection is `database` with its default queue `jobs`, and
 * `redis` is another connection whose default queue is `high`.
 */
function useTwoQueueConnections(): void
{
    Config::set('queue.default', 'database');
    Config::set('queue.connections.database.queue', 'jobs');
    Config::set('queue.connections.redis.queue', 'high');
}

/**
 * Whether this Laravel version lacks queue routes, as a `skip()` condition
 * alongside QUEUE_ROUTES_SKIP_REASON.
 */
function withoutQueueRoutes(): bool
{
    return ! app()->bound('queue.routes');
}

/**
 * Whether this Laravel version lacks queue forwards, as a `skip()` condition
 * alongside QUEUE_FORWARDS_SKIP_REASON.
 */
function withoutQueueForwards(): bool
{
    return ! method_exists(app('queue'), 'forward');
}

/**
 * The value a dashboard panel shows beside a label, as decoded text, or an
 * empty string when the label is absent.
 */
function dashboardPanelValue(string $html, string $label): string
{
    preg_match('/'.preg_quote($label, '/').'<\/span>\s*<span class="rt-kv__val rt-kv__val--mono">([^<]*)<\/span>/', $html, $matches);

    return html_entity_decode($matches[1] ?? '', ENT_QUOTES);
}

/**
 * Boot the package over a host's published `config/ranetrace.php`: Laravel
 * loads that file into `ranetrace` before any provider registers, and the
 * provider then lays its own defaults underneath it.
 *
 * @param  array<string, mixed>  $published
 */
function registerWithPublishedConfig(array $published): void
{
    Config::set('ranetrace', $published);

    (new RanetraceServiceProvider(app()))->register();
}

/**
 * Run a command and return its exit code and its output.
 *
 * @param  array<string, mixed>  $parameters
 * @return array{0: int, 1: string}
 */
function artisanRun(string $command, array $parameters = []): array
{
    $exitCode = Artisan::call($command, $parameters);

    return [$exitCode, Artisan::output()];
}

/**
 * The text output of `ranetrace:status`, as plain lines without styling tags,
 * after checking that the command exited 0.
 *
 * @param  array<string, mixed>  $options
 */
function statusOutput(array $options = []): string
{
    expect(Artisan::call('ranetrace:status', $options))->toBe(0);

    return Artisan::output();
}

/**
 * The decoded output of `ranetrace:status --json`. Output that is not valid
 * JSON throws.
 *
 * @return array<string, mixed>
 */
function statusJson(): array
{
    return json_decode(mb_trim(statusOutput(['--json' => true])), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Leave an events item in the buffer past the stalled-drain window, with no
 * batch sent.
 */
function stallTheEventsDrain(): void
{
    app(RanetraceBatchBuffer::class)->addItem('events', ['event_name' => 'e1']);

    test()->travel(DashboardData::DRAIN_STALE_SECONDS + 1)->seconds();
}

/**
 * Fill the events buffer to 80 percent of its capacity, where status counts
 * it as near capacity.
 */
function fillTheEventsBufferNearCapacity(): void
{
    Config::set('ranetrace.batch.max_buffer_size', 10);

    $buffer = app(RanetraceBatchBuffer::class);

    for ($i = 0; $i < 8; $i++) {
        $buffer->addItem('events', ['event_name' => "e{$i}"]);
    }
}

/**
 * Record a successful events batch this many seconds ago.
 */
function recordEventsBatchSecondsAgo(int $seconds): void
{
    Cache::store('array')->forever(SendBatchToRanetraceJob::LAST_BATCH_PREFIX.'events', now()->subSeconds($seconds)->timestamp);
}

/**
 * Freeze time, buffer an item for each of $features, let $secondsWaited pass,
 * then pause each of them for $reason, so the pause is in force when the
 * status is read, as it is when the API sets it again on every attempt. By
 * default the items have waited one second past the grace of a pause the API
 * asked for.
 *
 * @param  list<string>  $features
 */
function pauseFeaturesWithItemsWaiting(array $features, string $reason = '403', int $secondsWaited = DashboardData::PAUSED_DRAIN_GRACE_SECONDS + 1): void
{
    test()->freezeTime();
    $buffer = app(RanetraceBatchBuffer::class);

    foreach ($features as $feature) {
        $buffer->addItem($feature, ['message' => 'a']);
    }

    test()->travel($secondsWaited)->seconds();

    foreach ($features as $feature) {
        app(RanetracePauseManager::class)->setFeaturePause($feature, 900, $reason);
    }
}
