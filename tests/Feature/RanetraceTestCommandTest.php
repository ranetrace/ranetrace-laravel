<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Ranetrace\Laravel\Ranetrace;
use Ranetrace\Laravel\Services\RanetraceApiClient;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;
use Ranetrace\Laravel\Services\RanetracePauseManager;
use Ranetrace\Laravel\Tests\Contract\ContractFields;
use Ranetrace\Php\Contract\WireContract;

beforeEach(function (): void {
    Config::set('ranetrace.batch.cache_driver', 'array');
    Cache::store('array')->flush();
});

/**
 * Run the bare `ranetrace:test` and return its exit code and its output.
 *
 * @return array{0: int, 1: string}
 */
function roundTripRun(): array
{
    $exitCode = Artisan::call('ranetrace:test');

    return [$exitCode, Artisan::output()];
}

/**
 * The items each request carried, keyed by the wrapper key they were sent
 * under, in the order they were sent.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function roundTripSentItems(): array
{
    $sent = [];

    foreach (Http::recorded() as [$request]) {
        foreach ($request->data() as $wrapper => $items) {
            $sent[$wrapper] = $items;
        }
    }

    return $sent;
}

/**
 * Every pause the store holds, of any scope.
 *
 * @return array<string, mixed>
 */
function roundTripPauses(): array
{
    $pauses = app(RanetracePauseManager::class);

    return array_filter([
        'everything' => $pauses->getGlobalPause(),
        ...array_combine(RanetraceBatchBuffer::TYPES, array_map($pauses->getFeaturePause(...), RanetraceBatchBuffer::TYPES)),
    ]);
}

/**
 * The 200 the API gives a batch of one item, with the item counted as $counter.
 */
function roundTripCounted(string $counter = 'processed'): Closure
{
    return static fn (): mixed => Http::response(['success' => true, 'items' => ['received' => 1, $counter => 1]]);
}

test('a test error and a test event are sent straight to the api, beside the buffer and the queue', function (bool $queue): void {
    Queue::fake();
    Config::set('ranetrace.errors.queue', $queue);
    Config::set('ranetrace.events.queue', $queue);
    fakeAcceptingApi();

    [$exitCode, $output] = roundTripRun();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain("  errors   accepted\n  events   accepted\n");

    Http::assertSentCount(2);

    $recorded = Http::recorded()->map(fn (array $pair): Request => $pair[0])->values();

    expect($recorded[0]->url())->toBe('https://api.ranetrace.com/v1/errors/store')
        ->and(array_keys($recorded[0]->data()))->toBe(['errors'])
        ->and($recorded[0]->data()['errors'])->toHaveCount(1)
        ->and($recorded[1]->url())->toBe('https://api.ranetrace.com/v1/events/store')
        ->and(array_keys($recorded[1]->data()))->toBe(['events'])
        ->and($recorded[1]->data()['events'])->toHaveCount(1);

    $error = $recorded[0]->data()['errors'][0];
    $event = $recorded[1]->data()['events'][0];

    expect($error['message'])->toBe('Test exception from Ranetrace')
        ->and($error['type'])->toBe('Exception')
        ->and($event['event_name'])->toBe('test_event')
        ->and($event['properties'])->toBe(['source' => 'ranetrace:test']);

    $buffer = app(RanetraceBatchBuffer::class);

    foreach (RanetraceBatchBuffer::TYPES as $type) {
        expect($buffer->count($type))->toBe(0);
    }

    Queue::assertNothingPushed();
})->with([
    'with the queue on' => [true],
    'with the queue off' => [false],
]);

test('the test items carry the keys the real capture path buffers, and only fields the endpoints declare', function (): void {
    fakeAcceptingApi();
    roundTripRun();
    $sent = roundTripSentItems();

    Config::set('ranetrace.errors.queue', false);
    Config::set('ranetrace.events.queue', false);

    /** @var array{simple: Closure(): Exception} $exceptions */
    $exceptions = require dirname(__DIR__, 2).'/resources/test-exceptions.php';
    app(Ranetrace::class)->report($exceptions['simple']());
    app(Ranetrace::class)->trackEvent('test_event', ['source' => 'ranetrace:test']);

    $buffer = app(RanetraceBatchBuffer::class);
    $captured = [
        'errors' => $buffer->getItems('errors', 1)[0]['data'],
        'events' => $buffer->getItems('events', 1)[0]['data'],
    ];

    expect(array_keys($sent['errors'][0]))->toBe(array_keys($captured['errors']))
        ->and(array_keys($sent['events'][0]))->toBe(array_keys($captured['events']))
        ->and(Arr::only($sent['errors'][0], ['message', 'type', 'file', 'line', 'environment', 'is_console', 'user']))
        ->toBe(Arr::only($captured['errors'], ['message', 'type', 'file', 'line', 'environment', 'is_console', 'user']))
        ->and(Arr::except($sent['events'][0], ['timestamp']))->toBe(Arr::except($captured['events'], ['timestamp']));

    foreach ($sent as $type => [$item]) {
        $fields = WireContract::item($type)['fields'];

        expect(array_diff(array_keys($item), ContractFields::topLevelKeys($fields)))->toBe([]);

        foreach (ContractFields::requiredTopLevelKeys($fields) as $field) {
            expect($item)->toHaveKey($field)
                ->and($item[$field])->not->toBeNull();
        }
    }
});

test('a 401 on the error stops the event from being sent, sets no pause and fails', function (): void {
    Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid API key']], 401)]);

    [$exitCode, $output] = roundTripRun();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain(
            "  errors   refused: the API answered 401 (Invalid API key). Check RANETRACE_KEY\n"
            ."  events   not sent: the key was refused\n"
            ."\nNothing was accepted.\n",
        )
        ->and(roundTripPauses())->toBe([]);

    Http::assertSentCount(1);
});

test('each answer that accepts nothing gets the shared line, sets no pause and fails', function (Closure $answer, string $line): void {
    Http::fake(['*' => $answer]);

    [$exitCode, $output] = roundTripRun();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain("  errors   {$line}\n  events   {$line}\n\nNothing was accepted.\n")
        ->and(roundTripPauses())->toBe([]);
})->with([
    'a 403' => [
        static fn (): mixed => Http::response(['error' => ['message' => 'Feature disabled']], 403),
        'refused: the API answered 403 (Feature disabled). The key lacks access to this feature, the website is in maintenance mode, or the account has no access',
    ],
    'a 429 with Retry-After' => [
        static fn (): mixed => Http::response([], 429, ['Retry-After' => '30']),
        'rate limited: the API answered 429. Try again in 30 seconds',
    ],
    'a 429 without Retry-After' => [
        static fn (): mixed => Http::response([], 429),
        'rate limited: the API answered 429. Try again in 60 seconds',
    ],
    'a 500' => [
        static fn (): mixed => Http::response(['error' => ['message' => 'Server Error']], 500),
        'failed: the API answered 500 (Server Error)',
    ],
    'a connection failure' => [
        static fn (): never => throw new ConnectionException('Connection refused'),
        'failed: could not reach https://api.ranetrace.com/v1 (Connection refused)',
    ],
    'a 200 whose body is not json' => [
        static fn (): mixed => Http::response('<html>Welcome</html>', 200),
        'failed: the API answered 200 without counting the test item, so https://api.ranetrace.com/v1 may not be the Ranetrace API',
    ],
    'a 200 that counts nothing' => [
        static fn (): mixed => Http::response(['success' => true]),
        'failed: the API answered 200 without counting the test item, so https://api.ranetrace.com/v1 may not be the Ranetrace API',
    ],
    'a 200 that failed the item' => [
        roundTripCounted('failed'),
        'refused: the API did not store the test item. This is a bug in the SDK, please report it',
    ],
]);

test('an item the api ignored on purpose counts as accepted', function (): void {
    Http::fake(['*' => roundTripCounted('ignored')]);

    [$exitCode, $output] = roundTripRun();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain(
            "  errors   accepted, not stored: the API stores the same error once per 5 minutes and skips an error you set to ignored\n"
            ."  events   accepted, not stored: the API ignored it\n",
        );
});

test('one type accepted and the other refused passes', function (): void {
    Http::fake([
        '*/errors/store' => Http::response(['error' => ['message' => 'Forbidden']], 403),
        '*/events/store' => roundTripCounted(),
    ]);

    [$exitCode, $output] = roundTripRun();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain("  events   accepted\n\nOpen your website in Ranetrace to see what was accepted.\n");
});

test('a type whose feature is off is skipped and not sent, and the other still is', function (string $off, string $sent, string $variable): void {
    Config::set("ranetrace.{$off}.enabled", false);
    fakeAcceptingApi();

    [$exitCode, $output] = roundTripRun();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain("  {$off}   skipped: {$variable} is false\n")
        ->and(array_keys(roundTripSentItems()))->toBe([$sent]);
})->with([
    'errors off' => ['errors', 'events', 'RANETRACE_ERRORS_ENABLED'],
    'events off' => ['events', 'errors', 'RANETRACE_EVENTS_ENABLED'],
]);

test('a type whose feature is off says so rather than not sent after a 401', function (): void {
    Config::set('ranetrace.events.enabled', false);
    Http::fake(['*' => Http::response([], 401)]);

    [, $output] = roundTripRun();

    expect($output)->toContain("  events   skipped: RANETRACE_EVENTS_ENABLED is false\n");
});

test('both features off sends nothing and fails', function (): void {
    Config::set('ranetrace.errors.enabled', false);
    Config::set('ranetrace.events.enabled', false);
    fakeAcceptingApi();

    [$exitCode, $output] = roundTripRun();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain(
            "  errors   skipped: RANETRACE_ERRORS_ENABLED is false\n"
            ."  events   skipped: RANETRACE_EVENTS_ENABLED is false\n"
            ."\nNothing was accepted.\n",
        );

    Http::assertNothingSent();
});

test('with the master switch off nothing is sent and the test fails', function (): void {
    Config::set('ranetrace.enabled', false);
    fakeAcceptingApi();

    [$exitCode, $output] = roundTripRun();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain("RANETRACE_ENABLED is false, so nothing is captured or sent.\nNothing was accepted.\n")
        ->not->toContain('Test error and test event');

    Http::assertNothingSent();
});

test('an accepted test lifts the global pause a 401 left and says so', function (): void {
    app(RanetracePauseManager::class)->setGlobalPause(900, '401');
    fakeAcceptingApi();

    [$exitCode, $output] = roundTripRun();

    expect($exitCode)->toBe(0)
        ->and(app(RanetracePauseManager::class)->isGloballyPaused())->toBeFalse()
        ->and($output)->toContain(
            "  events   accepted\n"
            ."  Lifted   the pause on everything (after a 401): the key is accepted now\n\n",
        );

    Http::assertSentCount(2);
});

test('an accepted test lifts the feature pause it disproves on a type it sent', function (string $reason, string $wording): void {
    app(RanetracePauseManager::class)->setFeaturePause('errors', 900, $reason);
    fakeAcceptingApi();

    [, $output] = roundTripRun();

    expect(app(RanetracePauseManager::class)->isFeaturePaused('errors'))->toBeFalse()
        ->and($output)->toContain("  Lifted   the pause on errors (after {$wording}): the errors endpoint answered 200 just now\n");
})->with([
    'a 403' => ['403', 'a 403'],
    'a network failure' => ['network', 'a network failure'],
]);

test('every lifted pause gets its own line, aligned under the first', function (): void {
    app(RanetracePauseManager::class)->setGlobalPause(900, '401');
    app(RanetracePauseManager::class)->setFeaturePause('events', 900, 'network');
    fakeAcceptingApi();

    [, $output] = roundTripRun();

    expect($output)->toContain(
        "  Lifted   the pause on everything (after a 401): the key is accepted now\n"
        ."           the pause on events (after a network failure): the events endpoint answered 200 just now\n",
    );
});

test('an accepted test keeps a feature pause one accepted item says nothing about', function (string $reason): void {
    app(RanetracePauseManager::class)->setFeaturePause('errors', 900, $reason);
    fakeAcceptingApi();

    [$exitCode, $output] = roundTripRun();

    expect($exitCode)->toBe(0)
        ->and(app(RanetracePauseManager::class)->getFeaturePause('errors')['reason'] ?? null)->toBe($reason)
        ->and($output)->not->toContain('Lifted');
})->with(['429', '413', '422']);

test('an accepted test leaves the pause on a type it does not send alone', function (): void {
    app(RanetracePauseManager::class)->setFeaturePause('logs', 900, 'network');
    fakeAcceptingApi();

    [, $output] = roundTripRun();

    expect(app(RanetracePauseManager::class)->isFeaturePaused('logs'))->toBeTrue()
        ->and($output)->not->toContain('Lifted');
});

test('a 200 that counts nothing lifts nothing', function (): void {
    app(RanetracePauseManager::class)->setGlobalPause(900, '401');
    app(RanetracePauseManager::class)->setFeaturePause('errors', 900, '403');
    Http::fake(['*' => Http::response(['success' => true])]);

    [, $output] = roundTripRun();

    expect(app(RanetracePauseManager::class)->isGloballyPaused())->toBeTrue()
        ->and(app(RanetracePauseManager::class)->isFeaturePaused('errors'))->toBeTrue()
        ->and($output)->not->toContain('Lifted');
});

test('the configuration is still printed before the round trip, without the old verdict on it', function (): void {
    fakeAcceptingApi();

    [, $output] = roundTripRun();

    expect($output)
        ->toContain('🔍 Testing Ranetrace configuration...')
        ->toContain('✅ Ingest API key configured: test******')
        ->toContain('📋 Feature configuration:')
        ->toContain('⚙️  Error reporting settings:')
        ->not->toContain('configuration is valid')
        ->and(mb_strpos($output, 'Error reporting settings:'))->toBeLessThan(mb_strpos($output, 'Test error and test event'))
        ->and($output)->toContain(
            "📡 Test error and test event, sent to the API:\n"
            ."  errors   accepted\n"
            ."  events   accepted\n"
            ."\n"
            ."Open your website in Ranetrace to see what was accepted.\n"
            ."Run php artisan ranetrace:status to see the buffers, the pauses and the schedule.\n"
            ."\n"
            ."📚 Test specific features:\n",
        );
});

test('an item that cannot be built is not captured, names the internal log that says why, and the other type is still sent', function (): void {
    app()->instance(Ranetrace::class, new class extends Ranetrace
    {
        public function buildErrorPayload(Throwable $exception): array
        {
            throw new RuntimeException('Builder broke');
        }
    });
    fakeAcceptingApi();

    [$exitCode, $output] = roundTripRun();

    $logFile = dirname((string) config('logging.channels.ranetrace_internal.path')).'/ranetrace-internal-'.date('Y-m-d').'.log';

    expect($exitCode)->toBe(0)
        ->and($output)->toContain("  errors   not captured: the SDK could not build the test item. The reason is in {$logFile}\n  events   accepted\n")
        ->and(is_file($logFile))->toBeTrue()
        ->and((string) file_get_contents($logFile))->toContain('Failed to build the test item')->toContain('Builder broke')
        ->and(array_keys(roundTripSentItems()))->toBe(['events']);
});

test('an item that cannot be built while internal logging is off says to turn it on', function (): void {
    Config::set('ranetrace.internal_logging.enabled', false);
    app()->instance(Ranetrace::class, new class extends Ranetrace
    {
        public function buildEventPayload(string $eventName, array $properties = [], int|string|null $userId = null): array
        {
            throw new RuntimeException('Builder broke');
        }
    });
    fakeAcceptingApi();

    [, $output] = roundTripRun();

    expect($output)->toContain("  events   not captured: the SDK could not build the test item. Turn internal logging on to see why\n");
});

test('a pause store that cannot be read costs the lifting, never the answer or the command', function (): void {
    Config::set('ranetrace.batch.cache_driver', 'store-that-does-not-exist');
    fakeAcceptingApi();

    [$exitCode, $output] = roundTripRun();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain("  errors   accepted\n  events   accepted\n\n")
        ->not->toContain('Lifted');
});

test('the test sends to a configured base url and names it in its lines', function (): void {
    Config::set('ranetrace.base_url', 'https://ranetrace.test/v1/');
    Http::fake(['*' => Http::response(['success' => true])]);

    [, $output] = roundTripRun();

    expect(Http::recorded()->map(fn (array $pair): string => $pair[0]->url())->all())
        ->toBe(['https://ranetrace.test/v1/errors/store', 'https://ranetrace.test/v1/events/store'])
        ->and($output)->toContain('so https://ranetrace.test/v1 may not be the Ranetrace API');
});

test('an auth guard that throws costs the test event its user, not the event', function (): void {
    useThrowingAuthGuard('Session store is down');
    fakeAcceptingApi();

    [$exitCode, $output] = roundTripRun();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain("  errors   accepted\n  events   accepted\n")
        ->and(roundTripSentItems()['events'][0]['user'])->toBeNull();
});

test('a failure the run did not turn into a line becomes one, and the command fails without throwing', function (): void {
    app()->bind(RanetraceApiClient::class, static fn (): never => throw new RuntimeException('Client broke'));

    [$exitCode, $output] = roundTripRun();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain("The test could not run: Client broke\n\nNothing was accepted.\n");
});

test('what the api answered is printed as text, never read as console markup', function (): void {
    Http::fake(['*' => Http::response(['error' => ['message' => 'see <info>the docs</info>']], 500)]);

    [, $output] = roundTripRun();

    expect($output)->toContain('  errors   failed: the API answered 500 (see <info>the docs</info>)');
});
