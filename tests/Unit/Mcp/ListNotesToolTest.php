<?php

declare(strict_types=1);

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Sorane\Laravel\Mcp\Tools\ListNotesTool;
use Sorane\Laravel\Services\SoraneApiClient;

beforeEach(function (): void {
    if (! class_exists(Laravel\Mcp\Server\Tool::class)) {
        $this->markTestSkipped('Laravel MCP package not installed');
    }

    $this->mockClient = Mockery::mock(SoraneApiClient::class);
    $this->tool = new ListNotesTool($this->mockClient);
});

test('lists notes successfully and returns formatted output', function (): void {
    $this->mockClient->shouldReceive('listNotes')
        ->once()
        ->with('123', [])
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => [
                'notes' => [
                    [
                        'id' => 'note_456',
                        'body' => 'First investigation note',
                        'author_name' => 'AI Agent',
                        'created_at' => '2026-01-23T12:34:56+00:00',
                    ],
                    [
                        'id' => 'note_457',
                        'body' => 'Second note by user',
                        'author_name' => 'John Doe',
                        'created_at' => '2026-01-23T12:35:56+00:00',
                    ],
                ],
                'meta' => ['total' => 2, 'limit' => 50, 'offset' => 0],
            ],
        ]);

    $response = $this->tool->handle(new Request(['error_id' => '123']));

    expect($response)->toBeInstanceOf(Response::class);
    expect((string) $response->content())
        ->toContain('Notes for Error 123')
        ->toContain('Showing 2 of 2 notes')
        ->toContain('note_456')
        ->toContain('AI Agent')
        ->toContain('John Doe');
});

test('normalizes error ID by stripping err_ prefix', function (): void {
    $this->mockClient->shouldReceive('listNotes')
        ->once()
        ->with('123', [])
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => ['notes' => [], 'meta' => ['total' => 0]],
        ]);

    $this->tool->handle(new Request(['error_id' => 'err_123']));
});

test('returns error when error_id is missing', function (): void {
    $this->mockClient->shouldNotReceive('listNotes');

    $response = $this->tool->handle(new Request([]));

    expect((string) $response->content())->toContain('Error ID is required');
});

test('returns error when error_id is empty', function (): void {
    $this->mockClient->shouldNotReceive('listNotes');

    $response = $this->tool->handle(new Request(['error_id' => '']));

    expect((string) $response->content())->toContain('Error ID is required');
});

test('returns message when no notes found', function (): void {
    $this->mockClient->shouldReceive('listNotes')
        ->once()
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => ['notes' => [], 'meta' => ['total' => 0]],
        ]);

    $response = $this->tool->handle(new Request(['error_id' => '123']));

    expect((string) $response->content())->toContain("No notes found for error '123'");
});

test('passes limit parameter to API', function (): void {
    $this->mockClient->shouldReceive('listNotes')
        ->once()
        ->with('123', ['limit' => 10])
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => ['notes' => [], 'meta' => ['total' => 0]],
        ]);

    $this->tool->handle(new Request(['error_id' => '123', 'limit' => 10]));
});

test('passes author parameter to API', function (): void {
    $this->mockClient->shouldReceive('listNotes')
        ->once()
        ->with('123', ['author' => 'ai'])
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => ['notes' => [], 'meta' => ['total' => 0]],
        ]);

    $this->tool->handle(new Request(['error_id' => '123', 'author' => 'ai']));
});

test('clamps limit to minimum of 1', function (): void {
    $this->mockClient->shouldReceive('listNotes')
        ->once()
        ->with('123', ['limit' => 1])
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => ['notes' => [], 'meta' => ['total' => 0]],
        ]);

    $this->tool->handle(new Request(['error_id' => '123', 'limit' => 0]));
});

test('clamps limit to maximum of 100', function (): void {
    $this->mockClient->shouldReceive('listNotes')
        ->once()
        ->with('123', ['limit' => 100])
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => ['notes' => [], 'meta' => ['total' => 0]],
        ]);

    $this->tool->handle(new Request(['error_id' => '123', 'limit' => 200]));
});

test('ignores empty author parameter', function (): void {
    $this->mockClient->shouldReceive('listNotes')
        ->once()
        ->with('123', [])
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => ['notes' => [], 'meta' => ['total' => 0]],
        ]);

    $this->tool->handle(new Request(['error_id' => '123', 'author' => '']));
});

test('returns error for 404 status', function (): void {
    $this->mockClient->shouldReceive('listNotes')
        ->once()
        ->andReturn([
            'success' => false,
            'status' => 404,
            'error' => 'Error not found',
        ]);

    $response = $this->tool->handle(new Request(['error_id' => '999']));

    expect((string) $response->content())->toContain("Not found");
});

test('returns error for 403 status', function (): void {
    $this->mockClient->shouldReceive('listNotes')
        ->once()
        ->andReturn([
            'success' => false,
            'status' => 403,
            'error' => 'Access denied',
        ]);

    $response = $this->tool->handle(new Request(['error_id' => '123']));

    expect((string) $response->content())->toContain('Access denied');
});

test('returns generic error for other failures', function (): void {
    $this->mockClient->shouldReceive('listNotes')
        ->once()
        ->andReturn([
            'success' => false,
            'status' => 500,
            'error' => 'Internal server error',
        ]);

    $response = $this->tool->handle(new Request(['error_id' => '123']));

    expect((string) $response->content())
        ->toContain('Failed to list notes')
        ->toContain('Internal server error');
});

test('truncates long note bodies in list view', function (): void {
    $longBody = str_repeat('a', 300);

    $this->mockClient->shouldReceive('listNotes')
        ->once()
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => [
                'notes' => [
                    [
                        'id' => 'note_456',
                        'body' => $longBody,
                        'author_name' => 'AI Agent',
                        'created_at' => '2026-01-23T12:34:56+00:00',
                    ],
                ],
                'meta' => ['total' => 1],
            ],
        ]);

    $response = $this->tool->handle(new Request(['error_id' => '123']));

    expect((string) $response->content())
        ->toContain('...')
        ->not->toContain($longBody);
});

test('displays archived indicator for archived notes', function (): void {
    $this->mockClient->shouldReceive('listNotes')
        ->once()
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => [
                'notes' => [
                    [
                        'id' => 'note_456',
                        'body' => 'Archived note',
                        'author_name' => 'AI Agent',
                        'created_at' => '2026-01-23T12:34:56+00:00',
                        'archived' => true,
                    ],
                ],
                'meta' => ['total' => 1],
            ],
        ]);

    $response = $this->tool->handle(new Request(['error_id' => '123']));

    expect((string) $response->content())->toContain('[ARCHIVED]');
});

test('has IsReadOnly annotation', function (): void {
    $reflection = new ReflectionClass($this->tool);
    $attributes = $reflection->getAttributes();

    $hasReadOnlyAttribute = false;
    foreach ($attributes as $attribute) {
        if (str_contains($attribute->getName(), 'IsReadOnly')) {
            $hasReadOnlyAttribute = true;
            break;
        }
    }

    expect($hasReadOnlyAttribute)->toBeTrue();
});

test('has correct description property', function (): void {
    $reflection = new ReflectionProperty($this->tool, 'description');
    $description = $reflection->getValue($this->tool);

    expect($description)->toContain('List investigation notes');
});
