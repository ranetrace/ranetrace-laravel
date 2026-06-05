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

class UnsnoozeErrorTool extends Tool
{
    use MapsErrorActionResponse, NormalizesErrorType, NormalizesIds;

    /**
     * The tool's description.
     */
    protected string $description = 'Remove snooze from an error, returning it to normal state. This is an idempotent operation.';

    public function __construct(
        protected RanetraceApiClient $client
    ) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $context = $this->resolveErrorContext($request->get('error_id'), $request->get('type'));

        if (! $context['ok']) {
            return Response::error($context['error']);
        }

        $errorId = $context['id'];
        $type = $context['type'];

        $result = $this->client->unsnoozeError($errorId, $type);

        if (! $result['success']) {
            return $this->handleErrorResponse($result, $errorId);
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
                ->description('REQUIRED. The error type — "php" or "javascript" (or "js"). Must match the err_/jserr_ id prefix.')
                ->enum(['php', 'javascript', 'js'])
                ->required(),
        ];
    }

    protected function actionFailureMessage(): string
    {
        return 'Failed to unsnooze error';
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
        $state = $error['state'] ?? 'open';
        $isResolved = ($error['is_resolved'] ?? false) ? 'Yes' : 'No';
        $isIgnored = ($error['is_ignored'] ?? false) ? 'Yes' : 'No';
        $snoozeUntil = $error['snooze_until'] ?? 'None';

        $activityId = $activity['id'] ?? 'N/A';
        $activityAction = $activity['action'] ?? 'unsnoozed';
        $performedAt = $activity['performed_at'] ?? 'N/A';

        return <<<RESPONSE
        # Error Unsnoozed Successfully

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
