<?php

declare(strict_types=1);

use Laravel\Mcp\Attributes\IsReadOnly;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Ranetrace\Laravel\Mcp\Tools\GetErrorActivityTool;
use Ranetrace\Laravel\Services\RanetraceApiClient;

beforeEach(function (): void {
    if (! class_exists(Laravel\Mcp\Server\Tool::class)) {
        $this->markTestSkipped('Laravel MCP package not installed');
    }

    $this->mockClient = Mockery::mock(RanetraceApiClient::class);
    $this->tool = new GetErrorActivityTool($this->mockClient);
});

test('gets error activity successfully and returns formatted output', function (): void {
    $this->mockClient->shouldReceive('getErrorActivity')
        ->once()
        ->with('123', [], 'php')
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => [
                'activities' => [
                    [
                        'id' => 456,
                        'action' => 'resolved',
                        'causer_name' => 'AI Agent',
                        'performed_at' => '2026-01-23T12:34:56+00:00',
                    ],
                    [
                        'id' => 455,
                        'action' => 'snoozed',
                        'causer_name' => 'John Doe',
                        'performed_at' => '2026-01-22T10:00:00+00:00',
                    ],
                ],
                'meta' => [
                    'total' => 2,
                    'limit' => 50,
                    'offset' => 0,
                ],
            ],
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
    ]));

    expect($response)->toBeInstanceOf(Response::class);
    expect((string) $response->content())
        ->toContain('Activity Log for Error err_123')
        ->toContain('resolved')
        ->toContain('AI Agent')
        ->toContain('snoozed')
        ->toContain('John Doe')
        ->toContain('Total:** 2');
});

test('handles empty activity list', function (): void {
    $this->mockClient->shouldReceive('getErrorActivity')
        ->once()
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => [
                'activities' => [],
                'meta' => ['total' => 0, 'limit' => 50, 'offset' => 0],
            ],
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
    ]));

    expect((string) $response->content())
        ->toContain('No activity log entries found')
        ->toContain('Total:** 0');
});

test('normalizes error ID by stripping err_ prefix', function (): void {
    $this->mockClient->shouldReceive('getErrorActivity')
        ->once()
        ->with('123', [], 'php')
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => ['activities' => [], 'meta' => []],
        ]);

    $this->tool->handle(new Request([
        'error_id' => 'err_123',
    ]));
});

test('passes javascript type correctly', function (): void {
    $this->mockClient->shouldReceive('getErrorActivity')
        ->once()
        ->with('123', [], 'javascript')
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => ['activities' => [], 'meta' => []],
        ]);

    $this->tool->handle(new Request([
        'error_id' => '123',
        'type' => 'javascript',
    ]));
});

test('normalizes js type to javascript', function (): void {
    $this->mockClient->shouldReceive('getErrorActivity')
        ->once()
        ->with('123', [], 'javascript')
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => ['activities' => [], 'meta' => []],
        ]);

    $this->tool->handle(new Request([
        'error_id' => '123',
        'type' => 'js',
    ]));
});

test('passes limit parameter correctly', function (): void {
    $this->mockClient->shouldReceive('getErrorActivity')
        ->once()
        ->with('123', ['limit' => 10], 'php')
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => ['activities' => [], 'meta' => []],
        ]);

    $this->tool->handle(new Request([
        'error_id' => '123',
        'limit' => 10,
    ]));
});

test('passes offset parameter correctly', function (): void {
    $this->mockClient->shouldReceive('getErrorActivity')
        ->once()
        ->with('123', ['offset' => 5], 'php')
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => ['activities' => [], 'meta' => []],
        ]);

    $this->tool->handle(new Request([
        'error_id' => '123',
        'offset' => 5,
    ]));
});

test('clamps limit parameter to valid range', function (): void {
    $this->mockClient->shouldReceive('getErrorActivity')
        ->once()
        ->with('123', ['limit' => 100], 'php')
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => ['activities' => [], 'meta' => []],
        ]);

    $this->tool->handle(new Request([
        'error_id' => '123',
        'limit' => 200,
    ]));
});

test('ensures minimum limit is 1', function (): void {
    $this->mockClient->shouldReceive('getErrorActivity')
        ->once()
        ->with('123', ['limit' => 1], 'php')
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => ['activities' => [], 'meta' => []],
        ]);

    $this->tool->handle(new Request([
        'error_id' => '123',
        'limit' => -5,
    ]));
});

test('ensures minimum offset is 0', function (): void {
    $this->mockClient->shouldReceive('getErrorActivity')
        ->once()
        ->with('123', ['offset' => 0], 'php')
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => ['activities' => [], 'meta' => []],
        ]);

    $this->tool->handle(new Request([
        'error_id' => '123',
        'offset' => -5,
    ]));
});

test('returns error when error_id is missing', function (): void {
    $this->mockClient->shouldNotReceive('getErrorActivity');

    $response = $this->tool->handle(new Request([]));

    expect((string) $response->content())->toContain('Error ID is required');
});

test('returns error when error_id is empty', function (): void {
    $this->mockClient->shouldNotReceive('getErrorActivity');

    $response = $this->tool->handle(new Request([
        'error_id' => '',
    ]));

    expect((string) $response->content())->toContain('Error ID is required');
});

test('returns error for 404 status', function (): void {
    $this->mockClient->shouldReceive('getErrorActivity')
        ->once()
        ->andReturn([
            'success' => false,
            'status' => 404,
            'error' => 'Error not found',
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '999',
    ]));

    expect((string) $response->content())->toContain("Error with ID '999' not found");
});

test('returns error for 403 status', function (): void {
    $this->mockClient->shouldReceive('getErrorActivity')
        ->once()
        ->andReturn([
            'success' => false,
            'status' => 403,
            'error' => 'Access denied',
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
    ]));

    expect((string) $response->content())->toContain('Access denied');
});

test('returns generic error for other failures', function (): void {
    $this->mockClient->shouldReceive('getErrorActivity')
        ->once()
        ->andReturn([
            'success' => false,
            'status' => 500,
            'error' => 'Internal server error',
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
    ]));

    expect((string) $response->content())
        ->toContain('Failed to get activity log')
        ->toContain('Internal server error');
});

test('has IsReadOnly attribute', function (): void {
    $reflection = new ReflectionClass($this->tool);
    $attributes = $reflection->getAttributes(IsReadOnly::class);

    expect($attributes)->toHaveCount(1);
});

test('has correct description property', function (): void {
    $reflection = new ReflectionProperty($this->tool, 'description');
    $description = $reflection->getValue($this->tool);

    expect($description)->toContain('activity log');
    expect($description)->toContain('state change history');
});
