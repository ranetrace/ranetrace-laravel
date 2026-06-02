<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Utilities;

/**
 * Enforces the per-field JSON byte budget shared by the capture subsystems
 * (log context/extra, JS-error context, breadcrumb data). Oversized data is
 * replaced wholesale with a `_truncated` marker rather than truncated
 * mid-structure, since partial JSON is invalid.
 */
class PayloadSizer
{
    /**
     * Return $data unchanged when its JSON-encoded byte size is within $maxBytes;
     * otherwise return a `['_truncated' => $reason]` marker.
     *
     * Byte size is measured with `mb_strlen(..., '8bit')` (NOT `strlen` — the
     * repo's Pint `mb_str_functions` rule would rewrite `strlen` to a
     * char-counting `mb_strlen`, breaking the byte budget on multibyte data).
     */
    public static function capBytes(mixed $data, int $maxBytes, string $reason): mixed
    {
        if (mb_strlen((string) json_encode($data), '8bit') > $maxBytes) {
            return ['_truncated' => $reason];
        }

        return $data;
    }
}
