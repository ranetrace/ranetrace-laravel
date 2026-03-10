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

class IgnoreErrorTool extends Tool
{
    use HandlesApiErrors;
    use NormalizesIds;

    /**
     * The tool's description.
     */
    protected string $description = 'Permanently ignore an error. Ignored errors will not trigger notifications. This is an idempotent operation.';

    public function __construct(
        protected SoraneApiClient $client
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

        $result = $this->client->ignoreError($errorId, $type);

        if (! $result['success']) {
            return $this->handleApiError($result, 'Failed to ignore error');
        }

        return Response::text($this->formatResponse($result['data'], $errorId));
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
     * Format the success response.
     *
     * @param  array<string, mixed>  $data
     */
    protected function formatResponse(array $data, string $errorId): string
    {
        $error = $data['error'] ?? [];
        $activity = $data['activity'] ?? [];

        $id = $error['id'] ?? "err_{$errorId}";
        $state = $error['state'] ?? 'ignored';
        $isResolved = ($error['is_resolved'] ?? false) ? 'Yes' : 'No';
        $isIgnored = ($error['is_ignored'] ?? false) ? 'Yes' : 'No';
        $snoozeUntil = $error['snooze_until'] ?? 'None';

        $activityId = $activity['id'] ?? 'N/A';
        $activityAction = $activity['action'] ?? 'ignored';
        $performedAt = $activity['performed_at'] ?? 'N/A';

        return <<<RESPONSE
        # Error Ignored Successfully

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
