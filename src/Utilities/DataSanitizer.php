<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Utilities;

use Closure;
use Throwable;

class DataSanitizer
{
    /**
     * Hard recursion ceiling. Bounds deep or circular object/array graphs so a
     * pathological structure (e.g. an Eloquent model with circular relations
     * logged in context) cannot recurse to stack exhaustion — which would be an
     * uncatchable fatal, defeating the capture paths' failure isolation.
     */
    private const int MAX_DEPTH = 20;

    /**
     * Sanitize data for serialization by removing closures and non-serializable values
     */
    public static function sanitizeForSerialization(mixed $data, int $depth = 0): mixed
    {
        if ($depth >= self::MAX_DEPTH) {
            return '[Max depth exceeded]';
        }

        if (is_array($data)) {
            return array_map(
                fn ($value) => self::sanitizeForSerialization($value, $depth + 1),
                $data
            );
        }

        if (is_object($data)) {
            if ($data instanceof Closure) {
                return '[Closure]';
            }

            // Try to convert objects to arrays, but catch any serialization issues
            try {
                // For objects that implement JsonSerializable
                if (method_exists($data, 'jsonSerialize')) {
                    return self::sanitizeForSerialization($data->jsonSerialize(), $depth + 1);
                }

                // For objects that implement toArray
                if (method_exists($data, 'toArray')) {
                    return self::sanitizeForSerialization($data->toArray(), $depth + 1);
                }

                // For other objects, try to convert to string or return class name
                if (method_exists($data, '__toString')) {
                    return (string) $data;
                }

                return '[Object: '.get_class($data).']';
            } catch (Throwable $e) {
                return '[Object: '.get_class($data).' - serialization failed]';
            }
        }

        // For resources and other non-serializable types
        if (is_resource($data)) {
            return '[Resource: '.get_resource_type($data).']';
        }

        // Return primitive values as-is
        return $data;
    }
}
