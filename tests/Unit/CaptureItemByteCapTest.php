<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Ranetrace\Laravel\Jobs\BaseRanetraceJob;
use Ranetrace\Laravel\Jobs\HandleErrorJob;
use Ranetrace\Laravel\Jobs\HandleLogJob;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;
use Ranetrace\Php\Support\PayloadSizer;

/**
 * Every capture path validates its own input, but a missing rule on any one of
 * them must not be able to buffer an item so large it draws a 413 and takes the
 * whole batch (up to 1000 items) down with it. The budget is therefore enforced
 * in the job layer too, not only by each caller's validation.
 */
beforeEach(function (): void {
    Config::set('ranetrace.batch.cache_driver', 'array');
    Cache::store('array')->flush();
});

function bufferedLogItem(RanetraceBatchBuffer $buffer): array
{
    return Cache::store('array')->get('ranetrace:buffer:logs')[0]['data'];
}

/**
 * An error payload whose every allowed key holds an oversize free-form string:
 * each one is shrunk to the per-field budget, yet nineteen of them together stay
 * far above the per-item budget, the only way an item reaches the drop branch.
 *
 * @return array<string, string>
 */
function irreduciblyOversizeErrorData(): array
{
    $keys = [
        'message', 'file', 'line', 'type', 'environment', 'trace', 'headers',
        'context', 'highlight_line', 'user', 'timestamp', 'url', 'method',
        'php_version', 'framework', 'framework_version', 'is_console',
        'console_command', 'console_arguments',
    ];

    return array_fill_keys($keys, str_repeat('a', 20_000));
}

test('an item within the budget is buffered untouched', function (): void {
    $buffer = new RanetraceBatchBuffer;

    (new HandleLogJob(['level' => 'error', 'message' => 'boom']))->handle($buffer);

    expect(bufferedLogItem($buffer))
        ->toMatchArray(['level' => 'error', 'message' => 'boom']);
});

test('an oversize free-form field is cut so the item stays within the budget', function (): void {
    $buffer = new RanetraceBatchBuffer;

    (new HandleLogJob([
        'level' => 'error',
        'message' => str_repeat('a', 200_000),
    ]))->handle($buffer);

    $item = bufferedLogItem($buffer);

    expect(mb_strlen((string) json_encode($item), '8bit'))
        ->toBeLessThanOrEqual(BaseRanetraceJob::MAX_ITEM_BYTES)
        ->and($item['message'])->toEndWith('... (truncated)')
        ->and($item['level'])->toBe('error');
});

test('an oversize sub-array is replaced wholesale rather than cut mid-structure', function (): void {
    $buffer = new RanetraceBatchBuffer;

    (new HandleLogJob([
        'level' => 'error',
        'message' => 'boom',
        'context' => ['blob' => str_repeat('b', 200_000)],
    ]))->handle($buffer);

    $item = bufferedLogItem($buffer);

    expect(mb_strlen((string) json_encode($item), '8bit'))
        ->toBeLessThanOrEqual(BaseRanetraceJob::MAX_ITEM_BYTES)
        ->and($item['context'])->toHaveKey('_truncated');
});

test('an item still over the budget after shrinking is dropped, not buffered', function (): void {
    $buffer = new RanetraceBatchBuffer;

    (new HandleErrorJob(irreduciblyOversizeErrorData()))->handle($buffer);

    expect(Cache::store('array')->get('ranetrace:buffer:errors'))->toBeNull();
});

test('a dropped item never leaves a _truncated marker in the buffered output', function (): void {
    // `_truncated` is on no job's allow-list, so a marker item would fail the
    // backend's strict field matching and be refused.
    $buffer = new RanetraceBatchBuffer;

    (new HandleErrorJob(irreduciblyOversizeErrorData()))->handle($buffer);
    (new HandleLogJob(['level' => 'error', 'message' => 'boom']))->handle($buffer);

    $buffered = array_merge(
        Cache::store('array')->get('ranetrace:buffer:errors', []),
        Cache::store('array')->get('ranetrace:buffer:logs', [])
    );

    foreach ($buffered as $item) {
        expect($item['data'])->not->toHaveKey('_truncated');
    }

    expect($buffered)->toHaveCount(1);
});

test('the drop is recorded on the internal channel, distinctly from a shrink', function (): void {
    $logger = Mockery::spy(LoggerInterface::class);
    Log::shouldReceive('channel')->with('ranetrace_internal')->andReturn($logger);

    (new HandleErrorJob(irreduciblyOversizeErrorData()))->handle(new RanetraceBatchBuffer);

    $logger->shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => str_contains($message, 'dropped')
            && ($context['type'] ?? null) === HandleErrorJob::class
    );
});

test('an item JSON cannot encode counts as over budget, so its unencodable array field is marked and the rest kept', function (): void {
    $buffer = new RanetraceBatchBuffer;

    (new HandleLogJob([
        'level' => 'error',
        'message' => 'Ratio computed',
        'context' => ['ratio' => INF],
    ]))->handle($buffer);

    $item = bufferedLogItem($buffer);

    expect($item)->toBe([
        'level' => 'error',
        'message' => 'Ratio computed',
        'context' => ['_truncated' => PayloadSizer::UNENCODABLE_REASON],
    ])
        ->and(json_encode($item))->not->toBeFalse();
});

test('an item still unencodable after the shrink pass is dropped and logged, never measured as zero bytes', function (): void {
    $logger = Mockery::spy(LoggerInterface::class);
    Log::shouldReceive('channel')->with('ranetrace_internal')->andReturn($logger);

    (new HandleErrorJob(['message' => 'boom', 'line' => NAN]))->handle(new RanetraceBatchBuffer);

    expect(Cache::store('array')->get('ranetrace:buffer:errors'))->toBeNull();

    $logger->shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => $message === 'Captured item could not be encoded as JSON and was dropped'
            && ($context['type'] ?? null) === HandleErrorJob::class
    );
});

/**
 * A 23-key error item whose structured frames carry `$lineLength`-character
 * source lines, with `$previousCount` previous exceptions of their own frames.
 *
 * @return array<string, mixed>
 */
function errorItemWithFrames(int $lineLength, int $previousCount = 2, string $trace = 'trace'): array
{
    $source = ['start_line' => 1, 'lines' => array_fill(0, 11, str_repeat('s', $lineLength))];
    $frames = fn (): array => array_map(fn (int $index): array => [
        'index' => $index,
        'file' => "app/Services/Service{$index}.php",
        'line' => 10 + $index,
        'class' => "App\\Services\\Service{$index}",
        'function' => 'handle',
        'type' => '->',
        'is_vendor' => false,
        'source' => $source,
    ], range(0, 49));

    return [
        'message' => 'Call to a member function diffInSeconds() on string',
        'file' => 'storage/framework/views/abc.php',
        'line' => 4,
        'type' => 'Illuminate\View\ViewException',
        'environment' => 'production',
        'trace' => $trace,
        'headers' => null,
        'context' => 'context',
        'highlight_line' => 6,
        'user' => null,
        'timestamp' => '2026-10-15T12:00:00+00:00',
        'url' => null,
        'method' => null,
        'php_version' => PHP_VERSION,
        'framework' => 'laravel',
        'framework_version' => '13.0.0',
        'is_console' => true,
        'console_command' => null,
        'console_arguments' => null,
        'exception_context' => null,
        'frames' => $frames(),
        'frames_omitted' => 0,
        'previous' => $previousCount === 0 ? null : array_fill(0, $previousCount, [
            'type' => 'Error',
            'message' => 'Call to a member function diffInSeconds() on string',
            'file' => 'resources/views/performance.blade.php',
            'line' => 4,
            'source' => $source,
            'frames' => $frames(),
            'frames_omitted' => 0,
        ]),
    ];
}

/**
 * The same item held to the budget by `ranetrace/ranetrace-php`, which this
 * job's budget is a port of.
 *
 * @param  array<string, mixed>  $item
 * @return array<string, mixed>|null
 */
function coreCappedErrorItem(array $item): ?array
{
    $config = new Ranetrace\Php\Config([
        'buffer_path' => sys_get_temp_dir().'/ranetrace-laravel-tests-core-budget',
        'internal_logging' => ['enabled' => false],
    ]);

    return (new Ranetrace\Php\Support\ItemByteBudget(new Ranetrace\Php\Support\InternalLogger($config)))->cap('errors', $item);
}

test('an error item within the budget keeps its frames, frames_omitted and previous', function (): void {
    $item = errorItemWithFrames(10);

    (new HandleErrorJob($item))->handle(new RanetraceBatchBuffer);

    $buffered = Cache::store('array')->get('ranetrace:buffer:errors')[0]['data'];

    expect($buffered['frames'])->toBe($item['frames'])
        ->and($buffered['frames_omitted'])->toBe(0)
        ->and($buffered['previous'])->toBe($item['previous']);
});

test('an over-budget error sheds its frames rather than cutting them to a _truncated marker', function (): void {
    $item = errorItemWithFrames(1_000);

    expect(mb_strlen((string) json_encode($item), '8bit'))->toBeGreaterThan(BaseRanetraceJob::MAX_ITEM_BYTES);

    (new HandleErrorJob($item))->handle(new RanetraceBatchBuffer);

    $buffered = Cache::store('array')->get('ranetrace:buffer:errors')[0]['data'];

    expect(mb_strlen((string) json_encode($buffered), '8bit'))->toBeLessThanOrEqual(BaseRanetraceJob::MAX_ITEM_BYTES)
        ->and($buffered['frames'])->toBeList()
        ->and($buffered['frames'][0])->toHaveKeys(['index', 'file', 'line', 'source'])
        ->and((string) json_encode($buffered['frames']))->not->toContain('_truncated')
        ->and((string) json_encode($buffered['previous']))->not->toContain('_truncated');
});

test('frames and previous are never cut to a marker even when another field keeps the item over budget', function (): void {
    // The trace stays over budget after every frame is shed, so the generic
    // cut runs; it must pass over frames and previous.
    $item = errorItemWithFrames(1_000, trace: str_repeat('t', 200_000));

    (new HandleErrorJob($item))->handle(new RanetraceBatchBuffer);

    $buffered = Cache::store('array')->get('ranetrace:buffer:errors')[0]['data'];

    expect($buffered['trace'])->toEndWith('... (truncated)')
        ->and($buffered['frames'])->toBeList()
        ->and($buffered['frames'])->not->toHaveKey('_truncated')
        ->and($buffered['previous'] ?? [])->not->toHaveKey('_truncated');
});

test('an over-budget error is shrunk exactly as the php sdk shrinks it', function (array $item): void {
    (new HandleErrorJob($item))->handle(new RanetraceBatchBuffer);

    expect(Cache::store('array')->get('ranetrace:buffer:errors')[0]['data'])
        ->toBe(coreCappedErrorItem($item));
})->with([
    'sources shed' => fn (): array => errorItemWithFrames(150),
    'frames shed' => fn (): array => errorItemWithFrames(2_000, previousCount: 5),
    'frames shed and trace cut' => fn (): array => errorItemWithFrames(1_000, trace: str_repeat('t', 200_000)),
]);
