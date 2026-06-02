<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Mcp\Tools\Concerns;

/**
 * Normalizes the MCP `type` parameter for error tools: the "js" alias maps to
 * "javascript" and a missing type defaults to "php". Shared so the single-action
 * and bulk error tools cannot drift.
 */
trait NormalizesErrorType
{
    protected function normalizeType(?string $type): string
    {
        if ($type === null) {
            return 'php';
        }

        return $type === 'js' ? 'javascript' : $type;
    }
}
