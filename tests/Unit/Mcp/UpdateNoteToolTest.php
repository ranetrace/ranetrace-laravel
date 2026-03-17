<?php

declare(strict_types=1);

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Ranetrace\Laravel\Mcp\Tools\UpdateNoteTool;
use Ranetrace\Laravel\Services\RanetraceApiClient;

beforeEach(function (): void {
    if (! class_exists(Laravel\Mcp\Server\Tool::class)) {
        $this->markTestSkipped('Laravel MCP package not installed');
    }

    $this->mockClient = Mockery::mock(RanetraceApiClient::class);
    $this->tool = new UpdateNoteTool($this->mockClient);
});

test('updates note successfully and returns formatted output', function (): void {
    $this->mockClient->shouldReceive('updateNote')
        ->once()
        ->with('123', '456', ['body' => 'Updated investigation notes'])
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => [
                'note' => [
                    'id' => 'note_456',
                    'error_id' => 'err_123',
                    'body' => 'Updated investigation notes',
                    'author_name' => 'AI Agent',
                    'created_at' => '2026-01-23T12:34:56+00:00',
                    'updated_at' => '2026-01-23T12:35:00+00:00',
                ],
            ],
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'note_id' => '456',
        'body' => 'Updated investigation notes',
    ]));

    expect($response)->toBeInstanceOf(Response::class);
    expect((string) $response->content())
        ->toContain('Note Updated Successfully')
        ->toContain('note_456')
        ->toContain('Updated investigation notes');
});

test('normalizes error ID by stripping err_ prefix', function (): void {
    $this->mockClient->shouldReceive('updateNote')
        ->once()
        ->with('123', '456', ['body' => 'Test'])
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => ['note' => ['id' => 'note_456']],
        ]);

    $this->tool->handle(new Request([
        'error_id' => 'err_123',
        'note_id' => '456',
        'body' => 'Test',
    ]));
});

test('normalizes note ID by stripping note_ prefix', function (): void {
    $this->mockClient->shouldReceive('updateNote')
        ->once()
        ->with('123', '456', ['body' => 'Test'])
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => ['note' => ['id' => 'note_456']],
        ]);

    $this->tool->handle(new Request([
        'error_id' => '123',
        'note_id' => 'note_456',
        'body' => 'Test',
    ]));
});

test('returns error when error_id is missing', function (): void {
    $this->mockClient->shouldNotReceive('updateNote');

    $response = $this->tool->handle(new Request([
        'note_id' => '456',
        'body' => 'Test',
    ]));

    expect((string) $response->content())->toContain('Error ID is required');
});

test('returns error when note_id is missing', function (): void {
    $this->mockClient->shouldNotReceive('updateNote');

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'body' => 'Test',
    ]));

    expect((string) $response->content())->toContain('Note ID is required');
});

test('returns error when body is missing', function (): void {
    $this->mockClient->shouldNotReceive('updateNote');

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'note_id' => '456',
    ]));

    expect((string) $response->content())->toContain('Note body is required');
});

test('returns error when body is empty', function (): void {
    $this->mockClient->shouldNotReceive('updateNote');

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'note_id' => '456',
        'body' => '',
    ]));

    expect((string) $response->content())->toContain('Note body is required');
});

test('returns error when body exceeds 5000 characters', function (): void {
    $this->mockClient->shouldNotReceive('updateNote');

    $longBody = str_repeat('a', 5001);
    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'note_id' => '456',
        'body' => $longBody,
    ]));

    expect((string) $response->content())->toContain('exceeds maximum length of 5000 characters');
});

test('accepts body at exactly 5000 characters', function (): void {
    $this->mockClient->shouldReceive('updateNote')
        ->once()
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => ['note' => ['id' => 'note_456']],
        ]);

    $exactBody = str_repeat('a', 5000);
    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'note_id' => '456',
        'body' => $exactBody,
    ]));

    expect((string) $response->content())->toContain('Note Updated Successfully');
});

test('returns error for 404 status with error context', function (): void {
    $this->mockClient->shouldReceive('updateNote')
        ->once()
        ->andReturn([
            'success' => false,
            'status' => 404,
            'error' => 'Error not found',
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '999',
        'note_id' => '456',
        'body' => 'Test',
    ]));

    expect((string) $response->content())->toContain("Error with ID '999' not found");
});

test('returns error for 404 status with note context', function (): void {
    $this->mockClient->shouldReceive('updateNote')
        ->once()
        ->andReturn([
            'success' => false,
            'status' => 404,
            'error' => 'Note not found',
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'note_id' => '999',
        'body' => 'Test',
    ]));

    expect((string) $response->content())->toContain("Note with ID '999' not found");
});

test('returns error for 403 status when trying to modify others note', function (): void {
    $this->mockClient->shouldReceive('updateNote')
        ->once()
        ->andReturn([
            'success' => false,
            'status' => 403,
            'error' => 'Cannot modify notes created by other users',
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'note_id' => '456',
        'body' => 'Test',
    ]));

    expect((string) $response->content())->toContain('Cannot modify notes created by other users');
});

test('returns error for 422 validation status', function (): void {
    $this->mockClient->shouldReceive('updateNote')
        ->once()
        ->andReturn([
            'success' => false,
            'status' => 422,
            'error' => 'The body field is required',
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'note_id' => '456',
        'body' => 'Test',
    ]));

    expect((string) $response->content())->toContain('Validation failed');
});

test('returns generic error for other failures', function (): void {
    $this->mockClient->shouldReceive('updateNote')
        ->once()
        ->andReturn([
            'success' => false,
            'status' => 500,
            'error' => 'Internal server error',
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'note_id' => '456',
        'body' => 'Test',
    ]));

    expect((string) $response->content())
        ->toContain('Failed to update note')
        ->toContain('Internal server error');
});

test('returns error when response data is empty', function (): void {
    $this->mockClient->shouldReceive('updateNote')
        ->once()
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => [],
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'note_id' => '456',
        'body' => 'Test',
    ]));

    expect((string) $response->content())->toContain('empty response received');
});

test('handles data directly without note wrapper', function (): void {
    $this->mockClient->shouldReceive('updateNote')
        ->once()
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => [
                'id' => 'note_456',
                'body' => 'Direct note data',
                'author_name' => 'AI Agent',
            ],
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'note_id' => '456',
        'body' => 'Test',
    ]));

    expect((string) $response->content())
        ->toContain('note_456')
        ->toContain('Direct note data');
});

test('has correct description property', function (): void {
    $reflection = new ReflectionProperty($this->tool, 'description');
    $description = $reflection->getValue($this->tool);

    expect($description)->toContain('Update an investigation note');
});
