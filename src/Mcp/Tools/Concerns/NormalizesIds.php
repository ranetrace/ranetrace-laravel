<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Mcp\Tools\Concerns;

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
     * The error type implied by an id's prefix: "php" for `err_`, "javascript"
     * for `jserr_`, or null when the id carries no recognized prefix. PHP and
     * JS errors live in separate id spaces, so the prefix is authoritative.
     */
    protected function errorTypeFromPrefix(?string $errorId): ?string
    {
        if ($errorId === null) {
            return null;
        }

        if (str_starts_with($errorId, 'jserr_')) {
            return 'javascript';
        }

        if (str_starts_with($errorId, 'err_')) {
            return 'php';
        }

        return null;
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
