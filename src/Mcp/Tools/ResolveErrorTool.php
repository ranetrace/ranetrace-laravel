<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Ranetrace\Laravel\Mcp\Tools\Concerns\MapsErrorActionResponse;
use Ranetrace\Laravel\Mcp\Tools\Concerns\NormalizesErrorType;
use Ranetrace\Laravel\Mcp\Tools\Concerns\NormalizesIds;
use Ranetrace\Laravel\Services\RanetraceApiClient;

class ResolveErrorTool extends Tool
{
    use MapsErrorActionResponse, NormalizesErrorType, NormalizesIds;

    /**
     * The tool's description.
     */
    protected string $description = 'Mark an error as resolved. This is an idempotent operation - resolving an already resolved error succeeds silently.';

    public function __construct(
        protected RanetraceApiClient $client
    ) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $errorId = $this->normalizeErrorId($request->get('error_id'));
        $type = $this->normalizeType($request->get('type'));

        if (empty($errorId)) {
            return Response::error('Error ID is required.');
        }

        $result = $this->client->resolveError($errorId, $type);

        if (! $result['success']) {
            return $this->handleErrorResponse($result, $errorId);
        }

        return Response::text($this->formatResponse($result['data'], $errorId, 'resolved'));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'error_id' => $schema->string()
                ->description('The error ID (with or without err_ prefix).')
                ->required(),
            'type' => $schema->string()
                ->description('The error type: "php" (default), "javascript", or "js".')
                ->enum(['php', 'javascript', 'js']),
        ];
    }

    protected function actionFailureMessage(): string
    {
        return 'Failed to resolve error';
    }

    /**
     * Format the success response.
     *
     * @param  array<string, mixed>  $data
     */
    protected function formatResponse(array $data, string $errorId, string $action): string
    {
        $error = $data['error'] ?? [];
        $activity = $data['activity'] ?? [];

        $id = $error['id'] ?? "err_{$errorId}";
        $state = $error['state'] ?? $action;
        $isResolved = ($error['is_resolved'] ?? false) ? 'Yes' : 'No';
        $isIgnored = ($error['is_ignored'] ?? false) ? 'Yes' : 'No';
        $snoozeUntil = $error['snooze_until'] ?? 'None';

        $activityId = $activity['id'] ?? 'N/A';
        $activityAction = $activity['action'] ?? $action;
        $performedAt = $activity['performed_at'] ?? 'N/A';

        return <<<RESPONSE
        # Error Resolved Successfully

        ## Error State
        - **Error ID:** {$id}
        - **State:** {$state}
        - **Resolved:** {$isResolved}
        - **Ignored:** {$isIgnored}
        - **Snoozed Until:** {$snoozeUntil}

        ## Activity Log Entry
        - **Activity ID:** {$activityId}
        - **Action:** {$activityAction}
        - **Performed At:** {$performedAt}
        RESPONSE;
    }
}
