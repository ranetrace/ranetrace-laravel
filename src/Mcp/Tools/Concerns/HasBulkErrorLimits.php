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
    use NormalizesErrorType;

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
     * Validate a bulk request: id-array structure, a required `type`, and that
     * every id's prefix matches that single type (php and js errors can't be
     * mixed in one call — they live in separate tables). Returns the stripped
     * ids + canonical type on success, or a user-facing error message.
     *
     * @return array{ok: bool, error: ?string, ids: array<int, string>, type: ?string}
     */
    protected function resolveBulkErrorContext(mixed $rawIds, ?string $rawType): array
    {
        $structureError = $this->validateErrorIds($rawIds);
        if ($structureError !== null) {
            return ['ok' => false, 'error' => $structureError, 'ids' => [], 'type' => null];
        }

        if ($rawType === null || $rawType === '') {
            return ['ok' => false, 'error' => 'The "type" parameter is required: "php" or "javascript" (use "js" for javascript).', 'ids' => [], 'type' => null];
        }

        $type = $this->normalizeType($rawType);

        /** @var array<int, string> $rawIds (validateErrorIds guaranteed a non-empty string array) */
        $ids = [];
        foreach ($rawIds as $rawId) {
            $prefixType = $this->errorTypeFromPrefix($rawId);
            if ($prefixType !== null && $prefixType !== $type) {
                return ['ok' => false, 'error' => "Error ID '{$rawId}' implies type '{$prefixType}', but type '{$type}' was given. All ids in one bulk call must match the single type.", 'ids' => [], 'type' => null];
            }
            $ids[] = (string) $this->normalizeErrorId($rawId);
        }

        return ['ok' => true, 'error' => null, 'ids' => $ids, 'type' => $type];
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
