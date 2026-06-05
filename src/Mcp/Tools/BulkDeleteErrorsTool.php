<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Ranetrace\Laravel\Mcp\Tools\Concerns\HasBulkErrorLimits;
use Ranetrace\Laravel\Mcp\Tools\Concerns\NormalizesIds;
use Ranetrace\Laravel\Services\RanetraceApiClient;

class BulkDeleteErrorsTool extends Tool
{
    use HasBulkErrorLimits;
    use NormalizesIds;

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
        $context = $this->resolveBulkErrorContext($request->get('error_ids'), $request->get('type'));

        if (! $context['ok']) {
            return Response::error($context['error']);
        }

        $result = $this->client->bulkDeleteErrors($context['ids'], $context['type']);

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
                ->description('REQUIRED. The error type — "php" or "javascript" (or "js"). Must match the id prefixes; all ids in one call must be the same type.')
                ->enum(['php', 'javascript', 'js'])
                ->required(),
        ];
    }

    protected function bulkActionVerb(): string
    {
        return 'delete';
    }

    protected function bulkActionPastVerb(): string
    {
        return 'deleted';
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
