<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Mcp\Tools\Concerns;

use Laravel\Mcp\Response;

/**
 * Maps an API failure result to a user-facing `Response::error()` for the MCP
 * tools that operate on a single error id. The 404/403/422 mapping is shared;
 * the only per-tool variation is the default-case action description, supplied
 * by {@see actionFailureMessage()}.
 */
trait MapsErrorActionResponse
{
    /**
     * User-facing failure description for the default (non-404/403/422) case,
     * e.g. "Failed to resolve error" or "Failed to create note".
     */
    abstract protected function actionFailureMessage(): string;

    /**
     * @param  array<string, mixed>  $result
     */
    protected function handleErrorResponse(array $result, string $errorId): Response
    {
        $errorMessage = $result['error'] ?? 'Unknown error occurred';

        return match ($result['status']) {
            404 => Response::error("Error with ID '{$errorId}' not found."),
            403 => Response::error("Access denied: {$errorMessage}"),
            422 => Response::error("Validation failed: {$errorMessage}"),
            default => Response::error($this->actionFailureMessage().": {$errorMessage}"),
        };
    }
}
