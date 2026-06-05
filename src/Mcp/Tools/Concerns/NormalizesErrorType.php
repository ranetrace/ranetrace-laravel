<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Mcp\Tools\Concerns;

/**
 * Resolves and validates the (error id, type) pair for the typed error tools.
 *
 * `type` is REQUIRED — there is no silent "php" default, because php and
 * javascript errors live in separate id spaces (the same numeric id exists in
 * both), so a wrong/missing type would act on a *different* error. When the id
 * carries an `err_`/`jserr_` prefix it must match the supplied type; the "js"
 * alias maps to "javascript". Shared so the single-action and bulk tools cannot
 * drift.
 */
trait NormalizesErrorType
{
    use NormalizesIds;

    /**
     * Canonicalize the error type: the "js" alias maps to "javascript".
     */
    protected function normalizeType(string $type): string
    {
        return $type === 'js' ? 'javascript' : $type;
    }

    /**
     * Validate and resolve a single error id + type. Returns the stripped id and
     * canonical type on success, or a user-facing error message.
     *
     * @return array{ok: bool, error: ?string, id: ?string, type: ?string}
     */
    protected function resolveErrorContext(?string $rawId, ?string $rawType): array
    {
        if ($rawId === null || $rawId === '') {
            return ['ok' => false, 'error' => 'Error ID is required.', 'id' => null, 'type' => null];
        }

        $typeError = $this->validateTypeForId($rawId, $rawType);
        if ($typeError !== null) {
            return ['ok' => false, 'error' => $typeError, 'id' => null, 'type' => null];
        }

        return [
            'ok' => true,
            'error' => null,
            'id' => $this->normalizeErrorId($rawId),
            'type' => $this->normalizeType((string) $rawType),
        ];
    }

    /**
     * Require a `type`, and when the id is prefixed require it to match. Returns
     * a user-facing error message, or null when the pair is acceptable.
     */
    protected function validateTypeForId(string $rawId, ?string $rawType): ?string
    {
        if ($rawType === null || $rawType === '') {
            return 'The "type" parameter is required: "php" or "javascript" (use "js" for javascript).';
        }

        $type = $this->normalizeType($rawType);
        $prefixType = $this->errorTypeFromPrefix($rawId);

        if ($prefixType !== null && $prefixType !== $type) {
            return "Error ID '{$rawId}' implies type '{$prefixType}', but type '{$type}' was given. "
                .'Pass the matching type, or use the bare numeric id.';
        }

        return null;
    }
}
