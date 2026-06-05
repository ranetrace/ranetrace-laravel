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

class DeleteErrorTool extends Tool
{
    use MapsErrorActionResponse, NormalizesErrorType, NormalizesIds;

    /**
     * The tool's description.
     */
    protected string $description = 'Soft delete (archive) an error. Archived errors are hidden from default views but can be restored if needed.';

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

        $result = $this->client->deleteError($errorId, $type);

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
        return 'Failed to delete error';
    }

    /**
     * Format the success response.
     *
     * @param  array<string, mixed>  $data
     */
    protected function formatResponse(array $data, string $errorId): string
    {
        $activity = $data['activity'] ?? [];
        $message = $data['message'] ?? 'Error archived successfully';

        $activityId = $activity['id'] ?? 'N/A';
        $activityAction = $activity['action'] ?? 'archived';
        $performedAt = $activity['performed_at'] ?? 'N/A';

        return <<<RESPONSE
        # Error Archived Successfully

        **Message:** {$message}
        **Error ID:** err_{$errorId}

        ## Activity Log Entry
        - **Activity ID:** {$activityId}
        - **Action:** {$activityAction}
        - **Performed At:** {$performedAt}
        RESPONSE;
    }
}
