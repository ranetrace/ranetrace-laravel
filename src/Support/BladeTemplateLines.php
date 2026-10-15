<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Support;

use Illuminate\Foundation\Exceptions\Renderer\Mappers\BladeMapper;

/**
 * Laravel's own compiled-line to template-line mapping, the one behind its
 * exception page, made callable for one template at a time.
 *
 * {@see BladeMapper::detectLineNumber()} cannot be called as it is: it reads
 * the template on every call, and it reports a compile failure through
 * `report()`. From inside the capture path that report sends a second error,
 * whose own trace can hold the same compiled frame and so fail and report
 * again. {@see sourceMap()} runs the same steps as its `compileSourcemap()` and
 * lets a failure reach the caller instead.
 *
 * @internal
 */
final class BladeTemplateLines extends BladeMapper
{
    /**
     * The template compiled with a line marker before every echo, directive
     * and component tag, so each compiled line carries the template line it
     * came from.
     */
    public function sourceMap(string $template): string
    {
        $value = $this->addEchoLineNumbers($template);
        $value = $this->addStatementLineNumbers($value);
        $value = $this->addBladeComponentLineNumbers($value);

        return $this->trimEmptyLines($this->bladeCompiler->compileString($value));
    }

    /**
     * The template line of the nearest marker at or above the compiled line.
     */
    public function templateLine(string $sourceMap, int $compiledLine): int
    {
        return $this->findClosestLineNumberMapping($sourceMap, $compiledLine);
    }
}
