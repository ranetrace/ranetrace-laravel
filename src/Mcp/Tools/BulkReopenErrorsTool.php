<?php

declare(strict_types=1);

namespace Sorane\Laravel\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Sorane\Laravel\Mcp\Tools\Concerns\HandlesApiErrors;
use Sorane\Laravel\Mcp\Tools\Concerns\NormalizesIds;
use Sorane\Laravel\Services\SoraneApiClient;

class BulkReopenErrorsTool extends Tool
{
    use HandlesApiErrors;
    use NormalizesIds;

    protected const MAX_ERRORS = 50;

    /**
     * The tool's description.
     */
    protected string $description = 'Reopen multiple resolved errors at once. Maximum 50 errors per request. Operations are atomic - all succeed or all fail.';

    public function __construct(
        protected SoraneApiClient $client
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

        $result = $this->client->bulkReopenErrors($normalizedIds, $type);

        if (! $result['success']) {
            return $this->handleApiError($result, 'Failed to bulk reopen errors');
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
                ->description('Array of error IDs to reopen (max 50). IDs can include err_ or jserr_ prefix.')
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
            return 'Maximum '.self::MAX_ERRORS.' errors can be reopened at once.';
        }

        foreach ($errorIds as $index => $id) {
            if (! is_string($id) || empty($id)) {
                return "Invalid error ID at index {$index}.";
            }
        }

        return null;
    }

    /**
     * Format the success response.
     *
     * @param  array<string, mixed>  $data
     */
    protected function formatResponse(array $data): string
    {
        $reopenedCount = $data['reopened_count'] ?? 0;
        $errors = $data['errors'] ?? [];

        $errorLines = [];
        foreach ($errors as $error) {
            $id = $error['id'] ?? 'unknown';
            $state = $error['state'] ?? 'open';
            $errorLines[] = "- **{$id}**: {$state}";
        }

        $errorList = ! empty($errorLines) ? implode("\n", $errorLines) : 'No details available';

        return <<<RESPONSE
        # Bulk Reopen Completed

        **Reopened Count:** {$reopenedCount}

        ## Errors
        {$errorList}
        RESPONSE;
    }
}
