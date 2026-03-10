<?php

declare(strict_types=1);

namespace Sorane\Laravel\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Sorane\Laravel\Mcp\Tools\Concerns\HandlesApiErrors;
use Sorane\Laravel\Mcp\Tools\Concerns\NormalizesIds;
use Sorane\Laravel\Services\SoraneApiClient;

#[IsReadOnly]
class GetNoteTool extends Tool
{
    use HandlesApiErrors;
    use NormalizesIds;

    /**
     * The tool's description.
     */
    protected string $description = 'Get detailed information about a specific note on an error. Returns the full note content and metadata.';

    public function __construct(
        protected SoraneApiClient $client
    ) {}

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $errorId = $this->normalizeErrorId($request->get('error_id'));
        $noteId = $this->normalizeNoteId($request->get('note_id'));

        if (empty($errorId)) {
            return Response::error('Error ID is required.');
        }

        if (empty($noteId)) {
            return Response::error('Note ID is required.');
        }

        $result = $this->client->getNote($errorId, $noteId);

        if (! $result['success']) {
            return $this->handleApiError($result, 'Failed to get note');
        }

        $note = $result['data']['note'] ?? $result['data'] ?? [];

        if (empty($note)) {
            return Response::error("Note with ID '{$noteId}' not found.");
        }

        return Response::text($this->formatNoteDetails($note, $errorId));
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
            'note_id' => $schema->string()
                ->description('The note ID (with or without note_ prefix).')
                ->required(),
        ];
    }

    /**
     * Format the note details for display.
     *
     * @param  array<string, mixed>  $note
     */
    protected function formatNoteDetails(array $note, string $errorId): string
    {
        $id = $note['id'] ?? 'unknown';
        $noteErrorId = $note['error_id'] ?? $errorId;
        $body = $note['body'] ?? '';
        $authorName = $note['author_name'] ?? 'Unknown';
        $createdAt = $note['created_at'] ?? 'unknown';
        $updatedAt = $note['updated_at'] ?? $createdAt;
        $archived = ! empty($note['archived']) ? 'Yes' : 'No';

        return <<<NOTE
        # Note Details

        **Note ID:** {$id}
        **Error ID:** {$noteErrorId}
        **Author:** {$authorName}
        **Created at:** {$createdAt}
        **Updated at:** {$updatedAt}
        **Archived:** {$archived}

        ## Content

        {$body}
        NOTE;
    }
}
