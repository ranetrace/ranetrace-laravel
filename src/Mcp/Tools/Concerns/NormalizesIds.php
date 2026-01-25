<?php

declare(strict_types=1);

namespace Sorane\Laravel\Mcp\Tools\Concerns;

trait NormalizesIds
{
    /**
     * Normalize the error ID by stripping the prefix if present.
     * Supports both "err_" (PHP errors) and "jserr_" (JavaScript errors) prefixes.
     */
    protected function normalizeErrorId(?string $errorId): ?string
    {
        if ($errorId === null) {
            return null;
        }

        if (str_starts_with($errorId, 'jserr_')) {
            return mb_substr($errorId, 6);
        }

        if (str_starts_with($errorId, 'err_')) {
            return mb_substr($errorId, 4);
        }

        return $errorId;
    }

    /**
     * Normalize the note ID by stripping the prefix if present.
     */
    protected function normalizeNoteId(?string $noteId): ?string
    {
        if ($noteId === null) {
            return null;
        }

        return str_starts_with($noteId, 'note_')
            ? mb_substr($noteId, 5)
            : $noteId;
    }
}
