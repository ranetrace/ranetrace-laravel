<?php

declare(strict_types=1);

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Sorane\Laravel\Mcp\Tools\IgnoreErrorTool;
use Sorane\Laravel\Services\SoraneApiClient;

beforeEach(function (): void {
    if (! class_exists(Laravel\Mcp\Server\Tool::class)) {
        $this->markTestSkipped('Laravel MCP package not installed');
    }

    $this->mockClient = Mockery::mock(SoraneApiClient::class);
    $this->tool = new IgnoreErrorTool($this->mockClient);
});

test('ignores error successfully and returns formatted output', function (): void {
    $this->mockClient->shouldReceive('ignoreError')
        ->once()
        ->with('123', 'php')
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => [
                'error' => [
                    'id' => 'err_123',
                    'state' => 'ignored',
                    'is_resolved' => false,
                    'is_ignored' => true,
                    'snooze_until' => null,
                ],
                'activity' => [
                    'id' => 458,
                    'action' => 'ignored',
                    'performed_at' => '2026-01-23T12:36:00+00:00',
                ],
            ],
        ]);

    $response = $this->tool->handle(new Request([
        'error_id' => '123',
    ]));

    expect($response)->toBeInstanceOf(Response::class);
    expect((string) $response->content())
        ->toContain('Error Ignored Successfully')
        ->toContain('ignored')
        ->toContain('Yes');
});

test('normalizes error ID by stripping err_ prefix', function (): void {
    $this->mockClient->shouldReceive('ignoreError')
        ->once()
        ->with('123', 'php')
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => ['error' => ['id' => 'err_123', 'state' => 'ignored']],
        ]);

    $this->tool->handle(new Request([
        'error_id' => 'err_123',
    ]));
});

test('passes javascript type correctly', function (): void {
    $this->mockClient->shouldReceive('ignoreError')
        ->once()
        ->with('123', 'javascript')
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => ['error' => ['id' => 'err_123', 'state' => 'ignored']],
        ]);

    $this->tool->handle(new Request([
        'error_id' => '123',
        'type' => 'javascript',
    ]));
});

test('normalizes js type to javascript', function (): void {
    $this->mockClient->shouldReceive('ignoreError')
        ->once()
        ->with('123', 'javascript')
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => ['error' => ['id' => 'err_123', 'state' => 'ignored']],
        ]);

    $this->tool->handle(new Request([
        'error_id' => '123',
        'type' => 'js',
    ]));
});

test('returns error when error_id is missing', function (): void {
    $this->mockClient->shouldNotReceive('ignoreError');

    $response = $this->tool->handle(new Request([]));

    expect((string) $response->content())->toContain('Error ID is required');
});

test('returns error when error_id is empty', function (): void {
    $this->mockClient->shouldNotReceive('ignoreError');

    $response = $this->tool->handle(new Request([
        'error_id' => '',
    ]));

    expect((string) $response->content())->toContain('Error ID is required');
});

test('returns error for 404 status', function (): void {
    $this->mockClient->shouldReceive('ignoreError')
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
    $this->mockClient->shouldReceive('ignoreError')
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
    $this->mockClient->shouldReceive('ignoreError')
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
        ->toContain('Failed to ignore error')
        ->toContain('Internal server error');
});

test('has correct description property', function (): void {
    $reflection = new ReflectionProperty($this->tool, 'description');
    $description = $reflection->getValue($this->tool);

    expect($description)->toContain('Permanently ignore');
    expect($description)->toContain('notifications');
});
