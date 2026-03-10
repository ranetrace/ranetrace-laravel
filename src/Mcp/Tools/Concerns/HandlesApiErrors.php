<?php

declare(strict_types=1);

namespace Sorane\Laravel\Mcp\Tools\Concerns;

use Laravel\Mcp\Response;

trait HandlesApiErrors
{
    /**
     * Handle API error responses consistently.
     *
     * @param  array<string, mixed>  $result
     */
    protected function handleApiError(array $result, string $defaultMessage = 'Request failed'): Response
    {
        $status = $result['status'] ?? 0;
        $data = $result['data'] ?? [];
        $errorCode = $result['error_code'] ?? $data['error_code'] ?? null;
        $errorMessage = $result['error'] ?? $data['message'] ?? $defaultMessage;

        if ($status === 403 && $errorCode === 'SUBSCRIPTION_REQUIRED') {
            return Response::error('Sorane: API access requires an active subscription or trial.');
        }

        return match ($status) {
            401 => Response::error('Authentication failed: Invalid or expired API key.'),
            403 => Response::error("Access denied: {$errorMessage}"),
            404 => Response::error("Not found: {$errorMessage}"),
            422 => Response::error("Validation failed: {$errorMessage}"),
            429 => Response::error('Rate limit exceeded. Please try again later.'),
            default => Response::error("{$defaultMessage}: {$errorMessage}"),
        };
    }
}
