<?php

declare(strict_types=1);

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Sorane\Laravel\Mcp\Tools\CreateNotesTool;
use Sorane\Laravel\Services\SoraneApiClient;

beforeEach(function (): void {
    if (! class_exists(Laravel\Mcp\Server\Tool::class)) {
        $this->markTestSkipped('Laravel MCP package not installed');
    }

    $this->mockClient = Mockery::mock(SoraneApiClient::class);
    $this->tool = new CreateNotesTool($this->mockClient);
});

test('creates multiple notes successfully and returns formatted output', function (): void {
    $this->mockClient->shouldReceive('createNotesBulk')
        ->once()
        ->with('123', [
            'notes' => [
                ['body' => 'First note'],
                ['body' => 'Second note'],
            ],
        ])
        ->andReturn([
            'success' => true,
            'status' => 201,
            'data' => [
                'notes' => [
                    [
                        'id' => 'note_456',
                        'body' => 'First note',
                        'author_name' => 'AI Agent',
                        'created_at' => '2026-01-23T12:34:56+00:00',
                    ],
                    [
                        'id' => 'note_457',
                        'body' => 'Second note',
                        'author_name' => 'AI Agent',
                        'created_at' => '2026-01-23T12:34:56+00:00',
                    ],
                ],
            ],
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'notes' => [
            ['body' => 'First note'],
            ['body' => 'Second note'],
        ],
    ]));

    expect($response)->toBeInstanceOf(Response::class);
    expect((string) $response->content())
        ->toContain('2 Note(s) Created Successfully')
        ->toContain('note_456')
        ->toContain('note_457')
        ->toContain('First note')
        ->toContain('Second note');
});

test('normalizes error ID by stripping err_ prefix', function (): void {
    $this->mockClient->shouldReceive('createNotesBulk')
        ->once()
        ->with('123', Mockery::any())
        ->andReturn([
            'success' => true,
            'status' => 201,
            'data' => ['notes' => [['id' => 'note_456']]],
        ]);

    $this->tool->handle(new Request([
        'error_id' => 'err_123',
        'notes' => [['body' => 'Test note']],
    ]));
});

test('returns error when error_id is missing', function (): void {
    $this->mockClient->shouldNotReceive('createNotesBulk');

    $response = $this->tool->handle(new Request([
        'notes' => [['body' => 'Test note']],
    ]));

    expect((string) $response->content())->toContain('Error ID is required');
});

test('returns error when error_id is empty', function (): void {
    $this->mockClient->shouldNotReceive('createNotesBulk');

    $response = $this->tool->handle(new Request([
        'error_id' => '',
        'notes' => [['body' => 'Test note']],
    ]));

    expect((string) $response->content())->toContain('Error ID is required');
});

test('returns error when notes is missing', function (): void {
    $this->mockClient->shouldNotReceive('createNotesBulk');

    $response = $this->tool->handle(new Request(['error_id' => '123']));

    expect((string) $response->content())->toContain('Notes array is required');
});

test('returns error when notes is empty', function (): void {
    $this->mockClient->shouldNotReceive('createNotesBulk');

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'notes' => [],
    ]));

    expect((string) $response->content())->toContain('Notes array is required');
});

test('returns error when notes is not an array', function (): void {
    $this->mockClient->shouldNotReceive('createNotesBulk');

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'notes' => 'invalid',
    ]));

    expect((string) $response->content())->toContain('Notes array is required');
});

test('returns error when notes exceeds maximum of 10', function (): void {
    $this->mockClient->shouldNotReceive('createNotesBulk');

    $notes = array_fill(0, 11, ['body' => 'Test note']);
    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'notes' => $notes,
    ]));

    expect((string) $response->content())->toContain('Maximum of 10 notes can be created per request');
});

test('accepts exactly 10 notes', function (): void {
    $this->mockClient->shouldReceive('createNotesBulk')
        ->once()
        ->andReturn([
            'success' => true,
            'status' => 201,
            'data' => ['notes' => array_fill(0, 10, ['id' => 'note_456'])],
        ]);

    $notes = array_fill(0, 10, ['body' => 'Test note']);
    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'notes' => $notes,
    ]));

    expect((string) $response->content())->toContain('10 Note(s) Created Successfully');
});

test('returns error when note item is not an array', function (): void {
    $this->mockClient->shouldNotReceive('createNotesBulk');

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'notes' => ['invalid string'],
    ]));

    expect((string) $response->content())->toContain('Note at index 0 must be an object');
});

test('returns error when note item is missing body field', function (): void {
    $this->mockClient->shouldNotReceive('createNotesBulk');

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'notes' => [['title' => 'No body here']],
    ]));

    expect((string) $response->content())->toContain('Note at index 0 is missing required body field');
});

test('returns error when note item has empty body field', function (): void {
    $this->mockClient->shouldNotReceive('createNotesBulk');

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'notes' => [['body' => '']],
    ]));

    expect((string) $response->content())->toContain('Note at index 0 is missing required body field');
});

test('returns error when any note body exceeds 5000 characters', function (): void {
    $this->mockClient->shouldNotReceive('createNotesBulk');

    $longBody = str_repeat('a', 5001);
    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'notes' => [
            ['body' => 'Valid note'],
            ['body' => $longBody],
        ],
    ]));

    expect((string) $response->content())->toContain('Note at index 1 exceeds maximum body length of 5000 characters');
});

test('validates all notes and reports first error', function (): void {
    $this->mockClient->shouldNotReceive('createNotesBulk');

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'notes' => [
            ['body' => ''],
            ['body' => 'Valid note'],
        ],
    ]));

    expect((string) $response->content())->toContain('Note at index 0 is missing required body field');
});

test('returns error for 404 status', function (): void {
    $this->mockClient->shouldReceive('createNotesBulk')
        ->once()
        ->andReturn([
            'success' => false,
            'status' => 404,
            'error' => 'Error not found',
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '999',
        'notes' => [['body' => 'Test note']],
    ]));

    expect((string) $response->content())->toContain("Error with ID '999' not found");
});

test('returns error for 403 status', function (): void {
    $this->mockClient->shouldReceive('createNotesBulk')
        ->once()
        ->andReturn([
            'success' => false,
            'status' => 403,
            'error' => 'Access denied',
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'notes' => [['body' => 'Test note']],
    ]));

    expect((string) $response->content())->toContain('Access denied');
});

test('returns error for 422 validation status', function (): void {
    $this->mockClient->shouldReceive('createNotesBulk')
        ->once()
        ->andReturn([
            'success' => false,
            'status' => 422,
            'error' => 'Validation failed for notes',
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'notes' => [['body' => 'Test note']],
    ]));

    expect((string) $response->content())->toContain('Validation failed');
});

test('returns generic error for other failures', function (): void {
    $this->mockClient->shouldReceive('createNotesBulk')
        ->once()
        ->andReturn([
            'success' => false,
            'status' => 500,
            'error' => 'Internal server error',
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'notes' => [['body' => 'Test note']],
    ]));

    expect((string) $response->content())
        ->toContain('Failed to create notes')
        ->toContain('Internal server error');
});

test('returns error when response data is empty', function (): void {
    $this->mockClient->shouldReceive('createNotesBulk')
        ->once()
        ->andReturn([
            'success' => true,
            'status' => 201,
            'data' => ['notes' => []],
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'notes' => [['body' => 'Test note']],
    ]));

    expect((string) $response->content())->toContain('empty response received');
});

test('truncates long note bodies in summary view', function (): void {
    $longBody = str_repeat('a', 300);

    $this->mockClient->shouldReceive('createNotesBulk')
        ->once()
        ->andReturn([
            'success' => true,
            'status' => 201,
            'data' => [
                'notes' => [
                    [
                        'id' => 'note_456',
                        'body' => $longBody,
                        'author_name' => 'AI Agent',
                        'created_at' => '2026-01-23T12:34:56+00:00',
                    ],
                ],
            ],
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
        'notes' => [['body' => $longBody]],
    ]));

    expect((string) $response->content())
        ->toContain('...')
        ->not->toContain($longBody);
});

test('has correct description property', function (): void {
    $reflection = new ReflectionProperty($this->tool, 'description');
    $description = $reflection->getValue($this->tool);

    expect($description)->toContain('Bulk create multiple investigation notes');
});
