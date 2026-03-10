<?php

declare(strict_types=1);

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Sorane\Laravel\Mcp\Tools\BulkResolveErrorsTool;
use Sorane\Laravel\Services\SoraneApiClient;

beforeEach(function (): void {
    if (! class_exists(Laravel\Mcp\Server\Tool::class)) {
        $this->markTestSkipped('Laravel MCP package not installed');
    }

    $this->mockClient = Mockery::mock(SoraneApiClient::class);
    $this->tool = new BulkResolveErrorsTool($this->mockClient);
});

test('resolves multiple errors successfully', function (): void {
    $this->mockClient->shouldReceive('bulkResolveErrors')
        ->once()
        ->with(['123', '124', '125'], 'php')
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => [
                'resolved_count' => 3,
                'errors' => [
                    ['id' => 'err_123', 'state' => 'resolved'],
                    ['id' => 'err_124', 'state' => 'resolved'],
                    ['id' => 'err_125', 'state' => 'resolved'],
                ],
            ],
        ]);

    $response = $this->tool->handle(new Request([
        'error_ids' => ['123', '124', '125'],
    ]));

    expect($response)->toBeInstanceOf(Response::class);
    expect((string) $response->content())
        ->toContain('Bulk Resolve Completed')
        ->toContain('Resolved Count:** 3')
        ->toContain('err_123')
        ->toContain('resolved');
});

test('normalizes error IDs by stripping err_ prefix', function (): void {
    $this->mockClient->shouldReceive('bulkResolveErrors')
        ->once()
        ->with(['123', '124'], 'php')
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => ['resolved_count' => 2, 'errors' => []],
        ]);

    $this->tool->handle(new Request([
        'error_ids' => ['err_123', 'err_124'],
    ]));
});

test('passes javascript type correctly', function (): void {
    $this->mockClient->shouldReceive('bulkResolveErrors')
        ->once()
        ->with(['123'], 'javascript')
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => ['resolved_count' => 1, 'errors' => []],
        ]);

    $this->tool->handle(new Request([
        'error_ids' => ['123'],
        'type' => 'javascript',
    ]));
});

test('normalizes js type to javascript', function (): void {
    $this->mockClient->shouldReceive('bulkResolveErrors')
        ->once()
        ->with(['123'], 'javascript')
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => ['resolved_count' => 1, 'errors' => []],
        ]);

    $this->tool->handle(new Request([
        'error_ids' => ['123'],
        'type' => 'js',
    ]));
});

test('returns error when error_ids is not an array', function (): void {
    $this->mockClient->shouldNotReceive('bulkResolveErrors');

    $response = $this->tool->handle(new Request([
        'error_ids' => '123',
    ]));

    expect((string) $response->content())->toContain('must be an array');
});

test('returns error when error_ids is empty', function (): void {
    $this->mockClient->shouldNotReceive('bulkResolveErrors');

    $response = $this->tool->handle(new Request([
        'error_ids' => [],
    ]));

    expect((string) $response->content())->toContain('At least one error ID is required');
});

test('returns error when error_ids exceeds 50 limit', function (): void {
    $this->mockClient->shouldNotReceive('bulkResolveErrors');

    $errorIds = array_map(fn ($i) => (string) $i, range(1, 51));
    $response = $this->tool->handle(new Request([
        'error_ids' => $errorIds,
    ]));

    expect((string) $response->content())->toContain('Maximum 50 errors');
});

test('accepts exactly 50 error IDs', function (): void {
    $this->mockClient->shouldReceive('bulkResolveErrors')
        ->once()
        ->andReturn([
            'success' => true,
            'status' => 200,
            'data' => ['resolved_count' => 50, 'errors' => []],
        ]);

    $errorIds = array_map(fn ($i) => (string) $i, range(1, 50));
    $response = $this->tool->handle(new Request([
        'error_ids' => $errorIds,
    ]));

    expect((string) $response->content())->toContain('Bulk Resolve Completed');
});

test('returns error for invalid error ID in array', function (): void {
    $this->mockClient->shouldNotReceive('bulkResolveErrors');

    $response = $this->tool->handle(new Request([
        'error_ids' => ['123', '', '125'],
    ]));

    expect((string) $response->content())->toContain('Invalid error ID at index 1');
});

test('returns error for non-string error ID in array', function (): void {
    $this->mockClient->shouldNotReceive('bulkResolveErrors');

    $response = $this->tool->handle(new Request([
        'error_ids' => ['123', 456, '789'],
    ]));

    expect((string) $response->content())->toContain('Invalid error ID at index 1');
});

test('returns error for 404 status', function (): void {
    $this->mockClient->shouldReceive('bulkResolveErrors')
        ->once()
        ->andReturn([
            'success' => false,
            'status' => 404,
            'error' => 'Error 123 not found',
        ]);

    $response = $this->tool->handle(new Request([
        'error_ids' => ['123', '999'],
    ]));

    expect((string) $response->content())->toContain('Not found');
});

test('returns error for 403 status', function (): void {
    $this->mockClient->shouldReceive('bulkResolveErrors')
        ->once()
        ->andReturn([
            'success' => false,
            'status' => 403,
            'error' => 'Access denied',
        ]);

    $response = $this->tool->handle(new Request([
        'error_ids' => ['123'],
    ]));

    expect((string) $response->content())->toContain('Access denied');
});

test('returns error for 422 validation status', function (): void {
    $this->mockClient->shouldReceive('bulkResolveErrors')
        ->once()
        ->andReturn([
            'success' => false,
            'status' => 422,
            'error' => 'Invalid error IDs',
        ]);

    $response = $this->tool->handle(new Request([
        'error_ids' => ['123'],
    ]));

    expect((string) $response->content())->toContain('Validation failed');
});

test('returns generic error for other failures', function (): void {
    $this->mockClient->shouldReceive('bulkResolveErrors')
        ->once()
        ->andReturn([
            'success' => false,
            'status' => 500,
            'error' => 'Internal server error',
        ]);

    $response = $this->tool->handle(new Request([
        'error_ids' => ['123'],
    ]));

    expect((string) $response->content())
        ->toContain('Failed to bulk resolve errors')
        ->toContain('Internal server error');
});

test('has correct description property', function (): void {
    $reflection = new ReflectionProperty($this->tool, 'description');
    $description = $reflection->getValue($this->tool);

    expect($description)->toContain('Resolve multiple errors');
    expect($description)->toContain('Maximum 50');
    expect($description)->toContain('atomic');
});
