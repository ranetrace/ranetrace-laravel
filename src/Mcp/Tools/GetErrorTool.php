<?php

declare(strict_types=1);

namespace Sorane\Laravel\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Sorane\Laravel\Services\SoraneApiClient;

#[IsReadOnly]
class GetErrorTool extends Tool
{
    /**
     * The tool's description.
     */
    protected string $description = 'Get detailed information about a specific error by its ID. Returns the full error details including stack trace, context, and metadata.';

    public function __construct(
        protected SoraneApiClient $client
    ) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $errorId = $request->get('error_id');

        if (empty($errorId)) {
            return Response::error('Error ID is required.');
        }

        $result = $this->client->getError($errorId);

        if (! $result['success']) {
            $errorMessage = $result['error'] ?? 'Unknown error occurred';

            if ($result['status'] === 404) {
                return Response::error("Error with ID '{$errorId}' not found.");
            }

            return Response::error("Failed to fetch error: {$errorMessage}");
        }

        $error = $result['data']['error'] ?? $result['data'] ?? [];

        if (empty($error)) {
            return Response::error("Error with ID '{$errorId}' not found.");
        }

        return Response::text($this->formatErrorDetails($error));
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
                ->description('The unique identifier of the error to retrieve.')
                ->required(),
        ];
    }

    /**
     * Format the full error details for display.
     *
     * @param  array<string, mixed>  $error
     */
    protected function formatErrorDetails(array $error): string
    {
        $id = $error['id'] ?? 'unknown';
        $message = $error['message'] ?? 'No message';
        $type = $error['type'] ?? 'unknown';
        $exceptionClass = $error['exception_class'] ?? 'unknown';
        $environment = $error['environment'] ?? 'unknown';
        $occurredAt = $error['occurred_at'] ?? 'unknown';
        $occurrences = $error['occurrences'] ?? 1;
        $file = $error['file'] ?? 'unknown';
        $line = $error['line'] ?? 'unknown';

        $output = <<<ERROR
        # Error Details

        **ID:** {$id}
        **Type:** {$type}
        **Exception Class:** {$exceptionClass}
        **Environment:** {$environment}
        **Message:** {$message}
        **File:** {$file}:{$line}
        **Occurred at:** {$occurredAt}
        **Total Occurrences:** {$occurrences}

        ERROR;

        if (! empty($error['stack_trace'])) {
            $stackTrace = is_array($error['stack_trace'])
                ? implode("\n", $error['stack_trace'])
                : $error['stack_trace'];
            $output .= "\n## Stack Trace\n```\n{$stackTrace}\n```\n";
        }

        if (! empty($error['context'])) {
            $context = is_array($error['context'])
                ? json_encode($error['context'], JSON_PRETTY_PRINT)
                : $error['context'];
            $output .= "\n## Context\n```json\n{$context}\n```\n";
        }

        if (! empty($error['request'])) {
            $requestData = is_array($error['request'])
                ? json_encode($error['request'], JSON_PRETTY_PRINT)
                : $error['request'];
            $output .= "\n## Request Data\n```json\n{$requestData}\n```\n";
        }

        if (! empty($error['user'])) {
            $userData = is_array($error['user'])
                ? json_encode($error['user'], JSON_PRETTY_PRINT)
                : $error['user'];
            $output .= "\n## User\n```json\n{$userData}\n```\n";
        }

        return $output;
    }
}
