<?php

declare(strict_types=1);

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Sorane\Laravel\Mcp\Tools\CreateNoteTool;
use Sorane\Laravel\Services\SoraneApiClient;

beforeEach(function (): void {
    if (! class_exists(Laravel\Mcp\Server\Tool::class)) {
        $this->markTestSkipped('Laravel MCP package not installed');
    }

    $this->mockClient = Mockery::mock(SoraneApiClient::class);
    $this->tool = new CreateNoteTool($this->mockClient);
});

test('creates note successfully and returns formatted output', function (): void {
    $this->mockClient->shouldReceive('createNote')
        ->once()
        ->with('123', ['body' => 'Test investigation notes'])
        ->andReturn([
            'success' => true,
            'status' => 201,
            'data' => [
                'note' => [
                    'id' => 'note_456',
                    'error_id' => 'err_123',
                    'body' => 'Test investigation notes',
                    'author_name' => 'AI Agent',
                    'created_at' => '2026-01-23T12:34:56+00:00',
                    'updated_at' => '2026-01-23T12:34:56+00:00',
                ],
            ],
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'body' => 'Test investigation notes',
    ]));

    expect($response)->toBeInstanceOf(Response::class);
    expect((string) $response->content())
        ->toContain('Note Created Successfully')
        ->toContain('note_456')
        ->toContain('AI Agent')
        ->toContain('Test investigation notes');
});

test('normalizes error ID by stripping err_ prefix', function (): void {
    $this->mockClient->shouldReceive('createNote')
        ->once()
        ->with('123', ['body' => 'Test note'])
        ->andReturn([
            'success' => true,
            'status' => 201,
            'data' => ['note' => ['id' => 'note_456']],
        ]);

    $this->tool->handle(new Request([
        'error_id' => 'err_123',
        'body' => 'Test note',
    ]));
});

test('returns error when error_id is missing', function (): void {
    $this->mockClient->shouldNotReceive('createNote');

    $response = $this->tool->handle(new Request(['body' => 'Test note']));

    expect((string) $response->content())->toContain('Error ID is required');
});

test('returns error when error_id is empty', function (): void {
    $this->mockClient->shouldNotReceive('createNote');

    $response = $this->tool->handle(new Request([
        'error_id' => '',
        'body' => 'Test note',
    ]));

    expect((string) $response->content())->toContain('Error ID is required');
});

test('returns error when body is missing', function (): void {
    $this->mockClient->shouldNotReceive('createNote');

    $response = $this->tool->handle(new Request(['error_id' => '123']));

    expect((string) $response->content())->toContain('Note body is required');
});

test('returns error when body is empty', function (): void {
    $this->mockClient->shouldNotReceive('createNote');

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'body' => '',
    ]));

    expect((string) $response->content())->toContain('Note body is required');
});

test('returns error when body exceeds 5000 characters', function (): void {
    $this->mockClient->shouldNotReceive('createNote');

    $longBody = str_repeat('a', 5001);
    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'body' => $longBody,
    ]));

    expect((string) $response->content())->toContain('exceeds maximum length of 5000 characters');
});

test('accepts body at exactly 5000 characters', function (): void {
    $this->mockClient->shouldReceive('createNote')
        ->once()
        ->andReturn([
            'success' => true,
            'status' => 201,
            'data' => ['note' => ['id' => 'note_456']],
        ]);

    $exactBody = str_repeat('a', 5000);
    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'body' => $exactBody,
    ]));

    expect((string) $response->content())->toContain('Note Created Successfully');
});

test('returns error for 404 status', function (): void {
    $this->mockClient->shouldReceive('createNote')
        ->once()
        ->andReturn([
            'success' => false,
            'status' => 404,
            'error' => 'Error not found',
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '999',
        'body' => 'Test note',
    ]));

    expect((string) $response->content())->toContain("Not found");
});

test('returns error for 403 status', function (): void {
    $this->mockClient->shouldReceive('createNote')
        ->once()
        ->andReturn([
            'success' => false,
            'status' => 403,
            'error' => 'Cross-project access denied',
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'body' => 'Test note',
    ]));

    expect((string) $response->content())->toContain('Access denied');
});

test('returns error for 422 validation status', function (): void {
    $this->mockClient->shouldReceive('createNote')
        ->once()
        ->andReturn([
            'success' => false,
            'status' => 422,
            'error' => 'The body field is required',
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'body' => 'Test note',
    ]));

    expect((string) $response->content())->toContain('Validation failed');
});

test('returns generic error for other failures', function (): void {
    $this->mockClient->shouldReceive('createNote')
        ->once()
        ->andReturn([
            'success' => false,
            'status' => 500,
            'error' => 'Internal server error',
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'body' => 'Test note',
    ]));

    expect((string) $response->content())
        ->toContain('Failed to create note')
        ->toContain('Internal server error');
});

test('returns error when response data is empty', function (): void {
    $this->mockClient->shouldReceive('createNote')
        ->once()
        ->andReturn([
            'success' => true,
            'status' => 201,
            'data' => [],
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'body' => 'Test note',
    ]));

    expect((string) $response->content())->toContain('empty response received');
});

test('handles data directly without note wrapper', function (): void {
    $this->mockClient->shouldReceive('createNote')
        ->once()
        ->andReturn([
            'success' => true,
            'status' => 201,
            'data' => [
                'id' => 'note_456',
                'body' => 'Direct note data',
                'author_name' => 'AI Agent',
            ],
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'body' => 'Test note',
    ]));

    expect((string) $response->content())
        ->toContain('note_456')
        ->toContain('Direct note data');
});

test('uses fallback for missing error_id in response', function (): void {
    $this->mockClient->shouldReceive('createNote')
        ->once()
        ->andReturn([
            'success' => true,
            'status' => 201,
            'data' => [
                'note' => [
                    'id' => 'note_456',
                    'body' => 'Test note',
                    'author_name' => 'AI Agent',
                ],
            ],
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'body' => 'Test note',
    ]));

    expect((string) $response->content())->toContain('123');
});

test('has correct description property', function (): void {
    $reflection = new ReflectionProperty($this->tool, 'description');
    $description = $reflection->getValue($this->tool);

    expect($description)->toContain('Create an investigation note');
});
