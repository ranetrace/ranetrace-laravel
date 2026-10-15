<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Support;

use Illuminate\Contracts\View\Factory;
use Ranetrace\Php\Errors\FrameLocation;
use Ranetrace\Php\Errors\FrameMapper;
use Throwable;

/**
 * Maps a frame in a compiled Blade view (`storage/framework/views/<hash>.php`)
 * to the template a developer wrote and the line in it, so the frame, its
 * source lines and the editor link point at the `.blade.php` file.
 *
 * The template comes from the `PATH ... ENDPATH` comment Laravel appends to
 * every compiled view, read from the file's tail only, so a huge compiled file
 * costs one short read. The template line comes from Laravel's own mapping,
 * through {@see BladeTemplateLines}, which says why it is not called as it is.
 *
 * Only files under the compiled view directory are considered. When the
 * template in the marker no longer exists, as with views compiled by
 * `view:cache` under another base path on a build machine, the same path is
 * looked for under this application's base path, longest tail first. Each
 * compiled file and each template is read at most once per mapper, and the
 * mapper is built per capture. Anything that fails answers null, which leaves
 * the frame as it was.
 */
final class BladeFrameMapper implements FrameMapper
{
    /**
     * A template is compiled whole to find one line, so one larger than this
     * is left unmapped, the size above which the core reads no source either.
     */
    public const int MAX_TEMPLATE_BYTES = 1_048_576;

    /**
     * How much of a compiled view's end is read for the marker: a path of
     * PATH_MAX (4,096 bytes) and the marker around it, twice over.
     */
    private const int MARKER_TAIL_BYTES = 8_192;

    /**
     * The template each compiled file names, null for one that names none.
     *
     * @var array<string, string|null>
     */
    private array $templates = [];

    /**
     * Each template's line-marked compile, null for one that cannot be read or
     * compiled.
     *
     * @var array<string, string|null>
     */
    private array $sourceMaps = [];

    private ?BladeTemplateLines $lineMapper = null;

    /**
     * @param  list<string>  $compiledDirectories  Absolute paths of the compiled view directory, as configured and resolved.
     */
    public function __construct(
        private readonly array $compiledDirectories,
        private readonly string $basePath,
    ) {}

    public static function forApplication(): self
    {
        $configured = config('view.compiled');
        $directories = [];

        if (is_string($configured) && $configured !== '') {
            $directories[] = $configured;
            $resolved = realpath($configured);

            if ($resolved !== false && $resolved !== $configured) {
                $directories[] = $resolved;
            }
        }

        return new self($directories, base_path());
    }

    public function map(FrameLocation $location): ?FrameLocation
    {
        try {
            if (! $this->isCompiledView($location->file)) {
                return null;
            }

            if (! array_key_exists($location->file, $this->templates)) {
                $this->templates[$location->file] = $this->templateOf($location->file);
            }

            $template = $this->templates[$location->file];

            if ($template === null) {
                return null;
            }

            $line = $this->templateLine($template, $location->line);

            return $line === null ? null : new FrameLocation($template, $line);
        } catch (Throwable) {
            return null;
        }
    }

    private function isCompiledView(string $file): bool
    {
        $file = str_replace('\\', '/', $file);

        if (! str_ends_with($file, '.php')) {
            return false;
        }

        foreach ($this->compiledDirectories as $directory) {
            if (str_starts_with($file, mb_rtrim(str_replace('\\', '/', $directory), '/').'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The template the compiled file's marker names, re-rooted under this
     * application's base path when it does not exist where it was compiled.
     */
    private function templateOf(string $compiled): ?string
    {
        $tail = $this->readTail($compiled);

        if ($tail === null || preg_match('~<\?php /\*\*PATH ([^\r\n]+?) ENDPATH\*\*/ \?>\s*\z~', $tail, $matches) !== 1) {
            return null;
        }

        $template = $matches[1];

        if (@is_file($template)) {
            return $template;
        }

        $segments = array_values(array_filter(explode('/', str_replace('\\', '/', $template)), fn (string $segment): bool => $segment !== ''));
        $root = mb_rtrim($this->basePath, '/\\');

        // At least a directory and a file name, so a bare file name never
        // matches some other file of that name at the root.
        for ($start = 1; $start <= count($segments) - 2; $start++) {
            $candidate = $root.'/'.implode('/', array_slice($segments, $start));

            if (@is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function readTail(string $file): ?string
    {
        $size = @filesize($file);

        if ($size === false || ! @is_file($file)) {
            return null;
        }

        $handle = @fopen($file, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            if ($size > self::MARKER_TAIL_BYTES && @fseek($handle, $size - self::MARKER_TAIL_BYTES) !== 0) {
                return null;
            }

            $tail = @stream_get_contents($handle);

            return $tail === false ? null : $tail;
        } finally {
            @fclose($handle);
        }
    }

    private function templateLine(string $template, int $compiledLine): ?int
    {
        if (! array_key_exists($template, $this->sourceMaps)) {
            $this->sourceMaps[$template] = $this->sourceMapOf($template);
        }

        $sourceMap = $this->sourceMaps[$template];

        return $sourceMap === null ? null : $this->lineMapper()->templateLine($sourceMap, $compiledLine);
    }

    private function sourceMapOf(string $template): ?string
    {
        $size = @filesize($template);

        if ($size === false || $size > self::MAX_TEMPLATE_BYTES || ! @is_readable($template)) {
            return null;
        }

        $contents = @file_get_contents($template);

        if ($contents === false) {
            return null;
        }

        try {
            return $this->lineMapper()->sourceMap($contents);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Laravel's mapper, over a copy of the application's Blade compiler: the
     * copy keeps every custom directive, so the template compiles to the same
     * lines it did when it ran, while the state a compile leaves behind stays
     * out of the compiler the application is still rendering with.
     */
    private function lineMapper(): BladeTemplateLines
    {
        return $this->lineMapper ??= new BladeTemplateLines(app(Factory::class), clone app('blade.compiler'));
    }
}
