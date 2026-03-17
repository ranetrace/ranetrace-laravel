<?php

declare(strict_types=1);

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Ranetrace\Laravel\Mcp\Tools\RestoreErrorTool;
use Ranetrace\Laravel\Services\RanetraceApiClient;

beforeEach(function (): void {
    if (! class_exists(Laravel\Mcp\Server\Tool::class)) {
        $this->markTestSkipped('Laravel MCP package not installed');
    }

    $this->mockClient = Mockery::mock(RanetraceApiClient::class);
    $this->tool = new RestoreErrorTool($this->mockClient);
});

test('restores error successfully', function (): void {
    $this->mockClient->shouldReceive('restoreError')
        ->once()
        ->with('123', 'php')
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => [
                'error' => [
                    'id' => 'err_123',
                    'state' => 'open',
                    'status' => 'open',
                    'is_resolved' => false,
                    'is_ignored' => false,
                    'snooze_until' => null,
                ],
                'activity' => [
                    'id' => 500,
                    'action' => 'restored',
                    'performed_at' => '2026-01-23T12:40:00+00:00',
                ],
            ],
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
    ]));

    expect($response)->toBeInstanceOf(Response::class);
    expect((string) $response->content())
        ->toContain('Error Restored Successfully')
        ->toContain('err_123')
        ->toContain('restored')
        ->toContain('2026-01-23T12:40:00+00:00');
});

test('normalizes error ID by stripping err_ prefix', function (): void {
    $this->mockClient->shouldReceive('restoreError')
        ->once()
        ->with('123', 'php')
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => ['error' => [], 'activity' => []],
        ]);

    $this->tool->handle(new Request([
        'error_id' => 'err_123',
    ]));
});

test('passes javascript type correctly', function (): void {
    $this->mockClient->shouldReceive('restoreError')
        ->once()
        ->with('123', 'javascript')
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => ['error' => [], 'activity' => []],
        ]);

    $this->tool->handle(new Request([
        'error_id' => '123',
        'type' => 'javascript',
    ]));
});

test('normalizes js type to javascript', function (): void {
    $this->mockClient->shouldReceive('restoreError')
        ->once()
        ->with('123', 'javascript')
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => ['error' => [], 'activity' => []],
        ]);

    $this->tool->handle(new Request([
        'error_id' => '123',
        'type' => 'js',
    ]));
});

test('returns error when error_id is missing', function (): void {
    $this->mockClient->shouldNotReceive('restoreError');

    $response = $this->tool->handle(new Request([]));

    expect((string) $response->content())->toContain('Error ID is required');
});

test('returns error when error_id is empty', function (): void {
    $this->mockClient->shouldNotReceive('restoreError');

    $response = $this->tool->handle(new Request([
        'error_id' => '',
    ]));

    expect((string) $response->content())->toContain('Error ID is required');
});

test('returns error for 404 status', function (): void {
    $this->mockClient->shouldReceive('restoreError')
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
    $this->mockClient->shouldReceive('restoreError')
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

test('returns error for 422 validation status', function (): void {
    $this->mockClient->shouldReceive('restoreError')
        ->once()
        ->andReturn([
            'success' => false,
            'status' => 422,
            'error' => 'Validation failed',
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
    ]));

    expect((string) $response->content())->toContain('Validation failed');
});

test('returns generic error for other failures', function (): void {
    $this->mockClient->shouldReceive('restoreError')
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
        ->toContain('Failed to restore error')
        ->toContain('Internal server error');
});

test('formats response with all error state fields', function (): void {
    $this->mockClient->shouldReceive('restoreError')
        ->once()
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => [
                'error' => [
                    'id' => 'err_456',
                    'state' => 'open',
                    'status' => 'open',
                    'is_resolved' => false,
                    'is_ignored' => false,
                    'snooze_until' => null,
                ],
                'activity' => [
                    'id' => 789,
                    'action' => 'restored',
                    'performed_at' => '2026-01-23T14:00:00+00:00',
                ],
            ],
        ]);

    $response = $this->tool->handle(new Request(['error_id' => '456']));
    $text = (string) $response->content();

    expect($text)
        ->toContain('Error ID:** err_456')
        ->toContain('State:** open')
        ->toContain('Resolved:** No')
        ->toContain('Ignored:** No')
        ->toContain('Snoozed Until:** None')
        ->toContain('Activity ID:** 789')
        ->toContain('Action:** restored')
        ->toContain('Performed At:** 2026-01-23T14:00:00+00:00');
});

test('handles missing fields with defaults', function (): void {
    $this->mockClient->shouldReceive('restoreError')
        ->once()
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => [],
        ]);

    $response = $this->tool->handle(new Request(['error_id' => '123']));
    $text = (string) $response->content();

    expect($text)
        ->toContain('err_123')
        ->toContain('State:** open')
        ->toContain('Activity ID:** N/A')
        ->toContain('Action:** restored');
});

test('has correct description property', function (): void {
    $reflection = new ReflectionProperty($this->tool, 'description');
    $description = $reflection->getValue($this->tool);

    expect($description)->toContain('Restore a soft-deleted');
    expect($description)->toContain('idempotent');
});

test('defaults to php type when not specified', function (): void {
    $this->mockClient->shouldReceive('restoreError')
        ->once()
        ->with('123', 'php')
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => ['error' => [], 'activity' => []],
        ]);

    $this->tool->handle(new Request(['error_id' => '123']));
});
