<?php

declare(strict_types=1);

namespace Sorane\Laravel\Mcp\Tools\Concerns;

trait NormalizesIds
{
    /**
     * Normalize the error ID by stripping the prefix if present.
     */
    protected function normalizeErrorId(?string $errorId): ?string
    {
        if ($errorId === null) {
            return null;
        }

        return str_starts_with($errorId, 'err_')
            ? mb_substr($errorId, 4)
            : $errorId;
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
