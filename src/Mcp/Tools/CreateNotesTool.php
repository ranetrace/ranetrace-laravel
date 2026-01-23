<?php

declare(strict_types=1);

namespace Sorane\Laravel\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Sorane\Laravel\Mcp\Tools\Concerns\NormalizesIds;
use Sorane\Laravel\Services\SoraneApiClient;

class CreateNotesTool extends Tool
{
    use NormalizesIds;

    protected const MAX_NOTES = 10;

    protected const MAX_BODY_LENGTH = 5000;

    /**
     * The tool's description.
     */
    protected string $description = 'Bulk create multiple investigation notes on an error. Creates up to 10 notes in a single request with all-or-nothing transaction behavior.';

    public function __construct(
        protected SoraneApiClient $client
    ) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $errorId = $this->normalizeErrorId($request->get('error_id'));
        $notes = $request->get('notes');

        if (empty($errorId)) {
            return Response::error('Error ID is required.');
        }

        if (empty($notes) || ! is_array($notes)) {
            return Response::error('Notes array is required.');
        }

        if (count($notes) > self::MAX_NOTES) {
            return Response::error('Maximum of '.self::MAX_NOTES.' notes can be created per request.');
        }

        $validationError = $this->validateNotes($notes);
        if ($validationError !== null) {
            return Response::error($validationError);
        }

        $result = $this->client->createNotesBulk($errorId, ['notes' => $notes]);

        if (! $result['success']) {
            return $this->handleErrorResponse($result, $errorId);
        }

        $createdNotes = $result['data']['notes'] ?? [];

        if (empty($createdNotes)) {
            return Response::error('Failed to create notes: empty response received.');
        }

        return Response::text($this->formatCreatedNotes($createdNotes, $errorId));
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
            'notes' => $schema->array()
                ->description('Array of note objects with body field (max 10 notes).')
                ->items($schema->object()
                    ->properties([
                        'body' => $schema->string()
                            ->description('The markdown content of the note (max 5000 characters).'),
                    ])
                )
                ->required(),
        ];
    }

    /**
     * Validate the notes array.
     *
     * @param  array<int, mixed>  $notes
     */
    protected function validateNotes(array $notes): ?string
    {
        foreach ($notes as $index => $note) {
            if (! is_array($note)) {
                return "Note at index {$index} must be an object with a body field.";
            }

            if (empty($note['body'])) {
                return "Note at index {$index} is missing required body field.";
            }

            if (mb_strlen($note['body']) > self::MAX_BODY_LENGTH) {
                return "Note at index {$index} exceeds maximum body length of ".self::MAX_BODY_LENGTH.' characters.';
            }
        }

        return null;
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
            422 => Response::error("Validation failed: {$errorMessage}"),
            default => Response::error("Failed to create notes: {$errorMessage}"),
        };
    }

    /**
     * Format the created notes for display.
     *
     * @param  array<int, array<string, mixed>>  $notes
     */
    protected function formatCreatedNotes(array $notes, string $errorId): string
    {
        $count = count($notes);
        $output = "# {$count} Note(s) Created Successfully\n\n";
        $output .= "**Error ID:** {$errorId}\n\n";

        foreach ($notes as $index => $note) {
            $id = $note['id'] ?? 'unknown';
            $authorName = $note['author_name'] ?? 'Unknown';
            $createdAt = $note['created_at'] ?? 'unknown';
            $body = $note['body'] ?? '';

            $truncatedBody = mb_strlen($body) > 200
                ? mb_substr($body, 0, 200).'...'
                : $body;

            $output .= "---\n\n";
            $output .= '**Note '.($index + 1)."**\n";
            $output .= "**ID:** {$id}\n";
            $output .= "**Author:** {$authorName}\n";
            $output .= "**Created:** {$createdAt}\n\n";
            $output .= "{$truncatedBody}\n\n";
        }

        return $output;
    }
}
