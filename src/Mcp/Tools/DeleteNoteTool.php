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

class DeleteNoteTool extends Tool
{
    use HandlesApiErrors;
    use NormalizesIds;

    /**
     * The tool's description.
     */
    protected string $description = 'Archive (delete) an investigation note on an error. Can only delete notes created by the AI Agent. Archived notes are hidden by default but can be recovered.';

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

        $result = $this->client->deleteNote($errorId, $noteId);

        if (! $result['success']) {
            return $this->handleApiError($result, 'Failed to delete note');
        }

        return Response::text("Note '{$noteId}' has been archived successfully.");
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
}
