<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\ViewException;
use Ranetrace\Laravel\Facades\Ranetrace;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;
use Ranetrace\Laravel\Support\BladeFrameMapper;
use Ranetrace\Php\Errors\FrameLocation;

/**
 * An error inside a Blade template runs in the compiled copy under
 * `storage/framework/views`, a file nobody wrote. These views are written under
 * the application's own `resources/views`, so the mapped paths come out
 * relative the way a customer's do, and every file a test writes or compiles
 * is removed after it.
 */
beforeEach(function (): void {
    config([
        'ranetrace.batch.cache_driver' => 'array',
        'ranetrace.errors.queue' => false,
    ]);

    Cache::store('array')->flush();

    $this->viewDirectory = 'resources/views/ranetrace-'.str_replace('.', '', uniqid('', true));
    $this->writtenFiles = [];
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory(base_path($this->viewDirectory));

    foreach ($this->writtenFiles as $file) {
        @unlink($file);
    }
});

/**
 * Write a template under this test's view directory and return its absolute
 * path.
 */
function bladeTemplate(string $name, string $contents): string
{
    $path = base_path(test()->viewDirectory.'/'.$name);

    @mkdir(dirname($path), 0755, true);
    file_put_contents($path, $contents);

    test()->writtenFiles = [...test()->writtenFiles, app('blade.compiler')->getCompiledPath($path)];

    return $path;
}

/**
 * Render a template file and return what it threw.
 */
function renderBladeTemplate(string $path, array $data = []): ViewException
{
    try {
        view()->file($path, $data)->render();
    } catch (ViewException $exception) {
        return $exception;
    }

    throw new RuntimeException("{$path} rendered without throwing.");
}

/**
 * Write a file under the compiled view directory, as Laravel would.
 */
function compiledViewFile(string $contents): string
{
    $path = mb_rtrim((string) config('view.compiled'), '/').'/ranetrace-'.str_replace('.', '', uniqid('', true)).'.php';

    file_put_contents($path, $contents);

    test()->writtenFiles = [...test()->writtenFiles, $path];

    return $path;
}

/**
 * @return array<string, mixed>
 */
function bufferedBladeErrorItem(): array
{
    $items = (new RanetraceBatchBuffer)->getItems('errors', 10);

    expect($items)->toHaveCount(1);

    return $items[0]['data'];
}

function throwFromInsideBlade(): never
{
    throw new RuntimeException('Thrown from a helper the template called');
}

function performanceTemplate(): string
{
    return <<<'BLADE'
        <div>
            <h1>Performance</h1>
            @php($startedAt = 'yesterday')
            <p>{{ $startedAt->diffInSeconds(now()) }}</p>
        </div>
        BLADE;
}

test('a view error reads as the template: message names it, previous carries the cause at the template line', function (): void {
    $template = bladeTemplate('performance.blade.php', performanceTemplate());

    Ranetrace::report(renderBladeTemplate($template));

    $item = bufferedBladeErrorItem();
    $cause = $item['previous'][0];

    expect($item['type'])->toBe(ViewException::class)
        ->and($item['message'])->toContain('Call to a member function diffInSeconds() on string')
        ->and($item['message'])->toContain('performance.blade.php')
        ->and($cause['type'])->toBe(Error::class)
        ->and($cause['message'])->toBe('Call to a member function diffInSeconds() on string')
        ->and($cause['file'])->toBe($this->viewDirectory.'/performance.blade.php')
        ->and($cause['line'])->toBe(4)
        ->and($cause['source']['start_line'])->toBe(1)
        ->and($cause['source']['lines'][3])->toContain('{{ $startedAt->diffInSeconds(now()) }}');
});

test('the item keeps the compiled throw site as its own file and line, so existing error groups hold', function (): void {
    $template = bladeTemplate('performance.blade.php', performanceTemplate());
    $exception = renderBladeTemplate($template);

    Ranetrace::report($exception);

    $item = bufferedBladeErrorItem();

    expect($item['file'])->toEndWith('.php')
        ->and($item['file'])->not->toEndWith('.blade.php')
        ->and($item['file'])->toBe(mb_ltrim(mb_substr($exception->getFile(), mb_strlen(base_path())), '/'))
        ->and($item['line'])->toBe($exception->getLine());
});

test('a frame in a compiled view maps to the template path, line and source', function (): void {
    $template = bladeTemplate('dashboard.blade.php', <<<'BLADE'
        <section>
            <h2>Dashboard</h2>

            {{ throwFromInsideBlade() }}
        </section>
        BLADE);

    Ranetrace::report(renderBladeTemplate($template));

    $cause = bufferedBladeErrorItem()['previous'][0];
    $templateFrame = $cause['frames'][0];

    expect($cause['message'])->toBe('Thrown from a helper the template called')
        ->and($templateFrame['function'])->toBe('throwFromInsideBlade')
        ->and($templateFrame['file'])->toBe($this->viewDirectory.'/dashboard.blade.php')
        ->and($templateFrame['line'])->toBe(4)
        ->and($templateFrame['is_vendor'])->toBeFalse()
        ->and($templateFrame['source']['lines'][3])->toContain('{{ throwFromInsideBlade() }}');
});

test('with source capture off no template line leaves the app, context included', function (): void {
    config(['ranetrace.errors.capture_source' => false]);

    $template = bladeTemplate('performance.blade.php', performanceTemplate());

    Ranetrace::report(renderBladeTemplate($template));

    $item = bufferedBladeErrorItem();
    $sources = array_merge(
        array_column($item['frames'], 'source'),
        array_column($item['previous'], 'source'),
        ...array_map(fn (array $previous): array => array_column($previous['frames'], 'source'), $item['previous']),
    );

    expect($item['context'])->toBeNull()
        ->and($item['highlight_line'])->toBeNull()
        ->and(array_filter($sources))->toBe([])
        ->and($item['previous'][0]['file'])->toBe($this->viewDirectory.'/performance.blade.php')
        ->and((string) json_encode($item))->not->toContain('diffInSeconds(now())');
});

test('a frame outside the compiled view directory is not the mapper\'s', function (): void {
    expect(BladeFrameMapper::forApplication()->map(new FrameLocation(__FILE__, 10)))->toBeNull();
});

test('a compiled file without the path marker is not the mapper\'s', function (): void {
    $compiled = compiledViewFile("<?php echo 'no marker here'; ?>\n<p>plain</p>\n");

    expect(BladeFrameMapper::forApplication()->map(new FrameLocation($compiled, 1)))->toBeNull();
});

test('a compiled file that is gone or unreadable answers null without throwing', function (): void {
    $missing = mb_rtrim((string) config('view.compiled'), '/').'/ranetrace-missing-'.uniqid().'.php';

    $unreadable = compiledViewFile('<?php echo 1; ?><?php /**PATH /nowhere/x.blade.php ENDPATH**/ ?>');
    chmod($unreadable, 0000);

    $mapper = BladeFrameMapper::forApplication();

    expect($mapper->map(new FrameLocation($missing, 3)))->toBeNull()
        ->and($mapper->map(new FrameLocation($unreadable, 1)))->toBeNull();

    chmod($unreadable, 0644);
});

test('a compiled view whose template was deleted since answers null', function (): void {
    $template = bladeTemplate('removed.blade.php', performanceTemplate());
    $exception = renderBladeTemplate($template);
    unlink($template);

    $previous = $exception->getPrevious();

    expect(BladeFrameMapper::forApplication()->map(new FrameLocation($previous->getFile(), $previous->getLine())))
        ->toBeNull();
});

test('a template under a directory with spaces in its name maps', function (): void {
    $template = bladeTemplate('my reports/annual report.blade.php', performanceTemplate());
    $previous = renderBladeTemplate($template)->getPrevious();

    $mapped = BladeFrameMapper::forApplication()->map(new FrameLocation($previous->getFile(), $previous->getLine()));

    expect($mapped?->file)->toBe($template)
        ->and($mapped?->line)->toBe(4);
});

test('a view compiled under another base path is found under this one', function (): void {
    // `view:cache` on a build machine writes the build machine's template path
    // into the marker; the compiled file name does not depend on it.
    $template = bladeTemplate('performance.blade.php', performanceTemplate());
    $compiled = compiledViewFile(
        app('blade.compiler')->compileString(performanceTemplate())
        .'<?php /**PATH /build/workspace/app/'.$this->viewDirectory.'/performance.blade.php ENDPATH**/ ?>'
    );

    $mapped = BladeFrameMapper::forApplication()->map(new FrameLocation($compiled, 4));

    expect($mapped?->file)->toBe(base_path($this->viewDirectory.'/performance.blade.php'))
        ->and($mapped?->line)->toBe(4)
        ->and($template)->toBe($mapped?->file);
});

test('a marker naming a missing template is never matched on its file name alone', function (): void {
    $name = 'ranetrace-'.str_replace('.', '', uniqid('', true)).'.blade.php';
    file_put_contents(base_path($name), performanceTemplate());
    $this->writtenFiles = [...$this->writtenFiles, base_path($name)];

    $compiled = compiledViewFile("<?php echo 1; ?>\n<?php /**PATH /build/{$name} ENDPATH**/ ?>");

    expect(BladeFrameMapper::forApplication()->map(new FrameLocation($compiled, 1)))->toBeNull();
});

test('an inline Blade::render template maps without throwing', function (): void {
    try {
        Blade::render("<div>\n\n{{ throwFromInsideBlade() }}\n</div>");
    } catch (ViewException $exception) {
        $frame = $exception->getPrevious()->getTrace()[0];
    }

    $mapped = BladeFrameMapper::forApplication()->map(new FrameLocation($frame['file'], $frame['line']));

    // Laravel writes an inline template to the compiled directory itself.
    expect($mapped?->file)->toEndWith('.blade.php')
        ->and($mapped?->line)->toBe(3);
});

test('a huge compiled file is mapped from its tail alone', function (): void {
    $template = bladeTemplate('performance.blade.php', performanceTemplate());
    $compiled = compiledViewFile(
        app('blade.compiler')->compileString(performanceTemplate())
        ."<?php /*\n".str_repeat(str_repeat('x', 1_023)."\n", 4_096).'*/ ?>'
        .'<?php /**PATH '.$template.' ENDPATH**/ ?>'
    );

    $mapped = BladeFrameMapper::forApplication()->map(new FrameLocation($compiled, 4));

    expect(filesize($compiled))->toBeGreaterThan(4_000_000)
        ->and($mapped?->file)->toBe($template)
        ->and($mapped?->line)->toBe(4);
});

test('a template over the size bound is left unmapped rather than compiled whole', function (): void {
    $template = bladeTemplate('huge.blade.php', performanceTemplate()."\n".str_repeat("<p>{{ \$row }}</p>\n", 80_000));
    $compiled = compiledViewFile("<?php echo 1; ?>\n<?php /**PATH {$template} ENDPATH**/ ?>");

    expect(filesize($template))->toBeGreaterThan(BladeFrameMapper::MAX_TEMPLATE_BYTES)
        ->and(BladeFrameMapper::forApplication()->map(new FrameLocation($compiled, 4)))->toBeNull();
});

test('a template that no longer compiles answers null and reports nothing', function (): void {
    // Laravel's own mapper reports a compile failure through report(). From
    // inside the capture path that would send a second error, which carries
    // the same compiled frame and fails again.
    $handler = new Handler(app());
    Ranetrace::handles(new Exceptions($handler));
    app()->instance(ExceptionHandler::class, $handler);

    $template = bladeTemplate('component.blade.php', "<div>\n<x-ranetrace-no-such-component />\n</div>");
    $compiled = compiledViewFile("<?php echo 1; ?>\n<?php /**PATH {$template} ENDPATH**/ ?>");

    expect(BladeFrameMapper::forApplication()->map(new FrameLocation($compiled, 2)))->toBeNull()
        ->and((new RanetraceBatchBuffer)->getItems('errors', 10))->toBe([]);
});
