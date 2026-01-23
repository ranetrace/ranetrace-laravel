<?php

declare(strict_types=1);

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Sorane\Laravel\Mcp\Tools\GetNoteTool;
use Sorane\Laravel\Services\SoraneApiClient;

beforeEach(function (): void {
    if (! class_exists(Laravel\Mcp\Server\Tool::class)) {
        $this->markTestSkipped('Laravel MCP package not installed');
    }

    $this->mockClient = Mockery::mock(SoraneApiClient::class);
    $this->tool = new GetNoteTool($this->mockClient);
});

test('gets note successfully and returns formatted output', function (): void {
    $this->mockClient->shouldReceive('getNote')
        ->once()
        ->with('123', '456')
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => [
                'note' => [
                    'id' => 'note_456',
                    'error_id' => 'err_123',
                    'body' => 'Detailed investigation notes with **markdown**',
                    'author_name' => 'AI Agent',
                    'created_at' => '2026-01-23T12:34:56+00:00',
                    'updated_at' => '2026-01-23T12:35:00+00:00',
                    'archived' => false,
                ],
            ],
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'note_id' => '456',
    ]));

    expect($response)->toBeInstanceOf(Response::class);
    expect((string) $response->content())
        ->toContain('Note Details')
        ->toContain('note_456')
        ->toContain('err_123')
        ->toContain('AI Agent')
        ->toContain('Detailed investigation notes with **markdown**')
        ->toContain('Archived:** No');
});

test('normalizes error ID by stripping err_ prefix', function (): void {
    $this->mockClient->shouldReceive('getNote')
        ->once()
        ->with('123', '456')
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => ['note' => ['id' => 'note_456']],
        ]);

    $this->tool->handle(new Request([
        'error_id' => 'err_123',
        'note_id' => '456',
    ]));
});

test('normalizes note ID by stripping note_ prefix', function (): void {
    $this->mockClient->shouldReceive('getNote')
        ->once()
        ->with('123', '456')
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => ['note' => ['id' => 'note_456']],
        ]);

    $this->tool->handle(new Request([
        'error_id' => '123',
        'note_id' => 'note_456',
    ]));
});

test('returns error when error_id is missing', function (): void {
    $this->mockClient->shouldNotReceive('getNote');

    $response = $this->tool->handle(new Request(['note_id' => '456']));

    expect((string) $response->content())->toContain('Error ID is required');
});

test('returns error when error_id is empty', function (): void {
    $this->mockClient->shouldNotReceive('getNote');

    $response = $this->tool->handle(new Request([
        'error_id' => '',
        'note_id' => '456',
    ]));

    expect((string) $response->content())->toContain('Error ID is required');
});

test('returns error when note_id is missing', function (): void {
    $this->mockClient->shouldNotReceive('getNote');

    $response = $this->tool->handle(new Request(['error_id' => '123']));

    expect((string) $response->content())->toContain('Note ID is required');
});

test('returns error when note_id is empty', function (): void {
    $this->mockClient->shouldNotReceive('getNote');

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'note_id' => '',
    ]));

    expect((string) $response->content())->toContain('Note ID is required');
});

test('returns error for 404 status with error context', function (): void {
    $this->mockClient->shouldReceive('getNote')
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
    $this->mockClient->shouldReceive('getNote')
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

test('returns error for 403 status', function (): void {
    $this->mockClient->shouldReceive('getNote')
        ->once()
        ->andReturn([
            'success' => false,
            'status' => 403,
            'error' => 'Access denied',
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'note_id' => '456',
    ]));

    expect((string) $response->content())->toContain('Access denied');
});

test('returns generic error for other failures', function (): void {
    $this->mockClient->shouldReceive('getNote')
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
        ->toContain('Failed to get note')
        ->toContain('Internal server error');
});

test('returns error when response data is empty', function (): void {
    $this->mockClient->shouldReceive('getNote')
        ->once()
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => [],
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'note_id' => '456',
    ]));

    expect((string) $response->content())->toContain("Note with ID '456' not found");
});

test('handles data directly without note wrapper', function (): void {
    $this->mockClient->shouldReceive('getNote')
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
    ]));

    expect((string) $response->content())
        ->toContain('note_456')
        ->toContain('Direct note data');
});

test('displays archived status as Yes when archived', function (): void {
    $this->mockClient->shouldReceive('getNote')
        ->once()
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => [
                'note' => [
                    'id' => 'note_456',
                    'body' => 'Archived note',
                    'author_name' => 'AI Agent',
                    'archived' => true,
                ],
            ],
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'note_id' => '456',
    ]));

    expect((string) $response->content())->toContain('Archived:** Yes');
});

test('handles missing optional fields with defaults', function (): void {
    $this->mockClient->shouldReceive('getNote')
        ->once()
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => [
                'note' => [
                    'id' => 'note_456',
                ],
            ],
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'note_id' => '456',
    ]));

    expect((string) $response->content())
        ->toContain('Unknown')
        ->toContain('Archived:** No');
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

    expect($description)->toContain('Get detailed information about a specific note');
});
