<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Mcp\Tools\Concerns;

use Laravel\Mcp\Response;

/**
 * Shared limit and request-handling helpers for the bulk error-action MCP
 * tools (resolve, reopen, ignore, delete, restore). Keeps the per-tool
 * ceiling, type normalization, ID-array validation and error-response mapping
 * in one place so the only per-tool variation is the action verb.
 */
trait HasBulkErrorLimits
{
    protected const int MAX_BULK_ERRORS = 50;

    /**
     * Infinitive verb for the bulk action, e.g. "delete", "ignore", "reopen".
     * Used in user-facing failure messages ("Failed to bulk {verb} errors").
     */
    abstract protected function bulkActionVerb(): string;

    /**
     * Past participle for the bulk action, e.g. "deleted", "ignored",
     * "reopened". Used in the limit-exceeded validation message.
     */
    abstract protected function bulkActionPastVerb(): string;

    /**
     * Normalize the error type parameter. Accepts the "js" alias for
     * "javascript"; null defaults to "php".
     */
    protected function normalizeType(?string $type): string
    {
        if ($type === null) {
            return 'php';
        }

        return $type === 'js' ? 'javascript' : $type;
    }

    /**
     * Validate the error IDs array. Returns the first error message found, or
     * null when the array is acceptable.
     *
     * @param  mixed  $errorIds
     */
    protected function validateErrorIds($errorIds): ?string
    {
        if (! is_array($errorIds)) {
            return 'The "error_ids" parameter must be an array.';
        }

        if (empty($errorIds)) {
            return 'At least one error ID is required.';
        }

        if (count($errorIds) > self::MAX_BULK_ERRORS) {
            return 'Maximum '.self::MAX_BULK_ERRORS.' errors can be '.$this->bulkActionPastVerb().' at once.';
        }

        foreach ($errorIds as $index => $id) {
            if (! is_string($id) || empty($id)) {
                return "Invalid error ID at index {$index}.";
            }
        }

        return null;
    }

    /**
     * Map an API failure result to a user-facing Response::error().
     *
     * @param  array<string, mixed>  $result
     */
    protected function handleErrorResponse(array $result): Response
    {
        $errorMessage = $result['error'] ?? 'Unknown error occurred';

        return match ($result['status']) {
            404 => Response::error("One or more errors not found: {$errorMessage}"),
            403 => Response::error("Access denied: {$errorMessage}"),
            422 => Response::error("Validation failed: {$errorMessage}"),
            default => Response::error('Failed to bulk '.$this->bulkActionVerb().' errors: '.$errorMessage),
        };
    }
}
