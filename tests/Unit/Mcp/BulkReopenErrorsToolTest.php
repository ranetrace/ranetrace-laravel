<?php

declare(strict_types=1);

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Ranetrace\Laravel\Mcp\Tools\BulkReopenErrorsTool;
use Ranetrace\Laravel\Services\RanetraceApiClient;

beforeEach(function (): void {
    if (! class_exists(Laravel\Mcp\Server\Tool::class)) {
        $this->markTestSkipped('Laravel MCP package not installed');
    }

    $this->mockClient = Mockery::mock(RanetraceApiClient::class);
    $this->tool = new BulkReopenErrorsTool($this->mockClient);
});

test('reopens multiple errors successfully', function (): void {
    $this->mockClient->shouldReceive('bulkReopenErrors')
        ->once()
        ->with(['123', '124'], 'php')
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => [
                'reopened_count' => 2,
                'errors' => [
                    ['id' => 'err_123', 'state' => 'open'],
                    ['id' => 'err_124', 'state' => 'open'],
                ],
            ],
        ]);

    $response = $this->tool->handle(new Request([
        'error_ids' => ['123', '124'],
        'type' => 'php',
    ]));

    expect($response)->toBeInstanceOf(Response::class);
    expect((string) $response->content())
        ->toContain('Bulk Reopen Completed')
        ->toContain('Reopened Count:** 2')
        ->toContain('err_123');
});

test('normalizes error IDs by stripping err_ prefix', function (): void {
    $this->mockClient->shouldReceive('bulkReopenErrors')
        ->once()
        ->with(['123', '124'], 'php')
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => ['reopened_count' => 2, 'errors' => []],
        ]);

    $this->tool->handle(new Request([
        'error_ids' => ['err_123', 'err_124'],
        'type' => 'php',
    ]));
});

test('passes javascript type and strips jserr_ prefix', function (): void {
    $this->mockClient->shouldReceive('bulkReopenErrors')
        ->once()
        ->with(['123'], 'javascript')
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => ['reopened_count' => 1, 'errors' => []],
        ]);

    $this->tool->handle(new Request([
        'error_ids' => ['jserr_123'],
        'type' => 'js',
    ]));
});

test('returns error when error_ids is not an array', function (): void {
    $this->mockClient->shouldNotReceive('bulkReopenErrors');

    $response = $this->tool->handle(new Request([
        'error_ids' => '123',
        'type' => 'php',
    ]));

    expect((string) $response->content())->toContain('must be an array');
});

test('returns error when error_ids is empty', function (): void {
    $this->mockClient->shouldNotReceive('bulkReopenErrors');

    $response = $this->tool->handle(new Request([
        'error_ids' => [],
        'type' => 'php',
    ]));

    expect((string) $response->content())->toContain('At least one error ID is required');
});

test('returns error when error_ids exceeds 50 limit', function (): void {
    $this->mockClient->shouldNotReceive('bulkReopenErrors');

    $errorIds = array_map(fn ($i) => (string) $i, range(1, 51));
    $response = $this->tool->handle(new Request([
        'error_ids' => $errorIds,
        'type' => 'php',
    ]));

    expect((string) $response->content())->toContain('Maximum 50 errors');
});

test('returns error for 404 status', function (): void {
    $this->mockClient->shouldReceive('bulkReopenErrors')
        ->once()
        ->andReturn([
            'success' => false,
            'status' => 404,
            'error' => 'Error 999 not found',
        ]);

    $response = $this->tool->handle(new Request([
        'error_ids' => ['123', '999'],
        'type' => 'php',
    ]));

    expect((string) $response->content())->toContain('One or more errors not found');
});

test('returns generic error for other failures', function (): void {
    $this->mockClient->shouldReceive('bulkReopenErrors')
        ->once()
        ->andReturn([
            'success' => false,
            'status' => 500,
            'error' => 'Internal server error',
        ]);

    $response = $this->tool->handle(new Request([
        'error_ids' => ['123'],
        'type' => 'php',
    ]));

    expect((string) $response->content())
        ->toContain('Failed to bulk reopen errors')
        ->toContain('Internal server error');
});

test('requires an explicit type for bulk operations', function (): void {
    $this->mockClient->shouldNotReceive('bulkReopenErrors');

    $response = $this->tool->handle(new Request([
        'error_ids' => ['123'],
    ]));

    expect((string) $response->content())->toContain('"type" parameter is required');
});

test('rejects mixing php and javascript ids in one bulk call', function (): void {
    $this->mockClient->shouldNotReceive('bulkReopenErrors');

    $response = $this->tool->handle(new Request([
        'error_ids' => ['err_1', 'jserr_2'],
        'type' => 'php',
    ]));

    expect((string) $response->content())
        ->toContain("implies type 'javascript'")
        ->toContain('must match the single type');
});

test('has correct description property', function (): void {
    $reflection = new ReflectionProperty($this->tool, 'description');
    $description = $reflection->getValue($this->tool);

    expect($description)->toContain('Reopen multiple');
    expect($description)->toContain('Maximum 50');
    expect($description)->toContain('atomic');
});
