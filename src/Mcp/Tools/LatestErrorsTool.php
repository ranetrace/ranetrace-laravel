<?php

declare(strict_types=1);

namespace Sorane\Laravel\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Sorane\Laravel\Mcp\Tools\Concerns\HandlesApiErrors;
use Sorane\Laravel\Services\SoraneApiClient;

#[IsReadOnly]
class LatestErrorsTool extends Tool
{
    use HandlesApiErrors;
    protected const VALID_STATUSES = ['open', 'resolved', 'ignored', 'snoozed', 'active', 'closed', 'all'];


    /**
     * The tool's description.
     */
    protected string $description = 'Fetch the latest errors from Sorane. Returns a list of recent errors with their basic information including message, type, and when they occurred.';

    public function __construct(
        protected SoraneApiClient $client
    ) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $params = array_filter([
            'limit' => $request->get('limit'),
            'environment' => $request->get('environment'),
            'type' => $request->get('type'),
        ], fn ($value) => $value !== null);

        $result = $this->client->getLatestErrors($params);

        if (! $result['success']) {
            return $this->handleApiError($result, 'Failed to fetch errors');
        }

        $errors = $result['data']['errors'] ?? [];

        if (empty($errors)) {
            return Response::text('No errors found matching the specified criteria.');
        }

        $output = 'Found '.count($errors)." error(s):\n\n";

        foreach ($errors as $index => $error) {
            $output .= $this->formatError($index + 1, $error);
        }

        return Response::text($output);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()
                ->description('Maximum number of errors to return (1-100). Defaults to 10.')
                ->default(10),

            'environment' => $schema->string()
                ->description('Filter by environment (e.g., "production", "staging", "local").'),

            'type' => $schema->string()
                ->description('Filter by error type (e.g., "exception", "javascript").'),
        ];
    }

    /**
     * Format a single error for display.
     *
     * @param  array<string, mixed>  $error
     */
    protected function formatError(int $index, array $error): string
    {
        $id = $error['id'] ?? 'unknown';
        $message = $error['message'] ?? 'No message';
        $type = $error['type'] ?? 'unknown';
        $environment = $error['environment'] ?? 'unknown';
        $occurredAt = $error['occurred_at'] ?? 'unknown';
        $occurrences = $error['occurrences'] ?? 1;

        return <<<ERROR
        ---
        #{$index} Error ID: {$id}
        Type: {$type}
        Environment: {$environment}
        Message: {$message}
        Occurred at: {$occurredAt}
        Occurrences: {$occurrences}

        ERROR;
    }
}
