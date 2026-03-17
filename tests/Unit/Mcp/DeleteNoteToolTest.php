<?php

declare(strict_types=1);

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Ranetrace\Laravel\Mcp\Tools\DeleteNoteTool;
use Ranetrace\Laravel\Services\RanetraceApiClient;

beforeEach(function (): void {
    if (! class_exists(Laravel\Mcp\Server\Tool::class)) {
        $this->markTestSkipped('Laravel MCP package not installed');
    }

    $this->mockClient = Mockery::mock(RanetraceApiClient::class);
    $this->tool = new DeleteNoteTool($this->mockClient);
});

test('deletes note successfully and returns confirmation message', function (): void {
    $this->mockClient->shouldReceive('deleteNote')
        ->once()
        ->with('123', '456')
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => ['message' => 'Note archived successfully'],
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'note_id' => '456',
    ]));

    expect($response)->toBeInstanceOf(Response::class);
    expect((string) $response->content())->toContain("Note '456' has been archived successfully");
});

test('normalizes error ID by stripping err_ prefix', function (): void {
    $this->mockClient->shouldReceive('deleteNote')
        ->once()
        ->with('123', '456')
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => [],
        ]);

    $this->tool->handle(new Request([
        'error_id' => 'err_123',
        'note_id' => '456',
    ]));
});

test('normalizes note ID by stripping note_ prefix', function (): void {
    $this->mockClient->shouldReceive('deleteNote')
        ->once()
        ->with('123', '456')
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => [],
        ]);

    $this->tool->handle(new Request([
        'error_id' => '123',
        'note_id' => 'note_456',
    ]));
});

test('returns error when error_id is missing', function (): void {
    $this->mockClient->shouldNotReceive('deleteNote');

    $response = $this->tool->handle(new Request(['note_id' => '456']));

    expect((string) $response->content())->toContain('Error ID is required');
});

test('returns error when error_id is empty', function (): void {
    $this->mockClient->shouldNotReceive('deleteNote');

    $response = $this->tool->handle(new Request([
        'error_id' => '',
        'note_id' => '456',
    ]));

    expect((string) $response->content())->toContain('Error ID is required');
});

test('returns error when note_id is missing', function (): void {
    $this->mockClient->shouldNotReceive('deleteNote');

    $response = $this->tool->handle(new Request(['error_id' => '123']));

    expect((string) $response->content())->toContain('Note ID is required');
});

test('returns error when note_id is empty', function (): void {
    $this->mockClient->shouldNotReceive('deleteNote');

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'note_id' => '',
    ]));

    expect((string) $response->content())->toContain('Note ID is required');
});

test('returns error for 404 status with error context', function (): void {
    $this->mockClient->shouldReceive('deleteNote')
        ->once()
        ->andReturn([
            'success' => false,
            'status' => 404,
            'error' => 'Error not found',
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '999',
        'note_id' => '456',
    ]));

    expect((string) $response->content())->toContain("Error with ID '999' not found");
});

test('returns error for 404 status with note context', function (): void {
    $this->mockClient->shouldReceive('deleteNote')
        ->once()
        ->andReturn([
            'success' => false,
            'status' => 404,
            'error' => 'Note not found',
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'note_id' => '999',
    ]));

    expect((string) $response->content())->toContain("Note with ID '999' not found");
});

test('returns error for 403 status when trying to delete others note', function (): void {
    $this->mockClient->shouldReceive('deleteNote')
        ->once()
        ->andReturn([
            'success' => false,
            'status' => 403,
            'error' => 'Cannot delete notes created by other users',
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'note_id' => '456',
    ]));

    expect((string) $response->content())->toContain('Cannot delete notes created by other users');
});

test('returns generic error for other failures', function (): void {
    $this->mockClient->shouldReceive('deleteNote')
        ->once()
        ->andReturn([
            'success' => false,
            'status' => 500,
            'error' => 'Internal server error',
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'note_id' => '456',
    ]));

    expect((string) $response->content())
        ->toContain('Failed to delete note')
        ->toContain('Internal server error');
});

test('returns unknown error when error field is missing', function (): void {
    $this->mockClient->shouldReceive('deleteNote')
        ->once()
        ->andReturn([
            'success' => false,
            'status' => 500,
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'note_id' => '456',
    ]));

    expect((string) $response->content())->toContain('Unknown error occurred');
});

test('has correct description property', function (): void {
    $reflection = new ReflectionProperty($this->tool, 'description');
    $description = $reflection->getValue($this->tool);

    expect($description)->toContain('Archive (delete) an investigation note');
});
