<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Ranetrace\Laravel\Mcp\Tools\Concerns\NormalizesIds;
use Ranetrace\Laravel\Services\RanetraceApiClient;

class BulkDeleteErrorsTool extends Tool
{
    use NormalizesIds;

    protected const MAX_ERRORS = 50;

    /**
     * The tool's description.
     */
    protected string $description = 'Soft delete (archive) multiple errors at once. Maximum 50 errors per request. Operations are atomic - all succeed or all fail.';

    public function __construct(
        protected RanetraceApiClient $client
    ) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $errorIds = $request->get('error_ids');
        $type = $this->normalizeType($request->get('type'));

        $validationError = $this->validateErrorIds($errorIds);
        if ($validationError !== null) {
            return Response::error($validationError);
        }

        $normalizedIds = array_map(fn ($id) => $this->normalizeErrorId($id), $errorIds);

        $result = $this->client->bulkDeleteErrors($normalizedIds, $type);

        if (! $result['success']) {
            return $this->handleErrorResponse($result);
        }

        return Response::text($this->formatResponse($result['data']));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'error_ids' => $schema->array()
                ->description('Array of error IDs to delete (max 50). IDs can include err_ prefix.')
                ->required(),
            'type' => $schema->string()
                ->description('The error type: "php" (default), "javascript", or "js".')
                ->enum(['php', 'javascript', 'js']),
        ];
    }

    /**
     * Normalize the error type parameter.
     */
    protected function normalizeType(?string $type): string
    {
        if ($type === null) {
            return 'php';
        }

        return $type === 'js' ? 'javascript' : $type;
    }

    /**
     * Validate the error IDs array.
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

        if (count($errorIds) > self::MAX_ERRORS) {
            return 'Maximum '.self::MAX_ERRORS.' errors can be deleted at once.';
        }

        foreach ($errorIds as $index => $id) {
            if (! is_string($id) || empty($id)) {
                return "Invalid error ID at index {$index}.";
            }
        }

        return null;
    }

    /**
     * Handle error responses from the API.
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
            default => Response::error("Failed to bulk delete errors: {$errorMessage}"),
        };
    }

    /**
     * Format the success response.
     *
     * @param  array<string, mixed>  $data
     */
    protected function formatResponse(array $data): string
    {
        $deletedCount = $data['deleted_count'] ?? 0;
        $errors = $data['errors'] ?? [];

        $errorLines = [];
        foreach ($errors as $error) {
            $id = $error['id'] ?? 'unknown';
            $state = $error['state'] ?? 'archived';
            $errorLines[] = "- **{$id}**: {$state}";
        }

        $errorList = ! empty($errorLines) ? implode("\n", $errorLines) : 'No details available';

        return <<<RESPONSE
        # Bulk Delete Completed

        **Deleted Count:** {$deletedCount}

        ## Errors
        {$errorList}
        RESPONSE;
    }
}
