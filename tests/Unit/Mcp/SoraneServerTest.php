<?php

declare(strict_types=1);

use Laravel\Mcp\Server;
use Sorane\Laravel\Mcp\SoraneServer;
use Sorane\Laravel\Mcp\Tools\BulkDeleteErrorsTool;
use Sorane\Laravel\Mcp\Tools\BulkIgnoreErrorsTool;
use Sorane\Laravel\Mcp\Tools\BulkResolveErrorsTool;
use Sorane\Laravel\Mcp\Tools\BulkRestoreErrorsTool;
use Sorane\Laravel\Mcp\Tools\CreateNotesTool;
use Sorane\Laravel\Mcp\Tools\CreateNoteTool;
use Sorane\Laravel\Mcp\Tools\DeleteErrorTool;
use Sorane\Laravel\Mcp\Tools\DeleteNoteTool;
use Sorane\Laravel\Mcp\Tools\ErrorStatsTool;
use Sorane\Laravel\Mcp\Tools\GetErrorActivityTool;
use Sorane\Laravel\Mcp\Tools\GetErrorTool;
use Sorane\Laravel\Mcp\Tools\GetNoteTool;
use Sorane\Laravel\Mcp\Tools\IgnoreErrorTool;
use Sorane\Laravel\Mcp\Tools\LatestErrorsTool;
use Sorane\Laravel\Mcp\Tools\ListNotesTool;
use Sorane\Laravel\Mcp\Tools\ReopenErrorTool;
use Sorane\Laravel\Mcp\Tools\ResolveErrorTool;
use Sorane\Laravel\Mcp\Tools\RestoreErrorTool;
use Sorane\Laravel\Mcp\Tools\SearchErrorsTool;
use Sorane\Laravel\Mcp\Tools\SnoozeErrorTool;
use Sorane\Laravel\Mcp\Tools\UnignoreErrorTool;
use Sorane\Laravel\Mcp\Tools\UnsnoozeErrorTool;
use Sorane\Laravel\Mcp\Tools\UpdateNoteTool;

beforeEach(function (): void {
    if (! class_exists(Server::class)) {
        $this->markTestSkipped('Laravel MCP package not installed');
    }
});

function getServerProperty(string $property): mixed
{
    return (new ReflectionClass(SoraneServer::class))->getDefaultProperties()[$property];
}

test('extends Laravel MCP Server class', function (): void {
    expect(SoraneServer::class)->toExtend(Server::class);
});

test('has correct name property', function (): void {
    expect(getServerProperty('name'))->toBe('Sorane');
});

test('has correct version property', function (): void {
    expect(getServerProperty('version'))->toBe('1.0.0');
});

test('has instructions property set', function (): void {
    $instructions = getServerProperty('instructions');

    expect($instructions)->toBeString()
        ->not->toBeEmpty()
        ->toContain('Sorane');
});

test('instructions mention notes functionality', function (): void {
    $instructions = getServerProperty('instructions');

    expect($instructions)->toContain('notes');
});

test('instructions mention error state functionality', function (): void {
    $instructions = getServerProperty('instructions');

    expect($instructions)->toContain('resolve');
});

test('instructions mention search functionality', function (): void {
    $instructions = getServerProperty('instructions');

    expect($instructions)->toContain('search');
});

test('instructions mention restore functionality', function (): void {
    $instructions = getServerProperty('instructions');

    expect($instructions)->toContain('restore');
});

test('registers LatestErrorsTool', function (): void {
    expect(getServerProperty('tools'))->toContain(LatestErrorsTool::class);
});

test('registers GetErrorTool', function (): void {
    expect(getServerProperty('tools'))->toContain(GetErrorTool::class);
});

test('registers ErrorStatsTool', function (): void {
    expect(getServerProperty('tools'))->toContain(ErrorStatsTool::class);
});

test('registers CreateNoteTool', function (): void {
    expect(getServerProperty('tools'))->toContain(CreateNoteTool::class);
});

test('registers ListNotesTool', function (): void {
    expect(getServerProperty('tools'))->toContain(ListNotesTool::class);
});

test('registers GetNoteTool', function (): void {
    expect(getServerProperty('tools'))->toContain(GetNoteTool::class);
});

test('registers UpdateNoteTool', function (): void {
    expect(getServerProperty('tools'))->toContain(UpdateNoteTool::class);
});

test('registers DeleteNoteTool', function (): void {
    expect(getServerProperty('tools'))->toContain(DeleteNoteTool::class);
});

test('registers CreateNotesTool', function (): void {
    expect(getServerProperty('tools'))->toContain(CreateNotesTool::class);
});

test('registers ResolveErrorTool', function (): void {
    expect(getServerProperty('tools'))->toContain(ResolveErrorTool::class);
});

test('registers ReopenErrorTool', function (): void {
    expect(getServerProperty('tools'))->toContain(ReopenErrorTool::class);
});

test('registers IgnoreErrorTool', function (): void {
    expect(getServerProperty('tools'))->toContain(IgnoreErrorTool::class);
});

test('registers UnignoreErrorTool', function (): void {
    expect(getServerProperty('tools'))->toContain(UnignoreErrorTool::class);
});

test('registers SnoozeErrorTool', function (): void {
    expect(getServerProperty('tools'))->toContain(SnoozeErrorTool::class);
});

test('registers UnsnoozeErrorTool', function (): void {
    expect(getServerProperty('tools'))->toContain(UnsnoozeErrorTool::class);
});

test('registers DeleteErrorTool', function (): void {
    expect(getServerProperty('tools'))->toContain(DeleteErrorTool::class);
});

test('registers GetErrorActivityTool', function (): void {
    expect(getServerProperty('tools'))->toContain(GetErrorActivityTool::class);
});

test('registers BulkResolveErrorsTool', function (): void {
    expect(getServerProperty('tools'))->toContain(BulkResolveErrorsTool::class);
});

test('registers BulkIgnoreErrorsTool', function (): void {
    expect(getServerProperty('tools'))->toContain(BulkIgnoreErrorsTool::class);
});

test('registers BulkDeleteErrorsTool', function (): void {
    expect(getServerProperty('tools'))->toContain(BulkDeleteErrorsTool::class);
});

test('registers SearchErrorsTool', function (): void {
    expect(getServerProperty('tools'))->toContain(SearchErrorsTool::class);
});

test('registers RestoreErrorTool', function (): void {
    expect(getServerProperty('tools'))->toContain(RestoreErrorTool::class);
});

test('registers BulkRestoreErrorsTool', function (): void {
    expect(getServerProperty('tools'))->toContain(BulkRestoreErrorsTool::class);
});

test('registers all twenty-three tools', function (): void {
    expect(getServerProperty('tools'))->toHaveCount(23);
});
