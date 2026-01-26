<?php

declare(strict_types=1);

namespace Sorane\Laravel\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Sorane\Laravel\Mcp\Tools\Concerns\NormalizesIds;
use Sorane\Laravel\Services\SoraneApiClient;

#[IsReadOnly]
class GetErrorActivityTool extends Tool
{
    use NormalizesIds;

    /**
     * The tool's description.
     */
    protected string $description = 'Get the activity log for an error, showing state change history including resolved, ignored, snoozed, and other actions.';

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

        $params = $this->buildParams($request);

        $result = $this->client->getErrorActivity($errorId, $params, $type);

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
            'limit' => $schema->integer()
                ->description('Maximum number of activities to return (default: 50).')
                ->min(1)
                ->max(100),
            'offset' => $schema->integer()
                ->description('Number of activities to skip for pagination.')
                ->min(0),
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
     * Build the query parameters from the request.
     *
     * @return array<string, int>
     */
    protected function buildParams(Request $request): array
    {
        $params = [];

        $limit = $request->get('limit');
        if ($limit !== null) {
            $params['limit'] = min(max((int) $limit, 1), 100);
        }

        $offset = $request->get('offset');
        if ($offset !== null) {
            $params['offset'] = max((int) $offset, 0);
        }

        return $params;
    }

    /**
     * Handle error responses from the API.
     *
     * @param  array<string, mixed>  $result
     */
    protected function handleErrorResponse(array $result, string $errorId): Response
    {
        $errorMessage = $result['error'] ?? 'Unknown error occurred';

        return match ($result['status']) {
            404 => Response::error("Error with ID '{$errorId}' not found."),
            403 => Response::error("Access denied: {$errorMessage}"),
            default => Response::error("Failed to get activity log: {$errorMessage}"),
        };
    }

    /**
     * Format the success response.
     *
     * @param  array<string, mixed>  $data
     */
    protected function formatResponse(array $data, string $errorId): string
    {
        $activities = $data['activities'] ?? [];
        $meta = $data['meta'] ?? [];

        $total = $meta['total'] ?? count($activities);
        $limit = $meta['limit'] ?? 50;
        $offset = $meta['offset'] ?? 0;

        if (empty($activities)) {
            return <<<RESPONSE
            # Activity Log for Error err_{$errorId}

            No activity log entries found.

            ## Meta
            - **Total:** 0
            - **Limit:** {$limit}
            - **Offset:** {$offset}
            RESPONSE;
        }

        $activityLines = [];
        foreach ($activities as $index => $activity) {
            $activityId = $activity['id'] ?? 'N/A';
            $action = $activity['action'] ?? 'unknown';
            $causerName = $activity['causer_name'] ?? 'Unknown';
            $performedAt = $activity['performed_at'] ?? 'N/A';

            $activityLines[] = "### {$index}. {$action}";
            $activityLines[] = "- **Activity ID:** {$activityId}";
            $activityLines[] = "- **Performed By:** {$causerName}";
            $activityLines[] = "- **Performed At:** {$performedAt}";
            $activityLines[] = '';
        }

        $activityList = implode("\n", $activityLines);

        return <<<RESPONSE
        # Activity Log for Error err_{$errorId}

        {$activityList}
        ## Meta
        - **Total:** {$total}
        - **Limit:** {$limit}
        - **Offset:** {$offset}
        RESPONSE;
    }
}
