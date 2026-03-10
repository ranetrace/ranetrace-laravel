<?php

declare(strict_types=1);

use Laravel\Mcp\Request;
use Sorane\Laravel\Mcp\Tools\LatestErrorsTool;
use Sorane\Laravel\Services\SoraneApiClient;

beforeEach(function (): void {
    if (! class_exists(Laravel\Mcp\Server\Tool::class)) {
        $this->markTestSkipped('Laravel MCP package not installed');
    }

    $this->mockClient = Mockery::mock(SoraneApiClient::class);
});

test('MCP tools handle SUBSCRIPTION_REQUIRED error gracefully', function (): void {
    $tool = new LatestErrorsTool($this->mockClient);

    $this->mockClient->shouldReceive('getLatestErrors')
        ->once()
        ->andReturn([
            'success' => false,
            'status' => 403,
            'error_code' => 'SUBSCRIPTION_REQUIRED',
            'data' => [
                'success' => false,
                'message' => 'An active subscription or trial is required',
                'error_code' => 'SUBSCRIPTION_REQUIRED',
            ],
        ]);

    $response = $tool->handle(new Request([]));

    expect((string) $response->content())
        ->toContain('Sorane: API access requires an active subscription or trial.');
});

test('MCP tools handle 403 without SUBSCRIPTION_REQUIRED normally', function (): void {
    $tool = new LatestErrorsTool($this->mockClient);

    $this->mockClient->shouldReceive('getLatestErrors')
        ->once()
        ->andReturn([
            'success' => false,
            'status' => 403,
            'error_code' => 'FORBIDDEN',
            'data' => [
                'success' => false,
                'message' => 'Access denied',
                'error_code' => 'FORBIDDEN',
            ],
        ]);

    $response = $tool->handle(new Request([]));

    expect((string) $response->content())
        ->toContain('Access denied');
});
